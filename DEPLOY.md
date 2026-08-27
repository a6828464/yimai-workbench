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

## 二、域名规划（示例，替换成你的主域）

| 用途 | 域名 | 说明 |
|------|------|------|
| 前端 | `oa.yimaiyoga.com` | 工作台 + H5 分享页 |
| 后端 API | `oaapi.yimaiyoga.com` | Laravel 接口 |

国内服务器需 ICP 备案后才能解析。

## 三、上传安装包

1. 宝塔 → 文件 → 上传 `releases/yimai-workbench-xxxx.zip`
2. 解压到 `/www/wwwroot/`，得到：

```
/www/wwwroot/yimai-workbench/
├── backend/      # 后端（含 vendor；安装器仅限首次受控安装）
└── frontend/     # 前端构建产物
```

## 四、后端站点（oaapi.yourdomain.com）

1. 宝塔 → 网站 → 添加站点：
   - 域名：`oaapi.yourdomain.com`
   - 根目录：`/www/wwwroot/yimai-workbench/backend/public`
   - PHP 版本：8.2+
2. 数据库（二选一）：
   - **面板建库**：数据库菜单创建 `yimai` 库+用户（推荐）
   - 安装向导里填 root 也可自动建库
3. SSL：Let's Encrypt 一键签发 → 开启「强制 HTTPS」
4. 首次部署应在受限网络中完成数据库配置和迁移；安装完成后立即删除 `backend/public/install.php`，禁止将安装器长期暴露在公网。
5. 不要使用 `migrate:fresh` 初始化已有生产数据库；后续发布只执行 `php artisan migrate --force`。

验证：访问 `https://oaapi.yourdomain.com/up` 返回 ok 即正常。

## 五、前端站点（oa.yourdomain.com）

1. 宝塔 → 网站 → 添加**静态**站点，根目录：`/www/wwwroot/yimai-workbench/frontend`
2. 伪静态规则：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

3. SSL 同上配置并强制 HTTPS
4. **接口地址对接**（二选一）：
   - 双域名（默认）：编辑 `frontend/config.js`：
     ```js
     window.__YIMAI_API_BASE__ = 'https://oaapi.yourdomain.com/api'
     ```
   - 单域名反代：保持 `/api`，并在前端站点 nginx 配置加反向代理：
     ```nginx
     location ^~ /api/ {
         proxy_pass https://oaapi.yourdomain.com/api/;
         proxy_set_header Host oaapi.yourdomain.com;
         proxy_ssl_server_name on;
     }
     ```
   改完刷新浏览器即生效，无需重新构建。

## 六、测试账号（仅本地演示）

密码统一 `yimai123`，禁止直接用于公网生产环境；生产安装后应立即轮换。

| 账号 | 角色 |
|------|------|
| `nange` | 超管 |
| `wangdz` / `lidz` | 店长（绿地店 / 东部店） |
| `huangmin` / `tingting` | 老师 |
| `ayu` | 新媒体 |

## 七、日常更新

- **后端**：使用受控发布流程覆盖代码，保留 `.env`、`storage` 和 `bootstrap/cache`，然后执行 `php artisan migrate --force`；不要依赖 Web 请求直接覆盖生产代码。
- **前端**：重新打包取 frontend 目录整体覆盖（config.js 记得保留或重配）

## 八、常见问题

| 现象 | 处理 |
|------|------|
| install.php 环境检测红叉 | 装对应 PHP 扩展；目录权限改 www 可写 |
| 登录报网络错误 | config.js 的接口地址与实际不符；或后端站点 SSL 未配 |
| KeepYoga 同步失败 | 检查后台“登录账号设置”；数据库配置优先于 `backend/.env`，再查看 Laravel 当日日志 |
| 页面刷新 404 | 前端伪静态未配 try_files index.html |
| 接口 500 | 看 `backend/storage/logs/` 当天日志；多为权限或 .env 配置问题 |
