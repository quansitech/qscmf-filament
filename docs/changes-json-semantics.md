# changes.json 语义规范化（改造方案）

> 状态：**修订稿（revised proposal）**——已按评审报告 `docs/changes-json-semantics-review.md`
> （结论：修改后评审）完成修订，待维护者确认 §6 结论后进入步骤 A。
>
> 本稿相对初稿的修订（对应评审报告行动清单）：
> - **P0**：新增 §2.5「id_reuse 链与映射执行顺序」（拓扑排序 + 禁环 + revert 逆序），§4.3/§4.4/§4.6 联动；
> - **P1**：§4.3 补「边端点派生 node 的结构操作」规则；§2.3 统一 `unit_mapping` 口径（渝北不成立的
>   原因改为"覆盖条款不满足"），执行范围措辞改为"下级边一律执行、单位级边按 unit_mapping 判定"；
>   §2.3 明确 `change_type` 标签载体，`code_change` 改为"单位级 1:1 且 id 变（含整族）"；
> - **P2**：I7 扩触发（含废止复用与成环禁环）；§4.2 补 diff 事实认领规则（id_reuse 特判 + 残余盲区）；
>   §6.1/§6.2/§6.4 给结论；§3/§4.1 补 `cross_level_reason`；§4.4 补 `cmf_area_changes` 存量回填策略；
> - **措辞修正**：§1.3.1 机制重写（真正的洞是覆盖率不区分事实类型，而非"一 id 糊两侧"）；
>   §1.3.4/§1.4 补 42 条双写证据与"缺陷被冗余掩盖"的客观记录。
>
> 涉及文件：`area/skill/changes.schema.json`、`area/skill/SKILL.md`、
> `area/src/Console/Commands/CheckChangesCommand.php`、`area/src/Services/MigrationGenerator.php`、
> `area/src/Services/MigrationExecutor.php`、`area/tests/Fixtures/data/changes_*.json`
> 触发案例：2025.251231.260403 升级（重庆撤江北/渝北设两江新区、三沙市辖区代码修正）

---

## 0. TL;DR

现在一条 change 记录**同时承载三种坐标空间的信息**，且裸 `id` 被当作跨版本通用的身份，
于是"以 old_id 还是 new_id 为主键"都说不通。改造方向：

1. **身份 = (版本侧 side, id)**，不是裸 id；
2. 文件里只有两类记录：**`node`（一个单位在某一版的存续状态）** 与
   **`edge`（一条 `旧 id → 新 id` 对应关系，拍平、任意层级、无嵌套）**；
3. **一条 edge 只连两个 id**，没有"容器"→ 不存在"越界/旁落"这种判定，
   "5 镇去了北碚"就是另一条 edge，`full_transfer` / `to_third_party` 这类补丁字段全部删除；
4. **无对应项（例外）只挂在 node 上**，id 空间由 `side` 唯一确定；
5. `change_type`、单位级映射、人工清单一律**由节点状态 + 出入边推导**，不再手写；
6. **id 复用（同 id 换单位）必须显式声明**，映射执行序由复用链拓扑排序确定（§2.5）。

---

## 1. 现状诊断

### 1.1 事实上的主键是"一条边"，但 schema 从未声明

`MigrationExecutor::writeRecords()`（`src/Services/MigrationExecutor.php:339`）的 `updateOrCreate`
键是 `(version, change_type, old_id, new_id)`——模块自己认定一条 change 的主语是
**有向边 `old_id → new_id`**。但五类记录两侧的缺失情况并不一样：

| change_type | n | old_id | new_id |
|---|---|---|---|
| rename | 850 | 有 | 有（两侧相等） |
| code_change | 936 | 有 | 有 |
| merge_into | 4 | 有 | 有（**两条共享同一 new_id**：500105/500112 → 500157） |
| add | 1282 | **null** | 有 |
| abolish | 1570 | 有 | **null** |

- 以 new_id 为主键 → `add`/`abolish` 不成立、多源合并必然重键；
- 以 old_id 为主键 → `add` 不成立。

**主键只能是边**；而边两端分属不同版本，不能共用一个 `id` 字段。

### 1.2 一条记录混了三个坐标空间

| 字段 | id 属于哪一版 | 现状消费者 |
|---|---|---|
| `old_id` / `old_name` | 旧版 | 校验、生成、留档 |
| `new_id` / `new_name` | 新版 | 校验、生成、留档 |
| `detail.child_id_map` 的 **key** | 旧版，且（2026-09 新增规则后）被约束在 `old_id` 子树内 | 校验（`CheckChangesCommand.php:339`）、生成 |
| `detail.child_id_map` 的 **value** | 新版，**允许越出 `new_id` 子树（"旁落"），无声明、无校验** | 校验、生成 |
| `detail.unmatched[].id` | **未声明**：schema 文案写的是旧侧语义（"如同期撤并"），校验器却新旧两侧都用（`:315` 聚合到 new 侧，`:352` 起用于旧侧消失下级） | 校验（两侧）、生成（**不读**）、留档 |
| `detail.renames[]` | 旧/新子 id | **无任何消费者**（生成器不读；2025 产物 0 条） |

`detail` 在 schema 里未声明 `additionalProperties: false`（开放对象），进一步放大歧义。

### 1.3 由此产生的四个具体问题

1. **`unmatched` 的 id 空间未声明**：同一数组既当"旧侧消失下级的豁免"（`:352` 起），
   又当"新侧新增下级的认领"（`:315` 聚合），schema 未声明它属于哪一侧。
   需要修正初稿的机制描述："一条 unmatched 同时糊住两侧"**并不成立**——两版均存在的 id 在
   `checkChildIdMap` 双侧都被跳过（新侧 `:350` `isset($oldMap)` continue、旧侧 `isset($newMap)` continue），
   无法用一个 id 同时骗过两侧。真正的洞在下一条。
2. **覆盖率不区分 id 空间、也不区分事实类型**：`checkCoverage()`（`:157`）把 `old_id` / `new_id` /
   map 键 / map 值 / unmatched 全塞进同一个 `$claimed` 集合，旧版事实可被新侧字段认领（反之亦然）。
   尤其 `renamed`/`parent_changed` 这类"id 两版均在"的事实，可被对侧字段（map 值 / unmatched）
   **静默吸收**，且不经 `checkTypeConsistency`（`:231`）任何复核。
3. **"旁落"是隐式的**：`full_transfer` 的唯一机器依据是"map value 有没有越出 `new_id` 子树"，
   但没有任何校验强制 `越界 ⇔ full_transfer=false`；渝北那条完全靠人手写 summary 说清。
4. **没有单一事实源**：`460301000→460302000` 既在 `460301` 的 map 里、又自成一条 merge_into；
   5 条托管 id 同时挂在江北/渝北两条记录上（同一列表被引用两遍）。规模最大的实例是整个重庆下级映射：
   **42 对下级对应关系既写在两条 merge 的 `child_id_map` 里，又双写成 42 条独立 `code_change` 记录**——
   哪一份才是权威，schema 不回答。

### 1.4 另一处隐性缺陷（顺带记录）

`MigrationExecutor::applyMappings()`（`:230`）对 `merge_into && full_transfer=false` 是
**整条映射跳过**——连 `child_id_map` 里无歧义的下级映射（渝北 30 镇街 → 两江新区/北碚）也一并丢弃，
只把单位级 id 进人工清单。语义上不可判定的只是**单位级**那一项，下级对应关系是确定的。
`revert()`（`:68`）同病。

需要客观记录的是：在真实 2025 产物中，这个缺陷**被 §1.3.4 的冗余双写掩盖了**——merge 的 map 被 skip，
但 42 条独立 `code_change` 记录照常执行，`UPDATE` 幂等无害，所以本次迁移结果没有错。
缺陷本身真实存在：schema 并未强制双写，一旦某次升级只写 merge 不双写，下级映射就会静默丢失。

---

## 2. 目标模型：node + flat edge

### 2.1 身份

- **node（点）**：一个单位在**某一版**里的存续状态，身份 = `(side, id)`，`side ∈ {old, new}`。
- **edge（边）**：一条 id 对应关系 `(from_id → to_id)`，任意层级、**拍平不嵌套**，
  身份 = `(from_id, to_id)`。要求 `from_id ≠ to_id`——同 id 的属性变化是 continued node，不是边。

裸 `id` 在两版之间**可以指不同单位**，这是当前模型最深的坑：三沙 `460302` 在旧版是南沙区、
在新版是西沙区——两条不同的 node，不可能用一条 `rename` 表达清楚。

**为什么边拍平**：嵌套的 `descendants` 会引入"key 在不在 from 子树 / value 在不在 to 子树"的判定，
越界项就要靠 `to_third_party` 之类的补丁字段兜——而这本来只是"另一条边"。拍平后：

- 一条边只连两个 id，越界问题**不存在**；
- "渝北 5 镇去了北碚" = `edge(500112113 → 500109126)`，与 `edge(500112001 → 500157006)` 平级，无主从；
- 生成器要的正是这张 (旧 id → 新 id) 的平表，1:1 对应。

层级只作**弱约束**：建议 `deep(from) == deep(to)`（本次数据全部满足），跨层需在边里显式写 `cross_level_reason`。

### 2.2 七条可机器校验的不变量

| # | 不变量 | 说明 |
|---|---|---|
| I1 | node 唯一键 `(side, id)`；edge 唯一键 `(from_id, to_id)`；edge 要求 `from_id ≠ to_id` | 不允许重复记录、不允许退化边 |
| I2 | edge 方向与端点真实：`from_id ∈ 旧版 csv`、`to_id ∈ 新版 csv` | 消灭"影子端点"式临时引用 |
| I3 | edge 两端层级建议相同（`deep` 相等），跨层必须显式写 `cross_level_reason` | 本次数据全部同层 |
| I4 | **例外挂 node**：`node.exceptions[].id` ⊆ 本节点同侧子树，且该 id **只在同侧存在**（old 节点：新版无此 id；new 节点：旧版无此 id） | id 空间由 `side` 唯一确定 |
| I5 | **按侧覆盖**：diff 的每个 id 事实必须被**同侧**的 node / edge 端点 / exceptions 认领 | 旧侧事实不得由新侧字段认领；continued 类事实的认领规则见 §4.2 |
| I6 | **派生一致性**：文件里写的 `state` / `change_type` / 单位级映射标记必须等于由「节点状态 + 出入边 + 属性变化」推导的值 | 手写可读、机器复核 |
| I7 | **id 复用显式化**（扩触发，评审 P2 修订）：同一 id 被旧侧消费（边的 `from` 端点 / retired node）且被新侧产出（边的 `to` 端点 / appeared node），且**不是** continued 关联（同 id 同单位）⇒ 新侧 node 必须 `id_reuse: true` 并写明对应关系；复用链**成环**（A→B、B→A）⇒ 报错转人工（§2.5 禁环） | 涵盖两种形态：链式复用（三沙 460302：既是 from 又是 to）与废止复用（旧单位无承继、新单位启用同码，即 code_reuse 方向） |

I7 覆盖的两种复用形态在判读与执行上要区分（评审 §6.2 结论）：

- **链式复用（id_reuse）**：旧单位在另一 id 下继续存续（南沙区 460302→460303），旧 id 被别的单位启用
  （西沙区启用 460302）→ 旧业务数据 remap 到旧单位的新 id；
- **废止复用（code_reuse）**：旧单位彻底消失、无承继，同码被新单位启用 → 旧业务数据归档到 `90{id}` 段。

两者都是"同 id 不同单位"，显式声明的要求统一走 I7，但判读规则与执行路径分开写。

> **拍平后机器校验能力不降反升**（评审补充观察）：v1 的"key 必须在本旧单位子树内"（`:339`）
> 随容器概念消失，但 I5 的"按侧残余未认领"机制能捕获单个错挂（错挂一条边 ⇒ 原单位的旧侧事实
> 无人认领 ⇒ 报错）；只有"成对交换 to_id"这类纯语义错误才漏网——而 v1 对此同样无法捕获，校验能力无回退。

### 2.3 派生量（不再手写）

先统一术语（评审 P1 修订）：**单位级出边** = 以 X 本身为 `from` 的边；**下级出边** = 以 X 的某个
下级为 `from` 的边。渝北 5 镇去北碚是下级出边，不影响单位级出边的计数。

| 派生量 | 规则 |
|---|---|
| `unit_mapping(X → Y)`（单位级 id 映射，旧语义 `full_transfer=true`） | 三条同时成立：① X 的**单位级出边唯一**，为 X→Y；② X 的每个"消失下级"（旧版有、新版无）都有出边，且 `to` 落在 Y 的新版子树内；③ X 无 `exceptions`（有例外说明存在未交代的去向） |
| 人工清单（manual） | retired 但 `unit_mapping` 不成立的**单位级对**（旧 `full_transfer=false`，附候选 X→Y 与未覆盖原因；split 出边 ≥ 2 时条件①自然不成立，浅层值一并归入此类）＋ 无出边的 retired（旧 `abolish`）＋ 废止复用归档项 |
| 映射执行范围 | **下级边一律执行**（每条都是无歧义的一对一）；**单位级边仅 `unit_mapping` 成立时执行**——不成立的单位级对只进 manual、不进 mappings；全部映射按 §2.5 拓扑序执行 |
| `change_type`（人读标签） | 派生规则见下表；判定质量体现在图本身（哪些点、哪些边、哪些例外），标签错了是派生错了，一眼可查 |

`change_type` 派生规则（评审 P1 修订：**标签载体一并定义**）：

| 标签 | 载体 | 规则 |
|---|---|---|
| `rename` | continued node | `attributes` 仅 name/ext_name 类变化 |
| `parent_change` | continued node | `attributes` 仅 pid 变化（两者皆变时按校验器统一约定的优先级出标签） |
| `code_change` | 单位级边对 (X→Y) | X 单位级出边唯一为 X→Y、Y 入边唯一、X≠Y，**含整族换码**（下级边随同推导，不再要求叶子级——三沙西沙区 460301→460302 带 460301000→460302000 即属此类） |
| `merge_into` | 单位级边对 (X→Y) | Y 的入边 ≥ 2；或 `unit_mapping` 因覆盖条款不成立（旁落） |
| `split_from` | old node X 及其各单位级边 | X 的单位级出边 ≥ 2 |
| `add` | appeared node | 无入边 |
| `abolish` | retired node | 无出边 |

### 2.4 三个案例在 v2 下的形态

**重庆（撤销江北区、渝北区，设立两江新区）**

```
node(old, 500105 江北区, retired)        node(new, 500157 两江新区, appeared,
node(old, 500112 渝北区, retired)             exceptions=[500157005, 500157024, 500157031, 500157107, 500157110])
edge(500105 → 500157)    edge(500105004 → 500157020) …（江北 12 个下级）
edge(500112 → 500157)    edge(500112001 → 500157006) …（渝北 25 个下级）
edge(500112113 → 500109126) edge(500112115 → 500109127) edge(500112117 → 500109123)
edge(500112121 → 500109124) edge(500112123 → 500109125)          ← 5 镇去北碚，独立成边
```

- `to_third_party` 消失：它就是 `edge(500112113 → 500109126)` 等 5 条边。
- 5 条托管 id 只出现一次，挂在 `node(new, 500157)` 的 exceptions 上（"新版新增、旧版无对应"）。
- `unit_mapping`：江北成立（单位级出边唯一 500105→500157，且 12/12 消失下级落进 500157 子树）；
  渝北不成立——单位级出边同样唯一（500112→500157），真正不满足的是**覆盖条款**（5 镇落到 500109 子树）
  → 单位级对进人工清单，30 条下级边照常执行（修掉 §1.4 的一刀切）。

**三沙（西沙区 460301→460302、南沙区 460302→460303）**

```
node(old, 460302 南沙区, retired)  node(new, 460302 西沙区, appeared, id_reuse=true)
node(old, 460301 西沙区, retired)  node(new, 460303 南沙区, appeared)
edge(460301 → 460302)   edge(460301000 → 460302000)   ← 西沙区换码
edge(460302 → 460303)   edge(460302000 → 460303000)   ← 南沙区换码
# 460302 同时是 `460302→460303` 的 from、`460301→460302` 的 to
# → I7 触发，必须显式声明 id_reuse
```

- 不再需要"rename 460302 南沙→西沙"这种糊法。
- 业务数据：`460302`（南沙区）会正确 remap 到 `460303`（**当前 v1 模型下不会，是真 bug**——v1 产物以
  `rename 460302 南沙→西沙` + `add 460303` 表达，rename/add 均不产生 mapping，引用 460302 的业务行
  会静默变成西沙区数据）。
- 两条单位级边都满足"出边唯一、入边唯一、id 变" ⇒ 标签均为 `code_change`（整族），分类法无遗漏。
- **执行顺序（§2.5）**：`edge(460302→460303)` 必须先于 `edge(460301→460302)`；反序会把西沙业务行
  裹进 460303，数据不可逆错乱。

**乡镇级 code_change（936 条）**

```
node(old, 653223102 赛图拉镇, retired)   node(new, 653228101 昆岭镇, appeared)
edge(653223102 → 653228101)
```

叶子单位的两侧状态可直接由边的端点推出，node 记录只在需要摘要/证据/例外/属性变化时才写
（校验器按 I5 只要求"事实被认领"，不强制每个端点都有 node）。未书写 node 的边端点由生成器
**派生结构操作**（§4.3）：`653223102 ∉ 新版` ⇒ retire；`653228101 ∉ 旧版` ⇒ insert。

### 2.5 id_reuse 链与映射执行顺序（P0 新增）

业务 remap 的物理操作是 `UPDATE ... SET col = to WHERE col = from`，**边与边之间不可交换**。
对每个复用 id R（I7 检出），设 `e_out(R)` 为 `from = R` 的边、`e_in(R)` 为 `to = R` 的边，
则执行序必须满足约束：

```
e_out(R) ≺ e_in(R)        （先腾空 R 上的旧数据，再迁入新数据）
```

- **三沙实例**：先 `460302→460303`（南沙业务行腾空 460302），后 `460301→460302`（西沙业务行迁入）。
  顺序颠倒时，第一条 UPDATE 把西沙业务行改写成 460302，第二条 UPDATE 会把它们和南沙行**一起扫进
  460303**，数据不可逆错乱。
- **链式复用**（A→B、B→C、C→D，B/C 被复用）按约束自然得到逆链序：C→D、B→C、A→B。
- **环形换码**（A→B、B→A 互换）约束成环、拓扑排序失败 ⇒ v2 选择**校验层禁环、转人工处理**
  （本次及历史数据均未出现；若未来出现，再评估两阶段暂存段 remap：环上 from 值先迁 `90` 段暂存、再迁目标）。
- **revert 严格逆序**：按 apply 执行序的反向逐对回滚。
- **职责划分**：校验器检测复用 id 清单与环（I7），生成器消费**同一份**清单在写 payload 前完成
  拓扑排序（payload 自证执行序），执行器按数组顺序执行、不自行排序。复用判定逻辑抽成共享实现，
  校验器与生成器不各写一份，避免两处漂移。

---

## 3. 文件格式草案（v2）

```jsonc
{
  "schema_version": 2,
  "version": "2025.251231.260403",
  "from_version": "2023.240319.250114",   // 便于脱离 config 自证
  "changes": [
    {
      "kind": "node",
      "side": "old",                       // old | new
      "id": 500112,
      "name": "渝北区",
      "state": "retired",                  // appeared | retired | continued
      // 仅 state=continued 且属性有变时出现，例（rename）："attributes": {"name": ["海府", "海府路"]}
      // 例（parent_change）："attributes": {"pid": [460108, 460109]}
      "attributes": {},
      "id_reuse": false,                   // 见 I7：true 时需在 summary 写明复用对应关系
      "exceptions": [                      // 见 I4；id 空间 = 本 node 的 side
        { "id": 500157005, "reason": "新版两江新区托管原北碚区水土街道，原单位仍在，无旧版对应" }
      ],
      "summary": "撤销渝北区：大部分区域并入两江新区，5 镇划归北碚区，单位无单一承继者",
      "evidence": [ { "title": "...", "url": "..." } ],
      "confidence": "high"
    },
    {
      "kind": "edge",
      "from_id": 500112113,                // 要求 from_id ≠ to_id（同 id 属性变化用 continued node）
      "to_id": 500109126,
      // "cross_level_reason": "...",      // deep(from) ≠ deep(to) 时必填（I3）；本次数据全部同层
      "summary": "渝北区大湾镇划归北碚区",
      "evidence": [ { "title": "...", "url": "..." } ],
      "confidence": "high"
    }
  ]
}
```

相对 v1 的字段去向：

| v1 | v2 | 变化 |
|---|---|---|
| `change_type` | 派生（I6 复核），可选保留为只读标签 | 不再是权威 |
| `old_id` / `new_id` | node 的 `(side, id)`；edge 的 `from_id` / `to_id` | 消除跨版本共用一个 `id` |
| `detail.child_id_map` | 多条 edge | 拍平；key/value 的"子树归属"约束取消（不再需要） |
| `detail.full_transfer` | 删除 | 由 `unit_mapping` 推导（§2.3） |
| `detail.unmatched` | node `exceptions` | 挂点、带 side、同侧子树约束（I4） |
| `detail.renames` | 删除 | 无消费者；子级名称变化 = 子单位自己的 continued node |
| `detail.summary` | node / edge 各自 `summary` | |
| `evidence` / `confidence` | 保留 | |

---

## 4. 代码改造清单

### 4.1 `changes.schema.json`

- 顶层加 `schema_version`；`changes[]` 用 `oneOf` 判别 `node` / `edge`；
- `node` / `edge` 均 `additionalProperties: false`；
- `edge` 增加可选 `cross_level_reason`（string, `minLength: 1`）；`deep(from) ≠ deep(to)` 时必填、
  `from_id ≠ to_id`——这类跨字段条件 JSON Schema 表达不了的一律由校验器强制（I1/I3）；
- `node` 增加 `id_reuse`（bool，缺省 false）；`id_reuse=true` 时校验器要求 summary 非空并写明对应关系（I7）；
- 删除 `detail.renames` / `full_transfer` / `child_id_map` / `unmatched`；
- `exceptions[].reason` 保持 `minLength: 1`。

### 4.2 `CheckChangesCommand.php`

从"逐条 change 的规则"改为"图级检查"，新增：

- `checkNodeUniqueness()`（I1，含退化边 `from == to` 拦截）、`checkEdgeEndpoints()`（I2）、`checkEdgeLevel()`（I3）
- `checkExceptionsScope()`（I4）
- `checkCoverageBySide()`（I5）：**替换**现有单集合 `checkCoverage()`（`:157`）
- `checkDerivedConsistency()`（I6）：重算 state / change_type / unit_mapping 并比对。
  **diff 事实的认领规则在此写明**（评审 P2 修订）：
  - `renamed` / `parent_changed`（id 两版均在）：常规由**一条** continued node 认领——单侧书写
    （side=old，`attributes` 记 `[旧值, 新值]`），校验器派生对侧并视为两侧均已认领（§6.4 结论，
    850 条 rename 不翻倍成 1700 条）；
  - **id_reuse 特判**：被声明 `id_reuse` 的 id，其 `renamed` 事实由 `retired(old)` +
    `appeared(new, id_reuse=true)` 节点对认领（三沙：diff 里 `renamed 460302 南沙区→西沙区`
    一条事实，由旧 460302 retired + 新 460302 appeared 认领，而非 continued node）；
  - **残余盲区（承认）**：若复用时 `ext_name` 恰好未变，diff **完全观察不到**该 id
    （added/removed/renamed/parent_changed 均不命中），机器零兜底，只能依赖人工判读比对民政公告。
- `checkIdReuseExplicit()`（I7，扩触发）：三种情形——
  (a) 同 id 既是某边 `from` 又是某边 `to`（链式复用）；
  (b) 同 id 挂 retired old node 且挂 appeared new node、无 continued 关联（废止复用）；
  (c) 复用约束成环（§2.5 禁环检测，直接报错转人工）。
  (a)(b) 要求对应新侧 node `id_reuse: true`。

删除：`checkChildIdMap()`（`:292`）的双侧混用实现；
`$valueClaimsByNewId`（`:299`）保留思路但改名改语义——它现在的正确含义是
"**新节点的入边 + 自身 exceptions 是否覆盖新节点全部独有下级**"（聚合本身没错，错在没名字、被当成了通用认领集合）。

### 4.3 `MigrationGenerator.php`

- 入参从"逐条 switch（`:44`-`:141`）"改为图遍历：
  1. **显式 node** → 结构操作（appeared = 整族 insert；retired = retire；continued + attributes = rename / reparent）；
  2. **edge 端点的派生 node 同样发射结构操作**（评审 P1 补）：对每条 `edge(F→T)` 派生 `(old,F)`、
     `(new,T)` 两个隐式节点——`F ∉ 新版 csv` ⇒ retire；`T ∉ 旧版 csv` ⇒ **整族 upsert**
     （借 `updateOrCreate` 幂等性覆盖 continued 下级，重复写无害）；`F ∈ 新版 且 T ∈ 旧版`
     （两版均在却成边）⇒ 仅属性操作，不 retire/insert。
     由此 936 条乡镇级 code_change 无需手写 node 也有完整结构操作；
  3. **edge → 映射项拍平**：每条边产出一个 `{from, to, unit_level}` 对，不再区分"单位级/子级"
     两种来源，payload 中不再出现 `child_id_map`；
  4. 按 §2.3 推导 `unit_mapping` 与 manual：**mappings 只放可执行对**（全部下级边 + 成立的单位级边），
     不成立的单位级对只进 manual（附候选 from/to 与原因）；
  5. 按 §2.5 对 mappings **拓扑排序**后写 payload（复用清单来自 I7 的共享实现）；
- payload 顶层结构（`areas` / `mappings` / `manual` / `records`）**保持不变**；
  mapping 项内部简化（`full_transfer` / `child_id_map` 消失，新增 `unit_level`），执行器随之简化；
- `mergedOutChildren()`（`:310`，恒返回空数组的死代码）删除。

### 4.4 `MigrationExecutor.php`

- `applyMappings()`（`:230`）**结构性简化**：按 payload 数组顺序逐对执行，不再有类型分支——
  `merge_into && !full_transfer` 整条 skip、split 深浅层分流、`child_id_map` 展开全部删除
  （§1.4 的一刀切在模型层面不复存在：不可判定的单位级对根本不在 mappings 里）；
- `revert()`（`:68`）同步简化，**严格按执行序的逆序**回滚（§2.5）；
- `writeRecords()`（`:339`）：主键从 `(version, change_type, old_id, new_id)` 改为
  `(version, kind, side, old_id, new_id)`（edge 记录的 old_id/new_id 列存 from/to；
  node 记录按 side 存一侧）；
- `cmf_area_changes` 增加 `kind`、`side` 两列（新迁移）。**存量 v1 行回填策略**（评审 P2 补）：
  两列先 nullable；提供一次性回填脚本按 change_type 推导
  （rename/parent_change → node/old；add → node/new；abolish → node/old；
  merge/split/code_change → edge/null）；回填完成前读取侧双读（kind 为空按 v1 语义展示），
  回填后新写入只走 v2。

### 4.5 `SKILL.md`

- §判读规则表改写为"node / edge 判定要点"，明确四句话：
  **一条边一个对应、越界就是另一条边**、**例外挂节点**、**id 跨版本不等价**、**id 复用必须显式声明**；
- §产出物示例换成 v2（重庆：2 个 retired node + 1 个 appeared node + 44 条边 = 2 条单位级 + 42 条下级）；
- §②语义判读补一步自检：先把"出边/入边"表列全，再补 node 的摘要、例外与证据；
- 补 §2.5 的判读提示：发现"同 id 换单位"时，先判链式复用还是废止复用（I7 两种形态），
  链式复用检查是否成环。

### 4.6 测试与 fixture

- `tests/Fixtures/data/changes_split.json`、`changes_merge_multi.json` 重写为 v2；
- 新增用例（红→绿各一份）：
  1. 一条边两端层级不同且未声明 `cross_level_reason` → 报错；
  2. exceptions 挂到非本侧/非本子树的 node → 报错；
  3. 同 id 既是 from 又是 to 但未声明 `id_reuse` → 报错（三沙案例）；
  4. 渝北式 partial：5 条边去北碚 + 25 条去两江 → `unit_mapping` 不成立但 30 条边照常执行；
  5. 新旧事实由异侧字段认领 → 报错（I5）；
  6. **id_reuse 链执行顺序**（评审 P0 补）：三沙案例按错误顺序（先 460301→460302）执行 ⇒
     西沙业务行被裹进 460303（红）；按拓扑序执行 ⇒ 结果正确（绿）；
  7. **复用环**（A→B、B→A）⇒ 校验报错转人工（红→绿）；
- `MigrationPipelineTest` / `RealCaseRegressionTest` 的 payload 断言基本不变（payload 顶层结构未动），
  只改 fixture 输入、mapping 项内部结构与 records 断言。

---

## 5. 落地步骤与验收

| 步骤 | 内容 | 验收 |
|---|---|---|
| **A** | 本文件评审定稿；把 §2 的不变量写进 schema description 与 SKILL.md（纯文档） | 无代码变更；维护者确认语义（含 §6 结论） |
| **B** | schema v2 + 校验器图级重写（含 I7 扩触发与禁环）+ 生成器图遍历（含拓扑排序）+ 执行器简化与逆序 revert + fixture/测试改造 | `vendor/bin/pest` 全绿；7 类反例用例能红 |
| **C** | `build_changes.py` 改产 v2，重出 2025.251231.260403 的 changes.json + 迁移，重新验收 | `area:check-changes` 全绿；e2e 迁移结果与新版基线逐行一致；重庆/三沙/托管三处形态符合 §2.4 |

**与本次数据升级解耦**：当前 v1 产物已满足"迁移后 `cmf_areas` 与新版基线一致"，可以按 v1 先合入升级 PR；
B 作为独立的"schema v2"PR，合并后再用 C 重放一次。

---

## 6. 待定问题（评审后给结论）

1. ~~node 是否带属性快照~~ → **结论：不带**。csv 仍是事实源（I2/I5 已交叉核对端点与覆盖），
   continued node 的 `attributes` 足够记录变化，全量快照只带来膨胀。
2. ~~`id_reuse` 与 `code_reuse` 的边界~~ → **结论：并入 I7**（§2.2）。两者都是"同 id 不同单位"：
   链式复用（旧单位在另一 id 下存续 → 业务数据 remap）与废止复用（旧单位无承继 → 走 `90{id}` 归档）
   分别写判读规则，显式声明的要求统一。
3. **name/pinyin 漂移盲区**（diff 只比 `ext_name`/`pid`，31 个 id 的 `name`/`pinyin`/`pinyin_prefix`
   漂移不迁移）→ **结论：独立于 v2 修复**（扩展 `DiffService` 比对字段），不与 schema v2 绑定、
   不等 B/C 完成。
4. ~~覆盖率对 `renamed`/`parent_changed` 的归属~~ → **结论：单侧书写 + 派生对销**。
   continued node 只写一条（side=old，`attributes` 记 `[旧值, 新值]`），校验器派生对侧并视为
   两侧均已认领（§4.2）；严格双侧书写的备选方案（850 条 rename 变 1700 条 node）放弃。
5. **跨层边** → **维持开放**：I3 弱约束（跨层必须写 `cross_level_reason`）先行；
   "县 → 新县 + 其下级"的跨层对应规则留待真实案例出现再补。

---

## 附：相关代码位置索引

| 位置 | 说明 |
|---|---|
| `CheckChangesCommand.php:157` | `checkCoverage()` 单集合认领（I5 要替换） |
| `CheckChangesCommand.php:205` | `checkIdExistence()`（两侧存在性） |
| `CheckChangesCommand.php:231` | `checkTypeConsistency()`（类型与事实一致） |
| `CheckChangesCommand.php:292-370` | `checkChildIdMap()`：`:299` 按 new_id 聚合、`:339` key 侧子树约束、`:348` 新侧完整性 |
| `CheckChangesCommand.php:407` | `descendantsOf()` |
| `MigrationGenerator.php:40-41` | 读 `child_id_map` / `full_transfer` |
| `MigrationGenerator.php:44-141` | 按 change_type 展开结构操作与映射 |
| `MigrationGenerator.php:310` | `mergedOutChildren()` 恒空（死代码） |
| `MigrationExecutor.php:125` | `insert` 用 `updateOrCreate` |
| `MigrationExecutor.php:230` | `merge_into && !full_transfer` 整条跳过（§1.4） |
| `MigrationExecutor.php:302` | `collectManualItems()` |
| `MigrationExecutor.php:339` | `writeRecords()` 主键 `(version, change_type, old_id, new_id)` |
| `DiffService.php` | `renamed` 仅看 `ext_name`；`added/removed/renamed/parent_changed` 是 **id 空间的事实观察**，与语义判型不同构 |
