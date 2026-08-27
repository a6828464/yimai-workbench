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

- **后端**：使用受控发布流程覆盖代码，保留 `.env`、`storage` 和 `bootstrap/cache`，然后执行 `php artisan migrate --force`；不要依赖 Web 请求直接覆盖生产代码。
- **前端**：重新构建并覆盖 `app/public/index.html`、`app/public/assets/`；保留 `index.php` 和 `.env`

## 八、常见问题

| 现象 | 处理 |
|------|------|
| install.php 环境检测红叉 | 装对应 PHP 扩展；目录权限改 www 可写 |
| 登录报网络错误 | config.js 的接口地址与实际不符；或后端站点 SSL 未配 |
| KeepYoga 同步失败 | 检查后台“登录账号设置”；数据库配置优先于 `backend/.env`，再查看 Laravel 当日日志 |
| 页面刷新 404 | 前端伪静态未配 try_files index.html |
| 接口 500 | 看 `backend/storage/logs/` 当天日志；多为权限或 .env 配置问题 |
