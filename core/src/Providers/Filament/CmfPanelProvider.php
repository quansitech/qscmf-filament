<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Quansitech\Cmf\Core\Cmf;

/**
 * CMF 后台面板基座：固化中间件栈 / 登录 / 配色 / 默认页面与 Widget，
 * 并自动挂载所有通过 Cmf::registerPlugin() 登记的模块插件。
 *
 * 宿主项目继承后可继续追加项目级配置（discover*、brand、tenant 等）。
 */
class CmfPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins(Cmf::pluginInstances())
            ->authMiddleware([
                Authenticate::class,
            ]);

        /** @var string $theme */
        $theme = config('cmf-core.theme', 'resources/css/filament/admin/theme.css');

        if (file_exists(base_path($theme))) {
            $panel->viteTheme($theme);
        }

        return $panel;
    }
}
