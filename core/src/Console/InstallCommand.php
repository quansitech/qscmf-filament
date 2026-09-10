<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core\Console;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OwenIt\Auditing\AuditingServiceProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * 初始化 QS CMF：发布模块配置 → 迁移 → 生成 Shield 权限点 →
 * 创建角色 → 脚手架后台面板 → 创建初始管理员。
 */
#[AsCommand(name: 'cmf:install', description: '初始化 QS CMF（配置 / 迁移 / 权限 / 面板 / 管理员）')]
class InstallCommand extends Command
{
    protected $signature = 'cmf:install
        {--admin-name= : 初始管理员姓名（默认 admin）}
        {--admin-email= : 初始管理员邮箱（默认 admin@ 应用域名）}
        {--admin-password= : 初始管理员密码（默认随机生成并在终端打印）}
        {--skip-admin : 跳过创建初始管理员}
        {--force : 强制覆盖已发布的配置文件}';

    public function handle(): int
    {
        $this->components->info('开始安装 QS CMF...');

        $this->publishModuleAssets();
        $this->publishFilamentAssets();
        $this->ensureAuthUserModel();
        $this->publishModuleMigrations();
        $this->call('migrate', ['--force' => true]);
        $this->scaffoldPanel();
        $this->registerViteTheme();
        $this->generateShieldPermissions();
        $this->setupRoles();
        $this->createAdminUser();

        $this->components->info('QS CMF 安装完成。');

        $this->components->bulletList([
            '执行 npm install && npm run build 编译 Filament 主题',
            '执行 php artisan serve 后访问 '.url($this->getPanelPath()).' 登录后台',
        ]);

        return self::SUCCESS;
    }

    /**
     * 发布 core 与各 CMF 模块登记的配置、语言包、视图与品牌静态资源
     * （tag: cmf-config / cmf-lang / cmf-views / cmf-assets；
     * 各模块需把自身配置挂到 cmf-config，package-tools 默认登记的
     * {shortName}-config 不在此发布范围），
     * 不覆盖项目已有文件（除非 --force）。
     */
    protected function publishModuleAssets(): void
    {
        $this->components->task('发布模块配置、语言包与视图', function (): void {
            $params = ['--no-interaction' => true];

            if ($this->option('force')) {
                $params['--force'] = true;
            }

            $this->callSilently('vendor:publish', [...$params, '--tag' => 'cmf-config']);
            $this->callSilently('vendor:publish', [...$params, '--tag' => 'cmf-lang']);
            $this->callSilently('vendor:publish', [...$params, '--tag' => 'cmf-views']);
            $this->callSilently('vendor:publish', [...$params, '--tag' => 'cmf-assets']);
        });
    }

    /**
     * 发布 Filament 前端资源（public/js|css/filament）。缺失时面板的 Alpine 组件
     * 无法注册，会出现登录按钮无文字等异常；命令幂等，可安全重跑。
     */
    protected function publishFilamentAssets(): void
    {
        if (! $this->getApplication()?->has('filament:assets')) {
            return;
        }

        $this->components->task('发布 Filament 前端资源', function (): void {
            $this->callSilently('filament:assets');
        });
    }

    /**
     * 发布依赖包的数据表迁移（spatie permission / owen-it auditing），
     * 项目已有同名迁移时跳过，避免重复建表。
     */
    protected function publishModuleMigrations(): void
    {
        $this->components->task('发布数据表迁移', function (): void {
            if (
                class_exists(PermissionServiceProvider::class)
                && ! glob(database_path('migrations/*_create_permission_tables.php'))
            ) {
                $this->callSilently('vendor:publish', [
                    '--provider' => PermissionServiceProvider::class,
                    '--tag' => 'permission-migrations',
                    '--no-interaction' => true,
                ]);
            }

            if (
                class_exists(AuditingServiceProvider::class)
                && ! glob(database_path('migrations/*_create_audits_table.php'))
            ) {
                $this->callSilently('vendor:publish', [
                    '--provider' => AuditingServiceProvider::class,
                    '--tag' => 'migrations',
                    '--no-interaction' => true,
                ]);
            }
        });
    }

    /**
     * 生成 Shield 权限点（只生成 permissions；Policy 由各模块包自带，
     * 项目自研 Resource 需要 Policy 时请自行执行 shield:generate）。
     */
    protected function generateShieldPermissions(): void
    {
        if (! class_exists(FilamentShieldPlugin::class)) {
            return;
        }

        $this->components->task('生成 Shield 权限点', function (): void {
            $this->callSilently('shield:generate', [
                '--all' => true,
                '--option' => 'permissions',
                '--panel' => 'admin',
                '--no-interaction' => true,
            ]);
        });
    }

    /**
     * 创建 super_admin / panel_user 角色。
     * super_admin.define_via_gate=false 时把全部权限点显式挂到 super_admin 角色。
     */
    protected function setupRoles(): void
    {
        if (! class_exists(Role::class)) {
            return;
        }

        $this->components->task('创建角色', function (): void {
            /** @var class-string $roleModel */
            $roleModel = config('permission.models.role');
            /** @var class-string $permissionModel */
            $permissionModel = config('permission.models.permission');
            /** @var string $guard */
            $guard = config('auth.defaults.guard', 'web');

            $superAdmin = $roleModel::firstOrCreate([
                'name' => config('filament-shield.super_admin.name', 'super_admin'),
                'guard_name' => $guard,
            ]);

            if (! config('filament-shield.super_admin.define_via_gate', false)) {
                $superAdmin->syncPermissions($permissionModel::all());
            }

            if (config('filament-shield.panel_user.enabled', false)) {
                $roleModel::firstOrCreate([
                    'name' => config('filament-shield.panel_user.name', 'panel_user'),
                    'guard_name' => $guard,
                ]);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * 脚手架宿主 AdminPanelProvider 与 Filament 主题，注册到 bootstrap/providers.php，
     * 并在当前进程中动态注册，保证后续 shield:generate 能解析到面板。
     */
    protected function scaffoldPanel(): void
    {
        $this->components->task('脚手架后台面板', function (): void {
            $this->callSilently('vendor:publish', [
                '--tag' => 'cmf-panel',
                '--no-interaction' => true,
            ]);

            $this->registerAdminPanelProvider();
        });
    }

    protected function registerAdminPanelProvider(): void
    {
        $providerClass = 'App\\Providers\\Filament\\AdminPanelProvider';

        if (class_exists($providerClass)) {
            /** @var PanelProvider $provider */
            $provider = new $providerClass($this->laravel);
            $panel = $provider->panel(Panel::make());

            $registry = app(PanelRegistry::class);

            // Filament 5 的 Filament::registerPanel() 走容器 resolving 回调，
            // PanelRegistry 已被解析时不会生效，这里直接注册。
            if (! $registry->get($panel->getId())) {
                $registry->register($panel);
            }
        }

        $providersFile = base_path('bootstrap/providers.php');

        if (! is_file($providersFile)) {
            return;
        }

        $contents = file_get_contents($providersFile);

        if (! is_string($contents) || str_contains($contents, 'AdminPanelProvider::class')) {
            return;
        }

        $patched = preg_replace(
            '/return \[\s*/',
            "return [\n    App\\Providers\\Filament\\AdminPanelProvider::class,\n",
            $contents,
            1,
        );

        if (is_string($patched)) {
            file_put_contents($providersFile, $patched);
        }
    }

    /**
     * 把 Filament 主题加入宿主 Vite 配置的 input（幂等），
     * 免去用户手动改 vite.config.js；配置格式无法识别时提示手动处理。
     */
    protected function registerViteTheme(): void
    {
        $theme = (string) config('cmf-core.theme', 'resources/css/filament/admin/theme.css');

        if ($theme === '' || ! file_exists(base_path($theme))) {
            return;
        }

        /** @var string|null $configPath */
        $configPath = collect(['vite.config.js', 'vite.config.ts'])
            ->map(fn (string $file): string => base_path($file))
            ->first(fn (string $path): bool => is_file($path));

        if (! is_string($configPath)) {
            return;
        }

        $contents = file_get_contents($configPath);

        if (! is_string($contents) || str_contains($contents, $theme)) {
            return;
        }

        $patched = $this->patchViteInput($contents, $theme);

        if ($patched === null) {
            $configFile = basename($configPath);
            $this->components->warn("未能自动把 {$theme} 加入 {$configFile} 的 input，请手动处理。");

            return;
        }

        file_put_contents($configPath, $patched);
        $this->components->twoColumnDetail('Vite 主题入口', $theme);
    }

    /**
     * 在 vite 配置的 input 数组/字符串里追加主题入口；识别不了 input 结构时返回 null。
     */
    protected function patchViteInput(string $contents, string $theme): ?string
    {
        if (! preg_match('/input\s*:\s*\[/s', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return $this->patchViteInputString($contents, $theme);
        }

        $openPos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $closePos = $this->findMatchingBracket($contents, $openPos);

        if ($closePos === null) {
            return null;
        }

        $inner = substr($contents, $openPos + 1, $closePos - $openPos - 1);
        $length = $closePos - $openPos - 1;

        if (! str_contains($inner, "\n")) {
            $body = rtrim(rtrim($inner, " \t"), ',');

            return substr_replace($contents, ($body === '' ? '' : $body.', ')."'{$theme}' ", $openPos + 1, $length);
        }

        $closeIndent = $this->lineIndent($contents, $closePos);
        $entryIndent = $this->entryIndent($inner, $closeIndent);
        $body = trim($inner) === ''
            ? ''
            : rtrim($inner).(str_ends_with(rtrim($inner), ',') ? '' : ',');

        return substr_replace(
            $contents,
            $body."\n{$entryIndent}'{$theme}',\n{$closeIndent}",
            $openPos + 1,
            $length,
        );
    }

    /**
     * input 为单个字符串入口时（input: 'resources/js/app.js'），改写为数组。
     */
    protected function patchViteInputString(string $contents, string $theme): ?string
    {
        if (! preg_match('/input\s*:\s*([\'"])([^\'"]+)\1/s', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $entry = $matches[2][0];
        $indent = $this->lineIndent($contents, $matches[0][1]);
        $entryIndent = $indent.'    ';

        $replacement = "input: [\n{$entryIndent}'{$entry}',\n{$entryIndent}'{$theme}',\n{$indent}]";

        return substr_replace($contents, $replacement, $matches[0][1], strlen($matches[0][0]));
    }

    /**
     * 从 '[' 位置起找到配对的 ']'。
     */
    protected function findMatchingBracket(string $contents, int $openPos): ?int
    {
        $depth = 0;
        $length = strlen($contents);

        for ($i = $openPos; $i < $length; $i++) {
            if ($contents[$i] === '[') {
                $depth++;
            } elseif ($contents[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 指定位置所在行的缩进。
     */
    protected function lineIndent(string $contents, int $pos): string
    {
        $lineStart = strrpos(substr($contents, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return substr($contents, $lineStart, strspn($contents, " \t", $lineStart, $pos - $lineStart));
    }

    /**
     * input 数组内首个条目的缩进，用于对齐新增条目。
     */
    protected function entryIndent(string $inner, string $fallback): string
    {
        foreach (explode("\n", $inner) as $line) {
            if (trim($line) !== '') {
                return substr($line, 0, strspn($line, " \t"));
            }
        }

        return $fallback.'    ';
    }

    /**
     * 把宿主的 auth 用户模型指向 CMF 用户模型（仅当宿主未使用带 HasRoles 的模型时），
     * 否则创建出来的管理员无法登录 Filament 面板。
     * Laravel 11+ 默认 config/auth.php 使用 env('AUTH_MODEL', User::class)，
     * 因此优先写入 .env 的 AUTH_MODEL；旧项目已发布 config/auth.php 的走文件替换。
     */
    protected function ensureAuthUserModel(): void
    {
        /** @var class-string|null $cmfModel */
        $cmfModel = config('cmf-users.model');

        if (! is_string($cmfModel) || ! class_exists($cmfModel) || ! method_exists($cmfModel, 'assignRole')) {
            return;
        }

        /** @var class-string|null $authModel */
        $authModel = config('auth.providers.users.model');

        if (is_string($authModel) && $authModel !== '' && method_exists($authModel, 'assignRole')) {
            return;
        }

        $patchedConfig = $this->patchAuthConfig($cmfModel);
        $patchedEnv = $this->patchEnvAuthModel($cmfModel);

        config(['auth.providers.users.model' => $cmfModel]);

        if ($patchedConfig || $patchedEnv) {
            $this->components->twoColumnDetail('用户模型', $cmfModel);

            if ($patchedEnv) {
                $this->callSilently('config:clear');
            }
        } else {
            $this->components->warn("未自动绑定用户模型，请手动将 config/auth.php 的 providers.users.model 指向 {$cmfModel}。");
        }
    }

    /**
     * 旧式 config/auth.php（含 App\Models\User 字面量）直接替换模型。
     */
    protected function patchAuthConfig(string $cmfModel): bool
    {
        $configPath = config_path('auth.php');

        if (! is_file($configPath)) {
            return false;
        }

        $contents = file_get_contents($configPath);

        if (! is_string($contents)) {
            return false;
        }

        $patched = str_replace(
            ['App\\Models\\User::class', 'use App\\Models\\User;'],
            ['\\'.$cmfModel.'::class', 'use '.$cmfModel.';'],
            $contents,
        );

        if ($patched === $contents) {
            return false;
        }

        file_put_contents($configPath, $patched);

        return true;
    }

    /**
     * 在 .env 写入 AUTH_MODEL（Laravel 11+ 默认 auth 配置会读取它）。
     */
    protected function patchEnvAuthModel(string $cmfModel): bool
    {
        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            return false;
        }

        $contents = file_get_contents($envPath);

        if (! is_string($contents)) {
            return false;
        }

        if (preg_match('/^AUTH_MODEL=/m', $contents)) {
            $patched = preg_replace('/^AUTH_MODEL=.*$/m', 'AUTH_MODEL='.$cmfModel, $contents, 1);
        } else {
            $patched = rtrim($contents, "\n")."\nAUTH_MODEL={$cmfModel}\n";
        }

        if (! is_string($patched) || $patched === $contents) {
            return false;
        }

        file_put_contents($envPath, $patched);

        return true;
    }

    /**
     * 创建初始管理员并分配 super_admin 角色。
     * 姓名/邮箱未指定时取默认值，密码未指定时随机生成并打印在终端。
     */
    protected function createAdminUser(): void
    {
        if ($this->option('skip-admin')) {
            return;
        }

        /** @var string|null $userModel */
        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! class_exists($userModel) || ! method_exists($userModel, 'assignRole')) {
            $this->components->warn('未找到带 HasRoles 的用户模型，跳过创建管理员。');

            return;
        }

        $name = (string) ($this->option('admin-name') ?: 'admin');
        $email = (string) ($this->option('admin-email') ?: $this->defaultAdminEmail());

        /** @var string|null $password */
        $password = $this->option('admin-password');
        $passwordGenerated = ! is_string($password) || $password === '';

        if ($passwordGenerated) {
            $password = Str::password(16, symbols: false);
        }

        /** @var string $password */
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->warn("管理员邮箱 {$email} 格式不正确，跳过创建管理员。");

            return;
        }

        if ($userModel::query()->where('email', $email)->exists()) {
            $this->components->warn("邮箱 {$email} 已存在，跳过创建管理员。");

            return;
        }

        $user = $userModel::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $user->assignRole(config('filament-shield.super_admin.name', 'super_admin'));

        $this->newLine();
        $this->components->info('初始管理员已创建（super_admin）');
        $this->components->twoColumnDetail('登录邮箱', $email);
        $this->components->twoColumnDetail('初始密码', $password);
        $this->components->twoColumnDetail('后台地址', url($this->getPanelPath()));

        if ($passwordGenerated) {
            $this->components->warn('密码仅在本次安装输出，请立即保存，并在登录后及时修改。');
        }

        $this->newLine();
    }

    /**
     * 默认管理员邮箱：admin@<APP_URL 域名>。
     * 本地开发（localhost 等无点域名）回退为 admin@example.com（邮箱校验不接受无点域名）。
     */
    protected function defaultAdminEmail(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '' || ! str_contains($host, '.')) {
            return 'admin@example.com';
        }

        return 'admin@'.$host;
    }

    protected function getPanelPath(): string
    {
        try {
            return Filament::getPanel('admin')->getPath();
        } catch (\Throwable) {
            return 'admin';
        }
    }
}
