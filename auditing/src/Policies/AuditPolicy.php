<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Tapp\FilamentAuditing\Models\Audit;

class AuditPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Audit');
    }

    public function view(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('View:Audit');
    }

    public function restore(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('Restore:Audit');
    }
}
