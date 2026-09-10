<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Users\Filament\Resources\Users\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Quansitech\Cmf\Users\Filament\Resources\Users\UserResource;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
