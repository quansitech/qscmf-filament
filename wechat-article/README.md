# quansitech/cmf-module-wechat-article

QS CMF 公众号文章嵌入模块：在 Filament RichEditor 里插入「公众号文章」自定义块，块内是**保真只读 iframe**（保留公众号原始排版）；保存内容时即抓取原文快照落库，正文图片自动下载到媒体库（cmf-media）并登记引用。

## 能力

| 能力 | 说明 |
| --- | --- |
| 公众号文章块 | 工具栏「自定义块」→「公众号文章」，粘贴 `https://mp.weixin.qq.com/...` 链接即插入；块在编辑器内只读预览、不可编辑，可拖动/删除 |
| 保真渲染 | 不做 TipTap 结构化改写，保留公众号原始 `<section>`/内联样式，iframe 只读展示 |
| 提交即抓 | 保存内容记录时抓取正文写入 `wechat_articles` 表（`url` 唯一），前台直接读库，不再实时抓取 |
| 图片落地 | 正文外链图片下载进媒体库，正文改写成 `<img data-id src>`，并在承载记录上登记媒体引用，避免孤儿清理 |
| 重新抓取 | 编辑器内块标题栏「重新抓取」按钮，强制覆盖快照并刷新预览；代码侧 `WechatArticleStore::fetch($url, force: true)` |
| 保存中反馈 | 保存含公众号块的表单时显示「正在抓取…」浮层，重新抓取按钮有旋转态 |
| 安全 | 域名白名单 + 仅公网 IP + 禁止重定向/认证信息/异常端口 + CSP + iframe sandbox + 抓取限流 |

## 安装

```bash
composer require quansitech/cmf-module-wechat-article
```

依赖 `quansitech/cmf-core`、`quansitech/cmf-module-media`、`symfony/html-sanitizer`（均为已有依赖，无需额外配置）。

发布配置（可选，默认值即可用）：

```bash
php artisan vendor:publish --tag=cmf-config          # 或随 cmf:install 一起发布
php artisan vendor:publish --tag=cmf-wechat-article-migrations   # 仅需把迁移导出到宿主目录时
php artisan migrate                                   # 建 wechat_articles 表（Provider 已 loadMigrationsFrom，无需发布也可迁移）
```

## 后台接线（两步）

### 1. RichEditor 挂块与前端增强

```php
use Quansitech\Cmf\WechatArticle\Filament\RichContent\WechatEditorPlugin;
use Quansitech\Cmf\WechatArticle\Filament\RichContent\WechatEmbedBlock;
use Filament\Forms\Components\RichEditor;

RichEditor::make('content')
    ->customBlocks([WechatEmbedBlock::class])
    ->plugins([WechatEditorPlugin::class]);
```

- `customBlocks()` 让工具栏出现「自定义块」，`WechatEmbedBlock` 负责链接输入弹窗与只读预览；
- `WechatEditorPlugin` 只注入前端脚本（块标题栏「重新抓取」按钮、保存中浮层），不新增节点。

### 2. 内容模型接入保存钩子与媒体引用

```php
use Quansitech\Cmf\Media\Concerns\HasMedia;
use Quansitech\Cmf\WechatArticle\Concerns\SavesWechatArticleEmbeds;

class Content extends Model implements HasMedia
{
    use HasMedia;
    use SavesWechatArticleEmbeds;
}
```

并在 `config/cmf-wechat-article.php` 指定承载模型（包内不绑定具体模型）：

```php
'content_model' => \App\Models\Content::class,
```

- `saving`：按 `content_fields`（默认 `['content']`）扫描公众号块，抓取并落库；单个链接失败不阻断保存，只在后台弹通知；
- `saved`：把块内图片与 `wechat_articles.media_ids` 合并后 `syncMedia($ids, $field)`，保证图片不被 24h 孤儿清理。

## 前台渲染

```blade
{!! \Quansitech\Cmf\WechatArticle\Support\WechatEmbedView::render($content->content, $content->id) !!}
```

`render($html, $ref)` 只替换公众号块 `<div data-type="customBlock" data-id="wechat-embed">` 为 iframe，其余 HTML 原样保留；`$ref` 为该记录 id，用于把图片引用登记到本记录。API/外部消费场景可传第三个参数 `absolute: true` 生成绝对地址。

## 路由

| 路由 | 方法 | 用途 |
| --- | --- | --- |
| `/wechat-article/embed` | GET | iframe 内容：按 `url` + `t`（HMAC 令牌）返回库中快照；库中缺失时兜底抓取（限流 2 次/分钟/URL、10 次/分钟/IP） |
| `/wechat-article/refetch` | POST | 块标题栏「重新抓取」：需后台登录 + CSRF，限流 10 次/分钟 |
| `/wechat-article/editor.js` | GET | 给 RichEditor 注入的增强脚本（带 mtime 版本号，长缓存安全） |

令牌为 `hash_hmac('sha256', $url, config('app.key'))`，只与文章 url 绑定，不受访问域名/协议影响（避免 `signedRoute` 在后台 iframe 场景下签名不一致导致 403）。

## 配置

配置文件 `config/cmf-wechat-article.php`（全部支持 env）：

| 配置 | 默认 | 说明 |
| --- | --- | --- |
| `allowed_hosts` | `mp.weixin.qq.com,weixin.qq.com` | 正文抓取域名白名单，支持 `*.example.com` |
| `connect_timeout` / `timeout` | 5 / 15 秒 | 抓取超时 |
| `max_bytes` | 5 MB | 正文响应体积上限 |
| `verify_tls` | `true` | 是否校验证书（内网代理场景可关） |
| `content_selector` | `#js_content` | 正文容器选择器（`#id` / `.class` / `tag`），未命中兜底 `<body>` |
| `cache_ttl` | 30 分钟 | 块预览版本号缓存时长 |
| `content_fields` | `['content']` | 需要处理公众号块的字段（模型可覆写 `wechatArticleEmbedFields()`） |
| `content_model` | 空 | 承载记录的模型类；留空则跳过媒体引用登记 |
| `image_allowed_hosts` | `*.qpic.cn,*.qlogo.cn,*.wx.qq.com,*.weixin.qq.com,*.gtimg.cn` | 图片域名白名单；为空表示不限域名（仍强制公网 IP） |
| `max_image_bytes` | 10 MB | 单图上限 |
| `max_images` | 80 | 单篇最多落地图片数 |
| `image_budget_seconds` | 60 | 图片落地总时间预算，超时剩余图片保留外链 |
| `media_rule` | `editor` | 图片上传使用的媒体规则（`cmf-media.rules.*`） |
| `image_referer` | `https://mp.weixin.qq.com/` | 下载图片携带的 Referer |

## 安全说明

- **SSRF**：仅 http/https、禁 userinfo、端口限 80/443、域名白名单、DNS 解析后所有 IP 必须为公网地址，并在请求时用 `CURLOPT_RESOLVE` 钉住已校验 IP（防 DNS rebinding）；不跟随重定向。
- **XSS**：正文经 Symfony HtmlSanitizer 白名单清洗（去 `script/iframe/object`、`on*`、`javascript:`），iframe 带 `sandbox="allow-same-origin"`，响应带严格 CSP（`default-src 'none'`，仅放行图片/样式/字体，禁止脚本）。
- **滥用**：embed 兜底抓取按 URL/IP 限流；refetch 为 POST + CSRF + 后台登录校验，并单独限流。
- **媒体库**：图片只接受 `image/*`（显式拒绝 SVG），单图与总预算双重限制。

## 测试

模块自带 Pest/PHPUnit 测试（Orchestra Testbench + sqlite 内存库，无网络依赖）：

```bash
cd wechat-article
composer install
vendor/bin/pest
```

## 已知取舍

- 公众号排版通过 iframe 原样呈现，因此前台是「只读嵌入」而非可继续编辑的富文本；需要在同一篇里混排文字时，可在块外正常编辑。
- `wechat_articles.url` 长度 768（PostgreSQL btree 上限内），公众号链接远小于该值。
- 令牌无过期时间：embed 本身是公开只读内容，风险面已由白名单与限流收窄；如需更强约束可在签名中附过期时间与 `ref`。

## 目录结构

```
src/
├── WechatArticleServiceProvider.php
├── Concerns/SavesWechatArticleEmbeds.php     # 保存钩子：抓取落库 + 媒体引用
├── Filament/RichContent/
│   ├── WechatEmbedBlock.php                  # 自定义块（链接弹窗 + 只读预览）
│   └── WechatEditorPlugin.php                # 注入块标题栏脚本
├── Http/Controllers/
│   ├── WechatEmbedController.php             # GET /wechat-article/embed
│   └── WechatRefetchController.php           # POST /wechat-article/refetch
├── Models/WechatArticle.php
└── Support/
    ├── SafeUrlValidator.php                  # URL/SSRF 统一校验
    ├── WechatSnapshotter.php                 # 抓取 + 提取 + 清洗
    ├── RemoteImageLocalizer.php              # 图片落地媒体库
    ├── WechatArticleStore.php                # 快照存取 / 媒体引用同步
    └── WechatEmbedView.php                   # 块 → iframe 渲染
```
