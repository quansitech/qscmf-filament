<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Roles\Models;

use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * 继承 Spatie Role 并接入审计：角色名称等字段变更会记录到 audits 表。
 * 权限勾选（pivot sync）的审计在 CreateRole/EditRole 页面钩子里补记。
 */
class Role extends SpatieRole implements Auditable
{
    use AuditableTrait;
}
