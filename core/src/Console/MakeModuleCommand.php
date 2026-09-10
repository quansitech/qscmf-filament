<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * 生成 CMF 模块包骨架：composer.json / config / ServiceProvider / Plugin。
 * 新模块从骨架起步即符合 CMF 约定（插件自动登记、配置可发布）。
 */
#[AsCommand(name: 'make:cmf-module', description: '生成 CMF 模块包骨架')]
class MakeModuleCommand extends Command
{
    protected $signature = 'make:cmf-module
        {name : 模块名（如 Blog）}
        {--path= : 生成目录（默认当前工作目录）}';

    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name');

        $studly = Str::studly($name);
        $kebab = Str::kebab($name);
        $basePath = rtrim($this->option('path') ?: getcwd(), '/').'/'.$kebab;

        if (is_dir($basePath)) {
            $this->components->error("目录已存在：{$basePath}");

            return self::FAILURE;
        }

        $this->writeFile("{$basePath}/composer.json", $this->composerJson($studly, $kebab));
        $this->writeFile("{$basePath}/config/cmf-{$kebab}.php", $this->configStub($studly, $kebab));
        $this->writeFile("{$basePath}/src/{$studly}ServiceProvider.php", $this->serviceProviderStub($studly, $kebab));
        $this->writeFile("{$basePath}/src/{$studly}Plugin.php", $this->pluginStub($studly, $kebab));

        $this->components->info("模块骨架已生成：{$basePath}");
        $this->components->bulletList([
            "在宿主项目 composer.json 的 repositories 中加入该路径后 composer require quansitech/cmf-module-{$kebab}",
            'Resource 放入 src/Filament/Resources/，在 Plugin 的 register() 中通过 config 注册（参照 cmf-module-users）',
            '有 Shield 权限点时，在 ServiceProvider 的 packageBooted() 中向 filament-shield.resources.manage 登记（参照 cmf-module-users）',
        ]);

        return self::SUCCESS;
    }

    protected function composerJson(string $studly, string $kebab): string
    {
        return <<<JSON
        {
            "name": "quansitech/cmf-module-{$kebab}",
            "description": "QS CMF {$studly} 模块",
            "type": "library",
            "license": "proprietary",
            "require": {
                "php": "^8.3",
                "quansitech/cmf-core": "*"
            },
            "autoload": {
                "psr-4": {
                    "Quansitech\\\\Cmf\\\\{$studly}\\\\": "src/"
                }
            },
            "extra": {
                "laravel": {
                    "providers": [
                        "Quansitech\\\\Cmf\\\\{$studly}\\\\{$studly}ServiceProvider"
                    ]
                }
            },
            "config": {
                "sort-packages": true
            },
            "minimum-stability": "stable",
            "prefer-stable": true
        }

        JSON;
    }

    protected function configStub(string $studly, string $kebab): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        return [
            /*
             * 模块的 Filament Resource：深度定制时在宿主项目执行
             * php artisan cmf:extend {$kebab} 生成继承类并自动替换。
             */
            // 'resource' => \\Quansitech\\Cmf\\{$studly}\\Filament\\Resources\\{$studly}s\\{$studly}Resource::class,

            /*
             * Shield 权限点（写入 filament-shield.resources.manage）
             */
            // 'permissions' => ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'],
        ];

        PHP;
    }

    protected function serviceProviderStub(string $studly, string $kebab): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Quansitech\\Cmf\\{$studly};

        use Quansitech\\Cmf\\Core\\Cmf;
        use Spatie\\LaravelPackageTools\\Package;
        use Spatie\\LaravelPackageTools\\PackageServiceProvider;

        class {$studly}ServiceProvider extends PackageServiceProvider
        {
            public function configurePackage(Package \$package): void
            {
                \$package
                    ->name('cmf-{$kebab}')
                    ->hasConfigFile();
            }

            public function packageRegistered(): void
            {
                Cmf::registerPlugin({$studly}Plugin::class);
            }
        }

        PHP;
    }

    protected function pluginStub(string $studly, string $kebab): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Quansitech\\Cmf\\{$studly};

        use Filament\\Contracts\\Plugin;
        use Filament\\Panel;

        class {$studly}Plugin implements Plugin
        {
            public static function make(): static
            {
                return app(static::class);
            }

            public function getId(): string
            {
                return 'cmf-{$kebab}';
            }

            public function register(Panel \$panel): void
            {
                // \$panel->resources([config('cmf-...-resource')]);
            }

            public function boot(Panel \$panel): void
            {
                //
            }
        }

        PHP;
    }

    protected function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $contents);
    }
}
