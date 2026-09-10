<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core\Console;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use OwenIt\Auditing\AuditingServiceProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * 初始化 QS CMF：发布模块配置 → 迁移 → 生成 Shield 权限点 →
 * 创建角色 → 脚手架后台面板 → 创建初始管理员。
 */
#[AsCommand(name: 'cmf:install', description: '初始化 QS CMF（配置 / 迁移 / 权限 / 面板 / 管理员）')]
class InstallCommand extends Command
{
    protected $signature = 'cmf:install
        {--panel=admin : Filament 面板 ID}
        {--admin-name= : 初始管理员姓名}
        {--admin-email= : 初始管理员邮箱}
        {--admin-password= : 初始管理员密码}
        {--skip-admin : 跳过创建初始管理员}
        {--force : 强制覆盖已发布的配置文件}';

    public function handle(): int
    {
        $this->components->info('开始安装 QS CMF...');

        $this->publishModuleAssets();
        $this->publishModuleMigrations();
        $this->call('migrate', ['--force' => true]);
        $this->generateShieldPermissions();
        $this->setupRoles();
        $this->scaffoldPanel();
        $this->createAdminUser();

        $this->components->info('QS CMF 安装完成。');

        $this->components->bulletList([
            '确认 vite.config.js 的 input 包含 resources/css/filament/admin/theme.css 后执行 npm run build',
            '访问 /'.$this->getPanelPath().' 进入后台',
        ]);

        return self::SUCCESS;
    }

    /**
     * 发布各 CMF 模块登记的配置与语言包（tag: cmf-config / cmf-lang），
     * 不覆盖项目已有文件（除非 --force）。
     */
    protected function publishModuleAssets(): void
    {
        $this->components->task('发布模块配置与语言包', function (): void {
            $params = ['--no-interaction' => true];

            if ($this->option('force')) {
                $params['--force'] = true;
            }

            $this->callSilently('vendor:publish', [...$params, '--tag' => 'cmf-config']);
            $this->callSilently('vendor:publish', [...$params, '--tag' => 'cmf-lang']);
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
                '--panel' => $this->option('panel'),
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
     * 脚手架宿主 AdminPanelProvider 与 Filament 主题，并注册到 bootstrap/providers.php。
     */
    protected function scaffoldPanel(): void
    {
        $this->components->task('脚手架后台面板', function (): void {
            $this->callSilently('vendor:publish', [
                '--tag' => 'cmf-panel',
                '--no-interaction' => true,
            ]);

            $providersFile = base_path('bootstrap/providers.php');

            if (! file_exists($providersFile)) {
                return;
            }

            $contents = file_get_contents($providersFile);

            if ($contents === false || str_contains($contents, 'AdminPanelProvider::class')) {
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
        });
    }

    /**
     * 创建初始管理员并分配 super_admin 角色。
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

        if ($this->input->isInteractive()) {
            $name = $this->option('admin-name') ?? text('管理员姓名', default: 'admin', required: true);
            $email = $this->option('admin-email') ?? text('管理员邮箱', required: true, validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : '邮箱格式不正确');
            $password = $this->option('admin-password') ?? password('管理员密码', required: true, validate: fn (string $value): ?string => mb_strlen($value) >= 8 ? null : '密码至少 8 位');
        } else {
            $name = $this->option('admin-name');
            $email = $this->option('admin-email');
            $password = $this->option('admin-password');

            if (! $name || ! $email || ! $password) {
                $this->components->warn('非交互模式且未提供 --admin-name/--admin-email/--admin-password，跳过创建管理员。');

                return;
            }
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

        $this->components->info("管理员 {$email} 创建成功（super_admin）。");
    }

    protected function getPanelPath(): string
    {
        try {
            return Filament::getPanel($this->option('panel'))->getPath();
        } catch (\Throwable) {
            return 'admin';
        }
    }
}
