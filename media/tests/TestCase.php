<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Quansitech\Cmf\Core\CmfCoreServiceProvider;
use Quansitech\Cmf\Media\CmfMediaServiceProvider;
use Quansitech\Cmf\Media\Tests\Fixtures\Providers\AdminPanelProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            // 注意顺序：filament/support 会 bind() 覆盖 Livewire 的 DataStore，
            // Livewire 后注册可让 registerMechanisms 的 instance() 绑定优先生效
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            CmfCoreServiceProvider::class,
            AdminPanelProvider::class,
            CmfMediaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', Fixtures\Models\TestUser::class);

        // 三组驱动配上哑凭证，使 ServiceProvider 注册对应 disk
        $app['config']->set('cmf-media.disks.tos', [
            'key' => 'test-tos-key',
            'secret' => 'test-tos-secret',
            'region' => 'cn-beijing',
            'bucket' => 'test-tos-bucket',
            'endpoint' => 'tos-s3-cn-beijing.volces.com',
            'thumb_suffix' => '?x-tos-process=image/resize,w_200',
        ]);
        $app['config']->set('cmf-media.disks.oss', [
            'key' => 'test-oss-key',
            'secret' => 'test-oss-secret',
            'bucket' => 'test-oss-bucket',
            'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
            'thumb_suffix' => '?x-oss-process=image/resize,w_200',
        ]);
        $app['config']->set('cmf-media.disks.cos', [
            'secret_id' => 'test-cos-id',
            'secret_key' => 'test-cos-secret',
            'region' => 'ap-guangzhou',
            'bucket' => 'test-cos-1250000000',
            'thumb_suffix' => '?imageMogr2/thumbnail/200x200',
        ]);
        $app['config']->set('cmf-media.disks.local', [
            'root' => sys_get_temp_dir().'/cmf-media-test',
            'url' => '/cmf-media',
            'thumb_suffix' => '',
        ]);
        $app['config']->set('cmf-media.default', 'tos');
        $app['config']->set('cmf-media.middleware', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
