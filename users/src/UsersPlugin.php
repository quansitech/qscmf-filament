<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Users;

use Filament\Contracts\Plugin;
use Filament\Panel;

class UsersPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'cmf-users';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            config('cmf-users.resource'),
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
