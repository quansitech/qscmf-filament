<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Users\Filament\Resources\Users\Pages;

use Filament\Resources\Pages\CreateRecord;
use Quansitech\Cmf\Users\Filament\Resources\Users\UserResource;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
