<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Roles;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class RolesPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'cmf-roles';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->plugin(FilamentShieldPlugin::make())
            ->resources([
                config('cmf-roles.resource'),
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
