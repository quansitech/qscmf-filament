<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use Quansitech\Cmf\Media\Models\Media;
use Illuminate\Auth\Access\HandlesAuthorization;

class MediaPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Media');
    }

    public function view(AuthUser $authUser, Media $media): bool
    {
        return $authUser->can('View:Media');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Media');
    }

    public function delete(AuthUser $authUser, Media $media): bool
    {
        return $authUser->can('Delete:Media');
    }

}