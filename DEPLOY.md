# 宝塔面板部署指南（阶段1测试环境）

> 架构：前端静态站（Vue3 构建产物）+ 后端 API 站（Laravel 12 + MySQL）
> 微信内打开 H5 分享页必须 HTTPS，请务必配置 SSL 证书

## 一、域名规划（示例，替换成你的主域）

| 用途 | 域名 | 说明 |
|------|------|------|
| 前端 | `oa.yimaiyoga.com` | 工作台 + H5 分享页 |
| 后端 | `oaapi.yimaiyoga.com` | Laravel API |

国内服务器需完成 ICP 备案后才能解析使用。

## 二、宝塔环境要求

- Nginx 任意近期版本
- MySQL 5.7+（推荐 8.0）
- PHP **8.2 ~ 8.4**（Laravel 12 要求 ≥8.2），安装扩展：`fileinfo opcache pdo_mysql mbstring curl zip gd bcmath`
- Composer（宝塔 PHP 设置里启用）

## 三、后端部署（oaapi.yourdomain.com）

```bash
# 1. 拉代码
cd /www/wwwroot
git clone <仓库地址> yimai && cd yimai/backend

# 2. 配置
cp .env.example .env    # 填好数据库/KeepYoga凭据，改成实际域名
php artisan key:generate

# 3. 依赖与初始化
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate:fresh --seed --force   # 首次；已有数据用 migrate --force

# 4. 目录权限（宝塔网站用户为 www）
chown -R www:www storage bootstrap/cache
```

宝塔 → 网站 → 添加站点：
- 域名：`oaapi.yourdomain.com`，根目录指向 **`yimai/backend/public`**
- PHP 版本选 8.2+；伪静态规则填 Laravel：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

- SSL：Let's Encrypt 一键签发，开启「强制HTTPS」

验证：浏览器打开 `https://oaapi.yourdomain.com/api/app-setting-rules`（401 JSON 即正常）或 `GET /up` 返回 ok。

## 四、前端部署（oa.yourdomain.com）

本地构建后上传 dist（或服务器上装 Node 构建）：

```bash
cd admin-web
# .env.production 已入库：VITE_API_BASE=/api 时走单域名反代；
# 双域名部署则改为 VITE_API_BASE=https://oaapi.yourdomain.com/api
pnpm install && pnpm build
# 产物在 admin-web/dist
```

宝塔 → 添加静态站点，根目录指向 `admin-web/dist`，伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

同样配置 SSL + 强制 HTTPS。

### 单域名方案（可选，免 CORS 更干净）

只开一个站点 `oa.yourdomain.com`：
- 根目录指向前端 dist
- 反向代理 `/api` → 后端站点域名
- `.env.production` 保持 `VITE_API_BASE = /api`

```nginx
location ^~ /api/ {
    proxy_pass https://oaapi.yourdomain.com/api/;
    proxy_set_header Host oaapi.yourdomain.com;
    proxy_ssl_server_name on;
}
location / { try_files $uri $uri/ /index.html; }
```

## 五、测试账号

密码统一 `yimai123`：超管 `nange` / 店长 `wangdz`(绿地) `lidz`(东部) / 老师 `huangmin` `tingting` / 新媒体 `ayu`

## 六、日常更新

后台「系统管理 → 版本更新」一键更新仅对**后端代码**生效。
前端更新流程：本地 `pnpm build` → 覆盖上传 dist（或在服务器 git pull 后重新构建）。

## 七、常见问题

| 现象 | 处理 |
|------|------|
| 登录报网络错误 | 检查 `.env` 的 APP_URL 与 SANCTUM_STATEFUL_DOMAINS 是否匹配实际域名 |
| KeepYoga 同步 502 | `.env` 缺 KY_PHONE/KY_PASSWORD，改完 `php artisan config:clear` |
| 页面刷新 404 | 前端伪静态未配 try_files index.html |
| 接口 500 | `storage/logs/laravel.log` 看日志；多为目录权限或 .env 未配 |
