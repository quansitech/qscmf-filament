# quansitech/cmf-module-auditing

QS CMF 审计模块。安装：`composer require quansitech/cmf-module-auditing`。总文档与接入指南见 monorepo：[quansitech/cmf](https://github.com/quansitech/cmf)。

## 提供

- 中文版审计后台 Resource（event/审计对象/字段名经语言包翻译）+ 中文版回滚 Action
- `Policies\AuditPolicy`，并接管 tapp/filament-auditing 的 `audit` / `restoreAudit` Gate 为 Shield 权限点（ViewAny:Audit / Restore:Audit）
- `Support\AuditLogger::custom()`：pivot sync 等不触发模型事件的操作手动补记审计
- 可发布配置 stub：`config/audit.php`、`config/filament-auditing.php`、中文语言包（`php artisan cmf:install` 自动发布）

## 配置（config/cmf-auditing.php）

`resource` / `policy` / `permissions` 三项。深度定制：`php artisan cmf:extend auditing`。

## 使用约定

业务模型挂 `OwenIt\Auditing\Auditable` trait + 实现 `Contracts\Auditable` 即接入审计；Resource 有 View 页时 `getRelations()` 加 `AuditsRelationManager::class`；新增可审计模型/字段时在 `lang/vendor/filament-auditing/zh_CN` 补 `model.Xxx` / `field.xxx` 键。
