# modMedRecord — Dolibarr 诊疗记录（门诊病历）模块

Dolibarr 22.0.x 外部模块：面向中医馆/中西医结合诊所的门诊病历。零 core 修改。
医疗模块群一期第二个模块，依赖 [modPatient](https://github.com/kongzong/dolibarr-modpatient) ≥ 0.1.1。

当前版本：**0.1.0**（2026-09-20 四阶段全部验收通过，标签 `v0.1.0`）。规格见 [docs/spec-medrecord-v0.1.md](docs/spec-medrecord-v0.1.md)。

## 设计要点

- 一条记录 = 一次就诊：主诉、现病史、既往史快照、舌象、脉象、检查、西医诊断（ICD-10，多条）、中医病名、证型、治法、医嘱
- 状态机 **草稿 → 已签署 → 已作废**：签署即锁（可配置宽限小时数，本人修改留痕）；作废必填原因；**永不物理删除**
- 编号 `JZ-YYYYMMDD-NNN` 按日流水，取号与保存同事务（复制 modPatient 的行锁方案）
- 权限一级形式 `read / write / sign / void / admin`；`read` 即医疗数据读取权限，控制患者卡片 Tab 与 REST
- 审计复用 `llx_patient_audit`，动作 `MEDRECORD_CREATE/MODIFY/SIGN/VOID/READ/PRINT/MODIFY_AFTER_SIGN`
- 三套字典：ICD-10（国家临床版 2.0 编码结构：主要编码 + 附加编码）、中医病名、中医证型；诊断保存 label 快照
- 病历页预留 hook `medrecordcard`，modPrescription 装上后注入"开方"
- 模块 ID `501610`；左菜单挂在 modPatient 的"诊所"顶级菜单下

## 字典数据

模块只打包种子（ICD-10 常见门诊诊断 42 条、中医病名 44 条、证型 48 条）。
全量 ICD-10 通过管理页 CSV 导入（阶段 3），列格式 `主要编码,附加编码,疾病名称`。
参考数据：GitHub `724686158/China-ICD-10` 的"6位扩展代码表.csv"（21,194 行，2018 修订版，无许可声明，**不随模块分发**）。
中医病名/证型编码为模块自定义 `BM-nnn` / `ZH-nnn`，国标 GB/T 15657 / 16751 表到位后整表导入覆盖（`code` 唯一键保证幂等）。

## 阶段状态

| 阶段 | 内容 | 状态 |
|---|---|---|
| 0 modPatient 0.1.1 | 患者卡片 Tab 开放、患者选择器、页头摘要 | 已发布 `v0.1.1` |
| 1 骨架 | descriptor、4 张表 + 3 字典 + 种子、权限、菜单、患者 Tab（时间线）、设置页常量、测试 | 已完成（2026-09-20 UI 验收通过） |
| 2 记录与编号 | `MedRecord` 类（状态机、审计含字段级 diff）、`MedRecordNumbering` + 10 单元 + 6 并发集成测试、记录页（ICD-10 自动补全芯片、签署/作废/复诊引用）、列表 | 已完成（2026-09-20 UI 验收通过） |
| 3 字典导入与打印 | `MedRecordDictImport`（按列形态解析、幂等 upsert、UTF-8/GBK）+ 6 单元测试、设置页导入表单、A4 打印视图（水印页脚 + MEDRECORD_PRINT 审计） | 已完成（2026-09-20 UI 验收通过：2.1 万行导入幂等、搜索命中、打印留痕） |
| 4 集成面 | REST 7 端点、停用→启用全流程、`v0.1.0` | 已完成（2026-09-20 REST 权限矩阵与停用→启用验收通过） |

## REST API

所有端点需 `DOLAPIKEY`。状态机与权限规则和页面完全一致（同一个 `MedRecord` 类）；不返回患者证件号。

```
GET  /api/index.php/medrecord/records?q=&patient=&doctor=&status=&from=&to=&limit=&page=   # read；status 缺省为非作废
GET  /api/index.php/medrecord/records/{id}            # read；写 MEDRECORD_READ 审计
POST /api/index.php/medrecord/records                 # write；建草稿，body 见类注释（diagnoses 数组 {code,label,is_primary}）
PUT  /api/index.php/medrecord/records/{id}            # write；改草稿（或宽限期内本人改已签署记录）；不可编辑返回 403
POST /api/index.php/medrecord/records/{id}/sign       # sign；需为接诊医生或 admin；缺主诉/诊断返回 400
POST /api/index.php/medrecord/records/{id}/void       # void；body {reason}，缺原因 400
GET  /api/index.php/medrecord/dictionaries/{name}?q=  # read；name ∈ icd10 / tcm_disease / tcm_syndrome
```

## 字典导入

设置 → 诊疗记录设置 → 字典 CSV 导入。按 `code` 唯一键幂等：已有编码只更新名称并恢复启用，不删除任何行；重复导入结果不变。

- **ICD-10**：每行含主要编码（`A01.001`，可带剑号 `+`，入库时去掉）、可选附加编码（`K77.0*`）与名称，列顺序不限，前导空列忽略。
  与国家"6位扩展代码表"导出格式直接兼容；仅在附加列出现的编码（如 `B95.000`）按主要编码收录。
- **中医病名 / 证型**：`编码,名称[,排序]`，列序不限；编码需含字母或数字且不含汉字。
- 编码：UTF-8（可带 BOM）或 GBK/GB18030 自动识别。文件上限 20 MB，500 行一批提交。

## 打印

记录页"打印"在新窗口打开 A4 版式的 HTML，页脚为 打印人 / 打印时间 / 记录编号，打开即写 `MEDRECORD_PRINT` 审计。
作废记录打印时顶部带红色作废戳与原因。打印视图不含患者证件号。PDF 版与处方笺一起在 modPrescription 做。

## 状态机与权限

| 动作 | 条件 |
|---|---|
| 新建/编辑草稿 | `write`；非 admin 只能改接诊医生为自己或自己创建的草稿；医生必须已在患者模块登记 |
| 签署 | `sign` 且为接诊医生或 admin；主诉与至少一条诊断（ICD-10 或中医病名）必填；签署后锁定 |
| 签署后修改 | 仅 `MEDRECORD_SIGN_LOCK_HOURS` > 0 时，签署人本人在窗口内可改，记 `MEDRECORD_MODIFY_AFTER_SIGN` |
| 作废 | `void`；原因必填；作废后只读并从默认列表隐藏，所有字段保留 |
| 复诊引用 | 已签署记录上点"复诊"，新草稿预填诊断/证型/治法并指向原记录 |
| 阅读 | `read`；打开记录页写 `MEDRECORD_READ` 审计，打印写 `MEDRECORD_PRINT` |

修改类审计（`MEDRECORD_MODIFY` / `MODIFY_AFTER_SIGN`）附带字段级差异：旧值与新值原样存在审计表里，
modPatient 的审计日志页把多行文本渲染为按行 diff（删除红、新增绿、未变折叠）。
下拉空值 `-1` 与文本 CRLF 在保存前归一，避免"未改动也记为修改"。

`llx_medrecord` 主表没有任何 DELETE 路径；草稿保存时诊断行整体替换，签署后不再可编辑。
编号在 `create()` 的事务内取得，并发测试覆盖 20 并发、锁交接、回滚释放、缺表失败。

## 已知限制与待验证事项

- 编号流水表仅支持 MySQL/MariaDB
- `medrecordcard` hook 已预留，实际注入等 modPrescription 联调
- 打印为 HTML 视图，PDF 版与处方笺一起在 modPrescription 做
- 中医病名/证型种子为模块自定义编码，国标表到位后整表导入覆盖
- 单机构设计（entity 列已备，无跨 entity 共享策略）

## 安装

```
git clone https://github.com/kongzong/dolibarr-modmedrecord htdocs/custom/medrecord
```
先启用 modPatient（≥ 0.1.1），再启用 MedRecord。停用只移除常量/权限/菜单/Tab/hook，不删任何表。

## 测试

```
php tests/run_all.php                 # 结构 + 编号行为测试，无需数据库
php tests/integration/numbering.php   # 真实 MariaDB：20 并发取号、锁交接、回滚释放、缺表失败（隔离临时表）
```

## 开发约定

遵循 [custom/DOLIBARR-MODULE-DEVELOPMENT.md](../DOLIBARR-MODULE-DEVELOPMENT.md)；环境与模块状态见 [custom/AGENTS.md](../AGENTS.md)。
