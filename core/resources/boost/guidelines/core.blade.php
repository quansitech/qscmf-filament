## QS CMF

本项目安装了 QS CMF(quansitech/cmf-*)：一套模块化后台框架，基于 Filament Plugin 分发。用户/角色/审计等公共功能由模块包提供，**修改这些功能一律改包代码，不要在 app/ 下重建**。

### 架构与模块注册

- 模块包：`quansitech/cmf-core`（基座）+ `quansitech/cmf-module-{users,roles,auditing}` 等，命名空间 `Quansitech\Cmf\{模块名}`。
- 面板 Provider 只继承 `Quansitech\Cmf\Core\Providers\Filament\CmfPanelProvider`（固化中间件/登录/主题）；模块经各自 ServiceProvider 的 `Cmf::registerPlugin()` 自动挂载，不要在 PanelProvider 里手动 `->plugin()`。
- 停用模块：`config/cmf-core.php` 的 `disabled_plugins`。
- 可替换类（model/resource/policy）一律经 `config/cmf-*.php` 解析，不在代码里硬编码类名。

### 双模定制（重要）

- 默认模式：直接使用包内类，`composer update` 即升级。
- 深度定制：执行 `php artisan cmf:extend {module}`，在 app/ 下生成继承类（Resource + Pages）并自动改 config 指向；之后覆写父类的 `getFormFields()` / `getTableColumns()` 等 protected 方法。**不要 fork 包或整文件复制包内代码。**
- 模型定制：继承包内模型 + 改 `config/cmf-*.php` 的 model 键（users 模块还需同步 config/auth.php），加字段用追加迁移。

### 初始化与新模块

- 新项目接入：`composer require quansitech/cmf-*` 后 `php artisan cmf:install`（发布配置/语言包/视图 → 绑定用户模型 → 迁移 → 面板脚手架 → shield:generate 权限点 → 建角色 → 建管理员；幂等可重跑）。
- 安装后需确认 `resources/css/filament/admin/theme.css` 在 vite input 中并执行 `npm run build`。
- 开发新模块：`php artisan make:cmf-module {Name} --path={包仓库目录}` 生成骨架，装包即自动挂面板。
