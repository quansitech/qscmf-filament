<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Roles;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class RolesPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'cmf-roles';
    }

    public function register(Panel $panel): void
    {
        // 必须先注册 CMF 的 RoleResource 再挂载 Shield：plugin() 会立即触发
        // Shield 的 register()，其 isResourcePublished() 检测到面板已有
        // RoleResource 时会跳过自带的 RoleResource，否则导航会出现两个"角色"。
        $panel->resources([
            config('cmf-roles.resource'),
        ]);

        $panel->plugin(FilamentShieldPlugin::make());
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
