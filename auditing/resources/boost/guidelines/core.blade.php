## QS CMF 审计模块

审计页中文化由四个挂载点组成，改动前先确认改的是哪一层：

1. **语言包** `stubs/lang/vendor/filament-auditing/zh_CN/filament-auditing.php`（cmf:install 发布到宿主）：镜像 tapp/filament-auditing 官方键 + 自定义 `event.*` / `model.*` / `field.*` / `restore.*` 键。插件的键是带点字面量键，新增键保持此风格。
2. **Resource** `src/Filament/Resources/Audits/AuditResource.php`：继承 vendor 类做标签与列值翻译。ServiceProvider 会把 `filament-auditing.resources` 置空，避免 vendor 自带英文版与本模块中文版双重注册。
3. **Blade 改写** `stubs/views/vendor/filament-auditing/**`（tag `cmf-views` 发布）：diff 视图的字段名翻译，用 `Lang::has("filament-auditing::filament-auditing.field.{key}")` 兜底 `Str::title($key)`。共 3 个文件：tables/columns/{audit-values-column,key-value}.blade.php、infolists/components/audit-values-entry.blade.php。新增字段的中文名只改语言包的 `field.*` 键，不动 blade。
4. **回滚弹窗** `src/Actions/RestoreAuditAction.php`：子类覆盖 schema 以中文化硬编码英文的弹窗。

已知边界：用户详情页 `AuditsRelationManager`（users 模块引用）的回滚弹窗仍是 vendor 原版英文区块标题，如需中文化按第 4 点同样处理。

授权：本模块在 ServiceProvider 中接管 `audit` / `restoreAudit` 两个 Gate（映射到 Shield 的 `ViewAny:Audit` / `Restore:Audit`），并向 `filament-shield.resources.manage` 自动登记权限点（宿主已配置同名条目时以宿主为准）。
