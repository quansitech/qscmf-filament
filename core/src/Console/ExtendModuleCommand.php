<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * 把 CMF 模块的 Resource（含 Pages）生成为宿主项目 app/ 下的继承类，
 * 并自动把 config/cmf-{module}.php 中的类名替换为继承类，
 * 之后即可在宿主代码里自由覆写定制，模块其余部分仍随 composer 升级。
 */
#[AsCommand(name: 'cmf:extend', description: '生成 CMF 模块资源的宿主继承类以深度定制')]
class ExtendModuleCommand extends Command
{
    protected $signature = 'cmf:extend
        {module : 模块名（对应 config/cmf-{module}.php，如 users）}
        {--force : 覆盖已存在的继承类}';

    public function handle(): int
    {
        /** @var string $module */
        $module = $this->argument('module');

        /** @var class-string|null $resourceClass */
        $resourceClass = config("cmf-{$module}.resource");

        if (! is_string($resourceClass) || ! class_exists($resourceClass)) {
            $this->components->error("config('cmf-{$module}.resource') 不存在或类未找到，请确认模块已安装。");

            return self::FAILURE;
        }

        $appResourceClass = $this->toAppClass($resourceClass);

        if ($appResourceClass === null) {
            $this->components->error("无法从 {$resourceClass} 推导宿主类名（类名中需包含 \\Filament\\）。");

            return self::FAILURE;
        }

        $this->publishModuleConfig($module);

        $generated = [];
        $generated[] = $this->generateResourceSubclass($resourceClass, $appResourceClass);

        foreach ($this->generatePageSubclasses($resourceClass, $appResourceClass) as $pageClass) {
            $generated[] = $pageClass;
        }

        $this->swapConfigClass($module, $resourceClass, $appResourceClass);

        $this->components->info('已生成继承类：');
        $this->components->bulletList($generated);
        $this->components->info("config/cmf-{$module}.php 已指向继承类，现在可以在 app/ 下自由定制。");

        return self::SUCCESS;
    }

    /**
     * Quansitech\Cmf\Users\Filament\Resources\Users\UserResource
     * → App\Filament\Resources\Users\UserResource
     *
     * @param  class-string  $class
     * @return class-string|null
     */
    protected function toAppClass(string $class): ?string
    {
        $position = strpos($class, '\\Filament\\');

        if ($position === false) {
            return null;
        }

        /** @var class-string */
        return 'App\\Filament\\'.substr($class, $position + strlen('\\Filament\\'));
    }

    /**
     * @param  class-string  $class
     */
    protected function toAppPath(string $class): string
    {
        return app_path(str_replace('\\', '/', Str::after($class, 'App\\')).'.php');
    }

    /**
     * 发布模块配置文件（已存在则保留，由 swapConfigClass 就地改类名）。
     */
    protected function publishModuleConfig(string $module): void
    {
        $this->callSilently('vendor:publish', [
            '--tag' => "cmf-{$module}-config",
            '--no-interaction' => true,
        ]);
    }

    /**
     * @param  class-string  $resourceClass
     * @param  class-string  $appResourceClass
     * @return class-string
     */
    protected function generateResourceSubclass(string $resourceClass, string $appResourceClass): string
    {
        $namespace = Str::beforeLast($appResourceClass, '\\');
        $name = Str::afterLast($appResourceClass, '\\');

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        /**
         * 继承 CMF 模块资源（{$resourceClass}）。
         * 在 app/ 下覆写父类方法即可深度定制；未覆写的部分仍随模块包升级。
         */
        class {$name} extends \\{$resourceClass}
        {
            //
        }

        PHP;

        $this->writeClass($appResourceClass, $stub);

        return $appResourceClass;
    }

    /**
     * 为模块 Resource 的每个 Page 生成宿主子类，并把 $resource 指向宿主 Resource。
     *
     * @param  class-string  $resourceClass
     * @param  class-string  $appResourceClass
     * @return list<class-string>
     */
    protected function generatePageSubclasses(string $resourceClass, string $appResourceClass): array
    {
        $reflection = new ReflectionClass($resourceClass);
        $pagesDir = dirname((string) $reflection->getFileName()).'/Pages';

        if (! is_dir($pagesDir)) {
            return [];
        }

        $generated = [];

        foreach (glob($pagesDir.'/*.php') ?: [] as $pageFile) {
            $pageName = pathinfo($pageFile, PATHINFO_FILENAME);
            $modulePageClass = Str::beforeLast($resourceClass, '\\').'\\Pages\\'.$pageName;
            $appPageClass = Str::beforeLast($appResourceClass, '\\').'\\Pages\\'.$pageName;

            if (! class_exists($modulePageClass)) {
                continue;
            }

            $namespace = Str::beforeLast($appPageClass, '\\');

            $stub = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            class {$pageName} extends \\{$modulePageClass}
            {
                protected static string \$resource = \\{$appResourceClass}::class;
            }

            PHP;

            $this->writeClass($appPageClass, $stub);

            /** @var class-string $appPageClass */
            $generated[] = $appPageClass;
        }

        return $generated;
    }

    /**
     * 把模块配置里的模块类名替换为宿主继承类名。
     *
     * @param  class-string  $resourceClass
     * @param  class-string  $appResourceClass
     */
    protected function swapConfigClass(string $module, string $resourceClass, string $appResourceClass): void
    {
        $configPath = config_path("cmf-{$module}.php");

        if (! file_exists($configPath)) {
            return;
        }

        $contents = file_get_contents($configPath);

        if ($contents === false) {
            return;
        }

        $contents = str_replace(
            [$resourceClass, str_replace('\\', '\\\\', $resourceClass)],
            [$appResourceClass, str_replace('\\', '\\\\', $appResourceClass)],
            $contents,
        );

        file_put_contents($configPath, $contents);
    }

    /**
     * @param  class-string  $class
     */
    protected function writeClass(string $class, string $contents): void
    {
        $path = $this->toAppPath($class);

        if (file_exists($path) && ! $this->option('force')) {
            $this->components->warn("已存在，跳过：{$path}（--force 可覆盖）");

            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $contents);
    }
}
