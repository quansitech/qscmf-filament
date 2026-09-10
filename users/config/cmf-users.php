<?php

declare(strict_types=1);

use Quansitech\Cmf\Users\Filament\Resources\Users\UserResource;
use Quansitech\Cmf\Users\Models\User;
use Quansitech\Cmf\Users\Policies\UserPolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | 用户模型
    |--------------------------------------------------------------------------
    |
    | 深度定制时在 app/ 下继承 Quansitech\Cmf\Users\Models\User，
    | 替换这里并同步修改 config/auth.php 的 providers.users.model。
    |
    */

    'model' => User::class,

    /*
    |--------------------------------------------------------------------------
    | 用户管理 Resource
    |--------------------------------------------------------------------------
    |
    | 执行 php artisan cmf:extend users 可在 app/ 下生成继承类并自动替换。
    |
    */

    'resource' => UserResource::class,

    /*
    |--------------------------------------------------------------------------
    | 用户 Policy
    |--------------------------------------------------------------------------
    */

    'policy' => UserPolicy::class,

    /*
    |--------------------------------------------------------------------------
    | Shield 权限点（写入 filament-shield.resources.manage）
    |--------------------------------------------------------------------------
    |
    | 与表格/页面实际注册的 Action 保持一致（如表格有 DeleteBulkAction
    | 就要带 deleteAny），只列真实实现了的能力。
    |
    */

    'permissions' => ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'],

];
