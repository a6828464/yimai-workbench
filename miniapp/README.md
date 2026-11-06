# 一麦工作台 · 微信小程序（miniapp/）

uni-app（Vue 3 + Vite + TypeScript）+ **wot-design-uni 1.14.0**，复用现有 Laravel 12 API。
方案依据：`docs/miniapp-migration/11-工作台四板块重构方案.md`（本轮执行）；总路线见 `06-总方案与路线图.md`（R6 切换条件允许的 uni-app 路线）。

## 运行

```bash
cd miniapp
npm install
npm run dev:mp-weixin     # 开发（产物在 dist/dev/mp-weixin，API 走 .env.development = oa.shunan.fun）
npm run build:mp-weixin   # 生产构建（dist/build/mp-weixin，API 走 .env = oa.nbyimai.com）
npm run type-check        # = npx vue-tsc --noEmit，必须 exit 0 且无输出
```

微信开发者工具「导入项目」选择 `dist/build/mp-weixin`（或 dev 产物目录）。

**包体验收（两种口径都要报，闸门按真实字节判）**：

```bash
cd dist/build/mp-weixin
du -sk .                       # 块占用：268 个小文件 × 4KB 块 → 虚高（本轮 1628KB）
find . -type f -exec stat -f %z {} + | awk '{s+=$1} END {print s/1024" KB"}'
                               # 真实字节：本轮 853.98KB（874,480B / 0.834MB）→ 闸门 1229KB 通过，余 375KB
                               # WeChat 按真实字节计，**以这一行为准**
find . -type f -exec stat -f '%z %N' {} + | sort -rn | head -10   # 最大 10 个文件
```

## 已落地（2026-11-04 四板块重构）

- **四板块 tabBar**：工作台（hero + 2×2 指标卡 + 30 天走势 `ym-chart` + 转化漏斗 + 待办摘要 + 快捷宫格）/ 客户经营（按角色摆入口 + 双角标）/ AI 助手（营销工具 + 谈单工具 + 最近生成）/ 我的（`ym-hero` + 账号卡五行 + 退出二次确认）。
- **18 个子页**：今日待办、会员列表/详情、新客培养、客户经营池（SUPER）、留资列表/表单、任务中心、价格审批、课后分析、训练计划、营销工具、谈单工具、个人信息、模板通知（W7 分包后物理目录在 `src/pkg-*`；**文档一律写路由**，如 `pkg-crm/leads/leads`）；另有 4 页公开页（`home` 品牌首屏 / `book` 约课 web-view / `guide` 到店指引 / `login`）。合计 **26 页**（4 tab + 18 子页 + 4 公开页）。
- **权限单一事实来源** `src/utils/permission.ts`：`FEATURES` 注册表（15 项）× 5 角色，口径逐条对齐 `admin-web/src/router/modules/yimai.ts` 的 `meta.roles`。页面只调 `visibleFeatures()/canUse()`，不写 `roles.includes()`。
- **导航守卫**：`requireLogin`（`utils/guard.ts`）+ `requireFeature` / `requireSuper` / `goFeature` / `goPath`（`utils/nav.ts`，自动区分 `switchTab` 与 `navigateTo`）。26 页中 22 页有守卫，无守卫的 4 页是 `home`（分流器）/`book`（公开 web-view）/`guide`（公开到店指引）/`login`。
- **设计系统**：`src/styles/tokens.scss`（`--ym-*` 自有令牌 + 14 个 `--wot-color-*` 主题变量，定义在 `page{}` 上）+ 8 个 `ym-*` 自研组件，easycom 自动注册（`^wd-(.*)` / `^ym-(.*)`），**编译期展开**：产物 `usingComponents` 合计 `wd-*` 69 处 / `ym-*` 58 处。
- **后端订阅消息（模板通知）**：`GET/POST /wx/subscriptions`、`POST /wx/subscriptions/test`；后端 10 个测试通过（64 断言）。
- **HTTP core** `src/utils/http.ts`（R4 闸门）：statusCode 强制 2xx 判定（401 清会话 reLaunch、3s 防抖；403 归一化 toast）；三形态 unwrap（`{code,message}` / `{ok:false}` / `{errno,emsg}`），与 `admin-web/src/api/backend.ts` 逐行对齐。
- **存储适配层** `src/utils/storage.ts`：StorageLike shim（web localStorage 语义 → `uni.getStorageSync`）。
- **构建事实**：`vue-tsc --noEmit` exit 0（0 错误）；`uni build -p mp-weixin` DONE；**真实包体 853.98KB**（22 页 / 268 文件，2026-11-04 构建口径；2026-11-06 复核 `src/pages.json` 已是 26 页，下次构建应为 26 页并重新计字节），闸门 1.2MB；`app.json` 22 页与 `src/pages.json` 同序对应，`entryPagePath=pages/home/home`，tabBar 4 项 8 图标全在。
- **操作留痕**：任何一轮改动记录到 `docs/miniapp-migration/09-开发操作记录.md`（时间·动作·依据·证据·结果），本档案只保留最新快照。

## 待办（按方案门禁）

| 项 | 状态 | 说明 |
|---|---|---|
| 线上后端部署 | 🔴 阻塞 | `GET https://oa.nbyimai.com/api/wx/subscriptions` 现返回 **404 + SPA index.html**（期望 401），说明本轮后端未部署；`/api/me`、`/api/auth/wx-identity` 均 401（旧路由在）。部署含 2 个迁移 + 模板通知控制器。 |
| 模板 ID 配置 | 🔴 待配 | `config/services.php` 的订阅消息模板 id 未配 → 接口 `configured=false`（页面按「管理员尚未配置」整页说明，不报错）。步骤见 `docs/miniapp-migration/12-模板通知落地手册.md`。 |
| 体验版上传 + 真机联调 | 🔴 待做 | 包体 853.98KB 就绪；需重跑 `cli upload` 并设体验版。真机验：三张图渲染、空数据不白屏、模板通知授权弹窗、375px 折行、冷启耗时。编译通过 ≠ 页面正确。 |
| request 合法域名白名单 | ⏳ 运维 | mp 后台配置 `https://oa.nbyimai.com`（request/uploadFile/downloadFile 三件套）。 |
| 微信授权登录 | ✅ 代码就绪 | `/auth/wx-login` + `/auth/wx-self-bind` 已落地；`.env` 的 `VITE_WX_LOGIN_ENABLED=true`。见 `07-微信授权登录落地手册.md`。 |
| 小程序 appid | ✅ 已填 | `src/manifest.json` 的 `mp-weixin.appid`（与产物 `project.config.json` 一致）。 |
| 课后分析/训练计划编辑 | ⏳ M2 | 当前为列表 + 状态只读；编辑/确认链路在电脑端。 |
| 留资单条详情接口 | 🟢 建议 | 后端暂无 `GET /leads/{id}`，编辑靠本地草稿传参；补接口后可支持换设备直编。 |
| Tailwind | ❌ 不引 | weapp-tailwindcss 只覆盖 TW3，按方案兜底用手写 SCSS（`src/styles/*.scss`）。 |
| ECharts | ❌ 不引 | 主包必爆；走势用自研 `ym-chart`（CSS/SVG 折线），其余只做数字卡。 |

## 验证（无截图，纯文本断言）

```bash
cd miniapp
node verify/permission-matrix.test.mjs   # 权限矩阵：5 角色 × FEATURES 注册表（15 项），81 条断言
node verify/http-gate.test.mjs           # R4 statusCode 闸门 + 三形态 unwrap，15 条
node verify/fix-round.test.mjs --strict  # 真机反馈修复轮结构断言（28 条），有 FAIL 即 exit 1
```

三条都要求 **exit 0**。产物目录各自独立（`verify/out/` 已 gitignore，可重跑再生成）：
- `http-gate` → `verify/out/http-gate/`（`http.bundle.cjs` / `http-gate-report.json`）
- `permission-matrix` → `verify/out/`（`permission.bundle.cjs` / `permission-matrix.json`）
- `fix-round` → `verify/out/fix-round/`（`fix-round.json`，逐条 id/名称/通过/明细）

- `verify/permission-matrix.test.mjs`：用 esbuild 把 `src/utils/permission.ts` 打成 CJS，对 5 个角色求
  `visibleFeatures()` 全量集合并逐条断言（R_MEDIA 看不到 members/sales/pool、R_TEACHER 看不到 approvals、
  R_SERVICE 看不到 plans、R_MANAGER 看不到 pool、R_SUPER 全量 15/15），另外校验「注册表里所有 path
  都在 `pages.json` 注册」「没有入口直指 tabBar 页（会触发 `switchTab:fail`）」。实测 **81/81 PASS**。
- `verify/http-gate.test.mjs` 用 esbuild 把 `src/utils/http.ts` 打成 CJS，注入假 `uni`，逐条喂
  statusCode + body 断言 resolve/reject。**这是 R4 的运行时证据**：401 清 token 并 reLaunch、
  500 带 `{code:0}` 也不得透传成功、403/422 文案透出。实测 **15/15 PASS**。

> ✅ **2026-11-04 已修（队长批准）**：`verify/http-gate.test.mjs` 不再 `rmSync` 整个 `verify/out`，
> 只清自己的子目录 `verify/out/http-gate/`，bundle 与报告都写进该子目录。
> 修复后实测：15/15 PASS，且 `verify/out/` 既有 7 个文件 **md5 一位不变、一个不少**。
> 根下残留的 `http-gate-report.json` / `http.bundle.cjs` 是修复前的旧副本（内容与子目录版相同，可手删）。
>
> ⚠️ **新增验证脚本硬规矩**：一律写 `out/<脚本名>/`，禁止清 whole `out`。
> 2026-11-04 曾因整目录 `rmSync` 误删 7 张 `preview-qr-0.2.0~0.7.1.png` + 6 个 `upload-info*.json` + `preview-info.json`，
> 这些文件**不可恢复**，只能重新走 `wechat cli preview/upload` 生成。

`verify/runtime-check.mjs` 是早期尝试用 `miniprogram-automator` 做页面断言的脚本，
**当前 IDE 版本（1.06.2504060）已弃用 `App.evaluate` / `Page.getData`**（返回
`appservice ... unimplemented`），因此页面级断言暂时只能靠 `cli preview` 编译通过 +
人工在模拟器里看。脚本保留作为 IDE 恢复该能力后的现成资产。

## 契约注意

- `Authorization: Bearer <token>`，与 web 端同一套 Sanctum token。
- 任务/审批状态机已在页面层对齐后端守卫（`TaskController::update` / `ApprovalController::decide`），越权操作后端仍会 403/422，页面只做引导。
- 今日待办分组动作按 `TodayController::todoAction` 的流转分支写死，新增动作先看后端 `match` 分支。
- 订阅消息授权必须在**用户点击的同步栈**里调 `uni.requestSubscribeMessage`，不能先 await 网络再调（微信强约束）。
- `app.json` 里 `easycom` 键为 `null` 是正常现象（编译期展开不下发）；验证 easycom 是否生效请看页面产物 `usingComponents`。
