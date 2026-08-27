# 一麦工作台后端

Laravel 12 + Sanctum 的双店经营工作台 API。后端负责认证、角色和门店范围、客资/会员/任务/审批、审计、KeepYoga 只读同步以及 H5 分享数据。

## 环境要求

- PHP 8.2 或更高版本
- MySQL 8.0（5.7 兼容性未作为生产目标）
- `pdo_mysql`、`mbstring`、`openssl`、`curl`、`fileinfo`、`zip`
- 已安装项目依赖：`composer install --no-dev --prefer-dist`

## 本地运行

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

前端默认通过 `VITE_API_BASE` 访问 `/api`。生产环境应使用 HTTPS 和 Nginx 伪静态配置。

## KeepYoga 配置

KeepYoga 凭据只由服务端使用，不进入浏览器。首次安装可通过环境变量配置：

```env
KY_PHONE=随心瑜登录手机号
KY_PASSWORD=随心瑜登录密码
```

超管也可以在前端“KeepYoga同步 → 登录账号设置”中修改数据库配置。数据库中的 `app_settings.ky` 优先于环境变量。

“全量导入会员”会同步并聚合：

- 会员基础表
- 会员卡表
- 团课预约/签到记录
- 私教预约/签到记录

同步结果会写入 `customers`，并按 `member_id`、`m_id` 关联卡项和出勤。出勤使用最近三个完整自然月，只有“已签到”记录会计入。

## 主要接口

所有业务接口前缀为 `/api`，登录后使用 Sanctum Bearer Token：

- `POST /auth/login`
- `GET /me`
- `GET /customers`
- `GET /leads`
- `GET /tasks`
- `GET /approvals`
- `GET /audit-logs`
- `POST /ky/session`
- `POST /ky/call`
- `POST /ky/import`
- `GET /sync-jobs`

KeepYoga 会话、代理、导入和同步批次接口仅允许超管调用。前端菜单隐藏不等于后端授权，权限必须以 API 返回为准。

## 生产部署

- Nginx 根目录必须指向部署包中的 `app/public`（源码目录对应 `backend/public`）。
- 生产环境设置 `APP_ENV=production`、`APP_DEBUG=false` 和正确的 `APP_TIMEZONE`。
- 发布后执行 `php artisan migrate --force`，不要执行 `migrate:fresh`。
- 保留 `.env`、`storage` 和 `bootstrap/cache`，不要把这些内容放入发布包。
- 安装完成后删除或在 Nginx 中禁止访问 `public/install.php`。
- 不建议使用 Web 在线更新覆盖生产代码，应使用受控发布流程并保留回滚版本。

## 检查命令

```bash
php -l routes/api.php
php artisan route:list --path=api
composer audit --locked
```
