# QS CMF（quansitech/cmf-*）

基于 Laravel + Filament 的内容管理框架：把各项目共用的后台能力（用户、角色权限、操作审计等）沉淀为可复用模块包。

## 包结构

| 包 | 说明 |
| --- | --- |
| `core`（quansitech/cmf-core） | 面板基座 `CmfPanelProvider`、模块插件注册表、`cmf:install` / `cmf:extend` / `make:cmf-module` 命令 |
| `users`（quansitech/cmf-module-users） | 用户模型（FilamentUser + HasRoles + Auditable）、用户管理 Resource、UserPolicy |
| `roles`（quansitech/cmf-module-roles） | 角色管理 Resource（Filament Shield 集成）、可审计角色模型、RolePolicy |
| `auditing`（quansitech/cmf-module-auditing） | 中文版审计后台、回滚 Action、AuditPolicy、AuditLogger（pivot 审计补记） |

## 仓库与发布

本仓库为 monorepo（唯一真源），CI 按目录 split 出只读镜像仓库并同步到 Packagist：
`quansitech/cmf-core` / `quansitech/cmf-module-users` / `quansitech/cmf-module-roles` / `quansitech/cmf-module-auditing`。
发版只需在本仓库打 tag（如 `v1.1.0`），完整流程见 [RELEASING.md](RELEASING.md)。

## 新项目接入

```bash
composer require quansitech/cmf-core quansitech/cmf-module-users quansitech/cmf-module-roles quansitech/cmf-module-auditing
php artisan cmf:install
```

参与这些包本身的开发时（本地联调），宿主 `composer.json` 改用 path 仓库指向本目录，symlink 即时生效：

```json
"repositories": [
    { "type": "path", "url": "../qscmf-filament/*", "options": { "symlink": true } }
]
```

`cmf:install` 依次完成：发布模块配置/语言包（不覆盖已有文件）→ 发布 permission/audits 迁移并 migrate → `shield:generate` 生成权限点 → 创建 super_admin/panel_user 角色并挂载权限 → 脚手架 `AdminPanelProvider` + Filament 主题（并注册到 `bootstrap/providers.php`）→ 交互创建初始管理员。完成后访问 `/admin` 即是现成后台。

## 双模使用：默认开箱即用，按需深度定制

- **默认模式**：模块所有类（模型 / Resource / Policy）在包内，`composer update` 即升级。
- **定制模式**：需要深度定制某个模块时：

```bash
php artisan cmf:extend users
```

会在宿主 `app/` 下生成继承类（Resource + 全部 Pages），并把 `config/cmf-users.php` 的 `resource` 指向继承类。之后在 app/ 下覆写父类方法（`getFormFields()`、`getTableColumns()` 等）即可自由定制，未覆写的部分仍随包升级。

模型层面的定制：在 app/ 下继承 `Quansitech\Cmf\Users\Models\User`，替换 `config/cmf-users.php` 的 `model` 与 `config/auth.php` 的 `providers.users.model`（新增字段用宿主自己的追加迁移）。工厂会自动产出配置的新模型。

## 添加新的公共模块

```bash
php artisan make:cmf-module Blog --path=/var/www/qscmf-filament
```

生成标准骨架（composer.json / config / ServiceProvider / Plugin）。开发要点：

1. Resource 放 `src/Filament/Resources/`，Plugin 的 `register()` 里通过 `config('cmf-xxx.resource')` 注册；
2. 模型挂 `Auditable` trait 接入审计；Resource 有 View 页时 `getRelations()` 加 `AuditsRelationManager::class`；
3. 有 Shield 权限点时，在 ServiceProvider `packageBooted()` 里向 `filament-shield.resources.manage` 登记（参照 users 模块）；
4. 在 `.github/workflows/split.yml` 的 matrix 增加一条（`local_path` / `repository_name`），创建对应 GitHub public 空仓库，打 tag 后到 Packagist submit，之后随本仓库 tag 自动发版。

## 关键约定

- 模块 ServiceProvider 在 `packageRegistered()` 里 `Cmf::registerPlugin()`，面板自动挂载；`config/cmf-core.php` 的 `disabled_plugins` 可临时停用模块。
- 宿主 `config/filament-shield.php` 的 `resources.manage` 只登记项目自研 Resource；模块权限点由模块自动登记，宿主配置同名条目时以宿主为准。
- 审计 `audit` / `restoreAudit` Gate 已由 auditing 模块接管为 Shield 权限点（`ViewAny:Audit` / `Restore:Audit`）。
- 新增可审计模型/字段时，记得在 `lang/vendor/filament-auditing/zh_CN` 补 `model.Xxx` / `field.xxx` 中文键。
