<?php

declare(strict_types=1);

use Quansitech\Cmf\Roles\Filament\Resources\Roles\RoleResource;
use Quansitech\Cmf\Roles\Policies\RolePolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | 角色管理 Resource
    |--------------------------------------------------------------------------
    |
    | 执行 php artisan cmf:extend roles 可在 app/ 下生成继承类并自动替换。
    |
    */

    'resource' => RoleResource::class,

    /*
    |--------------------------------------------------------------------------
    | 角色 Policy
    |--------------------------------------------------------------------------
    */

    'policy' => RolePolicy::class,

    /*
    |--------------------------------------------------------------------------
    | Shield 权限点（写入 filament-shield.resources.manage）
    |--------------------------------------------------------------------------
    */

    'permissions' => ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'],

];
