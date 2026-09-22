# modMedRecord V0.1 规格 — 诊疗记录（门诊病历）

> 状态：**评审稿**（2026-09-20）。医疗模块群一期第二个模块，依赖 modPatient ≥ 0.1.1。
> 上位规划：`custom/HEALTHCARE-MODULES-PLAN.md` §3.2；技术约定：`custom/DOLIBARR-MODULE-DEVELOPMENT.md`。
> 立项答案（2026-09-20）：中医馆/中西医结合、单机构、药品目录未定、非医保纯自费。

## 0. 相对规划稿的调整

| 项 | 规划稿 | 本规格 | 理由 |
|---|---|---|---|
| 病历保存年限 | 诊所 ≥3 年 | 默认 **15 年**，可配置 | 《医疗机构病历管理规定》（2013）第 29 条：门（急）诊病历由医疗机构保管的，自最后一次就诊起不少于 15 年。本模块不做物理删除，年限只约束未来的归档工具 |
| 中医字段 | "中医辨证" 一项 | 拆为舌象、脉象、中医病名（字典）、证型（字典）、治法 | 中医馆日常记录的最小集，且证型是处方模块选方的依据 |
| 诊断 | ICD-10 字典 | ICD-10 + 中医病名 + 证型三套字典，均带 CSV 导入 | 全量 ICD-10 与国标中医病证名不随模块打包（体量与来源版本由使用方决定），种子只放常见项 |
| 与处方的关系 | 独立模块 | 病历页预留"开方"入口区域，处方保存时回写 `fk_medrecord` | 讨论时已定：接口分开、UI 合一 |
| 导出水印 | 导出必须带操作人水印 | V0.1 只做打印视图（HTML），页脚含操作人/时间，打印动作写审计 | PDF 与处方笺一起在 modPrescription 用 chinadoc 模式做 |

## 1. 定位与已核实地基

一条诊疗记录 = 患者一次就诊的门诊病历：主诉、现病史、四诊、诊断、治法、医嘱。
记录有状态机：**草稿 → 已签署 → 已作废**。签署后锁定，修正只能作废后引用新建。

本机 22.0.4 源码已核实（2026-09-20）：

| 依赖 | 出处 | 结论 |
|---|---|---|
| 外部模块给患者卡片加 Tab | `functions.lib.php:11763` `complete_head_from_modules($conf,$langs,$object,$head,$h,$type)`，`$type` 任意字符串；`conf.class.php:582` 按 `MAIN_MODULE_*_TABS_*` 常量的冒号前段分组 | modPatient 0.1.1 在 `patient_prepare_head()` 末尾调用 `complete_head_from_modules(..., 'patient')`；本模块 descriptor 声明 `patient:+medrecord:...` |
| 大字典 | `admin/dict.php:1526` 列表 `plimit($listlimit+1, $offset)` | ICD-10 万级行可放字典表；管理页可翻页、可搜索 |
| 编号并发 | `patient/class/patientcardnumbering.class.php`（已通过 6 项 MariaDB 并发测试） | 复制为 `MedRecordNumbering`，前缀 `JZ-YYYYMMDD-`，3 位日流水 |
| 过敏拦截入口 | `PatientAllergy::findConflicts($fkPatient, $fkProduct, $label)` | 病历页顶部展示活动过敏（红色重度），供医生看；真正阻断在处方模块 |
| 医生选择 | `patient_doctor_options($db)` → `fk_user => "姓名 (科室)"` | 病历的 `fk_doctor` 只能选已登记医生；当前用户是医生时默认自己 |
| 审计写入 | `patient_audit($db, $fkPatient, $action, $user, $detail)` | 病历读写复用同一张 `llx_patient_audit`，动作前缀 `MEDRECORD_`，不另建审计表 |

## 2. modPatient 0.1.1 前置改动（本模块动工前先做，独立提交）

1. `patient_prepare_head()` 末尾调用 `complete_head_from_modules($conf, $langs, $object, $head, $h, 'patient')`
2. 新增 `patient_select_html($db, $htmlname, $selected, $morecss)`：卡号/姓名/手机搜索的患者选择器
   （`select2` 走 AJAX `patient/ajax/search.php`，需 `patient read`），返回 `fk_patient`
3. 新增 `patient_get_summary($db, $fkPatient)`：卡号、姓名、性别、年龄、活动过敏列表（仅 `profile`）——
   供病历/处方/预约页头部复用，避免各模块重复查询
4. 版本 0.1.0 → 0.1.1，README 记录三处新增，`tests` 加断言，打 `v0.1.1`

## 3. 功能范围（做）

### 3.1 数据

- `llx_medrecord`：`rowid, entity, ref(唯一 JZ-YYYYMMDD-NNN), fk_patient, fk_doctor(fk_user), fk_department(字典), visit_date(datetime), visit_type(1初诊/2复诊), chief_complaint(主诉 text), present_illness(现病史 text), past_history_snapshot(既往史快照 text, 建档时从 patient 复制, 医生可改), tongue(舌象 varchar 255), pulse(脉象 varchar 255), exam_note(体格检查/其他四诊 text), tcm_disease_code(字典), tcm_syndrome_code(字典), treatment_principle(治法 text), advice(医嘱 text), fk_ref_medrecord(复诊引用的上一条, 可空), status(0草稿/1已签署/9已作废), date_signed, fk_user_sign, void_reason, date_void, fk_user_void, fk_user_creat, fk_user_modif, date_creation, tms`
- `llx_medrecord_diagnosis`：`rowid, fk_medrecord, diag_type(WM 西医/TCM 中医), code, label(快照，字典改名不影响历史), is_primary, position`
  （西医诊断可多条，用 ICD-10；中医诊断走主表两个字段，本表 TCM 行留给多病名场景）
- `llx_medrecord_sequence`：`ref_prefix PK, last_value`（同 patient 卡号表，停用不删）
- 字典（模块 `dictionaries` 声明，`rowid` 无自增）：
  `llx_c_medrecord_icd10`（code, label, chapter, pos, active）、
  `llx_c_medrecord_tcm_disease`（code, label, pos, active；参考 GB/T 15657）、
  `llx_c_medrecord_tcm_syndrome`（code, label, pos, active；参考 GB/T 16751.2）
  种子各 30–50 条常见项；管理页提供 CSV 导入（`code,label[,chapter]`，幂等按 code upsert）
- 常量：`MEDRECORD_RETENTION_YEARS`（默认 15，只展示与校验，不触发删除）、
  `MEDRECORD_SIGN_LOCK_HOURS`（默认 0；>0 时签署后 N 小时内允许签署医生本人修改，记 MODIFY_AFTER_SIGN 审计）

### 3.2 编号

`JZ-YYYYMMDD-NNN` 按日流水，全库跨 entity 单序列；取号在 create 事务内，事务外只预览。
实现复制 `PatientCardNumbering`，历史号从 `llx_medrecord.ref` 回填。

### 3.3 状态机与权限

| 动作 | 从 → 到 | 权限 | 附加条件 |
|---|---|---|---|
| 新建/编辑草稿 | — → 0 / 0 → 0 | `write` | `fk_doctor` 必须是已登记医生；非 admin 只能编辑 `fk_doctor` 为自己的草稿 |
| 签署 | 0 → 1 | `sign` | 主诉、至少一条诊断（西医或中医）必填；签署人写 `fk_user_sign` |
| 签署后修改 | 1 → 1 | `sign` + 本人 + `MEDRECORD_SIGN_LOCK_HOURS` 窗口内 | 每次写 `MEDRECORD_MODIFY_AFTER_SIGN` 审计含改动字段 |
| 作废 | 0/1 → 9 | `void` | 必填原因；作废后只读，列表默认隐藏，可筛选显示 |
| 复诊引用 | 新建时 | `write` | 从该患者最近一条已签署记录复制诊断/证型/治法，`fk_ref_medrecord` 指向它，`visit_type=2` |
| 打印视图 | 任意状态 | `read` | 页脚"打印人 / 时间 / 记录编号"，写 `MEDRECORD_PRINT` 审计 |

权限一级形式：`read / write / sign / void / admin`（admin 管字典导入与常量）。
`read` 是医疗数据读取权限：无 `read` 的用户在患者卡片上看不到"诊疗记录"Tab。
打开记录页写 `MEDRECORD_READ` 审计（与 patient 的 READ_PROFILE 同级别）。

### 3.4 页面

- 左菜单挂在 `fk_mainmenu=clinic` 下：诊疗记录列表、新建记录
- **记录列表** `medrecord/list.php`：按编号/患者卡号/姓名/医生/日期/状态筛选，默认不显示作废；分页按 DEV.md §二.6/7
- **记录页** `medrecord/card.php`：
  - 头部：患者摘要（`patient_get_summary`，含活动过敏红色警示）、编号、状态、医生、就诊日期
  - 主体分区：主诉/现病史/既往史 → 四诊（舌象、脉象、检查）→ 诊断（西医 ICD-10 多选 + 中医病名 + 证型）→ 治法/医嘱
  - 右侧/底部：该患者历史记录时间线（编号、日期、主诊断、医生），点击可跳转
  - **"开方"预留区**：V0.1 显示"处方模块未安装"占位；modPrescription 安装后通过 hook `medrecordcard` 注入按钮与处方摘要
  - 动作按钮按状态机：保存草稿 / 签署 / 作废 / 复诊引用 / 打印
- **患者卡片 Tab "诊疗记录"** `medrecord/patient_tab.php?id=fk_patient`：时间线 + "新建记录"（带 fk_patient 预填）
- **打印视图** `medrecord/print.php`：无导航的 A4 排版 HTML，页脚水印
- **管理页** `medrecord/admin/setup.php`：常量、三套字典的 CSV 导入

### 3.5 Hook 与集成点（供 modPrescription / modFollowup）

- descriptor `module_parts['hooks'] = array('medrecordcard')`，`card.php` 在头部、诊断区下方、动作栏各 `executeHooks('printMedRecordCard'...)` 一次，上下文传 `$object`
- `lib/medrecord.lib.php`：`medrecord_get_last_signed($db, $fkPatient)`、`medrecord_timeline($db, $fkPatient, $limit)`、`medrecord_status_label($status)`
- REST（类 `Medrecord`；勘误 2026-09-21：v0.1.2 及之前业务类与 API 类只差大小写（`MedRecord`/`Medrecord`），PHP 类名大小写不敏感导致 REST fatal。业务类改名 `MedicalRecord`（文件 `medicalrecord.class.php`），API 类 `Medrecord` 保持不变。机制详见 modPrescription spec §3.7 "REST 命名约束"）：
  - `GET medrecord/records?patient=&doctor=&status=&from=&to=`（read）
  - `GET medrecord/records/{id}`（read，写 MEDRECORD_READ 审计）
  - `POST medrecord/records`（write，建草稿）
  - `PUT medrecord/records/{id}`（write，改草稿）
  - `POST medrecord/records/{id}/sign`（sign）
  - `POST medrecord/records/{id}/void`（void，body `{reason}`）
  - `GET medrecord/dictionaries/{name}?q=`（read；name ∈ icd10/tcm_disease/tcm_syndrome，供客户端自动补全；中文 q 需 URL 编码）

## 4. 明确不做（V0.1）

- 结构化病历模板/片段库、富文本编辑器（纯 textarea）
- 检验检查报告、影像、附件上传
- 住院病历、病程记录、会诊
- 处方本身（modPrescription）、收费（modClinicPay）
- 病历导出 PDF/批量导出、归档删除工具（只做年限常量）
- 多医生共同签署、电子签名证书
- 全量 ICD-10 / 国标中医病证名数据打包（只给导入工具与种子）
- PostgreSQL（流水表同限制）

## 5. 红线

1. 病历**不可物理删除**：模块无任何 `DELETE FROM llx_medrecord`（主表）；作废保留全部字段；`remove()` 不删表。
   草稿的诊断行（`llx_medrecord_diagnosis`）随每次保存整体替换，属于草稿编辑的一部分；签署后不可编辑，因此已签署记录的诊断不会被改写
2. 签署后锁定；例外窗口须显式配置且每次修改留痕
3. 读写全部审计到 `llx_patient_audit`（动作 `MEDRECORD_CREATE/MODIFY/SIGN/VOID/READ/PRINT/MODIFY_AFTER_SIGN`），审计不阻断业务（Trigger 返回 0）
4. 记录页与 REST 永不输出患者证件号，患者摘要只用 modPatient 提供的掩码字段
5. 医生字段只能取已登记医生；非 admin 不能改他人草稿
6. 零 core 修改；所有 API 先 grep；字典 `rowid` 无自增、`tabhelp` 非空
7. 编号取号与保存同事务，失败整体回滚

## 6. 阶段划分

| 阶段 | 交付 | 人工验证 |
|---|---|---|
| 0 modPatient 0.1.1 | §2 三项 + 版本 + 标签 | 患者卡片 Tab 机制可被外部模块使用（临时用 wecom 风格断言验证） |
| 1 骨架 | descriptor（ID 501610、hooks、tabs、dictionaries）、4 张表 + 3 字典 + 种子、权限、菜单、语言、测试运行器 | UI 启用；字典页 3 张表；"诊所"菜单下出现诊疗记录；患者卡片出现 Tab 壳 |
| 2 记录与编号 | `MedRecord` 类（create 取号同事务 / fetch / update / sign / void）、`MedRecordNumbering` + 单元与并发集成测试、记录页与列表、患者 Tab 时间线 | 建草稿→签署→作废流程；并发 20 建记录不重号；非本人不能改草稿 |
| 3 中医字段与字典 | ICD-10 多选（select2 AJAX）、中医病名/证型、CSV 导入、复诊引用、打印视图 | 导入 100 行 ICD-10 幂等；复诊引用复制正确；打印页脚含操作人 |
| 4 集成面 | hook 上下文、REST 7 端点、README、停用→启用全流程、`v0.1.0` | REST 权限矩阵；作废记录不出现在默认列表但 REST 可按状态取；停用→启用无损 |

## 7. 验收标准

- A. 干净库 UI 启用 → 建记录 → 签署 → 停用 → 启用，记录与编号无损
- B. 并发 20 建记录编号唯一连续；作废不释放已签署编号
- C. 状态机：草稿可改；签署后普通 `sign` 用户不可改（窗口=0）；作废后所有写入口拒绝
- D. 权限：无 `read` 用户患者卡片无 Tab、REST 403；`write` 非本人草稿改动被拒；`void` 缺原因被拒
- E. 审计：建/改/签/作废/读/打印各产生记录；库内无 DELETE 路径（测试断言）
- F. 字典：CSV 导入两次行数不变；停用字典项后历史记录仍显示快照 label
- G. 复诊引用：新记录 `fk_ref_medrecord` 正确、诊断/证型/治法复制、`visit_type=2`
- H. `tests/run_all.php` 全过；README/descriptor/API 版本一致；AGENTS.md 更新

## 8. 开放项（2026-09-20 已定）

1. **ICD-10 采用国家临床版 2.0 编码结构**：主要编码（`A01.001` 三位类目 + 三位扩展）+ 可选附加编码（星号码）+ 名称。
   导入 CSV 列：`主要编码,附加编码,疾病名称`（与 GitHub `724686158/China-ICD-10` 的"6位扩展代码表.csv" 一致，
   该文件 21,194 行、2018 修订版、无许可声明，**不打包进模块**，README 注明来源；正式 2.0 文件到位后整表覆盖导入）。
   种子约 40 条门诊常见诊断。
2. **中医病名/证型无公开数据集**（GitHub/Gitee 检索无 GB/T 15657 / 16751 编码表）。
   V0.1 由模块手写种子：证型约 40 条（八纲/脏腑/气血津液常见证）、病名约 40 条（内科/妇科/儿科/骨伤常见病），
   编码用模块自定义前缀 `ZH-`（证候）/ `BM-`（病名）+ 三位序号；国标表到位后通过 CSV 导入覆盖，`code` 唯一键保证幂等。
3. **签署即锁**：`MEDRECORD_SIGN_LOCK_HOURS` 默认 0。

## 9. 模块标识

- 目录 `htdocs/custom/medrecord/`，类 `modMedRecord`，常量 `MAIN_MODULE_MEDRECORD`
- 模块 ID **501610**；权限 ID `50161011/21/31/41/51`（read/write/sign/void/admin）
- `depends = array('modPatient')`；语言 `langs/zh_CN/medrecord.lang`、`langs/en_US/medrecord.lang`
- 仓库 `github.com/kongzong/dolibarr-modmedrecord`（推送前由维护者创建）
