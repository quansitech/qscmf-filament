<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Quansitech\Cmf\Core\Console\ExtendModuleCommand;
use Quansitech\Cmf\Core\Console\InstallCommand;
use Quansitech\Cmf\Core\Console\MakeModuleCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CmfCoreServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cmf-core')
            ->hasConfigFile()
            ->hasCommands([
                InstallCommand::class,
                ExtendModuleCommand::class,
                MakeModuleCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->registerPanelScaffolding();

        if (config('cmf-core.apply_defaults', true)) {
            $this->configureDefaults();
        }
    }

    /**
     * 面板脚手架（宿主 AdminPanelProvider、Filament 主题），
     * 由 cmf:install 通过 --tag=cmf-panel 落出，已存在时不覆盖。
     */
    protected function registerPanelScaffolding(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../stubs/panel/AdminPanelProvider.php.stub' => app_path('Providers/Filament/AdminPanelProvider.php'),
            __DIR__.'/../stubs/panel/theme.css.stub' => resource_path('css/filament/admin/theme.css'),
        ], 'cmf-panel');
    }

    /**
     * 生产环境默认行为。
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
