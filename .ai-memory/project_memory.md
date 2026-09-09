# 项目记忆

## Known Issues

- KeepYoga 客户 `external_id` 是数据库全局唯一键；批量预加载不得叠加 `venue` 条件，否则历史错店记录会被误判为新建并导致整店事务回滚。
- 新客培养依赖 `customers.enrolled_at` 与 `customers.visit_at`；升级后未执行迁移时，KeepYoga 同步必须在访问上游前给出明确的数据库未升级错误。
- 生产曾出现迁移表记录数与迁移文件数一致但 `customers.enrolled_at/visit_at` 实际缺失的半迁移状态；已执行过的迁移文件不能靠修改自愈，必须新增幂等修复迁移并在更新脚本中做结构健康检查。
- 2H2G 生产机 MySQL 5.7 仅 64 MB buffer pool，且同机 WordPress 存在长锁等待；系统盘 BPS 告警后出现 InnoDB page_cleaner 88.7 秒延迟。KeepYoga 同步须保持单任务、跳过未变化事实写入、压缩历史快照并设置服务端截止。

## Release Conventions

- 首次安装包将真实 Vue 入口保存为 `public/app.html`，`public/index.html` 使用安装跳转页；超管创建和密码校验成功后由 `install.php` 恢复真实入口并写安装锁。
- 普通在线升级包不得包含 `public/install.php`，也不得采用首次安装跳转入口。
- 初始安装超管密码至少 6 位；系统内后续创建账号、重置和改密仍遵循各自现有的 8 位规则。
- 安装完成页以 no-store 响应最后展示一次本次账号密码，确认后必须使用 `window.location.replace('/#/auth/login')`，Vue Router 必须显式使用根 base，禁止保留 `/install.php#` 路径。

## Glossary 术语表

| 术语名 | 规范名称 | 定义 | 记录日期 |
| --- | --- | --- | --- |
| 全新安装包 | installer release | 内置 vendor 与 install.php，根路径自动进入安装向导的首次部署发行包 | 2026-09-09 |
| 普通升级包 | update release | 保留现网配置、storage 与 vendor，不包含 install.php 的在线升级发行包 | 2026-09-09 |
