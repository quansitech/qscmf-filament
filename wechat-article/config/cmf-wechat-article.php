<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 公众号文章嵌入（RichEditor 自定义块）
    |--------------------------------------------------------------------------
    | 后台在富文本里以“公众号文章”自定义块插入文章；块内部为只读嵌入。
    | 保存内容记录时即抓取并存库（wechat_articles 表），前台直接读库渲染。
    */

    // 允许抓取的公众号域名（精确匹配，或 *.example.com 形式）
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WECHAT_ARTICLE_ALLOWED_HOSTS', 'mp.weixin.qq.com,weixin.qq.com'))
    ))),

    // 抓取超时（秒）与体积上限（字节）
    'connect_timeout' => (int) env('WECHAT_ARTICLE_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('WECHAT_ARTICLE_TIMEOUT', 15),
    'max_bytes' => (int) env('WECHAT_ARTICLE_MAX_BYTES', 5 * 1024 * 1024),

    // 是否校验证书
    'verify_tls' => (bool) env('WECHAT_ARTICLE_VERIFY_TLS', true),

    // 抓取结果缓存分钟数（仅在运行时兜底抓取时使用；正常保存已落库）
    'cache_ttl' => (int) env('WECHAT_ARTICLE_CACHE_TTL', 30),

    // 正文容器选择器（支持 #id / .class / tag；未命中时兜底取 <body>）
    'content_selector' => (string) env('WECHAT_ARTICLE_CONTENT_SELECTOR', '#js_content'),

    'user_agent' => (string) env(
        'WECHAT_ARTICLE_USER_AGENT',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
    ),

    /*
    |--------------------------------------------------------------------------
    | 抓取时机
    |--------------------------------------------------------------------------
    | 保存内容记录时，对正文里出现的公众号嵌入块立即抓取并写入 wechat_articles。
    | 需在承载内容的模型上 `use SavesWechatArticleEmbeds;`。
    */

    // 需要处理公众号嵌入块的字段（模型上可覆写 wechatArticleEmbedFields()）
    'content_fields' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WECHAT_ARTICLE_CONTENT_FIELDS', 'content'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | 图片落地到媒体库
    |--------------------------------------------------------------------------
    | 抓取正文中的外链图片下载后存入项目媒体库（cmf-media），改写成媒体 URL，
    | 并在正文对应的记录上建立引用（避免孤儿清理）。
    */

    // 允许下载图片的域名（为空表示不限制域名，但仍强制公网 IP）
    'image_allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'WECHAT_ARTICLE_IMAGE_HOSTS',
            '*.qpic.cn,*.qlogo.cn,*.wx.qq.com,*.weixin.qq.com,*.gtimg.cn'
        ))
    ))),

    // 单张图片最大字节数
    'max_image_bytes' => (int) env('WECHAT_ARTICLE_MAX_IMAGE_BYTES', 10 * 1024 * 1024),

    // 单篇文章最多落地多少张图片（防止超长文章拖慢抓取）
    'max_images' => (int) env('WECHAT_ARTICLE_MAX_IMAGES', 80),

    // 图片落地的总时间预算（秒）：超时后剩余图片保留外链，避免拖死保存请求
    'image_budget_seconds' => (int) env('WECHAT_ARTICLE_IMAGE_BUDGET_SECONDS', 60),

    // 图片上传所用媒体规则（cmf-media.rules.*）
    'media_rule' => (string) env('WECHAT_ARTICLE_MEDIA_RULE', 'editor'),

    // 下载图片时携带的 Referer（部分公众号 CDN 校验来源）
    'image_referer' => (string) env('WECHAT_ARTICLE_IMAGE_REFERER', 'https://mp.weixin.qq.com/'),

    // 承载该自定义块、用于登记图片媒体引用的模型类（包内不绑定具体模型，宿主必须配置；留空则跳过引用登记）
    'content_model' => env('WECHAT_ARTICLE_CONTENT_MODEL'),
];
