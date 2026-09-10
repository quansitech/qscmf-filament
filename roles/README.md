# quansitech/cmf-module-roles

QS CMF 角色模块。安装：`composer require quansitech/cmf-module-roles`。总文档与接入指南见 monorepo：[quansitech/cmf](https://github.com/quansitech/cmf)。

## 提供

- 角色管理 Resource（Filament Shield 集成，权限勾选差异经 AuditLogger 补记审计，View 页挂审计历史）
- `Models\Role`：继承 Spatie Role + Auditable（`config('permission.models.role')` 未被宿主指定时自动设为默认）
- `Policies\RolePolicy`
- 可发布配置 stub：`config/permission.php`、`config/filament-shield.php`、Shield 中文语言包（`php artisan cmf:install` 自动发布，不覆盖已有文件）

## 配置（config/cmf-roles.php）

`resource` / `policy` / `permissions` 三项。深度定制：`php artisan cmf:extend roles`。
