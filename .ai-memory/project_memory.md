# 项目记忆

## Known Issues

- KeepYoga 客户 `external_id` 是数据库全局唯一键；批量预加载不得叠加 `venue` 条件，否则历史错店记录会被误判为新建并导致整店事务回滚。
- 新客培养依赖 `customers.enrolled_at` 与 `customers.visit_at`；升级后未执行迁移时，KeepYoga 同步必须在访问上游前给出明确的数据库未升级错误。

## Release Conventions

- 首次安装包将真实 Vue 入口保存为 `public/app.html`，`public/index.html` 使用安装跳转页；超管创建和密码校验成功后由 `install.php` 恢复真实入口并写安装锁。
- 普通在线升级包不得包含 `public/install.php`，也不得采用首次安装跳转入口。
- 初始安装超管密码至少 6 位；系统内后续创建账号、重置和改密仍遵循各自现有的 8 位规则。

## Glossary 术语表

| 术语名 | 规范名称 | 定义 | 记录日期 |
| --- | --- | --- | --- |
| 全新安装包 | installer release | 内置 vendor 与 install.php，根路径自动进入安装向导的首次部署发行包 | 2026-09-09 |
| 普通升级包 | update release | 保留现网配置、storage 与 vendor，不包含 install.php 的在线升级发行包 | 2026-09-09 |
