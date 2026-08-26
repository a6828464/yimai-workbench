# 更新日志

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