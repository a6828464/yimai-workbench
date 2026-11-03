# 一麦工作台 · 微信小程序（miniapp/）

uni-app（Vue 3 + Vite + TypeScript）实现，复用现有 Laravel 12 API。
方案依据：`docs/miniapp-migration/06-总方案与路线图.md`（框架采用 R6 切换条件允许的 uni-app 路线）。

## 运行

```bash
cd miniapp
npm install
npm run dev:mp-weixin     # 开发（产物在 dist/dev/mp-weixin，API 走 .env.development = oa.shunan.fun）
npm run build:mp-weixin   # 生产构建（dist/build/mp-weixin，API 走 .env = oa.nbyimai.com）
```

微信开发者工具「导入项目」选择 `dist/build/mp-weixin`（或 dev 产物目录）。

## 已落地（M1 骨架 + 会员/留资）

- **登录闭环（账密）**：`POST /auth/login` → Sanctum Bearer Token → `GET /me`；登录页、用户 store、持久化。
- **存储适配层** `src/utils/storage.ts`：StorageLike shim（web localStorage 语义 → uni.getStorageSync）。
- **HTTP core** `src/utils/http.ts`（R4 闸门）：
  - statusCode 闸门：`wx.request` 任何 statusCode 都进 success，这里强制 2xx 判定，401 清会话 reLaunch 登录页（3s 防抖）、403 归一化提示；
  - 三形态 unwrap：`{code,message}` / `{ok:false}` / `{errno,emsg}`，与 `admin-web/src/api/backend.ts` 逐行对齐。
- **P0 页面**：
  - tabBar：工作台 / 今日待办 / 任务 / 我的
  - 子页：课后分析、训练计划、审批（店长+）、登录
- **会员管理（2026-11-04）**：`pages/members/members`（七清单 + 搜索 + 卡片）、`pages/members/detail`（客户 360 + 卡项 + 出勤 + 续费评估 + 留痕；可写最近沟通/生日/待复活三字段）。口径＝`03-页面迁移分级矩阵.md` §2.2 #2「只搬查 + 看卡项」。
- **留资管理（2026-11-04）**：`pages/leads/leads`（搜索 + 状态筛选 + 卡片 + 悬浮录入）、`pages/leads/form`（新增/编辑 + 手机号查重软提示；编辑只提交 diff）。口径＝同文档 §2.2 #3。
- **构建验证**：`vue-tsc --noEmit` 通过；`uni build -p mp-weixin` 通过；主包 **392KB**（12 页，gzip ≈120KB），远低于 R6 闸门 1.2MB。
- **操作留痕**：任何一轮改动记录到 `docs/miniapp-migration/09-开发操作记录.md`（时间·动作·依据·证据·结果），本档案只保留最新快照。

## 待办（按方案门禁）

| 项 | 状态 | 说明 |
|---|---|---|
| 微信授权登录 | ✅ 后端已落地（2026-11-04） | 后端 `/auth/wx-login`（`WeChatSession` + 31 条契约测试）+ 超管绑定接口 `/accounts/{key}/wx-binding` 全部完成。**仍需运维配置** `WECHAT_MINI_APPID/SECRET` 并把 `.env` 的 `VITE_WX_LOGIN_ENABLED` 置 `true` 重新构建，按钮才出现。完整步骤见 `docs/miniapp-migration/07-微信授权登录落地手册.md`。 |
| request 合法域名白名单 | ⏳ 运维 | mp 后台配置 `https://oa.nbyimai.com`（request/uploadFile/downloadFile 三件套）。 |
| ICP 备案主体确认 | ⏳ 队长 | D1 决策点，最慢一环。 |
| 小程序 appid | ✅ 已填 | `src/manifest.json` 的 `mp-weixin.appid` = `wx3a5379d124e2499c`（与产物 `project.config.json` 一致）。 |
| 会员/留资 真机联调 | 🔴 待做 | 4 页已编译通过（392KB）；**分页、权限隔离（403）、picker、375px 折行**必须真机验一遍，编译通过不等于页面正确。 |
| 课后分析/训练计划编辑 | ⏳ M2 | 当前为列表 + 状态只读；编辑/确认链路在 M2 按 `docs/03` 行数拆页实现。 |
| 留资单条详情接口 | 🟢 建议 | 后端暂无 `GET /leads/{id}`，编辑靠本地草稿传参；补接口后可支持换设备直编。 |
| Tailwind | ❌ 不引 | weapp-tailwindcss 只覆盖 TW3，按方案兜底用手写 SCSS（`src/styles/common.scss`）。 |
| ECharts | ❌ 不引 | R3：主包必爆，P1 看板只做数字卡 + 服务端出图。 |

## 验证（无截图，纯文本断言）

```bash
node verify/http-gate.test.mjs   # R4 statusCode 闸门 + 三形态 unwrap，15 条
```

`verify/http-gate.test.mjs` 用 esbuild 把 `src/utils/http.ts` 打成 CJS，注入假 `uni`，
逐条喂 statusCode + body 断言 resolve/reject。**这是 R4 的运行时证据**，不是文档声称：
401 清 token 并 reLaunch、500 带 `{code:0}` 也不得透传成功、403/422 文案透出。

`verify/runtime-check.mjs` 是早期尝试用 `miniprogram-automator` 做页面断言的脚本，
**当前 IDE 版本（1.06.2504060）已弃用 `App.evaluate` / `Page.getData`**（返回
`appservice ... unimplemented`），因此页面级断言暂时只能靠 `cli preview` 编译通过 +
人工在模拟器里看。脚本保留作为 IDE 恢复该能力后的现成资产。

## 契约注意

- `Authorization: Bearer <token>`，与 web 端同一套 Sanctum token。
- 任务/审批状态机已在页面层对齐后端守卫（`TaskController::update` / `ApprovalController::decide`），越权操作后端仍会 403/422，页面只做引导。
- 今日待办分组动作按 `TodayController::todoAction` 的流转分支写死，新增动作先看后端 `match` 分支。
