# quansitech/cmf-module-users

QS CMF 用户模块。安装：`composer require quansitech/cmf-module-users`。总文档与接入指南见 monorepo：[quansitech/qscmf-filament](https://github.com/quansitech/qscmf-filament)。

## 提供

- `Models\User`：集成 FilamentUser + HasRoles + Auditable 的用户模型（工厂产出 `config('cmf-users.model')` 配置的模型）
- 用户管理 Resource（中文，角色多选 auditSync 进审计，View 页挂审计历史）
- `Policies\UserPolicy`（Shield 权限点 ViewAny:User 等 6 个）

## 配置（config/cmf-users.php）

`model` / `resource` / `policy` / `permissions` 四项，均为类替换开关。深度定制：`php artisan cmf:extend users` 生成宿主继承 Resource；模型定制：继承 `Models\User` 后改 `model` + `config/auth.php`。

## 依赖的表

使用 Laravel 默认 users 表迁移，模块不自带建表迁移；新增字段用宿主追加迁移。
