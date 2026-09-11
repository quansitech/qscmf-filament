# 发布指南

本仓库是 monorepo（真源），通过 CI split 出 5 个只读镜像仓库，Packagist 从镜像仓库取包。

```
quansitech/qscmf-filament（本仓库，所有开发只在这里）
   ├── core/      ──split──► quansitech/cmf-core            ──┐
   ├── users/     ──split──► quansitech/cmf-module-users     ──┤
   ├── roles/     ──split──► quansitech/cmf-module-roles     ──┼──► Packagist
   ├── auditing/  ──split──► quansitech/cmf-module-auditing  ──┤
   └── media/     ──split──► quansitech/cmf-module-media     ──┘
```

> 以下假设 monorepo 仓库为 `quansitech/qscmf-filament`、镜像仓库为 `quansitech/cmf-*`。命名不同时全局替换即可。

## 一次性配置

1. 在 GitHub 创建 5 个 **public** 仓库，保持默认分支 `main`：
   `cmf-core`、`cmf-module-users`、`cmf-module-roles`、`cmf-module-auditing`、`cmf-module-media`。
   action v2.4.5 起支持空仓库自动建分支（旧版本需至少一个初始提交）。
2. 创建 fine-grained PAT，仅对这 5 个仓库授予 `Contents: Read and write`（或用 GitHub App 生成安装 token）。
   在本仓库 Settings → Secrets and variables → Actions 添加 secret：`SPLIT_TOKEN`（secret 里只放 token 原文，
   不带任何前缀；workflow 会自动加 `oauth2:` 前缀，因为 split action 不支持裸 fine-grained token，见
   [action issue #47](https://github.com/danharrin/monorepo-split-github-action/issues/47)）。
3. 推送首个版本 tag，等 workflow 跑完：

   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```

4. 用 GitHub 账号登录 [packagist.org](https://packagist.org)，Submit 5 个镜像仓库地址，例如
   `https://github.com/quansitech/cmf-core`。
5. 按 Packagist 包页面的提示，在 5 个镜像仓库配置 webhook（或安装 Packagist GitHub App），
   之后新 tag 会自动同步。

## 发版流程

```bash
git checkout main && git pull
git tag v1.1.0
git push origin v1.1.0
```

CI（`.github/workflows/split.yml`）对每个包执行：

1. 把子目录内容推到镜像仓库 `main`（内容无变化则跳过 commit）；
2. 在镜像仓库创建并推送同名 tag（`v1.1.0`）。

Packagist 收到 webhook 后刷新版本。统一版本策略下一次 tag 会给 5 个包都打上同一版本号；
某个包本次没有改动时，tag 会打在它的当前内容上，版本号仍保持全局一致。

## 跨包依赖规则

- 模块对核心包的依赖写 `^1.0`（已配置）。核心包出现 BC 变更发 `2.0` 时，
  必须在同一个提交里把依赖它的模块约束改成 `^2.0`，再统一发版。
- 开发态依赖通过根 `composer.json` 的 path repository 解析；各包的
  `extra.branch-alias` 把 `dev-main` 映射成 `1.x-dev`，所以 `^1.0` 在开发态也能解析。

## 迁移到独立版本（后续可选）

若某个模块需要独立节奏发版：

1. tag 改为带前缀：`core-v1.2.0`、`users-v0.5.0`；
2. workflow 解析 tag 前缀，只 split 对应包；
3. 镜像仓库和消费端的 `composer require` 方式不变。

## 注意

- **不要**向镜像仓库直接提交或提 PR，内容会被下一次 split 覆盖；issue/PR 请指向 monorepo。
- 镜像仓库默认分支保持 `main`，与 workflow 中 `branch: main` 一致。
- tag 必须是合法 semver（`v1.0.0` 或 `1.0.0`），否则 Composer 不识别为版本。
- `SPLIT_TOKEN` 未配置时 workflow 只打印 notice 并跳过 split（不报红），配置后自动生效。
- 发布 = 打 tag，不要手动改镜像；所有变更先合入 monorepo `main`。
