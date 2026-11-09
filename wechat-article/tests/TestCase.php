<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Quansitech\Cmf\Core\CmfCoreServiceProvider;
use Quansitech\Cmf\WechatArticle\Tests\Fixtures\Providers\AdminPanelProvider;
use Quansitech\Cmf\WechatArticle\WechatArticleServiceProvider;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            // 注意顺序：filament/support 会 bind() 覆盖 Livewire 的 DataStore，
            // Livewire 后注册可让 registerMechanisms 的 instance() 绑定优先生效
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
            CmfCoreServiceProvider::class,
            AdminPanelProvider::class,
            WechatArticleServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', Fixtures\Models\TestUser::class);

        // 包内不绑定具体业务模型；测试默认不登记媒体引用
        $app['config']->set('cmf-wechat-article.content_model', '');
        $app['config']->set('cmf-wechat-article.dns_check', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        // 包内迁移在测试环境显式加载：RefreshDatabase 早于 PackageServiceProvider
        // 的 packageBooted 收集迁移路径，单靠 loadMigrationsFrom 会漏掉建表
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
