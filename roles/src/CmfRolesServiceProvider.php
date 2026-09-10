<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Roles;

use Illuminate\Support\Facades\Gate;
use Quansitech\Cmf\Core\Cmf;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\Permission\Models\Role;

class CmfRolesServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cmf-roles')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        Cmf::registerPlugin(RolesPlugin::class);
    }

    public function packageBooted(): void
    {
        $this->registerModuleAssets();
        $this->configureRoleModel();

        Gate::policy(config('permission.models.role'), config('cmf-roles.policy'));

        // 向 Shield 登记本模块的权限点；宿主已配置同名条目时以宿主为准
        $manage = config('filament-shield.resources.manage', []);
        $manage[config('cmf-roles.resource')] ??= config('cmf-roles.permissions');
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

        // 模块自身配置一并挂到 cmf-config（package-tools 默认只登记 {shortName}-config tag）
        $this->publishes([
            __DIR__.'/../config/cmf-roles.php' => config_path('cmf-roles.php'),
            __DIR__.'/../stubs/config/permission.php' => config_path('permission.php'),
            __DIR__.'/../stubs/config/filament-shield.php' => config_path('filament-shield.php'),
        ], 'cmf-config');

        $this->publishes([
            __DIR__.'/../stubs/lang/vendor/filament-shield/zh_CN/filament-shield.php' => lang_path('vendor/filament-shield/zh_CN/filament-shield.php'),
        ], 'cmf-lang');
    }

    /**
     * 角色模型默认为继承 Spatie Role + 接入审计的模块 Role；
     * 宿主在 config/permission.php 自行指定时以宿主为准。
     */
    protected function configureRoleModel(): void
    {
        if (config('permission.models.role') === Role::class) {
            config()->set('permission.models.role', Models\Role::class);
        }
    }
}
