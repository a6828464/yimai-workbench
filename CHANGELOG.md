# 更新日志

## 2026-08-28 ｜ feat: v3.1.3 在线升级可用 + 更新日志/文档随版本同步

- 修复后台「版本更新 → 立即更新」报 500：生产 PHP 的 `disable_functions` 禁用了 `proc_open`，Symfony Process 无法拉起更新脚本，已解除该限制（详见 DEPLOY.md 部署前置说明）。
- 更新日志同步：构建产物把 `CHANGELOG.md` 一并放入 `backend/`，每次升级完成后「版本更新」页日志与版本保持一致；`make-release.sh` 与 GitHub Actions 同步调整。
- 文档留痕：README 新增「版本记录」表，功能模块与当前实现对齐；DEPLOY.md 补充在线升级按钮的 PHP/open_basedir 前置要求。
- 版本号同步更新（`VITE_VERSION` 3.1.3）。

## 2026-08-28 ｜ feat: v3.1.2 会籍顾问统一口径与匹配

- 术语全系统统一为「会籍顾问」：会员管理、客户经营池、留资管理、同步页面的「顾问/服务老师/销售顾问」全部统一命名。
- 会员管理「会员」列与客户经营池保持一致：展示 `手机号 · 来源`；「会籍顾问」独立成列（待分配红色标签）。
- 新增会籍顾问匹配：会员档案会籍顾问为空时，自动按手机号匹配留资里的服务老师，减少「待分配」误报（会员管理、客户经营池同步生效）。
- 会籍顾问筛选下拉与新增匹配数据联动。

## 2026-08-28 ｜ feat: v3.1.1 会员管理顾问筛选

- 会员管理「顾问」从会员列拆出为独立列（未分配显示红色「待分配」标签）。
- 新增「顾问」筛选下拉：可查看任意顾问名下会员，含「待分配」筛选项。
- 后端 `/customers` 支持 `consultant` 过滤参数（服务端 + 演示模式双通道）。

## 2026-08-28 ｜ feat: v3.1.0 补全未完成功能 + AI配置存库 + 在线更新加固

- AI 配置改为服务端数据库保管（`app_settings.ai`），任意设备登录自动水合使用同一份配置；AI 配置页改为展示“服务端保管”提示。
- 修复首页「一麦AI助手」永远提示未接入的问题：启动/调用前统一从服务端水合 AI 配置。
- 版本更新：后端返回完整更新脚本输出、前端展示失败明细面板；`update.sh` 加强健壮性（无 python3 兜底解析、双源重试下载、更清晰报错）。
- 任务中心：新增「新建任务」，支持认领开始执行、分配负责人、提报完成、店长验收通过/退回（后端 `POST/PATCH /tasks`）。
- 客户360：客户经营池新增详情抽屉（主档台账 + 工作流留痕时间线 + 按手机号关联的留资），新增 `GET /customers/{id}`。
- 价格审批：新增「发起价格审批」入口，终审通过后可「关联成交」（后端 `POST /approvals` + decide 支持关联成交）。
- 人员管理：新增「开通新账号」「停用/启用」「重置密码」，支持角色与门店范围配置；登录增加停用校验（后端 `POST/PATCH /accounts`）。
- 经营看板：新增近30天留资走势、留资来源分布、月度签到活跃度等真实数据图表（`/analytics/trends`、`/analytics/channels`）。
- 注册页接入真实注册接口（`POST /auth/register`，默认关闭，配置 `REGISTRATION_ENABLED=true` + 邀请码开启）。
- 数据模型：新增 `users.status`（启用/停用）字段迁移。

## 2026-08-28 ｜ fix: 完善在线发布包和版本日志

- GitHub Actions 推送 `main` 后自动构建 `auto-latest` 发布包。
- 支持配置 `GITEE_TOKEN` 后同步创建 Gitee Release 并上传同一发布包。
- 服务器更新脚本优先下载 Gitee 最新 Release，失败时回退 GitHub。
- 修复版本页面更新日志接口失败时整页状态异常的问题。
- 更新日志缺失时返回明确的空日志内容，不再触发 404。

## 2026-08-28 ｜ deploy: 阿里云切换为 oa.nbyimai.com 单域名单站点

- Vue 构建产物与 Laravel `public` 合并，由同一个 HTTPS 域名提供页面、API 和 H5 分享页。
- `oa.nbyimai.com` 根目录切换为 Laravel `public`，启用 PHP 8.4 和可信 SSL。
- `/api/*`、`/up` 进入 Laravel，其他路径回退 Vue `index.html`。
- 删除旧 `oaapi.nbyimai.com` 宝塔站点及旧目录，保留数据库和统一站点备份。
- 发布脚本改为生成 `app/public` 单站点结构，并使用锁文件安装依赖。

## 2026-08-28 ｜ feat: KeepYoga 多表同步与会员经营清单数据闭环

- 新增 KeepYoga 会员基础、会员卡、团课签到、私教签到多表同步。
- 按 `member_id` 与预约记录 `m_id` 精确关联，补齐主卡、剩余课次、到期日、最近到店和三个月出勤字段。
- 按有效卡状态过滤资产，排除过期、退卡、停卡和体验/员工占位卡。
- 会员管理改为使用最近三个完整自然月的已签到数据计算五张经营清单。
- 修复空到店日期误判待复活、出勤降低/预流失重叠、VIP 阈值边界等问题。
- 同步请求按时间窗口处理，避免 PHP 128MB 内存溢出。
- 同步页面展示会员、卡项、预约和签到数量，并在门店同步失败时显示部分失败。
- 统一任务、审批、审计和同步批次分页响应。
- 修复今日预约快照写入权限和输入范围校验。
- 清理后端 Laravel 默认 README，更新项目部署和 KeepYoga 配置说明。

## 2026-08-27 ｜ fix: 客户数据分页、导入幂等与双店数据可见性

- 修复 KeepYoga 导入只识别单一会员字段、已存在记录虚报更新的问题。
- 修复客户池、会员管理、留资和同步批次的分页与响应契约问题。
- 修复多账号门店数据范围和 KeepYoga 导入权限校验。
- 两台生产服务器完成会员数据回填和同步链路验证。

## 2026-08-26 14:01 ｜ feat: 阶段1启动——Laravel 12最小后端（认证/留资/会员五清单/任务/审批/审计API）+ 前端双通道切换开关

- 修改 admin-web/src/api/auth.ts
- 新增 admin-web/src/api/backend.ts
- 修改 admin-web/src/api/yimai.ts
- 修改 admin-web/src/store/modules/user.ts
- 修改 admin-web/src/views/yimai/members/index.vue
- 新增 backend/.editorconfig
- 新增 backend/.gitattributes
- 新增 backend/.gitignore
- 新增 backend/README.md
- 新增 backend/app/Http/Controllers/AuthController.php
- 新增 backend/app/Http/Controllers/Controller.php
- 新增 backend/app/Models/AppSetting.php
- 新增 backend/app/Models/Approval.php
- 新增 backend/app/Models/AuditLog.php
- 新增 backend/app/Models/Customer.php
- 新增 backend/app/Models/Lead.php
- 新增 backend/app/Models/Task.php
- 新增 backend/app/Models/TrainingPlan.php
- 新增 backend/app/Models/User.php
- 新增 backend/app/Providers/AppServiceProvider.php
- 新增 backend/artisan
- 新增 backend/bootstrap/app.php
- 新增 backend/bootstrap/cache/.gitignore
- 新增 backend/bootstrap/providers.php
- 新增 backend/check.php
- 新增 backend/composer.json
- 新增 backend/composer.lock
- 新增 backend/config/app.php
- 新增 backend/config/auth.php
- 新增 backend/config/cache.php
- …等共 73 个文件


## 2026-08-26 13:08 ｜ docs: 重写项目README，移除模板遗留文档；分支master更名main

- 新增 README.md
- 删除 admin-web/README.md
- 删除 admin-web/README.zh-CN.md
- 修改 sync.ps1


## 2026-08-26 13:03 ｜ chore: 接入GitHub远程仓库并启用自动变更日志

- 修改 sync.ps1
