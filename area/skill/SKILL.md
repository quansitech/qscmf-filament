---
name: area-upgrade
description: qscmf-filament area 模块的行政区划数据升级流水线：发现上游新版本后，驱动 AI agent 完成「下载 → diff → 联网取证判读 → 机器校验 → 生成迁移 → 提交 PR」全流程。当维护者要升级 cmf-module-area 内置的区划数据（AreaCity-JsSpider-StatsGov 上游更新、新区设立/撤并/更名）时使用。确定性步骤全部脚本化，本 skill 只负责语义判读与流程编排。
---

# 行政区划数据升级流水线

## 适用场景

- `php artisan area:check-upstream` 报告上游有新版本；
- 或人工从新闻/公告得知重要区划变更（如新区设立），需要升级模块数据。

## 核心原则

1. **AI 只做"判读"，落地必须是可审核、可回滚的确定性迁移**。每条变更判定必须附信息源 URL；
2. **确定性流程 = 脚本**（area:download / area:diff / area:check-changes / area:generate-migration），语义判断 = 本提示词；
3. **禁止编造**：查不到可靠来源的条目标 `confidence: "low"` 并在 summary 说明疑点，由 PR 审查重点核对；
4. 流程终点是**提交 PR 并停下**——合并、发版由人工接管。

## SOP（严格按序执行）

### ① 下载 + diff（脚本）

```bash
php artisan area:check-upstream            # 确认上游版本号 {version}
php artisan area:download {version}        # 产出 storage/app/cmf-area/ok_data_level4_{version}.csv
php artisan area:diff storage/app/cmf-area/ok_data_level4_{version}.csv
# 产出 storage/app/cmf-area/diff.json
```

diff.json 是纯事实清单（按省分组）：added / removed / renamed / parent_changed / code_reuse_suspected。
**若 `blocked: true`（疑似代码重用）：先走「代码重用专项」（见下），确认后方可继续。**

### ② 语义判读（本 skill 的核心环节）

产出物是 **changes.json v3**（契约见 `changes.schema.json`）：文件里只有两类记录——
**node**（一个单位在某一版的存续状态）与 **edge**（一条 `旧 id → 新 id` 对应关系，
拍平、任意层级、无嵌套）。判定要点四句话：

1. **一条边一个对应，越界就是另一条边**：不存在"容器"概念。"渝北 5 镇去了北碚"就是
   `edge(500112113 → 500109126)` 等独立边，与"其余 25 镇去两江"的边平级，无主从；
2. **例外挂节点**：无对应项的下级只挂在 node 的 `exceptions` 上（retired 节点挂旧侧消失下级，
   如同期撤并；appeared 节点挂新侧新增下级，如托管），且必须 ⊆ 本节点同侧子树、只在同侧存在；
3. **id 跨版本不等价**：裸 id 在两版之间可以指不同单位。同 id 的属性变化是 continued node
   （`attributes` 只写字段名清单，如 `["ext_name", "pid"]`，值由机器从两版 csv 取），不是边；
   `from_id ≠ to_id`；
4. **id 复用必须显式声明**：同 id 换单位时，appeared node 必须 `id_reuse: true` 并在 summary
   写明复用对应关系（整族复用可在单位级一次声明，覆盖其下级）。

书写约定（v3 已把机器可推导的字段全部移出可写契约）：

- **不写 `side`**：由 `state` 派生（retired→旧侧、appeared→新侧、continued 单侧书写一条即可）；
- **不写 `change_type`**：一律由节点状态 + 出入边派生（机器生成到档案）。可参考派生规则：
  出边唯一 + 入边唯一 + id 变 = code_change（含整族换码）；入边 ≥ 2 或疆域旁落 = merge_into；
  单位级出边 ≥ 2 = split_from；无入边 = add；无出边 = abolish；
- **evidence 只写证据池引用 id**：文件顶层 `evidence` 对象登记 `{引用id: {title, url}}`，
  node 与单位级边必须引用至少一条；**下级边可省略 evidence 与 summary，自动继承其单位级边**；
- node 只在需要摘要/证据/例外/属性变化时才写——叶子单位的两侧状态可由边端点推导
  （`from ∉ 新版 ⇒ retired`；`to ∉ 旧版 ⇒ appeared`），校验器只要求 diff 事实被同侧认领。

#### 判读自检（先列边，再补点）

1. **先把"出边/入边"表列全**：对每个 removed id 找它的去向（一条出边，或无出边=撤销）；
   对每个 added id 找它的来源（一条入边，或无入边=纯新设）；renamed/parent_changed
   用 continued node 声明变化字段名；
2. **再补 node 的摘要、例外与证据**：单位级主体（撤并双方、新设单位）写 node 挂 summary /
   exceptions / evidence 引用；叶子下级只靠边端点即可。

#### id 复用判读（同 id 换单位）

发现"同 id 换单位"时（如三沙：旧 460302 是南沙区、新 460302 是西沙区）：

1. 先判**链式复用**还是**废止复用**（I7 两种形态）：
   - 链式复用：旧单位在另一 id 下继续存续（南沙区 460302→460303），旧 id 被别的单位启用
     → 正常写边，业务数据会 remap 到旧单位的新 id；
   - 废止复用：旧单位彻底消失、无承继，同码被新单位启用 → 旧单位写 retired node（无出边），
     业务数据归档到 `90{id}` 段；
2. appeared node 声明 `id_reuse: true` 并在 summary 写明对应关系；
3. 链式复用检查**是否成环**（A→B、B→A 互换）：成环不可自动迁移，校验器会报错转人工
   （映射执行序由复用链拓扑排序确定：先腾空旧 id 上的旧数据，再迁入新数据）。

#### 取证策略（按优先级逐级跟进）

1. 先查维基百科年度列表：`{年份}年中华人民共和国县级以上行政区划变更列表`（1949 至今每年一页），
   用 MediaWiki API 取 wikitext 解析表格：
   `https://zh.wikipedia.org/w/api.php?action=parse&page={页面名}&prop=wikitext&format=json`
   年份区间由 diff.json 的 from_version / to_version 推出（上游版本号格式 `{数据年}.{采集日期}.{发布日期}`）。
2. 列表中"原行政单位"为空或信息不足时，**跟进到该县/区的独立词条**（如"和康县"词条写明"原为皮山县的一部分"）；
3. 再跟进词条引用的**政府公告原文**（省级政府/民政厅网站）核实归属明细（含乡镇级新码）；
4. 乡镇级变更维基覆盖不全（民政部 2019 后不再集中公布），兜底来源：国家地名信息库 dmfw.mca.gov.cn 的地名沿革、地级政府公告；
5. 疑似代码重用的条目：必须确认"新 id 单位"与"历史废止单位"是**两个不同的行政单位**（而非更名复活），并附证据。

### ③ 机器校验（脚本，不过则回 ② 修正）

```bash
php artisan area:check-changes storage/app/cmf-area/changes.json \
    --diff=storage/app/cmf-area/diff.json \
    --new=storage/app/cmf-area/ok_data_level4_{version}.csv
```

校验清单：schema（v3 顶层结构 / node、edge 字段白名单 / 证据池引用存在性）+ 逻辑层图级不变量
（I1 唯一键与退化边、I2 边端点真实、I3 跨层必填 cross_level_reason、I4 例外挂点与 id 空间、
I5 diff 事实按侧认领、I7 id 复用显式化与禁环、单位级边证据自证、continued 声明字段两版确有变化）、
版本一致。
**不通过则把错误清单作为修正输入，回到 ② 修正后重跑，直至全绿。**

### ④ 生成迁移（脚本）

```bash
php artisan area:generate-migration storage/app/cmf-area/changes.json \
    --new=storage/app/cmf-area/ok_data_level4_{version}.csv
# 产出 database/migrations/updates/{date}_area_update_{version}.php
```

生成物是薄壳迁移：只有冻结的 payload 数据（不含任何业务表名），执行逻辑在模块内置 MigrationExecutor。
mappings 按复用链拓扑序冻结（payload 自证执行序）；unit_mapping 不成立的单位级对只进人工清单、
不进 mappings，下级边一律执行。payload 带 journal 标记：migrate 时每个写动作的行级现场落
`cmf_area_migration_journal`，`migrate:rollback` 按日志精确逆序回放（升级后被业务改写的行跳过并进报告）。

### ⑤ 提交 PR，停下

1. 用新版 csv 覆盖 `database/data/ok_data_level4.csv`（新基线）；
2. 更新 `config/cmf-area.php` 的 `data_version` 为新版本号；
3. 把 changes.json 复制到 `database/data/changes_{version}.json` 留档；
4. 同一 PR 提交：新基线 csv + changes.json + 迁移文件 + config 变更；
5. **停**。后续由人工接管。

## 人工闸门（PR 审查要点，供维护者参考）

- 证据池条目链接真实可查、与判定结论一致；
- `confidence: "low"` 条目逐条人工复核；
- 单位级承继关系（unit_mapping 推导）与疆域归属明细（出入边完整性、exceptions 挂点已由
  area:check-changes 机器背书）；
- `id_reuse: true` 条目逐条人工复核（链式/废止复用判定、复用对应关系）；
- 迁移文件与 changes.json 内容一致。

合并 PR 即放行发版：打 tag `area-vX.Y.Z`（见仓库 RELEASING.md）。

## 代码重用专项

diff 命中 `code_reuse_suspected` 时（新 id 命中历史 status=0 废止行）：

1. 取证确认新旧两个 id 是**不同行政单位**（非更名复活），附证据；
2. changes.json 中按废止复用表达：旧单位写 `node(id, retired)`（无出边），
   新单位写 `node(id, appeared, id_reuse: true)` 并在 summary 写明对应关系；
3. 生成迁移后 payload 会含 `archive` 操作：旧行主键迁至归档 id 段（`90{原id}`），ext_name 保留不变，
   所有 keep 策略业务引用一并指向归档 id（显示结果不变，语义不断链）；新单位正常使用官方代码；
4. PR 审查重点核对。

## 产出物示例（changes.json v3）

2025 重庆（撤江北区、渝北区，合设两江新区）：证据入池一次，2 个 retired node + 1 个 appeared node
+ 2 条单位级边自证，其余下级边各一行（继承单位级边的证据与摘要）：

```json
{
  "schema_version": 3,
  "version": "2025.251231.260403",
  "from_version": "2023.240319.250114",
  "evidence": {
    "gov-2025-cq": {"title": "国务院关于同意重庆市调整部分行政区划的批复", "url": "https://..."}
  },
  "changes": [
    {"kind": "node", "id": 500112, "name": "渝北区", "state": "retired",
     "summary": "撤销渝北区：大部分区域并入两江新区，5 镇划归北碚区，单位无单一承继者",
     "evidence": ["gov-2025-cq"]},
    {"kind": "node", "id": 500157, "name": "两江新区", "state": "appeared",
     "exceptions": [
       {"id": 500157005, "reason": "新版两江新区托管原北碚区水土街道，原单位仍在，无旧版对应"}
     ],
     "summary": "撤销江北区、渝北区，设立两江新区",
     "evidence": ["gov-2025-cq"]},
    {"kind": "edge", "from_id": 500112, "to_id": 500157,
     "summary": "渝北区大部分区域并入两江新区（单位级对因疆域旁落进人工清单，下级边照常执行）",
     "evidence": ["gov-2025-cq"]},
    {"kind": "edge", "from_id": 500112113, "to_id": 500109126}
  ]
}
```

析出新设（2024 和康县析自皮山县，母体存续）：

```json
{
  "schema_version": 3,
  "version": "...",
  "evidence": {
    "gov-xj": {"title": "新疆维吾尔自治区人民政府公告", "url": "https://..."}
  },
  "changes": [
    {"kind": "node", "id": 653228, "name": "和康县", "state": "appeared",
     "summary": "析皮山县南部山区设立和康县，县政府驻原赛图拉镇（更名昆岭镇）",
     "evidence": ["gov-xj"]},
    {"kind": "edge", "from_id": 653223, "to_id": 653228,
     "summary": "和康县析自皮山县（皮山县存续）", "evidence": ["gov-xj"]},
    {"kind": "edge", "from_id": 653223102, "to_id": 653228101,
     "summary": "赛图拉镇划归和康县并更名昆岭镇"}
  ]
}
```
