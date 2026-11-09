<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Tests\Fixtures\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Quansitech\Cmf\Core\Cmf;

/**
 * 测试面板：自动挂载所有 CMF 插件。
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugins(Cmf::pluginInstances());
    }
}
