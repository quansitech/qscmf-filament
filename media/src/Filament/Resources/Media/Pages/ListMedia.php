<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Filament\Resources\Media\Pages;

use Filament\Resources\Pages\ListRecords;
use Quansitech\Cmf\Media\Filament\Resources\Media\MediaResource;

class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;
}
