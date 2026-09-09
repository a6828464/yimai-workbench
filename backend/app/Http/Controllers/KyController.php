<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\SyncJob;
use App\Services\KyClient;
use App\Services\KyMemberSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class KyController extends Controller
{
    /** POST /ky/session */
    public function session(Request $r)
    {
        requireSuper($r);
        try {
            KyClient::token((bool) $r->input('force'));
        } catch (Throwable $e) {
            return response()->json(['code' => 1, 'message' => $e->getMessage()]);
        }

        return ok(['ok' => true]);
    }

    /** POST /ky/call */
    public function call(Request $r)
    {
        requireSuper($r);
        $path = (string) $r->input('path', '');
        abort_unless(is_array($r->input('form')), 422, 'form 必须是对象');
        try {
            return ok(KyClient::call($path, $r->input('form')));
        } catch (InvalidArgumentException $e) {
            return response()->json(['code' => 1, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['code' => 1, 'message' => $e->getMessage()]);
        }
    }

    /** GET /ky/pending-contracts */
    public function pendingContracts(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['R_SUPER', 'R_MANAGER'], true), 403, '仅店长及以上可查看合同');
        $stores = ['绿地店' => '1', '东部店' => '4250'];
        if ($r->user()->role === 'R_MANAGER') {
            $stores = [$r->user()->venue => $stores[$r->user()->venue]];
        }
        $result = [];
        foreach ($stores as $venue => $venueId) {
            // 随心瑜口径实测（2026-09 解读）：contract_status 0=待签(恒空) 1=签署中 2=已完成 3=已撤回 4=已拒绝 5=已过期；
            // 「双方待签」= status=1 签署中 且 customer/venue_signatory_status 任一为 0（未签，2=已签）。
            $defaults = ['signing' => 0, 'pendingCustomer' => 0, 'pendingVenue' => 0, 'unknown' => 0, 'expired' => 0, 'items' => [], 'fieldConfirmed' => false];
            try {
                $response = KyClient::call('venue/api/getallcontractlist', [
                    'venue_id' => $venueId,
                    'page_index' => 1,
                    'page_size' => 200,
                    'contract_status' => '1',
                    'contract_name' => '',
                    'initiator_emp_name' => '',
                    'venue_signatory_emp_name' => '',
                    'customer_signatory_search' => '',
                    'initiator_start_date' => '',
                    'initiator_end_date' => '',
                ]);
            } catch (Throwable $e) {
                $result[$venue] = $defaults + ['error' => mb_substr($e->getMessage(), 0, 120)];

                continue;
            }
            $rows = contractRows($response);
            $items = collect($rows)->map(function ($row) use ($venue) {
                $customer = contractPartyState($row, 'customer');
                $venueState = contractPartyState($row, 'venue');

                return [
                    'id' => (string) ($row['id'] ?? $row['contract_id'] ?? $row['contract_no'] ?? ''),
                    'name' => (string) ($row['contract_name'] ?? $row['name'] ?? '未命名合同'),
                    'memberName' => (string) ($row['member_name'] ?? $row['customer_name'] ?? $row['m_name'] ?? $row['name'] ?? ''),
                    'venue' => $venue,
                    'customerState' => $customer,
                    'venueState' => $venueState,
                    'statusRaw' => (string) ($row['status_text'] ?? $row['contract_status_desc'] ?? $row['status_desc'] ?? $row['status'] ?? ''),
                ];
            });
            $pending = $items->filter(fn ($item) => in_array('incomplete', [$item['customerState'], $item['venueState']], true))->values();
            // 已过期合同（status=5）只取 total 做提醒，不拉明细
            $expiredTotal = 0;
            try {
                $expiredResp = KyClient::call('venue/api/getallcontractlist', [
                    'venue_id' => $venueId,
                    'page_index' => 1,
                    'page_size' => 1,
                    'contract_status' => '5',
                    'contract_name' => '',
                    'initiator_emp_name' => '',
                    'venue_signatory_emp_name' => '',
                    'customer_signatory_search' => '',
                    'initiator_start_date' => '',
                    'initiator_end_date' => '',
                ]);
                $expiredTotal = (int) ($expiredResp['data']['total'] ?? 0);
            } catch (Throwable $e) {
            }
            // 注意数组联合运算符左侧优先：计算值在前，defaults 只兜底缺失键
            $result[$venue] = [
                'signing' => (int) ($response['data']['total'] ?? $items->count()),
                'pendingCustomer' => $pending->where('customerState', 'incomplete')->count(),
                'pendingVenue' => $pending->where('venueState', 'incomplete')->count(),
                'unknown' => $items->filter(fn ($item) => in_array('unknown', [$item['customerState'], $item['venueState']], true))->count(),
                'expired' => $expiredTotal,
                'items' => $pending->values()->all(),
                'fieldConfirmed' => $items->contains(fn ($item) => ! in_array('unknown', [$item['customerState'], $item['venueState']], true)),
            ] + $defaults;
        }

        return ok(['venues' => $result, 'fetchedAt' => now()->format('Y-m-d H:i:s')]);
    }

    /** GET /ky/overview */
    public function overview(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['R_SUPER', 'R_MANAGER'], true), 403, '仅店长及以上可查看经营概览');
        $stores = ['绿地店' => '1', '东部店' => '4250'];
        if ($r->user()->role === 'R_MANAGER') {
            $stores = [$r->user()->venue => $stores[$r->user()->venue]];
        }
        $result = [];
        foreach ($stores as $venue => $venueId) {
            try {
                $overview = KyClient::call('venue/api/getvenuedataoverview', ['venue_id' => $venueId])['data'] ?? [];
            } catch (Throwable $e) {
                $result[$venue] = ['error' => mb_substr($e->getMessage(), 0, 120)];

                continue;
            }
            $activity = [];
            $visitor = [];
            try {
                $activity = KyClient::call('venue/api/getmembershipactivityanalysis', ['venue_id' => $venueId])['data'] ?? [];
            } catch (Throwable $e) {
            }
            try {
                $visitor = KyClient::call('venue/api/getvisitorconversion', ['venue_id' => $venueId])['data'] ?? [];
            } catch (Throwable $e) {
            }
            $result[$venue] = [
                'thisMonthRevenue' => (float) ($overview['this_month_revenue_total'] ?? 0),
                'lastMonthRevenue' => (float) ($overview['last_month_revenue_total'] ?? 0),
                'thisMonthUsage' => (float) ($overview['this_month_usage_total'] ?? 0),
                'remainingAssets' => (float) ($overview['remaining_assets_total'] ?? 0),
                'totalMembers' => (int) ($activity['total_members'] ?? 0),
                'activeMembers' => (int) ($activity['total_active_members'] ?? 0),
                'thisMonthClassMembers' => (int) ($activity['total_this_month_classes_members'] ?? 0),
                'lastMonthClassMembers' => (int) ($activity['total_last_month_classes_members'] ?? 0),
                'riskMembers' => (int) ($activity['total_risk_members'] ?? 0),
                'inactiveMembers' => (int) ($activity['total_inactive_members'] ?? 0),
                'lostMembers' => (int) ($activity['total_lost_members'] ?? 0),
                'totalVisitors' => (int) ($visitor['total_visitors'] ?? 0),
                'monthNewVisitors' => (int) ($visitor['total_this_month_new_add_visitors'] ?? 0),
                'monthVisitorClasses' => (int) ($visitor['total_this_month_classes_visitors'] ?? 0),
                'monthVisitorConversions' => (int) ($visitor['total_this_month_visitors_conversion_members'] ?? 0),
                // 上游两路分析任一失败时，相关计数不作为真实 0 展示
                'activityAvailable' => $activity !== [],
                'visitorAvailable' => $visitor !== [],
            ];
        }

        return ok(['venues' => $result, 'fetchedAt' => now()->format('Y-m-d H:i:s')]);
    }

    /** GET /ky/config */
    public function configShow(Request $r)
    {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $ky = (AppSetting::oldest('id')->first()?->ky) ?? [];

        return ok([
            'phone' => (string) ($ky['phone'] ?? ''),
            'configured' => (bool) ((($ky['phone'] ?? '') && ($ky['password'] ?? '')) || config('services.ky.phone')),
        ]);
    }

    /** PUT /ky/config */
    public function configUpdate(Request $r)
    {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $d = $r->validate(['phone' => 'required|string|max:20', 'password' => 'nullable|string|max:64']);
        $s = setting();
        $ky = (array) (($s->ky) ?? []);
        $ky['phone'] = trim($d['phone']);
        if (! empty($d['password'])) {
            $ky['password'] = $d['password'];
        }
        $s->update(['ky' => $ky]);
        Cache::forget('ky_access_token');
        audit($r, '修改', 'KeepYoga同步', 0, '随心瑜账号', '双店', "登录账号更新为 {$ky['phone']}");

        return ok(['phone' => $ky['phone'], 'configured' => true]);
    }

    /** POST /ky/import */
    public function import(Request $r)
    {
        requireSuper($r);
        $stores = ['绿地店' => '1', '东部店' => '4250'];
        $venue = (string) $r->input('venue');
        $venueId = (string) $r->input('venueId');
        abort_unless(isset($stores[$venue]) && $stores[$venue] === $venueId, 422, '门店参数无效');
        // 回收僵尸任务：进程被外部掐断时走不到 catch，会把任务永久留在"进行中"；开跑前先把超时未收尾的判失败
        SyncJob::where('venue', $venue)->where('status', '进行中')
            ->where('started_at', '<', now()->subMinutes(125))
            ->update(['status' => '失败', 'finished_at' => now(), 'error_message' => '同步超时未完成（疑似进程被中断），已自动回收']);
        // 2H2G 单机一次只跑一个门店，避免双任务叠加数据库、内存和系统盘写入峰值。
        $lock = Cache::lock('ky:import:global', 7200);
        abort_unless($lock->get(), 409, '服务器正在执行其他门店同步，请等待完成或稍后再试');
        $batch = 'IMP-'.now()->format('Ymd-His').'-'.substr((string) mt_rand(1000, 9999), 0, 4);
        $job = SyncJob::create([
            'batch_no' => $batch,
            'run_key' => $batch,
            'display_name' => now()->format('Y-m-d H:i')." {$venue} KeepYoga同步",
            'data_type' => '会员/卡项/出勤多表',
            'venue' => $venue,
            'status' => '进行中',
            'operator' => $r->user()->name,
            'started_at' => now(),
            'metadata' => ['venueId' => $venueId, 'operatorId' => $r->user()->id],
        ]);
        $ack = [
            'jobId' => $job->id,
            'batchNo' => $batch,
            'venue' => $venue,
            'status' => '进行中',
            'background' => function_exists('fastcgi_finish_request'),
        ];

        $runSync = function () use ($r, $venue, $venueId, $job, $batch, $lock): array {
            try {
                $result = KyMemberSyncService::sync($venue, $venueId, $job);

                $detail = sprintf(
                    '已保存快照：会员基础表 %d 条 · 会员卡表 %d 条 · 团课预约 %d 条 · 私教预约 %d 条（出勤口径月 %s / %s / %s）；导入落库：新增 %d · 更新 %d · 未变化 %d · 跳过 %d',
                    $result['total'], $result['cards'], $result['leagueBookings'] ?? 0, $result['privateBookings'] ?? 0,
                    $result['attendancePeriod']['m1'] ?? '-', $result['attendancePeriod']['m2'] ?? '-', $result['attendancePeriod']['m3'] ?? '-',
                    $result['created'], $result['updated'], $result['unchanged'], $result['skipped']
                );
                $job->update([
                    'total_count' => $result['total'],
                    'success_count' => $result['created'] + $result['updated'] + $result['unchanged'],
                    'fail_count' => $result['skipped'],
                    'status' => $result['skipped'] > 0 ? '部分失败' : '成功',
                    'finished_at' => now(), 'detail' => $detail, 'error_message' => null,
                ]);
                audit($r, '导入', 'KeepYoga同步', $job->id, "批次{$batch}", $venue, $detail);

                return $result;
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 1000);
                $job->update(['status' => '失败', 'finished_at' => now(), 'error_message' => $message]);
                Log::channel('system_error')->error('KeepYoga import failed', [
                    'batch' => $batch, 'venue' => $venue, 'job_id' => $job->id,
                    'exception' => $e::class, 'error' => $message,
                ]);
                audit($r, '导入失败', 'KeepYoga同步', $job->id, "批次{$batch}", $venue, $message);

                throw $e;
            } finally {
                $lock->release();
            }
        };

        // 生产 PHP-FPM：先交还「已受理」响应，浏览器不再挂起，同进程继续执行同步（僵尸回收与全局锁已兜底中断场景）
        if ($ack['background']) {
            response()->json(['code' => 0, 'data' => $ack + [
                'message' => '同步任务已受理，服务器后台执行中（约数分钟），可离开本页；完成后历史批次自动更新',
            ]])->send();
            fastcgi_finish_request();
            try {
                $runSync();
            } catch (Throwable) {
                // 失败已写入任务与日志；响应已发出，无需再抛
            }
            exit(0);
        }

        // 本地 serve / 测试环境：同步执行完再返回（兼容旧的全量结果契约）
        try {
            $result = $runSync();

            return ok($result + $ack + ['background' => false]);
        } catch (Throwable $e) {
            return response()->json(['code' => 1, 'message' => 'KeepYoga 多表同步失败：'.mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    /** POST /customers/import */
    public function legacyImport(Request $r)
    {
        requireSuper($r);

        return response()->json(['code' => 1, 'message' => '该接口已废弃，请使用 /ky/import 执行会员、卡项和出勤多表同步'], 410);
    }
}
