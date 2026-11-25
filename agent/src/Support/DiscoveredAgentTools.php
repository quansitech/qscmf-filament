<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Support;

use Composer\InstalledVersions;
use ReflectionClass;

/**
 * 从 Composer 已安装包中自动发现后台助手工具。
 *
 * 其它模块在自己的 composer.json 中声明：
 *
 *   "extra": {
 *       "cmf-agent": {
 *           "tools": ["Vendor\\Module\\AgentTools\\FooTool"]
 *       }
 *   }
 *
 * 本包安装时读取 vendor/composer/installed.json，不依赖 cmf-core，
 * 也不需要各模块在 ServiceProvider 里手工注册。
 */
final class DiscoveredAgentTools
{
    /** @var list<class-string>|null */
    private static ?array $cache = null;

    /** @var array<class-string, string> */
    private static array $origins = [];

    /**
     * @return list<class-string>
     */
    public static function discover(?string $path = null): array
    {
        if ($path === null && self::$cache !== null) {
            return self::$cache;
        }

        if ($path === null) {
            $composerFile = (new ReflectionClass(InstalledVersions::class))->getFileName();
            $path = $composerFile === false
                ? base_path('vendor/composer/installed.json')
                : dirname($composerFile).'/installed.json';
        }

        $tools = self::fromInstalledJson($path);

        self::$cache = $tools;

        return $tools;
    }

    /** 工具声明的来源包名；仅自动发现结果可用。 */
    public static function origin(string $tool): ?string
    {
        return self::$origins[$tool] ?? null;
    }

    /**
     * @return list<class-string>
     */
    public static function fromInstalledJson(string $path): array
    {
        self::$origins = [];

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $installed = json_decode((string) file_get_contents($path), true);

        if (! is_array($installed)) {
            return [];
        }

        $packages = $installed['packages'] ?? $installed;
        $tools = [];
        $origins = [];

        foreach ((array) $packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $declared = $package['extra']['cmf-agent']['tools'] ?? null;

            if (! is_array($declared)) {
                continue;
            }

            foreach ($declared as $tool) {
                if (is_string($tool) && trim($tool) !== '') {
                    $tool = trim($tool);

                    if (! isset($origins[$tool])) {
                        $tools[] = $tool;
                        $origins[$tool] = is_string($package['name'] ?? null) ? $package['name'] : 'unknown-package';
                    }
                }
            }
        }

        self::$origins = $origins;

        /** @var list<class-string> */
        return $tools;
    }

    /** 清空自动发现缓存；测试或长驻进程中重新扫描时使用。 */
    public static function flush(): void
    {
        self::$cache = null;
        self::$origins = [];
    }
}
