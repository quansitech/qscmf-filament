<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Users;

use Illuminate\Support\Facades\Gate;
use Quansitech\Cmf\Core\Cmf;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CmfUsersServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cmf-users')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        Cmf::registerPlugin(UsersPlugin::class);
    }

    public function packageBooted(): void
    {
        Gate::policy(config('cmf-users.model'), config('cmf-users.policy'));

        // 向 Shield 登记本模块的权限点；宿主已在 config/filament-shield.php
        // 配置同名条目时以宿主为准
        $manage = config('filament-shield.resources.manage', []);
        $manage[config('cmf-users.resource')] ??= config('cmf-users.permissions');
        config()->set('filament-shield.resources.manage', $manage);
    }
}
