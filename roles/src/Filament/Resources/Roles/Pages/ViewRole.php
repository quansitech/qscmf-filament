<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Roles\Filament\Resources\Roles\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Quansitech\Cmf\Roles\Filament\Resources\Roles\RoleResource;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
