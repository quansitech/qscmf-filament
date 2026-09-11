<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Models;

use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * 可审计的媒体模型：config('cmf-media.audit') 开启时由 ServiceProvider
 * 自动切换 config('cmf-media.model') 为本类（需宿主已安装
 * owen-it/laravel-auditing，例如通过 quansitech/cmf-module-auditing）。
 */
class AuditableMedia extends Media implements Auditable
{
    use AuditableTrait;
}
