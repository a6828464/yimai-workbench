# 部署指南（宝塔面板 · 安装包方式）

> 首次安装推荐上传 `yimai-workbench-installer-v<版本>.zip`，访问站点域名会自动进入安装向导；现有站点更新使用普通发行包。
> 微信内打开 H5 分享页必须 HTTPS，请务必配置 SSL 证书

## 一、宝塔环境要求

| 组件 | 版本要求 | 说明 |
|------|----------|------|
| Nginx | 任意近期版本 | Web 服务器 |
| MySQL | 5.7+（推荐 8.0） | 数据库 |
| PHP | **8.4.1+** | 当前锁定依赖要求 PHP ≥8.4.1 |

PHP 必装扩展（软件商店 → PHP → 设置 → 安装扩展）：
`fileinfo` `opcache` `pdo_mysql` `mbstring` `curl` `zip` `zlib` `gd` `bcmath`

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
   - PHP 版本：**8.4 或更高**（必须 ≥8.4.1；本仓现有两台服务器实测跑 8.5，选哪个都行，但要与下面「五、Nginx 路由 → 上传体积上限」里改 php.ini 的版本**保持一致**）
2. 数据库（二选一）：
   - **面板建库**：数据库菜单创建库和用户（推荐），记住库名、用户名、密码
   - 安装向导填有建库权限的账号，让向导自动建库
3. SSL：Let's Encrypt 一键签发 → 开启「强制 HTTPS」
4. 将 `app/backend/storage`、`app/backend/bootstrap/cache` 权限设置为 `www:www`、`775`。
5. 访问 `https://oa.yourdomain.com`，系统自动跳转安装页面，填写数据库、初始超管和可选 KeepYoga 信息。
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

### 上传体积上限（图片素材走请求体后必检）

朋友圈「图片素材」在 v3.7.0 起会把图片压缩后**放进请求体**（`attachments`，≤3 张）。这意味着
请求体从「几 KB 的文本」变成「**几 MB**」，四处体积上限必须同时放得下，任一过小都会在用户点「生成」时直接失败。

**数值**（3 张满配：前端单图 base64 上限 1.2MB × 3 = **3.6MB**，再加提示词文本；**若以后端单张上限 1.5MB 反推则 ≈6.0MB**）：

| 位置 | 缺省值 | 本版要求 | 配小了会怎样 |
|------|--------|----------|--------------|
| **nginx `client_max_body_size`** | **`1m`** | **≥16m** | **传 1 张图就 413**（1 张满配请求体 ≈1.2MB 已超 1m）—— nginx 在到达 PHP 前就拒掉 |
| PHP `post_max_size` | `8M` | ≥16M | 3 张满配 ≈**3.6MB**（占 8M 的 **45%**）；若按**后端**单张上限 1.5MB 反推则 ≈6.0MB（**75%**）—— 两种口径留量都不多，**建议直接留到 16M** |
| PHP `upload_max_filesize` | `2M` | 无需改 | 图片走 **base64 请求体**、不走上传，本项不参与 |
| 后端 `AI_MAX_IMAGE_BYTES` | `1572864`（1.5MB） | 无需改 | 单张解码后超 1.5MB 会 422（中文提示）；前端 1.2MB 上限更严，正常流程不触发 |

> nginx 的 `1m` 缺省值见官方文档 `client_max_body_size`（Default: `1m`）：https://nginx.org/en/docs/http/ngx_http_core_module.html#client_max_body_size
> 命中时 nginx 直接返回 **413**，请求不会进入 Laravel —— 也就是说**后端的中文提示救不了这一层，只能靠前置配置**。

**为什么是 16m 而不是刚好 3.6m**：留出「提示词文本 + JSON 转义膨胀 + 将来把单图上限调大」的余量；
16m 与 PHP 侧建议值对齐，便于一处记忆。

#### 改法（宝塔面板）

1. **nginx**：宝塔 → 软件商店 → Nginx → 设置 → **配置修改**，在 `http { }` 块内加（宝塔默认 `nginx.conf` 通常**已带** `client_max_body_size 50m;`，**先查再加，不要重复配置**）：

   ```nginx
   client_max_body_size 16m;
   ```

   保存后重载：`nginx -s reload`（或面板「服务 → 重载配置」）。
   > ⚠️ 必须放在 `http {}` 内（全局生效），**不要**只写进站点 conf 的某个 `location {}` —— 那样只对该路径生效，容易漏。
2. **PHP**：宝塔 → 软件商店 → **PHP-8.5**（**本站实际运行的版本**，见下方「先确认版本」）→ 设置 → **配置修改**，确认/修改：

   ```ini
   post_max_size = 16M
   ```

   保存后**重启 PHP**（不是重载）才生效。

   > ⚠️ **先确认版本，改错版本改了也不生效。** 一台服务器上常同时装了多个 PHP（本仓两台服务器都装着 8.4 与 8.5）。
   > 站点用的是哪个，看**宝塔 → 网站 → 站点 → 设置 → PHP 版本**（或站点列表页的「PHP 版本」列）。
   > 本仓两台服务器（生产 `oa.nbyimai.com`、测试 `oa.shunan.fun`）**实测站点 PHP 版本均为 8.5**
   > （注意：这与本文档「一、宝塔环境要求」里新装站点选的 **8.4** 不同 —— 8.4 是**新装**要求的下限，
   > 而现有站点跑的是 8.5；两个版本都要满足 ≥8.4.1）。**改 php.ini 时认准站点实际版本那个目录。**

> ⚠️ **两处都要改，只改一处仍会撞另一处。**
> nginx 的 `client_max_body_size` 与 PHP 的 `post_max_size` 谁先生效，取决于请求体是否被前置代理完整读入并回填
> `CONTENT_LENGTH` —— 也就是说**两条链路都可能先拦**：nginx 拦在「请求怎么进来」，PHP 拦在「body 怎么被解析」。
> 只把 nginx 调大、PHP 仍小 → 大请求进得来但 body 被丢；只把 PHP 调大、nginx 仍 1m → 请求根本进不来。
> **两处都 ≥16m/16M 才安全。**

#### 存量站点自查（必做，一条命令）

```bash
nginx -T | grep client_max_body_size
```

- 输出包含 `client_max_body_size 16m;`（或更大，如宝塔默认的 `50m`）→ 通过。
- **无任何输出** → 走的是 nginx 缺省 `1m`，**必须补上**，否则传图即 413。
- 若输出出现在 `server { }` / `location { }` 内而不是 `http { }` 内，需确认它覆盖了本站点。

```bash
# PHP 侧：确认站点实际运行版本的**生效值**（不是 php.ini 里的文件值）
# ⚠️ 直接执行 php -i 得到的是 CLI 版本，可能与站点不同！
#    宝塔里认准站点版本目录，例如本站实测为 8.5：
/www/server/php/85/bin/php -i | grep -E 'post_max_size|upload_max_filesize'
# 或面板「软件商店 → PHP-8.5 → 设置 → 配置修改」里看 post_max_size
```

#### 验收（部署后自测）

浏览器登录后进入「营销工具 → 朋友圈」，**选 3 张照片**（越大越好）点「生成朋友圈」：

- 正常返回文案（或模型的正常报错）→ 体积链路已通。
- 出现 **413** / 页面提示请求过大 → nginx 或 PHP 侧仍偏小，回到上面两步。
- 出现**中文**「图片总体积超出服务器上限…」→ 说明请求已到达 Laravel，是**后端应用层**上限（`AI_MAX_IMAGE_BYTES` / 合计上限），不是部署问题。

> 完整的四层契约与「谁先触发」推导见 `docs/营销工具/vision/limits/上传体积上限契约.md`。

#### 本仓库两台服务器的实测口径（2026-10-09）

> 注：宝塔面板默认安装的 nginx 自带 `client_max_body_size 50m;`（在 `nginx.conf` 的 `http {}` 块内），
> PHP 宝塔默认 `post_max_size = 50M`。**这与 nginx / PHP 自身的出厂缺省（`1m` / `8M`）不同** ——
> 所以「缺省即安全」并不成立：非宝塔环境、或面板配置被改小过的站点仍会 413。以下为本仓实测值：

| 服务器 | `client_max_body_size` | `post_max_size` | 结论 |
|--------|------------------------|-----------------|------|
| 生产 `oa.nbyimai.com`（aliyun-oa） | **50m**（http 块，站点无覆盖） | **50M**（站点跑 PHP **8.5**；8.4 的 php.ini 亦为 50M） | 已满足 16m 要求，**无需改动** |
| 测试 `oa.shunan.fun`（yimaiyoga） | **50m** | **50M**（站点跑 PHP **8.5**） | 已满足，**无需改动** |
| 本机 dev（macOS） | 开发用 `php artisan serve` / vite，无此限制 | **8M**（`upload_max_filesize=2M`） | 本机满配 3 张 ≈3.6MB 已占 45%，够用但接近，压测时留意 |

实测方法（无副作用，可复跑）：向生产站发一个 ~9MB 的 POST，若返回 **401**（未登录）说明请求体被完整接收、
nginx/PHP 都没拦；若返回 **413** 才是被体积上限拒掉。

## 六、初始账号

首次安装时由安装向导创建超管账号并要求设置至少 6 位密码。生产环境不创建固定演示账号；如需本地 Seeder 演示数据，必须显式设置 `DEMO_PASSWORD`。

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
   > **v3.7.0 无新增数据库迁移**，升级到本版**无需**手工执行 `php artisan migrate`（脚本照常执行，无迁移可跑时自动跳过）。
   > 最后两条迁移仍属 v3.6.0；若你的站点尚未升到 v3.6.0，仍需补跑一次。
   > 但 **v3.7.0 有部署配置前置**：图片走请求体后需确认 nginx `client_max_body_size` 与 PHP `post_max_size`
   > 均 ≥16m/16M（见「五、Nginx 路由 → 上传体积上限」一节），否则用户传图会 413。

生产环境需配置 Laravel 调度器，用于执行系统日志、人员操作日志和模型生成记录的保留策略，以及 KeepYoga 双店每日定时增量同步（`ky:autosync`，05:30 自动执行，当天已同步的门店自动跳过，也可在后台「KeepYoga同步」手动触发）：

```cron
* * * * * cd /www/wwwroot/站点目录/backend && php artisan schedule:run >> /dev/null 2>&1
```

> 路径以站点实际部署为准：宝塔站点根目录下的 Laravel 目录为 `backend`（发行包内为 `app/backend`）。
>
> 未配置此计划任务时，`ky:autosync` 每日定时同步与保留策略清理都不会执行，且没有任何报错——手工同步不受影响。排查方式：
>
> 1. 服务器执行 `cd /www/wwwroot/站点目录/backend && php artisan schedule:list`，能列出 `ky:autosync`（每日 05:30）等条目即为正常。
> 2. 后台「系统管理 → 系统日志 → 运行日志」按关键词 `KeepYoga 同步` 过滤，手动同步与系统定时同步（含「当天已同步，跳过」）都会逐条留痕；「KeepYoga同步 → 历史同步批次」的「触发方」列可区分操作人与「系统定时」。

系统日志默认保留 7 天，人员操作日志默认保留 **180 天**（可在日志页面调整，也能设为永久保留）；未配置调度器时，修改保留策略会立即清理一次，但之后不会按日自动清理。

发行包命名：版本号取自 `CHANGELOG.md` 首个版本标题（如 `v3.1.8`），安装包为 `yimai-workbench-v3.1.8.zip`，并额外生成 `yimai-workbench-latest.zip` 供 `update.sh` 固定名下载；Release 标题/备注含版本号与更新日志。

Gitee Release 自动发布需要在 GitHub 仓库配置 Actions Secret：`GITEE_TOKEN`。Token 至少需要仓库 Release 的创建和上传权限。Token 只用于创建发行包，不写入代码。

**在线更新通道（v3.8.0 起，参照《GitHub镜像推送与国内服务器在线更新-参考手册》多通道设计）**：

- 服务器 `update.sh` 按三通道顺序下载：**Gitee `auto-latest`（优先，国内秒级）→ 自建 GitHub 反代 `github.shunan.fun`（兜底，GitHub 被墙但 Cloudflare 可达时救场）→ GitHub 直连（末选）**。
- 每次发布 CI 会生成 `manifest.json`（version / updated / sha256 / commit / notes）随包上传到双平台 `auto-latest` Release；`update.sh` 先拉清单（带 `?t=` 时间戳破 CDN 缓存）、拿到权威 sha256 后再下包强校验。
- 通道记忆：上次下载成功的通道记在站点根 `.update-state.json`，下次优先走同一条（检查与下载同源）；逐通道失败明细会打在更新日志里（`gitee：HTTP 404；github_mirror：超时`），不再是一句「均不可达」。
- 后台「版本更新」页的检查也走同一条 manifest 通道（`GET /system/version`），带 1 小时成功缓存与 24 小时通道记忆；三通道全挂时回退旧的 Gitee/GitHub API 查 HEAD SHA，并展示「上次成功检查」的兜底快照。
- 开发本机推送 GitHub 用 `./sync.sh`（或 `sync.ps1`）：固定 `-c http.proxy=` 清掉本会话代理 + 显式镜像 URL + 三次抖动重试（间隔 20 秒）+ HTTP/1.1 降级，两端 `ls-remote` SHA 一致才算同步完成。

配置 Secret：GitHub 仓库 → Settings → Secrets and variables → Actions → New repository secret，名称填 `GITEE_TOKEN`。配置后重新推送一次 `main`，或在 Actions 中手动运行工作流，Gitee 会出现对应 Release 和发布包。

如果没有配置 `GITEE_TOKEN`，工作流会跳过 Gitee 发布（不影响 GitHub 发行包），服务器会直接走 GitHub 直连 / 反代通道。

更新脚本位于安装目录 `app/update.sh`，会自动以自身目录为站点根。生产服务器不安装 Node.js、不运行前端构建，也不通过 Web 请求直接执行任意 Shell 命令。

**升级回滚点**（v3.1.64 起）：`update.sh` 在覆盖代码之前会先执行
`php artisan backup:snapshot --label=升级前(<时间戳>)` 生成数据库快照，并把当前代码目录
（不含 `vendor` 与 `storage`）打成 tar 存到站点根目录的 `.update-backup/<时间戳>/`（只保留最近 3 份）。
若 `migrate` 或迁移后的结构健康检查失败，脚本会**自动还原代码**并提示用「升级前」快照恢复数据库。
注意：回滚只还原代码，不回退已执行过的迁移。

每次更新前应保留站点和数据库备份。后端迁移应使用 `php artisan migrate --force`，不要使用 `migrate:fresh`。

## 八、常见问题

| 现象 | 处理 |
|------|------|
| install.php 环境检测红叉 | 装对应 PHP 扩展；目录权限改 www 可写 |
| 登录报网络错误 | config.js 的接口地址与实际不符；或后端站点 SSL 未配 |
| KeepYoga 同步失败 | 检查后台“登录账号设置”；数据库配置优先于 `backend/.env`，再查看 Laravel 当日日志 |
| 定时同步没执行 | 服务器 crontab 缺少 `schedule:run`（见「七、日常更新」调度器说明）；缺它时手工同步仍可用，「系统日志 → 运行日志」也不会出现定时留痕 |
| 页面刷新 404 | 前端伪静态未配 try_files index.html |
| 接口 500 | 看 `backend/storage/logs/` 当天日志；多为权限或 .env 配置问题 |
| 「薪酬计算」页打开是空的 / 薪酬档案 0 人 | 未配 `PAYROLL_MASTER_XLSX`（说明见 `backend/.env.example`）。该变量填**仓库外**人员主档 xlsx 的绝对路径，配好后执行 `php artisan db:seed --class=PayrollProfileSeeder`，成功时输出「现有 55 人」。**注意：该文件缺失时 seeder 只打一条 warning 就跳过，不报错也不阻断部署**，所以漏配不会自己暴露 —— 部署后请主动确认薪酬档案数为 55 |
| 薪酬业绩表导入提示「服务器 PHP 缺少 zip 扩展」 | 在宝塔「PHP 设置 → 安装扩展」启用 `php_zip` 后重试。安装包方式升级只跑 `composer dump-autoload`，不跑 `composer install`，扩展不会自动补齐 |
