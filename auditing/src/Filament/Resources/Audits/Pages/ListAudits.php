<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing\Filament\Resources\Audits\Pages;

use Override;
use Quansitech\Cmf\Auditing\Filament\Resources\Audits\AuditResource;
use Tapp\FilamentAuditing\Filament\Resources\Audits\Pages\ListAudits as BaseListAudits;

class ListAudits extends BaseListAudits
{
    #[Override]
    protected static string $resource = AuditResource::class;
}
