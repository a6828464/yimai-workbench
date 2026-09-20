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
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostClassReviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TrainingPlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 一麦工作台 API 路由
|--------------------------------------------------------------------------
| 业务逻辑在 app/Http/Controllers 下的各域控制器中；
| 全局业务辅助函数在 app/Support/helpers.php（composer autoload.files 加载）。
*/

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // ---------- 个人中心（账号自助资料 / 密码 / 营销人设，按用户持久化，多设备共用） ----------
    Route::get('/my/profile', [ProfileController::class, 'myProfile']);
    Route::put('/my/profile', [ProfileController::class, 'updateMyProfile']);
    Route::put('/my/password', [ProfileController::class, 'updateMyPassword']);

    // ---------- 营销生成历史（按账号，每平台保留最近 300 条） ----------
    Route::get('/marketing/history', [MarketingController::class, 'history']);
    Route::post('/marketing/history', [MarketingController::class, 'saveHistory']);
    Route::delete('/marketing/history/{id}', [MarketingController::class, 'deleteHistory']);

    // ---------- 会员/客户（options/list-counts 必须先于 /customers/{id} 注册） ----------
    Route::get('/customers/options', [CustomerController::class, 'options']);
    Route::get('/customers/list-counts', [CustomerController::class, 'listCounts']);
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
    // 人员归属映射：查未映射的姓名 / 维护别名。放在 /accounts/{key} 之前，避免被当参数吃掉
    Route::get('/accounts/staff-mapping', [AccountController::class, 'staffMapping']);
    Route::put('/accounts/{key}/aliases', [AccountController::class, 'updateAliases']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::patch('/accounts/{key}', [AccountController::class, 'update']);

    // ---------- 训练计划（按人持久化，逐条读写） ----------
    // 注意：/training-plans/bulk 必须注册在 /training-plans/{id} 之前，否则会被当作 id 捕获
    Route::get('/training-plans', [TrainingPlanController::class, 'index']);
    Route::post('/training-plans', [TrainingPlanController::class, 'store']);
    Route::put('/training-plans/bulk', [TrainingPlanController::class, 'bulkSave']);
    Route::put('/training-plans/{id}', [TrainingPlanController::class, 'update'])->whereNumber('id');
    Route::delete('/training-plans/{id}', [TrainingPlanController::class, 'destroy'])->whereNumber('id');

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
    Route::get('/system/changelog', [SystemController::class, 'changelog']);
    Route::post('/system/update', [SystemController::class, 'update']);
});

// ---------- 公开接口（免登录，H5 分享页用） ----------
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('/training/{code}', [PublicShareController::class, 'training']);
    Route::get('/post-class/{code}', [PublicShareController::class, 'postClass']);
    Route::get('/sales/{token}', [PublicShareController::class, 'sales']);
});
