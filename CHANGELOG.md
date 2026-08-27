# 更新日志

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
