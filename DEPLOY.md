# 部署指南（宝塔面板 · 安装包方式）

> 推荐流程：本地跑 `./make-release.sh` 生成安装包 → 宝塔上传解压 → 配置站点和数据库 → 执行受控迁移
> 微信内打开 H5 分享页必须 HTTPS，请务必配置 SSL 证书

## 一、宝塔环境要求

| 组件 | 版本要求 | 说明 |
|------|----------|------|
| Nginx | 任意近期版本 | Web 服务器 |
| MySQL | 5.7+（推荐 8.0） | 数据库 |
| PHP | **8.2 ~ 8.4** | Laravel 12 要求 ≥8.2 |

PHP 必装扩展（软件商店 → PHP → 设置 → 安装扩展）：
`fileinfo` `opcache` `pdo_mysql` `mbstring` `curl` `zip` `gd` `bcmath`

- ❌ 不需要装 Composer（安装包已内置 vendor）
- ❌ 不需要装 Node.js（前端已构建成静态文件）

## 二、域名规划

统一使用一个域名，例如 `oa.nbyimai.com`：

- `/`、`/assets/*`：Vue 工作台与 H5 分享页
- `/api/*`：Laravel API
- `/up`：Laravel 健康检查

国内服务器需 ICP 备案后才能解析。

## 三、上传安装包

1. 宝塔 → 文件 → 上传 `releases/yimai-workbench-xxxx.zip`
2. 解压到 `/www/wwwroot/`，得到：

```
/www/wwwroot/yimai-workbench/
└── app/          # Laravel 应用；Vue 构建产物已合入 app/public
```

## 四、统一站点（oa.yourdomain.com）

1. 宝塔 → 网站 → 添加站点：
   - 域名：`oa.yourdomain.com`
   - 根目录：`/www/wwwroot/yimai-workbench/app/public`
   - PHP 版本：8.2+
2. 数据库（二选一）：
   - **面板建库**：数据库菜单创建 `yimai` 库+用户（推荐）
   - 安装向导里填 root 也可自动建库
3. SSL：Let's Encrypt 一键签发 → 开启「强制 HTTPS」
4. 配置 `app/.env`，然后执行 `php artisan migrate --force` 并创建初始超管账号。
5. 不要使用 `migrate:fresh` 初始化已有生产数据库；后续发布只执行 `php artisan migrate --force`。

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

## 七、日常更新

### 推荐流程：提交仓库后后台更新

1. 本地修改代码并完成 `pnpm exec vue-tsc --noEmit && pnpm build`。
2. 提交并推送到 `main`，GitHub Actions 自动构建 `auto-latest` 包，并创建同一提交对应的 Gitee Release。
3. 在后台「版本更新」点击「检查更新」，确认远端提交后点击「立即更新」。
4. 服务器下载已构建的 Release 包，只替换应用代码和前端资源，保留线上 `.env`、`storage`、`vendor` 和数据库。
5. 更新脚本执行 `php artisan migrate --force` 和 `php artisan optimize:clear`，完成后自动刷新页面。

Gitee Release 自动发布需要在 GitHub 仓库配置 Actions Secret：`GITEE_TOKEN`。Token 至少需要仓库 Release 的创建和上传权限。Token 只用于创建发行包，不写入代码。服务器更新脚本优先读取 Gitee 最新 Release 中的 `yimai-workbench-latest.zip`，Gitee 暂时不可用时回退到 GitHub 的 `auto-latest` 包。

配置 Secret：GitHub 仓库 → Settings → Secrets and variables → Actions → New repository secret，名称填 `GITEE_TOKEN`。配置后重新推送一次 `main`，或在 Actions 中手动运行工作流，Gitee 会出现对应 Release 和发布包。

如果没有配置 `GITEE_TOKEN`，工作流不会伪造 Gitee 发行包，服务器会自动回退到 GitHub `auto-latest`。这是当前 Gitee 页面没有发行包的直接原因，不是构建失败。

更新脚本位于仓库根目录 `update.sh`，服务器路径为 `/www/wwwroot/oa.nbyimai.com/update.sh`。生产服务器不安装 Node.js、不运行前端构建，也不通过 Web 请求直接执行任意 Shell 命令。

每次更新前应保留站点和数据库备份。后端迁移应使用 `php artisan migrate --force`，不要使用 `migrate:fresh`。

## 八、常见问题

| 现象 | 处理 |
|------|------|
| install.php 环境检测红叉 | 装对应 PHP 扩展；目录权限改 www 可写 |
| 登录报网络错误 | config.js 的接口地址与实际不符；或后端站点 SSL 未配 |
| KeepYoga 同步失败 | 检查后台“登录账号设置”；数据库配置优先于 `backend/.env`，再查看 Laravel 当日日志 |
| 页面刷新 404 | 前端伪静态未配 try_files index.html |
| 接口 500 | 看 `backend/storage/logs/` 当天日志；多为权限或 .env 配置问题 |
