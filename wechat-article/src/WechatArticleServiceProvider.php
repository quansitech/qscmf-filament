<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle;

use Quansitech\Cmf\WechatArticle\Support\SafeUrlValidator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * 公众号文章嵌入模块。
 *
 * 提供：
 * - Filament RichEditor 自定义块 WechatEmbedBlock（工具栏「自定义块」→「公众号文章」）；
 * - 内容记录保存时抓取公众号原文并存库（wechat_articles 表：url/content/图片 id）；
 * - 签名路由 /wechat-article/embed，按库中快照返回清洗后的公众号原文（iframe 渲染）；
 * - 抓取正文外链图片并落地到项目媒体库（cmf-media），保存媒体引用避免孤儿清理。
 *
 * 宿主用法：RichEditor 上 `->customBlocks([WechatEmbedBlock::class])`
 * + `->plugins([WechatEditorPlugin::class])`；内容模型 `use SavesWechatArticleEmbeds`。
 */
class WechatArticleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cmf-wechat-article')
            ->hasConfigFile()
            ->hasRoute('web');
    }

    public function packageRegistered(): void
    {
        // URL 安全校验无状态且与配置无关，直接绑定共享实例
        $this->app->singleton(SafeUrlValidator::class);
    }

    public function packageBooted(): void
    {
        $this->registerModuleAssets();
    }

    /**
     * 模块迁移与配置挂到 cmf 系列 tag，由 cmf:install 发布（不覆盖项目已有文件）。
     */
    protected function registerModuleAssets(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/cmf-wechat-article.php' => config_path('cmf-wechat-article.php'),
        ], 'cmf-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cmf-wechat-article-migrations');
    }
}
