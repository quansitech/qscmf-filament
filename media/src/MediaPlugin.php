<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MediaPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'cmf-media';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            config('cmf-media.resource'),
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
