# quansitech/cmf-core

QS CMF 核心包。安装：`composer require quansitech/cmf-core`。总文档与接入指南见 monorepo：[quansitech/qscmf-filament](https://github.com/quansitech/qscmf-filament)。

> 本仓库由 monorepo CI 自动生成的镜像（split 产物），请勿直接提交或提 PR；开发与 issue 请到上方 monorepo。

## 提供

- `Quansitech\Cmf\Core\Providers\Filament\CmfPanelProvider`：后台面板基座（中间件栈 / 登录 / 配色 / 主题），宿主 `AdminPanelProvider` 继承它
- `Quansitech\Cmf\Core\Cmf`：模块插件注册表，模块 ServiceProvider 用 `Cmf::registerPlugin()` 登记，面板自动挂载
- `php artisan cmf:install`：初始化（配置/语言包发布 → 迁移 → Shield 权限点 → 角色 → 面板脚手架 → 管理员）
- `php artisan cmf:extend {module}`：生成模块 Resource 的宿主继承类用于深度定制
- `php artisan make:cmf-module {name}`：生成新模块包骨架

## 配置（config/cmf-core.php）

- `apply_defaults`：生产环境默认行为（CarbonImmutable / 禁止破坏性 DB 命令 / 强密码）
- `disabled_plugins`：按 Plugin 类名临时停用模块
- `theme`：Filament viteTheme 路径，文件不存在时自动跳过
