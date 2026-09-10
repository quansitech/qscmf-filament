<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Tapp\FilamentAuditing\FilamentAuditingPlugin;

class AuditingPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'cmf-auditing';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->plugin(FilamentAuditingPlugin::make())
            ->resources([
                config('cmf-auditing.resource'),
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
