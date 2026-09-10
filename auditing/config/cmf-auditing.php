<?php

declare(strict_types=1);

use Quansitech\Cmf\Auditing\Filament\Resources\Audits\AuditResource;
use Quansitech\Cmf\Auditing\Policies\AuditPolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | 审计日志 Resource（中文版）
    |--------------------------------------------------------------------------
    |
    | 执行 php artisan cmf:extend auditing 可在 app/ 下生成继承类并自动替换。
    |
    */

    'resource' => AuditResource::class,

    /*
    |--------------------------------------------------------------------------
    | 审计日志 Policy（audit / restoreAudit Gate 也按此权限点校验）
    |--------------------------------------------------------------------------
    */

    'policy' => AuditPolicy::class,

    /*
    |--------------------------------------------------------------------------
    | Shield 权限点（写入 filament-shield.resources.manage）
    |--------------------------------------------------------------------------
    */

    'permissions' => ['viewAny', 'view', 'restore'],

];
