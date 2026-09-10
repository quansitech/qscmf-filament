<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Quansitech\Cmf\Core\Cmf;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tapp\FilamentAuditing\Models\Audit;

class CmfAuditingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cmf-auditing')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        Cmf::registerPlugin(AuditingPlugin::class);
    }

    public function packageBooted(): void
    {
        $this->registerModuleAssets();
        $this->configureAuditGates();

        // 插件不再注册自带的英文 AuditResource，改由本模块的中文版注册
        config()->set('filament-auditing.resources', config('filament-auditing.resources', []));

        // 向 Shield 登记本模块的权限点；宿主已配置同名条目时以宿主为准
        $manage = config('filament-shield.resources.manage', []);
        $manage[config('cmf-auditing.resource')] ??= config('cmf-auditing.permissions');
        config()->set('filament-shield.resources.manage', $manage);
    }

    /**
     * 模块配置与中文语言包，由 cmf:install 发布（不覆盖项目已有文件）。
     */
    protected function registerModuleAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../stubs/config/audit.php' => config_path('audit.php'),
            __DIR__.'/../stubs/config/filament-auditing.php' => config_path('filament-auditing.php'),
        ], 'cmf-config');

        $this->publishes([
            __DIR__.'/../stubs/lang/vendor/filament-auditing/zh_CN/filament-auditing.php' => lang_path('vendor/filament-auditing/zh_CN/filament-auditing.php'),
        ], 'cmf-lang');
    }

    /**
     * 接管 tapp/filament-auditing 的 audit / restoreAudit 默认 Gate（默认放行所有人），
     * 改为校验 Shield 权限点，与角色体系联动。
     */
    protected function configureAuditGates(): void
    {
        Gate::policy(Audit::class, config('cmf-auditing.policy'));
        Gate::define('audit', fn (User $user): bool => $user->can('ViewAny:Audit'));
        Gate::define('restoreAudit', fn (User $user): bool => $user->can('Restore:Audit'));
    }
}
