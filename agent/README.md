# cmf-module-agent

基于 [packstub/filament-agents](https://github.com/packstub/filament-agents) 的 CMF 后台 AI 助手：在 Filament 面板里提供对话页，按当前后台用户的 Policy 执行已注册工具；写入类操作需要人工在对话里批准。

同一个工具列表也由 Packstub 暴露为外部 MCP 端点（`POST /mcp`），令牌在 **Agent access** 页面签发。模块不依赖 `cmf-module-mcp`，也不提供自研的 `/mcp/{panel}` 端点。

## 安装

```bash
composer require quansitech/cmf-module-agent
php artisan vendor:publish --tag=cmf-agent-config   # 可选：发布 config/cmf-agent.php
php artisan migrate
```

模块通过 `Cmf::registerPlugin()` 自动挂到 `admin` 面板，宿主不需要改 `AdminPanelProvider`。Packstub 的数据库迁移由模块依赖自动带入。

### 外部 MCP 令牌所需

Packstub 用 Sanctum 签发 MCP 令牌，宿主认证用户模型需要 `Laravel\Sanctum\HasApiTokens`。本仓库的 `App\Models\User` 已继承 CMF 用户模型并加上该 trait，同时把 `config/auth.php`、`config/cmf-users.php` 的 model 指向 `App\Models\User`：

```php
namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Quansitech\Cmf\Users\Models\User as CmfUser;

class User extends CmfUser
{
    use HasApiTokens;
}
```

如果 `personal_access_tokens` 表不存在：

```bash
php artisan vendor:publish --tag=sanctum-migrations
php artisan migrate
```

### 前端主题

主题需要包含 Packstub 的视图扫描路径（本仓库已加）：

```css
@source '../../../../vendor/packstub/filament-agents/resources/views';
@source '../../../../vendor/packstub/agents/resources/views';
```

然后重新构建：

```bash
npm run build
```

## 汉化

包内已内置 `resources/lang/zh_CN.json`，覆盖 Packstub `filament-agents` 与 `agents` 的界面文案（共 332 个键），由 `CmfAgentServiceProvider` 自动加载；当应用 locale 为 `zh_CN` 时，Agent 页面与相关提示显示中文。需要其他语言或调整文案时，在该目录增加 `<locale>.json`。

## 配置 AI Provider

对话页是否出现由 Packstub 的 `AgentModels::enabled()` 决定：没有可用的 AI Provider Key 时，聊天页会主动隐藏（访问 `/admin/chat` 返回 403），这是正常行为。

Provider 配置由 `laravel/ai` 读取 `config/ai.php`。默认不需要发布配置文件：`laravel/ai` 的默认配置会自动合并，直接设置下表中的环境变量即可。只有需要增删 Provider、修改默认模型或写入固定配置时，才发布后编辑：

```bash
php artisan vendor:publish --tag=ai-config
# 编辑 config/ai.php
```

### 常用变量

| 变量 | 说明 |
| --- | --- |
| `AGENT_PROVIDER` | 平台默认 Provider，默认 `anthropic`。内置模型选择器为 `anthropic`、`openai`、`gemini`、`xai` 提供条目；其他 `laravel/ai` 文本 Provider（如 `ollama`、`openrouter`、`mistral`、`groq`、`deepseek`）也可使用，会自动采用其最聪明/最便宜的模型；`openai-compatible` 还需在 `config/ai.php` 配置默认文本模型。 |
| `AGENT_MODEL` | 固定 `auto` 模型名。不填时：`anthropic`/`gemini`/`xai` 使用各自默认值，`openai` 使用该 Provider 的最聪明模型。 |
| `AGENT_MODEL_FAST` | 固定 `fast` 模型名；不填时使用 Provider 的最便宜模型。 |
| `AGENT_MODEL_DEEP` | 固定 `deep` 模型名；不填时使用 Provider 的最聪明模型。 |
| `OPENAI_API_KEY` | `openai` Provider 的 API Key，`AGENT_PROVIDER=openai` 时必填。 |
| `OPENAI_URL` | OpenAI API 基础地址，默认 `https://api.openai.com/v1`。使用中转/兼容网关时改成对应基础地址（通常以 `/v1` 结尾，不要写到 `/chat/completions`）。 |

### 示例：官方 OpenAI

```dotenv
AGENT_PROVIDER=openai
AGENT_MODEL=gpt-5
OPENAI_API_KEY=sk-...
OPENAI_URL=https://api.openai.com/v1
```

`AGENT_MODEL` 可以省略，省略时使用 OpenAI 的最聪明模型；也可以同时设置 `AGENT_MODEL_FAST`、`AGENT_MODEL_DEEP` 给模型选择器的快速/深度选项。

### 示例：OpenAI 中转或兼容网关

```dotenv
AGENT_PROVIDER=openai
AGENT_MODEL=gpt-4o-mini
OPENAI_API_KEY=你的网关密钥
OPENAI_URL=https://your-relay.example.com/v1
```

这里仍使用 `openai` Provider（因此读取 `OPENAI_API_KEY` 和 `OPENAI_URL`），只是把基础地址指向网关。网关必须兼容 OpenAI 的 `/v1/chat/completions` 协议。

如果网关使用 `laravel/ai` 的独立 `openai-compatible` Provider，还需要在 `config/ai.php` 中为它指定默认文本模型（Packstub 没有为这个 Provider 内置模型选择器，`AGENT_MODEL` 不会作用于它）：

```dotenv
AGENT_PROVIDER=openai-compatible
OPENAI_COMPATIBLE_API_KEY=你的密钥
OPENAI_COMPATIBLE_URL=https://your-endpoint.example.com/v1
```

```php
// config/ai.php
'openai-compatible' => [
    'driver' => 'openai-compatible',
    'url' => env('OPENAI_COMPATIBLE_URL'),
    'key' => env('OPENAI_COMPATIBLE_API_KEY'),
    'models' => ['text' => ['default' => env('OPENAI_COMPATIBLE_MODEL', '你的模型名')]],
],
```

### 其他 Provider

按 `config/ai.php` 的变量名配置对应 Key，例如：

```dotenv
AGENT_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
# AGENT_MODEL=claude-opus-5

# 或
# AGENT_PROVIDER=gemini
# GEMINI_API_KEY=...

# 或
# AGENT_PROVIDER=xai
# XAI_API_KEY=...
```

`AGENT_ENABLED=false` 会强制隐藏聊天页；不设置时按 Provider Key 自动判断。修改 `.env` 后如果使用了配置缓存，执行：

```bash
php artisan config:clear
```

仅想先看页面结构、暂时没有 Key 时，可以临时设置 `AGENT_ENABLED=true` 让页面可见；但发送消息仍需真实 Provider Key。

## 配置

`config/cmf-agent.php`：

```php
return [
    // 挂载到哪个面板（仅在非租户面板生效）
    'panel' => 'admin',

    // 助手显示名称
    'name' => 'CMF 助手',

    // sync（请求内执行，默认）或 queue（队列 worker）
    'driver' => env('CMF_AGENT_DRIVER', 'sync'),

    // 使用 Packstub 自带的 /mcp 端点与 Agent access 令牌页
    'enable_packstub_mcp' => true,
    'agent_access' => true,

    // 宿主定义的 Packstub AgentTool 类
    'tools' => [
        App\Filament\AgentTools\UpdateArticleDraft::class,
    ],
];
```

`tools` 配置宿主自己的工具，自动发现到的工具仍会合并进来。

## 工具发现机制

其它 CMF 模块不需要依赖本包，也不需要在 ServiceProvider 里手工注册工具。在自己的 `composer.json` 中声明 `extra.cmf-agent.tools` 即可：

```json
{
    "name": "vendor/cmf-module-example",
    "require": {
        "php": "^8.5",
        "quansitech/cmf-core": "^1.0",
        "packstub/agents": "^1.14"
    },
    "extra": {
        "cmf-agent": {
            "tools": [
                "Vendor\\CmfModuleExample\\AgentTools\\ListOrders",
                "Vendor\\CmfModuleExample\\AgentTools\\ShowOrderStatus"
            ]
        }
    }
}
```

模块安装后，`AgentPlugin` 在构建后台助手时读取 Composer 安装目录中的 `composer/installed.json`（默认是 `vendor/composer/installed.json`），自动合并这些工具。安装声明工具的模块后 Composer 会写入该文件。修改已安装模块的声明后，运行 `composer update vendor/cmf-module-example` 重新导入该包的元数据；项目没有安装 `cmf-module-agent` 时，这些 `composer.json` 元数据不会被执行，也不会加载任何 Agent 类。

`AgentPlugin::toolClasses()` 会合并以下两类并校验它们都是 `Packstub\Agents\Mcp\AgentTool` 的子类：

1. 其它 Composer 包通过 `extra.cmf-agent.tools` 声明的工具；
2. 宿主在 `config('cmf-agent.tools')` 里显式配置的工具。

合并顺序是自动发现、宿主配置。两个类暴露同一个 MCP 工具名时，保留首次出现的声明，后续重复项只记录日志。自动发现里无效、损坏或无法实例化的声明也只记录日志并跳过，不会中断后台面板启动。宿主在 `config('cmf-agent.tools')` 里写错类名仍然抛出明确的 `InvalidArgumentException`，便于开发期发现配置错误。

`extra.cmf-agent.tools` 只是元数据，模块本身不需要依赖 `cmf-module-agent`。工具类源码会继承 `Packstub\Agents\Mcp\AgentTool`；需要独立开发、类型提示或跑静态分析时，模块可自行 require `packstub/agents`。

宿主示例 `App\Filament\AgentTools\UpdateArticleDraft`：修改草稿文章标题；没有 `#[IsReadOnly]`，Packstub 会在对话里要求人工批准，批准后才执行。

自定义工具应自己开启事务、加锁并按当前用户鉴权，例如：

```php
DB::transaction(function () use ($actor, $id, $newTitle, $oldTitle) {
    $article = Article::query()->lockForUpdate()->findOrFail($id);

    Gate::forUser($actor)->authorize('update', $article);

    if ($article->status !== 'draft' || $article->title !== $oldTitle) {
        throw new RuntimeException('文章状态或标题已变化。');
    }

    $article->title = $newTitle;
    $article->save();
});
```

保存走 Eloquent 事件，因此 `Article` 的审计会记录真实操作人；不会使用任何令牌或冒充身份。

## 外部 MCP 与授权码

启用后 Packstub 提供：

- MCP 端点：`POST /mcp`
- 令牌管理页：`/admin/agent-access`

具备 `Manage:AgentToken` 权限的后台用户可在 **Agent access** 页面为自己签发 Sanctum 令牌。在使用 Shield 的项目中，将此自定义权限分配给允许外部接入的角色。令牌可勾选：

- `read` / `write`
- `tool:{name}`：只允许调用指定工具
- `tenant:{slug}`：多租户项目使用

把令牌作为 `Authorization: Bearer <token>` 传给 MCP 客户端。`/mcp` 每次调用都要求令牌持有人具备 `Manage:AgentToken`，并要求令牌带显式 `read` 或 `write` 能力。通配令牌和网页登录会话无法用于该端点。`write` 也可调用只读工具，Packstub 会继续检查令牌的读写和工具范围。

### 从旧 CMF MCP 切换

旧 `/mcp/admin` 令牌在删除旧令牌表后失效。请在 Agent access 页面重新签发，并把客户端地址换成 `/mcp`。旧令牌、签发权限绑定和用户模型类型合并无法由迁移回滚完整恢复。需要恢复旧版本时，应使用升级前的数据库快照并同步恢复应用版本。

部署或修改 MCP 开关后，重新生成配置和路由缓存，再用 `php artisan route:list --path=mcp -v` 确认 `POST /mcp` 包含 `AuthorizeMcpAccess`。旧路由缓存缺少授权中间件时，端点返回 503，直到重建缓存。关闭 MCP 后，缓存里残留的端点返回 404。

## 权限与安全

- 对话工具和外部 MCP 工具都以令牌/当前登录用户身份执行，读写都过模型 Policy。
- 写入工具在聊天中必须人工批准。外部 MCP 持带 `write` 能力的令牌时直接执行，仍受当前用户的 Policy 约束。
- 不需要外部 MCP 时，把 `enable_packstub_mcp` 设为 `false`，`/mcp` 与 Agent access 页面都会关闭。
- 不需要 Agent access 页面时，把 `agent_access` 设为 `false`，只保留后台对话。
- `driver=queue` 时任务在 console 环境执行；若要让模型审计写入，需在宿主的 `config/audit.php` 设置 `'console' => true`，否则建议保持默认 `sync`。

## 测试

在 monorepo 开发时使用测试 manifest 和对应的锁文件，从兄弟目录安装本地 `cmf-core`：

```bash
cd packages/qscmf-filament/agent
COMPOSER=composer.test.json composer install
php vendor/bin/phpunit -c phpunit.xml --do-not-cache-result
```

在独立拆分的 Agent 仓库中，请使用 `composer install` 和 `composer test`，通过包仓库安装已发布的 `quansitech/cmf-core`。

宿主示例测试：

```bash
php vendor/bin/phpunit --filter AgentChatTest --do-not-cache-result
```

覆盖：草稿标题修改的审批流程与 `audits` 记录；工具发现、失效声明容错与重名处理。

核对 MCP 路由：

```bash
php artisan route:list --path=mcp
```

应看到 Packstub 的 `mcp` 路由，其中 `POST mcp` 处理 MCP 请求。
