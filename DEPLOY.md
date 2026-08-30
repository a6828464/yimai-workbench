# 部署指南（宝塔面板 · 安装包方式）

> 首次安装推荐上传 `yimai-workbench-installer-v<版本>.zip`，访问 `/install.php` 完成；现有站点更新使用普通发行包。
> 微信内打开 H5 分享页必须 HTTPS，请务必配置 SSL 证书

## 一、宝塔环境要求

| 组件 | 版本要求 | 说明 |
|------|----------|------|
| Nginx | 任意近期版本 | Web 服务器 |
| MySQL | 5.7+（推荐 8.0） | 数据库 |
| PHP | **8.4.1+** | 当前锁定依赖要求 PHP ≥8.4.1 |

PHP 必装扩展（软件商店 → PHP → 设置 → 安装扩展）：
`fileinfo` `opcache` `pdo_mysql` `mbstring` `curl` `zip` `gd` `bcmath`

### 在线升级按钮依赖（重要）

后台「版本更新 → 立即更新」通过 PHP 启动服务器更新脚本，需要满足：

1. **PHP 未禁用 `proc_open` / `putenv`**：宝塔默认 `php.ini` 的 `disable_functions` 含 `proc_open,putenv`，需把它们从 `disable_functions` 中移除并重启对应 PHP 版本（否则点击更新会报 `Server Error` / 500，改用计划任务则可绕过）。
   - 改法：`/www/server/php/{版本}/etc/php.ini` → `disable_functions = ...` 行删除 `proc_open,` 和 `putenv,` → 面板「软件商店 → PHP → 重启」或 `/system?action=ServiceAdmin`(`name=php-fpm-84`,`type=restart`)。
2. **站点 `open_basedir` 放开到站点根**：`update.sh` 位于站点根目录，若 `backend/public/.user.ini` 的 `open_basedir` 只允许 `backend/`，PHP 无法访问 `../update.sh` 也会报错。改为 `open_basedir=/www/wwwroot/oa.nbyimai.com/:/tmp/`。

- ❌ 首次安装服务器不需要装 Composer（首次安装包已内置生产 vendor）
- ❌ 不需要装 Node.js（前端已构建成静态文件）

## 二、域名规划

统一使用一个域名，例如 `oa.nbyimai.com`：

- `/`、`/assets/*`：Vue 工作台与 H5 分享页
- `/api/*`：Laravel API
- `/up`：Laravel 健康检查

国内服务器需 ICP 备案后才能解析。

## 三、上传安装包

1. 从 GitHub/Gitee Release 下载并上传 `yimai-workbench-installer-v<当前版本>.zip`
2. 解压到站点目录，例如 `/www/wwwroot/yimai-workbench/`，得到：

```
/www/wwwroot/yimai-workbench/
└── app/
    ├── backend/  # Laravel 应用；Vue 构建产物已合入 backend/public
    └── update.sh # 后台在线升级脚本
```

## 四、统一站点（oa.yourdomain.com）

1. 宝塔 → 网站 → 添加站点：
   - 域名：`oa.yourdomain.com`
   - 根目录：`/www/wwwroot/yimai-workbench/app/backend/public`
   - PHP 版本：8.4（确认小版本 ≥8.4.1）
2. 数据库（二选一）：
   - **面板建库**：数据库菜单创建库和用户（推荐），记住库名、用户名、密码
   - 安装向导填有建库权限的账号，让向导自动建库
3. SSL：Let's Encrypt 一键签发 → 开启「强制 HTTPS」
4. 将 `app/backend/storage`、`app/backend/bootstrap/cache` 权限设置为 `www:www`、`775`。
5. 访问 `https://oa.yourdomain.com/install.php`，填写数据库、初始超管和可选 KeepYoga 信息。
6. 安装器自动写 `.env`、生成 APP_KEY、执行迁移、创建超管并生成 `storage/install.lock`；请求结束后自动删除 `install.php`。
7. 不要使用 `migrate:fresh` 初始化已有生产数据库；后续发布只执行 `php artisan migrate --force`。

验证：访问 `https://oa.yourdomain.com/api/customers`，未登录时返回 JSON `401` 即表示 API 已进入 Laravel。

## 五、Nginx 路由

```nginx
location ~ ^/(api(?:/|$)|up$) {
    try_files $uri $uri/ /index.php?$query_string;
}

location / {
    try_files $uri $uri/ /index.html;
}
```

前端 `public/config.js` 固定使用同源 API：

```js
window.__YIMAI_API_BASE__ = '/api'
```

## 六、初始账号

首次安装时由安装向导创建超管账号并要求设置至少 12 位密码。生产环境不创建固定演示账号；如需本地 Seeder 演示数据，必须显式设置 `DEMO_PASSWORD`。

### 两种发行包的区别

| 文件 | 用途 | vendor | install.php |
|------|------|--------|-------------|
| `yimai-workbench-v<版本>.zip` / `latest.zip` | 已有站点在线升级 | 不含（保留服务器现有依赖） | 不含 |
| `yimai-workbench-installer-v<版本>.zip` | 全新宝塔服务器首次安装 | 包含生产依赖 | 包含，安装后自动删除并锁定 |

## 七、日常更新

### 推荐流程：提交仓库后后台更新

1. 本地修改代码并完成 `pnpm exec vue-tsc --noEmit && pnpm build`。
2. 提交并推送到 `main`，GitHub Actions 自动构建安装包，并创建 GitHub `auto-latest` / `v<版本>` 与 Gitee `v<版本>` Release。
3. 在后台「版本更新」点击「检查更新」，确认远端提交后点击「立即更新」。
4. 服务器下载已构建的 Release 包，只替换应用代码和前端资源，保留线上 `.env`、`storage`、`vendor` 和数据库。
5. 更新脚本执行 `php artisan migrate --force` 和 `php artisan optimize:clear`，完成后自动刷新页面。

发行包命名：版本号取自 `CHANGELOG.md` 首个版本标题（如 `v3.1.8`），安装包为 `yimai-workbench-v3.1.8.zip`，并额外生成 `yimai-workbench-latest.zip` 供 `update.sh` 固定名下载；Release 标题/备注含版本号与更新日志。

Gitee Release 自动发布需要在 GitHub 仓库配置 Actions Secret：`GITEE_TOKEN`。Token 至少需要仓库 Release 的创建和上传权限。Token 只用于创建发行包，不写入代码。服务器更新脚本优先读取 Gitee 最新 Release 中的 `yimai-workbench-latest.zip`，Gitee 暂时不可用时回退到 GitHub 的 `auto-latest` 包。

配置 Secret：GitHub 仓库 → Settings → Secrets and variables → Actions → New repository secret，名称填 `GITEE_TOKEN`。配置后重新推送一次 `main`，或在 Actions 中手动运行工作流，Gitee 会出现对应 Release 和发布包。

如果没有配置 `GITEE_TOKEN`，工作流会跳过 Gitee 发布（不影响 GitHub 发行包），服务器会自动回退到 GitHub `auto-latest`。

更新脚本位于安装目录 `app/update.sh`，会自动以自身目录为站点根。生产服务器不安装 Node.js、不运行前端构建，也不通过 Web 请求直接执行任意 Shell 命令。

每次更新前应保留站点和数据库备份。后端迁移应使用 `php artisan migrate --force`，不要使用 `migrate:fresh`。

## 八、常见问题

| 现象 | 处理 |
|------|------|
| install.php 环境检测红叉 | 装对应 PHP 扩展；目录权限改 www 可写 |
| 登录报网络错误 | config.js 的接口地址与实际不符；或后端站点 SSL 未配 |
| KeepYoga 同步失败 | 检查后台“登录账号设置”；数据库配置优先于 `backend/.env`，再查看 Laravel 当日日志 |
| 页面刷新 404 | 前端伪静态未配 try_files index.html |
| 接口 500 | 看 `backend/storage/logs/` 当天日志；多为权限或 .env 配置问题 |
