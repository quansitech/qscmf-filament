<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core;

use Filament\Contracts\Plugin;

/**
 * CMF 模块插件注册表。
 *
 * 各模块的 ServiceProvider 在 packageRegistered() 中调用
 * Cmf::registerPlugin() 登记自己的 Filament Plugin；
 * CmfPanelProvider 构建面板时统一挂载，实现"装包即上后台"。
 */
class Cmf
{
    /** @var list<class-string<Plugin>> */
    protected static array $pluginClasses = [];

    /**
     * @param  class-string<Plugin>  $pluginClass
     */
    public static function registerPlugin(string $pluginClass): void
    {
        if (! in_array($pluginClass, static::$pluginClasses, true)) {
            static::$pluginClasses[] = $pluginClass;
        }
    }

    /**
     * @return list<class-string<Plugin>>
     */
    public static function pluginClasses(): array
    {
        return static::$pluginClasses;
    }

    /**
     * 实例化所有已登记且未被 config('cmf-core.disabled_plugins') 停用的插件。
     *
     * @return list<Plugin>
     */
    public static function pluginInstances(): array
    {
        /** @var list<class-string<Plugin>> $disabled */
        $disabled = config('cmf-core.disabled_plugins', []);

        return collect(static::$pluginClasses)
            ->reject(fn (string $pluginClass): bool => in_array($pluginClass, $disabled, true))
            ->map(fn (string $pluginClass): Plugin => $pluginClass::make())
            ->values()
            ->all();
    }
}
