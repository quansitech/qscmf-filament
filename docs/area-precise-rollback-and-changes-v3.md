# area 迁移精确回滚（A 级）与 changes.json v3 简化方案

> 状态：**提案（proposal）**，待评审。
>
> 背景：现行 `MigrationExecutor::revert()` 经实测存在三个结构性缺陷（见 §1），
> 且业务侧回滚为"盲改式值扫描"。本方案以**执行期行级日志**统一承载结构数据与业务数据的
> 回滚现场，实现"A 级精确回滚"（定义见 §2.1）；同时将 changes.json 可写契约从 v2 收敛为 v3
> （五处简化，见 §6），降低 AI 判读书写量与校验错误循环。
>
> 涉及文件：`area/src/Services/MigrationExecutor.php`、`area/src/Services/MigrationGenerator.php`、
> `area/src/Services/ChangesGraph.php`、`area/src/Console/Commands/CheckChangesCommand.php`、
> `area/src/Console/Commands/GenerateMigrationCommand.php`、新增 journal 建表迁移与清理命令、
> `area/skill/changes.schema.json`、`area/skill/SKILL.md`、
> `area/tests/Fixtures/data/changes_*.json`

---

## 0. TL;DR

1. **回滚数据由"执行期行级日志"统一承载**：apply 时每行写入前先捕获 before-image 落
   `cmf_area_migration_journal` 表（结构行整行、业务行单值），revert 按日志逆序回放——
   结构侧 retire/rename/reparent/archive/链式复用的反向分支整体消失；
2. **业务行回放带守卫条件**（`WHERE pk=? AND col=to_value`）：只还原至今仍呈现迁移写入结果的行，
   升级后被业务改写的行跳过并出报告——不误伤、不漏判、不受登记表时间漂移影响；
3. **changes.json v3 五刀**：删 `side`（state 派生）、禁手写 `change_type`（纯派生）、
   `attributes` 值改字段名清单（值从 csv 取）、evidence 提为文件级证据池、下级边证据继承
   单位级边——AI 书写量约减 80%，I6 手写一致性核对整体消失。

---

## 1. 现状诊断：revert 的三个实测缺陷

以下均为 `apply()` → `revert()` 实测结果（测试复现后已删除临时用例），
不需要任何人工改数据即可触发：

### 1.1 链式复用：地区行丢失 + 业务引用悬空（严重）

三沙案例（南沙 460302→460303、西沙 460301→460302，id 460302 复用）：

```
apply 后：  460302 行被 insert 的 updateOrCreate 覆盖为西沙区（旧南沙行数据被覆盖）
revert 后： 460302 = null        ← insert 的反向 delete 把整行删掉
            460302000 = null     ← 下级行同病
            retire 的反向 update 落空（行已不存在）
            南沙店 area_id 回滚为 460302 → 指向不存在的地区行（悬空引用）
```

根因：结构操作逆序回放时，`insert→delete` 与 `retire→update` 对同一行的组合不自洽；
旧行数据未冻结，无法恢复。

### 1.2 废止复用：恢复行 status 错误（轻微）

`revertArchive()` 从归档行 replicate 恢复，只清 `successor_id`、未恢复 `status`——
回滚后旧单位为 `status=0`（停用），与升级前（`status=1`）不一致。

### 1.3 rename / reparent 不反转（设计缺口）

`revert()` 的 match 分支对两者显式返回 `null`（注释：旧值未冻结在 payload），
回滚后名称/拼音/隶属关系保持新值，需人工核对 `cmf_area_changes` 补救。

### 1.4 业务侧：盲改式值扫描的四个局限

现行 revert 对业务列执行 `UPDATE ... SET col=from WHERE col=to`：

1. 不识别"哪些行是 apply 改的"——升级后正常业务新写入的 to 值行被误伤；
2. apply 前就存在的 to 值脏数据（apply 未动）被 revert 误扫；
3. 登记表延迟绑定的时间漂移：apply 与 rollback 两个时点各读当时的
   `cmf_area_references`，期间增删登记列会造成镜像不对称；
4. keep 列若被业务方升级后手工迁移，回滚不知情、不还原。

---

## 2. 目标与非目标

### 2.1 目标：A 级精确回滚

> **迁移动作本身造成的全部 DB 写入被 100% 精确逆操作**——地区结构与业务数据，
> 不多改一行、不少改一行。

`apply()` 的全部写入仅四类，逐一对应逆操作：

| apply 的写入 | 逆操作 | 备注 |
|---|---|---|
| ① cmf_areas 结构操作（insert/retire/rename/reparent/archive，含链式复用覆盖） | 行级日志整行恢复 / 纯新增删除 | 修复 §1.1/§1.2/§1.3 |
| ② 登记业务列映射改写（remap 列 + archive 对全列） | 行级日志按主键+守卫条件还原 | 修复 §1.4 |
| ③ cmf_area_changes 履历（applied_at、detail） | applied_at 置空；**履历行保留** | 审计需求，有意不回滚 |
| ④ 迁移报告日志文件 | 不回滚 | 文件系统副作用，无业务影响 |

### 2.2 非目标（硬性边界，交由运维体系承担）

- **B 级：整个数据库回到升级时点**（含升级后新产生的业务数据消失）——属数据库级
  PITR（备份 + binlog 闪回），代价是丢失观察窗口内全部业务数据；迁移框架不承担；
- **库外状态**：缓存、搜索索引、报表快照、已通过 API 推送外部系统的数据——任何库内
  回滚无法撤回。

---

## 3. 核心机制：执行期行级日志（journal）

### 3.1 为什么不用"生成期 payload 快照"

| 候选 | 结论 |
|---|---|
| 生成期 payload 内嵌结构快照 | 恢复的是基线 csv 标准值，丢失本环境真实 `created_at`/`updated_at` 与本地差异；不符合"回到迁移前版本"语义 |
| **执行期行级日志（选定）** | 捕获本环境迁移前真实行状态；业务数据本来就必须要日志表，结构数据搭同一张表，**一套机制替代两套** |

设计哲学不受影响：payload 仍冻结"做什么"（操作集跨环境确定），日志只记录"做之前的
现场"（各环境自己的数据，本来就不同）。

### 3.2 表结构：`cmf_area_migration_journal`

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigIncrements | **天然即 apply 执行序**（revert 按 id DESC 回放） |
| `version` | string, index | 迁移版本号（回滚定位 / 清理用） |
| `kind` | string | `area`（cmf_areas 整行）/ `biz`（业务列单值） |
| `table_name` | string | biz 用；area 恒为 `cmf_areas` |
| `column_name` | string, nullable | biz 用；area 为 null |
| `pk` | string | 行主键值 |
| `before` | json, nullable | 改写前的值：biz=标量旧值；area=整行（含时间戳）；**null=该行由 apply 新建（revert 删除）** |
| `to_value` | bigint, nullable | biz 用，改写后的值（revert 守卫条件） |
| `created_at` | timestamp | |

### 3.3 apply() 改造

现有事务内、每个写动作**之前**捕获现场：

```
applyAreaOps   每个 op 执行前 select 目标行 → 写日志（不存在则 before=null）
               —— insert/retire/rename/reparent/archive 同一处理；
               —— archive 不再需要特殊分支：旧行记 before=整行、归档行（90{id}）
                  记 before=null，回放天然等价于"删归档行 + 恢复旧行"，
                  revertArchive() 方法删除
applyMappings  每个 映射对×登记列 的 UPDATE 前：
               where(col, from) 分块 pluck 主键（chunk 1000）
               → 逐行写日志（before=from, to_value=to）→ 再 UPDATE
               —— 同事务分块，内存可控；命中 0 行（幂等重跑）零日志
```

链式复用多触点天然正确：460302 行先被 retire 记一条（before=原始南沙行）、再被 insert
覆盖记一条（before=retire 态行），逆序回放后回到原始行——**根治 §1.1**。

### 3.4 revert() 改造：一个通用回放循环

```
revert(payload):
    校验 journal 存在：新格式 payload 无日志 → 拒绝回滚并明确报错
        （不退回盲扫——盲扫正是要消灭的东西）
    事务内按 id DESC 遍历该 version 的日志：
        area 行：before=null → 删除该行
                 否则       → 整行恢复（raw upsert，原样写回时间戳）
        biz 行： UPDATE t SET col=before WHERE pk=? AND col=to_value
                 —— 守卫条件：当前值≠to_value 说明升级后业务已改写过该行，
                    跳过并记入"跳过清单"（不覆盖业务新数据）
    跳过清单 + 回放统计写入迁移日志与报告文件（与 apply 的 report 对齐）
    cmf_area_changes.applied_at 置空（保留履历，现状）
    回滚成功后删除该 version 的日志行（使命完成；重新 migrate 会重新捕获）
```

守卫条件的语义收益（对比现行值扫描）：

| 情形 | 现行值扫描 | 日志+守卫 |
|---|---|---|
| 升级后业务改写了该行 | 误伤（扫走） | 跳过 + 报告 |
| 升级后新写入的 to 值行 | 误伤（扫走） | 不在日志，天然不碰 |
| apply 前的 to 值脏数据 | 误伤 | 不在日志，不碰 |
| 两次 migrate 间登记表增删 | 镜像不对称 | 表名/列名冻结在日志，免疫 |
| 链式复用回滚顺序 | 依赖严格镜像逆序 | 主键寻址，顺序无关（仍按 id DESC 回放） |

---

## 4. 体积、性能与生命周期

- **业务日志量 = apply 实际命中行数**：百万级订单 ≈ 百 MB 级 DB 临时占用；
  结构日志 ≤ 变更行数（普通年份数百行、重度年份千级，单行约 200 B，可忽略）；
- 新增 `area:cleanup-journal {version}` 命令：观察窗口确认无回滚需求后清理；
  apply 报告末尾输出清理提示；
- 无单主键的业务表不支持行级日志：登记时可声明主键列（默认 `id`），探测不到主键的
  列退回现行值扫描并在报告中警告（不阻塞主流程）；
- revert 幂等：守卫条件 + 整行恢复均为幂等；Laravel 不会重复调用 down()。

## 5. 兼容与测试

- **旧格式迁移文件**（payload 无日志标记）：revert 走现行逻辑 + 输出"旧格式，
  rename/reparent/链式复用不在自动回滚范围"警告；发布新版执行器时应同步重新生成
  在途迁移文件（生成物，可再生）；
- **测试清单**：
  - 链式复用 apply+revert 全量断言（460302 恢复为南沙区含时间戳、南沙店引用回流
    且指向存在的行）；
  - 废止复用 revert 后 status=1、归档行删除；
  - rename/reparent revert 后旧值（含 pid）恢复；
  - biz 守卫跳过场景（升级后业务改写过的行不还原、进跳过清单）；
  - 新格式 payload 无日志时拒绝回滚；
  - revert 幂等、chunk 大数据量、cleanup-journal 命令。

---

## 6. changes.json v3：五刀简化

v2 的复杂度本质：**大量可写字段是机器可推导的冗余**——AI 多写 → 校验器交叉核对 →
写错打回，错误循环多。node 12 个可写字段、edge 8 个，逐字段收敛：

### ① 删 `side`（state 派生）

`retired` 必为 old、`appeared` 必为 new、`continued` 约定单侧书写——state 100% 决定
side。连带删除三条校验规则（retired/appeared/continued 的 side 限定）。

### ② 禁手写 `change_type`（纯派生）

现状"不必手写、写了必须等于派生值"——只允许写对的字段，唯一产出是打回错误。
从可写契约移除；records 的 change_type 照常机器派生。I6 的 node/edge 两条比对消失。

### ③ `attributes` 值改字段名清单

校验器本就拿旧值对旧 csv、新值对新 csv 交叉核对——值完全可从 csv 推导：

```jsonc
// v2
"attributes": {"name": ["旧名镇", "新名镇"], "pid": [1000, 1001]}
// v3
"attributes": ["name", "pid"]   // 只声明"哪些字段变了"，值由机器从两版 csv 取
```

`MigrationGenerator` 现状本就如此取值（rename/reparent 读 `$newMap`），生成器零改动；
校验器两条"与 csv 不符"错误替换为一条"声明的字段在两版 csv 间无变化"。

### ④ evidence 提为文件级证据池 + 引用

重庆案例 44 条边重复抄同一份国务院批复 URL——文件体积与出错率的最大来源：

```jsonc
{
  "schema_version": 3,
  "version": "2025.251231.260403",
  "evidence": {
    "gov-2025-cq": {"title": "国务院关于同意重庆市调整部分行政区划的批复", "url": "https://..."}
  },
  "changes": [
    {"kind": "node", "id": 500112, "state": "retired",
     "summary": "撤销渝北区……", "evidence": ["gov-2025-cq"]},
    {"kind": "edge", "from_id": 500112, "to_id": 500157,
     "summary": "渝北区大部分并入两江新区", "evidence": ["gov-2025-cq"]},
    {"kind": "edge", "from_id": 500112113, "to_id": 500109126}
  ]
}
```

每条记录的 evidence 从对象数组变为引用 id 数组，校验"引用存在"即可。

### ⑤ 下级边证据/摘要继承单位级边

下级边与所属单位级边本是同一行政行为的产物：**下级边不写 evidence 时自动继承其
单位级边（复用 `ChangesGraph::topmostFromAncestor`）的 evidence 与 summary**；单位级边
和 node 必须自证，无单位级边的独立下级边必须自带证据。

重庆案例：44 条边约 300 行 JSON → 约 40 行（2 条单位级边带证据 + 42 条下级边各一行）。

### v3 契约总览

| 记录 | 必填 | 可选 |
|---|---|---|
| node | `kind, id, state` | `attributes`（字段名清单）、`id_reuse`、`exceptions`、`summary`、`evidence`（引用）、`confidence`、`name`（仅供人读，不交叉校验） |
| edge | `kind, from_id, to_id` | `cross_level_reason`、`summary`、`evidence`（引用）、`confidence` |

AI 需理解的不变量从 I1–I7 收缩为五条：端点真实（I2）、跨层声明（I3）、例外挂点（I4）、
按侧认领（I5）、id 复用声明（I7）——**I6 手写一致性核对整体消失**（剩余均为机器推导）。

### 连带改动

- `ChangesGraph`：构造时 state→side 派生；attributes 解析为键清单（值用时从 csv 取）；
  evidence 池解析；
- `CheckChangesCommand`：上述规则删除/替换，净减代码；
- `MigrationGenerator`：records 的 old_name/new_name/detail.attributes 全部从 csv 派生
  （现有 fallback 逻辑转正）；evidence 取池内第一条；
- `skill/SKILL.md`：SOP 判定要点与示例同步瘦身；
- **迁移路径**：`schema_version=3` 干净切换，不做 v2 兼容层（v2 文件是判读期瞬态产物，
  无在线兼容负担）；仓库两个回归 fixture（changes_split.json / changes_merge_multi.json）
  机械改写为 v3。

---

## 7. 明确不动的部分

映射拓扑排序（§2.5 先腾空后迁入 + 禁环）、unit_mapping 推导、keep/remap 策略、
I7 复用判定、薄壳迁移文件 + 延迟绑定架构——语义核心全部保留。
（注：行级日志使 revert 对映射顺序不再敏感，但 apply 侧拓扑排序仍是正确性前提，不动。）

## 8. 实施顺序建议

1. journal 建表迁移 + `MigrationExecutor` apply/revert 改造 + 测试（§3–§5）；
2. `area:cleanup-journal` 命令与报告提示；
3. changes.json v3：schema、ChangesGraph、校验器、生成器、SKILL.md、fixture 改写（§6）；
4. README 同步（回滚能力说明 + v3 契约）。
