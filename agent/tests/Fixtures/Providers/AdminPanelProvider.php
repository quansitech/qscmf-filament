<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Fixtures\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Quansitech\Cmf\Agent\AgentPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(AgentPlugin::make());
    }
}
