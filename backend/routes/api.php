<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BodyTestReportController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\KyController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MarketingFavoriteController;
use App\Http\Controllers\MiniappConfigController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PostClassReviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TrainingPlanController;
use App\Http\Controllers\WxSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 一麦工作台 API 路由
|--------------------------------------------------------------------------
| 业务逻辑在 app/Http/Controllers 下的各域控制器中；
| 全局业务辅助函数在 app/Support/helpers.php（composer autoload.files 加载）。
*/

/*
|--------------------------------------------------------------------------
| 全局路由参数约束：{id} 只接受纯数字，且不超过 18 位
|--------------------------------------------------------------------------
| 为什么必须有：所有 `{id}` 控制器的形参都是 `int $id`（照抄既有先例）。PHP 会把纯数字
| 字符串强转成 int，但「非数字」（abc / 12abc）与「超出 int64 的纯数字」（19 位以上，
| 如 9999999999999999999 > PHP_INT_MAX）都会抛 TypeError → **500**。这是本该 404 的输入，
| 却会在日志里刷真实异常（RV4 R1）。
|
| 为什么是 `{1,18}` 而不是 `[0-9]+`：`[0-9]+` 只挡字母；19 位以上纯数字仍会强转失败，
| 依旧 500。18 位上限（≤1e18）远在 int64 之内，而自增 id 距 1e18 还有若干数量级。
|
| 口径（全仓一致，不是只修营销两条）：畸形 id 不匹配任何 `{id}` 路由 → **404**；
| 合法数字 id 的语义完全不变 —— `DELETE /marketing/{history,favorites}/{id}` 对
| 「他人的 id / 不存在的 id」依旧一律 200 `{ok:true}`。防枚举红线未被触碰：
| 畸形 id 不可能对应任何人的记录，404 不泄露任何存在性。
|
| ⚠️ 路由自带的 where() 会**覆盖**这里的全局约束（Router::addWhereClausesToRoute 是
| array_merge(全局, 路由级)）—— 所以不要再给 `{id}` 单独写 `->whereNumber('id')`
| （`[0-9]+` 会放过 19 位纯数字，把 500 拽回来）。
*/
Route::pattern('id', '[0-9]{1,18}');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
// 微信授权登录（小程序 P2 合规硬门）。限流比账密登录更紧：
// 每次调用都会向微信发一次 code2session，被刷等于用我们自己的 appid 打微信接口。
Route::post('/auth/wx-login', [AuthController::class, 'wxLogin'])->middleware('throttle:wx-login');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| 公开接口（免登录）：小程序「到店指引」配置
|--------------------------------------------------------------------------
| 为什么公开：这份配置是给**顾客**看的（顾问会把 `pkg-public/guide/guide` 的链接直接发
| 给新客），未登录 / 未授权微信时必须能读，否则顾客点开只有空白。
|
| 为什么安全：只读、无 PII（门店地址 / 电话 / 坐标 / 图文引导），写操作在下面的登录组里
| 且控制器首行 requireSuper。仍挂 throttle：公开接口不设限流就是一张免费压测门票。
| 响应：`{ code:0, data:{ venues:[两家店], updatedAt } }`；列的值为 NULL 时回落内置默认值。
*/
Route::get('/miniapp/config', [MiniappConfigController::class, 'show'])->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    // 小程序「到店指引」保存（控制器首行 requireSuper；门店键固定两家，拒绝增删门店）
    Route::put('/miniapp/config', [MiniappConfigController::class, 'update']);
    // 微信自助绑定：本人读自己的 openid / 把当前微信绑到自己账号（必须已登录，否则等于开放注册）
    Route::get('/auth/wx-identity', [AuthController::class, 'wxIdentity']);
    Route::put('/auth/wx-self-bind', [AuthController::class, 'wxSelfBind'])->middleware('throttle:wx-bind');
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // ---------- 微信订阅消息（模板通知）：授权状态 / 记授权 / 自测发一条 ----------
    // 口径见 docs/miniapp-migration/11 工作台四板块重构方案 §6.3。
    // 只要求登录：每个人都有自己的一份授权账本，不涉及他人数据。
    Route::get('/wx/subscriptions', [WxSubscriptionController::class, 'index']);
    Route::post('/wx/subscriptions', [WxSubscriptionController::class, 'store']);
    // 自测：每次都会真发一条微信消息，按「接口成本」限流（一人 1 分钟最多 6 次够用）
    Route::post('/wx/subscriptions/test', [WxSubscriptionController::class, 'test'])->middleware('throttle:wx-test');

    // ---------- 个人中心（账号自助资料 / 密码 / 营销人设，按用户持久化，多设备共用） ----------
    Route::get('/my/profile', [ProfileController::class, 'myProfile']);
    Route::put('/my/profile', [ProfileController::class, 'updateMyProfile']);
    Route::put('/my/password', [ProfileController::class, 'updateMyPassword']);

    // ---------- 营销生成历史（按账号，每平台保留最近 300 条） ----------
    Route::get('/marketing/history', [MarketingController::class, 'history']);
    Route::post('/marketing/history', [MarketingController::class, 'saveHistory']);
    Route::delete('/marketing/history/{id}', [MarketingController::class, 'deleteHistory']);

    // ---------- 营销工具收藏（按账号持久化，跨设备同步；超限拒绝新增，不做静默裁剪） ----------
    Route::get('/marketing/favorites', [MarketingFavoriteController::class, 'index']);
    Route::post('/marketing/favorites', [MarketingFavoriteController::class, 'store']);
    Route::delete('/marketing/favorites/{id}', [MarketingFavoriteController::class, 'destroy']);

    // ---------- 会员/客户（options/list-counts/list-ids 必须先于 /customers/{id} 注册） ----------
    Route::get('/customers/options', [CustomerController::class, 'options']);
    Route::get('/customers/list-counts', [CustomerController::class, 'listCounts']);
    // 七个清单的会员 id 集合一次返回（取代前端「按清单 × 分页 size=5000」扫全表只为拿 id）。
    // 数据源是 memberListIds()（自带 120 秒缓存），与 /customers?list=<清单名> 同源同范围。
    Route::get('/customers/list-ids', [CustomerController::class, 'listIds']);
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::patch('/customers/{id}', [CustomerController::class, 'update']);
    Route::get('/customers/{id}', [CustomerController::class, 'show']);
    Route::get('/customers/{id}/renewal-evaluation', [CustomerController::class, 'renewalEvaluationShow']);
    Route::put('/customers/{id}/renewal-evaluation', [CustomerController::class, 'renewalEvaluationStore']);
    Route::get('/new-members/cultivation', [CustomerController::class, 'newMemberCultivation']);
    Route::get('/member-rules', [CustomerController::class, 'memberRulesShow']);
    Route::put('/member-rules', [CustomerController::class, 'memberRulesUpdate']);

    // ---------- 留资 ----------
    Route::get('/leads', [LeadController::class, 'index']);
    Route::get('/leads/check', [LeadController::class, 'check']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::patch('/leads/{id}', [LeadController::class, 'update']);
    Route::delete('/leads/{id}', [LeadController::class, 'destroy']);
    Route::get('/leads/{id}/history', [LeadController::class, 'history']);

    // ---------- 任务 / 审批 ----------
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{id}', [TaskController::class, 'update']);
    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals', [ApprovalController::class, 'store']);
    Route::post('/approvals/{id}/decide', [ApprovalController::class, 'decide']);

    // ---------- 审计 ----------
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // ---------- KeepYoga 只读代理（阶段1：凭据仅存服务端） ----------
    Route::post('/ky/session', [KyController::class, 'session']);
    Route::post('/ky/call', [KyController::class, 'call']);
    Route::get('/ky/pending-contracts', [KyController::class, 'pendingContracts']);
    Route::get('/ky/overview', [KyController::class, 'overview']);
    Route::get('/ky/config', [KyController::class, 'configShow']);
    Route::put('/ky/config', [KyController::class, 'configUpdate']);
    Route::post('/ky/import', [KyController::class, 'import']);

    // ---------- AI 大模型代理（OpenAI 兼容协议，解决浏览器跨域） ----------
    Route::post('/ai/chat', [AiController::class, 'chat'])->middleware('throttle:30,1');
    Route::post('/model-generations/fallback', [AiController::class, 'fallbackRecord']);
    Route::get('/model-generations', [AiController::class, 'generationsIndex']);
    Route::post('/ai/models', [AiController::class, 'models'])->middleware('throttle:10,1');
    Route::post('/ai/test', [AiController::class, 'test'])->middleware('throttle:10,1');
    Route::get('/ai/config', [AiController::class, 'configShow']);
    Route::put('/ai/config', [AiController::class, 'configUpdate']);

    // ---------- 人员管理（仅超管） ----------
    Route::get('/accounts', [AccountController::class, 'index']);
    // 身份归一：查未映射姓名 / 认领名字 / 全量回填 / 合并账号。
    // 全部放在 /accounts/{key} 之前，避免 staff-mapping 被当成 key 参数吃掉。
    Route::get('/accounts/staff-mapping', [AccountController::class, 'staffMapping']);
    Route::post('/accounts/staff-mapping/assign', [AccountController::class, 'assignStaffName']);
    Route::post('/accounts/staff-mapping/repair', [AccountController::class, 'repairStaffMapping']);
    Route::post('/accounts/staff-mapping/merge', [AccountController::class, 'mergeStaffAccount']);
    Route::put('/accounts/{key}/aliases', [AccountController::class, 'updateAliases']);
    // 归属下拉的统一数据源（所有登录用户可读：录留资/会员时要选人）
    Route::get('/staff/options', [AccountController::class, 'staffOptions']);
    // 微信身份绑定（小程序授权登录的唯一入口；须放在 /accounts/{key} 之前）
    Route::get('/accounts/{key}/wx-binding', [AccountController::class, 'wxBinding']);
    Route::put('/accounts/{key}/wx-binding', [AccountController::class, 'bindWx']);
    Route::delete('/accounts/{key}/wx-binding', [AccountController::class, 'unbindWx']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::patch('/accounts/{key}', [AccountController::class, 'update']);

    // ---------- 训练计划（按人持久化，逐条读写） ----------
    // 注意：/training-plans/bulk 必须注册在 /training-plans/{id} 之前，否则会被当作 id 捕获
    // `{id}` 的数字约束由文件头的 Route::pattern 统一提供（原先这里各写一份 ->whereNumber('id')
    // 是 `[0-9]+`，会覆盖全局约束、放过 19 位以上纯数字把 TypeError 500 拽回来，故删除）
    Route::get('/training-plans', [TrainingPlanController::class, 'index']);
    Route::post('/training-plans', [TrainingPlanController::class, 'store']);
    Route::put('/training-plans/bulk', [TrainingPlanController::class, 'bulkSave']);
    Route::put('/training-plans/{id}', [TrainingPlanController::class, 'update']);
    Route::delete('/training-plans/{id}', [TrainingPlanController::class, 'destroy']);

    // ---------- 体测报告（门店智能魔镜报告解析与归档） ----------
    Route::post('/body-test-reports/parse', [BodyTestReportController::class, 'parse'])->middleware('throttle:30,1');
    Route::get('/body-test-reports', [BodyTestReportController::class, 'index']);
    Route::post('/body-test-reports', [BodyTestReportController::class, 'store']);
    Route::get('/body-test-reports/{id}', [BodyTestReportController::class, 'show']);

    // ---------- 课后分析 ＋ 训练方向（P0：体验课价值交付凭证 + 成交归因起点） ----------
    Route::get('/post-class-reviews/catalog', [PostClassReviewController::class, 'catalog']);
    Route::get('/post-class-reviews/candidates', [PostClassReviewController::class, 'candidates']);
    Route::get('/post-class-reviews/pending-count', [PostClassReviewController::class, 'pendingCount']);
    Route::post('/post-class-reviews/preview', [PostClassReviewController::class, 'preview']);
    Route::get('/post-class-reviews', [PostClassReviewController::class, 'index']);
    Route::post('/post-class-reviews', [PostClassReviewController::class, 'store']);
    Route::get('/post-class-reviews/{id}', [PostClassReviewController::class, 'show']);
    Route::put('/post-class-reviews/{id}', [PostClassReviewController::class, 'update']);
    Route::delete('/post-class-reviews/{id}', [PostClassReviewController::class, 'destroy']);
    Route::post('/post-class-reviews/{id}/confirm', [PostClassReviewController::class, 'confirm']);
    Route::post('/post-class-reviews/{id}/to-plan', [PostClassReviewController::class, 'toPlan']);
    Route::post('/post-class-reviews/{id}/share', [PostClassReviewController::class, 'share']);

    // Legacy browser import (retained only for old clients)
    Route::post('/customers/import', [KyController::class, 'legacyImport']);

    // ---------- 同步任务批次（index/show 供异步同步轮询） ----------
    Route::get('/sync-jobs', [SyncJobController::class, 'index']);
    Route::get('/sync-jobs/{job}', [SyncJobController::class, 'show']);
    Route::get('/sync-jobs/{job}/artifacts', [SyncJobController::class, 'artifacts']);
    Route::get('/sync-artifacts/{artifact}/download', [SyncJobController::class, 'downloadArtifact']);

    // ---------- 数据备份（仅超管：配置/立即备份/恢复/列表/下载/校验，控制器内 requireSuper） ----------
    Route::get('/backup/config', [BackupController::class, 'configShow']);
    Route::put('/backup/config', [BackupController::class, 'configUpdate']);
    Route::post('/backup/test-connection', [BackupController::class, 'testConnection']);
    Route::post('/backup/run', [BackupController::class, 'run']);
    Route::get('/backup/files', [BackupController::class, 'files']);
    Route::get('/backup/download', [BackupController::class, 'download']);
    Route::delete('/backup/file', [BackupController::class, 'deleteFile']);
    Route::post('/backup/restore', [BackupController::class, 'restore']);
    Route::post('/backup/verify', [BackupController::class, 'verify']);

    // ---------- 今日工作台（快照 / 汇总 / 待办闭环） ----------
    Route::get('/today/snapshot', [TodayController::class, 'snapshotShow']);
    Route::put('/today/snapshot', [TodayController::class, 'snapshotUpdate']);
    Route::get('/today/summary', [TodayController::class, 'summary']);
    Route::get('/today/teacher-overview', [TodayController::class, 'teacherOverview']);
    Route::get('/today/followups', [TodayController::class, 'followups']);
    Route::get('/today/alerts', [TodayController::class, 'alerts']);
    Route::get('/today/todo', [TodayController::class, 'todo']);
    Route::post('/today/todo/action', [TodayController::class, 'todoAction']);

    // ---------- 经营看板指标（基于现有业务数据实时计算） ----------
    Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);
    Route::get('/analytics/trends', [AnalyticsController::class, 'trends']);
    Route::get('/analytics/channels', [AnalyticsController::class, 'channels']);
    Route::get('/analytics/platforms', [AnalyticsController::class, 'platforms']);
    // 战略规划象限② 新增：S11 到店频次×续费率曲线 / S12 未耗课余额分桶（卡数口径）/ S14 体验卡→会员卡转化
    Route::get('/analytics/attendance-renewal-curve', [AnalyticsController::class, 'attendanceRenewalCurve']);
    Route::get('/analytics/asset-buckets', [AnalyticsController::class, 'assetBuckets']);
    Route::get('/analytics/trial-conversion', [AnalyticsController::class, 'trialConversion']);

    // ---------- 通知中心 ----------
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/notifications/{key}/read', [NotificationController::class, 'readOne']);

    // ---------- 对外发布（H5 分享快照） ----------
    Route::post('/shares/publish', [ShareController::class, 'publish']);
    // 本人当前分享的权威状态：前端开关/链接以服务端为准
    Route::get('/shares/current', [ShareController::class, 'current']);
    // 停用对外分享：关闭分享开关必须落到服务端，公开接口据此返回 404
    Route::post('/shares/disable', [ShareController::class, 'disable']);
    // 归属孤儿行（created_by 查无此人 / 缺 user_id）的一次性清理与重新归属 —— 仅超管
    Route::get('/shares/orphans', [ShareController::class, 'orphans']);
    Route::post('/shares/orphans/repair', [ShareController::class, 'repairOrphan']);

    // ---------- 版本更新（仅超管） ----------
    Route::get('/system/version', [SystemController::class, 'version']);
    Route::get('/system/logs', [SystemController::class, 'logs']);
    Route::get('/system/retention', [SystemController::class, 'retentionShow']);
    Route::put('/system/retention', [SystemController::class, 'retentionUpdate']);
    // 注意：/system/changelog 不在登录组里，见文件末尾「公开接口」——小程序未登录也要看版本说明
    Route::post('/system/update', [SystemController::class, 'update']);

    // ---------- 薪酬计算（仅超管：控制器内每个端点首行 requireSuper，与 BackupController 同模式） ----------
    // 身份标签枚举的唯一下发点；前端必须从这里取，禁止在 payroll/ 下再写一份硬编码列表
    Route::get('/payroll/roles', [PayrollController::class, 'roles']);
    Route::get('/payroll/hours', [PayrollController::class, 'hours']);
    Route::get('/payroll/calculate', [PayrollController::class, 'calculate']);
    // copy-from-previous 必须注册在同前缀的其它动词之前，避免路径被参数化规则吃掉
    Route::post('/payroll/monthly-inputs/copy-from-previous', [PayrollController::class, 'monthlyInputsCopyFromPrevious']);
    Route::get('/payroll/monthly-inputs', [PayrollController::class, 'monthlyInputsShow']);
    Route::put('/payroll/monthly-inputs', [PayrollController::class, 'monthlyInputsUpdate']);
    Route::get('/payroll/profiles', [PayrollController::class, 'profiles']);
    // 新增建档 + 系统已知信息预填。prefill 必须注册在 {userId} 之前，
    // 否则会被参数化规则当成 userId 吃掉（与上面 copy-from-previous 同一个坑）
    Route::post('/payroll/profiles/prefill', [PayrollController::class, 'prefillProfiles']);
    Route::post('/payroll/profiles', [PayrollController::class, 'storeProfile']);
    // 人员主档导入：界面直接上传主档 xlsx（支持 dryRun 预览），复用 seeder 的
    // 幂等落库逻辑 —— 服务器上不再需要手工放置仓库外文件 + 配环境变量
    Route::post('/payroll/profiles/import-master', [PayrollController::class, 'importMaster']);
    Route::put('/payroll/profiles/{userId}', [PayrollController::class, 'updateProfile']);
    Route::get('/payroll/performance', [PayrollController::class, 'performanceIndex']);
    Route::post('/payroll/performance/preview', [PayrollController::class, 'performancePreview']);
    Route::post('/payroll/performance/commit', [PayrollController::class, 'performanceCommit']);
});

// ---------- 公开接口（免登录，H5 分享页用） ----------
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('/training/{code}', [PublicShareController::class, 'training']);
    Route::get('/post-class/{code}', [PublicShareController::class, 'postClass']);
    Route::get('/sales/{token}', [PublicShareController::class, 'sales']);
});

/*
|--------------------------------------------------------------------------
| 公开接口（免登录）：更新日志
|--------------------------------------------------------------------------
| 为什么公开：小程序「我的 → 关于」需要在**未登录**（未授权微信登录、token 过期）时也能
| 显示版本号与最近几条更新内容，否则用户点进来只能看到空白或「请先登录」，等于这个菜单
| 在游客态下不可用（v3.11 馆主需求）。
|
| 为什么可以公开：这个接口只读仓库里随包发布的 CHANGELOG.md 的**标题**（版本/日期/一句话），
| 没有任何业务数据，也不接受任何写参数；响应里的 `latest`（远端发行说明）只在登录后才去拉，
| 免登录调用返回空形状，避免这个接口被当成访问外部站点的匿名放大器。
| 仍然保留限流：公开接口不设限流就是一张免费的压测门票。
*/
Route::get('/system/changelog', [SystemController::class, 'changelog'])->middleware('throttle:60,1');
