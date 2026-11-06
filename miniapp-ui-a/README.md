# 一麦工作台 · 微信小程序 · A 方案「静奢东方」版（miniapp-ui-a/）

> 本目录是 `miniapp/` 的**独立 A 方案 UI 落地版**：完整拷贝原工程（uni-app Vue3 + Vite + TS + wot-design-uni），只重写了**主页**与**到店指引页**两页的视觉层，功能逻辑与原版 100% 一致。原 `miniapp/` 目录零改动，两套并存可随时对比/回退。
>
> 方向来源：`../ui-test/`（三风格对比，2026-11-07 馆主拍板选 A）。设计口径：静奢东方 · 留白即高级 —— 米灰底 `#F2F4F2` / 墨绿 `#1C6047` / 香槟金 `#C19A4B`（仅两处）/ 衬线大字（Songti SC）/ 发丝线分区 / 无卡片阴影。

## 运行

```bash
cd miniapp-ui-a
npm install                  # 或直接从 ../miniapp 复制 node_modules（本机已验证可行）
npm run dev:mp-weixin        # 开发（API 走 .env.development = oa.shunan.fun）
npm run build:mp-weixin      # 生产构建（dist/build/mp-weixin，API 走 .env = oa.nbyimai.com）
npm run type-check           # vue-tsc --noEmit，必须 exit 0 且无输出（本目录已验证 ✅）
```

微信开发者工具「导入项目」选 `dist/build/mp-weixin`。

## 本次改了什么（相对 miniapp/）

| 文件 | 改动 |
|---|---|
| `src/pages/home/home.vue` | **重写模板 + 样式**，script 逻辑原样保留。视觉：上 62% 沉浸实景（`/static/studio-oriental.png`，新增素材）底部 400rpx 渐变沉入米灰 → 衬线品牌大字 + 金线字距标签 → 「两馆约课」宋式区块标题 + 竖向长条门店入口（发丝线分隔）→ 金色细字「第一次来？到店指引」→ 底部 slogan 员工入口；右缘竖排「日日是好日」+ 渐隐细线。约课弹层 / 首页弹窗（后台可配、once 指纹、员工挂起）样式微调为米灰暖白，逻辑一行未动。 |
| `src/pkg-public/guide/guide.vue` | **重写模板 + 样式**，script 逻辑原样保留。视觉：选店步 = 纯排版大留白（VISIT GUIDE 金字距标签 + 衬线大标 + 竖排「静候光临」+ 竖向长条门店条）；门店步 = 沉浸头图（images[0] 八段渐变沉入米灰 + 白细线 + 衬线店名）→ 地址行（含电话小字）→ 1.5rpx 墨绿描边按钮 ×2（纯 CSS 图标）→ 视频号细线分区 + 细线圆播放钮行 → 门店实景图注（去卡片化，小字 + 短横线）→ 下划线式路线 tab → 「换一家门店」细字链。**退役 classic/editorial 双主题分支**：统一 A 视觉；后端 `theme` 字段仍透传不受影响。图标从 wd-icon 换成纯 CSS（本页不再依赖 wot 组件，分包更轻）。 |
| `src/pages.json` | 仅 home 页 style：三处背景色从深绿改「顶部深 / 底部米灰」（配合沉浸图下拉露出区）。其余 25 页零改动。 |
| `src/static/studio-oriental.png` | 新增：A 方案主视觉实景图（2.0MB，风格定稿时生成）。⚠️ 包体注意：如超主包闸门，压缩后替换或改 https 外链（guide 页图片本就支持外链）。 |
| 其余全部 | **零改动**：26 页路由、tabBar、权限注册表、http/storage/nav/share 工具链、store、组件库、verify 脚本。 |

## 功能逻辑保留清单（对照原版逐条）

主页：不强制登录（只 restore 会话）✅ / 约课双通道（VITE_BOOK_APPID 有值走 navigateToMiniProgram 直跳、无值回落小程序码弹层；失败中文兜底）✅ / 门店顺序与码图链接一一对应（东部店=4250、绿地店=1）✅ / 到店指引独立页 ✅ / slogan 员工入口（已登录 → 弹窗优先 → 工作台，含 1200ms 预算、pending 续跳；未登录 → 登录页）✅ / 首页弹窗全逻辑（enabled 严格 true、内容指纹 FNV-1a、once 关闭落盘、always 复用缓存、纯图坏图即关、401 不跳登录）✅ / 转发回自身 ✅

到店指引：免登录 ✅ / 两步流程（选店 → 门店）✅ / 深链 `?venue=绿地店`（默认值先认、配置回来对不上退回选店）✅ / 后台配置整体替换（`GET /miniapp/config` 免登录，失败静默回默认）✅ / 降级口径（东店地址空 → 可执行文案 + 复制门店名；电话空 → 不渲染拨号换复制；坐标空或占位白名单 → 不调 openLocation 改复制）✅ / 视频：直链内嵌、非直链退化复制链接 ✅ / 路线三段独立（note 换行切段 + 100 字折叠）、图各自预览不串组 ✅ / 视频号 openChannelsUserProfile + 占位弹窗（文案无那两个字）✅ / 转发带门店名 + 分包深链 ✅

## 验证记录（2026-11-07 00:26）

- `npm run type-check` → exit 0，无输出 ✅
- `npm run build:mp-weixin` → DONE ✅；产物含 26 页 + tabBar；`common/assets.js` 确认 `_imports_0 = studio-oriental.png`（home 主背景绑定生效）；guide 产物含全部降级函数（PLACEHOLDER_COORDS / hasRealCoords / openChannels / previewImage / loadRemoteConfig 均在编译后 JS 中）；home 产物含 popupFingerprint / enterWorkbench / pendingEnterWorkbench / navigateToMiniProgram ✅
- 构建产物已清理（交付纯源码）；重跑一条命令即可复现：`npm run build:mp-weixin`
- 未做：真机预览截图（本机无 ffmpeg / 微信开发者工具 CLI 未接；建议导入微信开发者工具走一遍两页四态：主页默认、指引选店、绿地店（完整档案）、东部店（降级态））

## 与原版的关系

- 两目录同一 git 仓库下并存，`miniapp/` 未被触碰（`git status` 可验证）。
- 若验收通过要切主：把 `miniapp/src/pages/home/home.vue`、`miniapp/src/pkg-public/guide/guide.vue`、`miniapp/src/pages.json`（home 条目）三处用本目录版本覆盖，再拷 `studio-oriental.png` 进 `miniapp/src/static/` 即可；其余文件完全相同。
- `ui-test/`（风格对比 mockup）与 `ui-test/shots/`（截图证据）保留不动，供回看。
