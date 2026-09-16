<?php

namespace App\Http\Controllers;

use App\Models\BodyTestReport;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\BodyTestReportService;
use Illuminate\Http\Request;

/**
 * 体测报告：解析、归档、供课后分析与训练计划引用。
 *
 * 老师把门店体测设备的报告链接贴进来，后端解析成结构化指标，
 * 并自动映射出训练观察项——老师不用再逐项勾选。
 */
final class BodyTestReportController extends Controller
{
    private function assertCanWrite(User $u): void
    {
        abort_unless(userHasAnyRole($u, ['R_SUPER', 'R_MANAGER', 'R_TEACHER']), 403, '仅授课老师、店长可录入体测报告');
    }

    private function isVisible(User $u, BodyTestReport $row): bool
    {
        if (userHasRole($u, 'R_SUPER')) {
            return true;
        }
        if ($row->venue !== $u->venue) {
            return false;
        }
        if (userHasRole($u, 'R_MANAGER')) {
            return true;
        }
        if (userHasRole($u, 'R_TEACHER')) {
            return (int) $row->created_by === (int) $u->id
                || $this->ownsByRelation($u, $row);
        }
        if (userHasRole($u, 'R_SERVICE')) {
            return $this->ownsByRelation($u, $row);
        }

        return false;
    }

    /** 会员/客资是否挂在该用户名下 */
    private function ownsByRelation(User $u, BodyTestReport $row): bool
    {
        if ($row->customer_id) {
            $c = Customer::find($row->customer_id);
            if ($c && in_array($u->name, [(string) $c->consultant, (string) $c->owner], true)) {
                return true;
            }
        }
        if ($row->lead_id) {
            $l = Lead::find($row->lead_id);

            return $l !== null && staffOwnsRow($u, $l, 'service_teacher_user_id', 'service_teacher');
        }

        return false;
    }

    /**
     * POST /body-test-reports/parse：只解析不入库，供老师当场预览。
     * 已入库过的同一条报告直接返回归档结果，避免重复拉取。
     */
    public function parse(Request $r)
    {
        $u = $r->user();
        $url = trim((string) $r->input('url', ''));
        abort_if($url === '', 422, '请粘贴体测报告链接');
        abort_unless(BodyTestReportService::parseUrl($url) !== null, 422, '链接格式不对，应该形如 https://bodytest.ruleye.com/#/report-new/…');

        $parts = BodyTestReportService::parseUrl($url);
        $existing = BodyTestReport::where('body_test_id', $parts['body_test_id'])->first();
        if ($existing && $this->isVisible($u, $existing)) {
            return ok($this->present($existing) + ['saved' => true, 'reportId' => $existing->id]);
        }

        $raw = BodyTestReportService::fetch($parts['body_test_id'], $parts['sign'], $parts['timestamp']);
        abort_if($raw === null, 422, '体测报告拉取失败，请确认链接是否有效（链接过期后需要重新生成）');

        $analysis = BodyTestReportService::analyze($raw);
        // score / create_time 在顶层，analyze 已经放进 profile，这里补一份便于前端直接取

        return ok($analysis + [
            'saved' => false,
            'reportId' => null,
            'raw' => $raw,
        ]);
    }

    /** POST /body-test-reports：归档 */
    public function store(Request $r)
    {
        $u = $r->user();
        $this->assertCanWrite($u);
        $url = trim((string) $r->input('url', ''));
        abort_if($url === '', 422, '请粘贴体测报告链接');
        $parts = BodyTestReportService::parseUrl($url);
        abort_if($parts === null, 422, '链接格式不对');

        $raw = BodyTestReportService::fetch($parts['body_test_id'], $parts['sign'], $parts['timestamp']);
        abort_if($raw === null, 422, '体测报告拉取失败，请确认链接是否有效');
        $analysis = BodyTestReportService::analyze($raw);

        $venue = (string) $r->input('venue', $u->venue ?: '');
        $customerId = $r->input('customerId') ?: null;
        $leadId = $r->input('leadId') ?: null;

        // 没显式传关联时按会员名/手机号回填，保证报告能挂到人身上
        $name = (string) ($analysis['profile']['nickName'] ?? '');
        if ((! $customerId || ! $leadId) && $name !== '') {
            $customerId = $customerId ?: Customer::where('name', $name)
                ->when($venue !== '', fn ($q) => $q->where('venue', $venue))
                ->orderByDesc('id')->value('id');
            $leadId = $leadId ?: Lead::where('name', $name)
                ->when($venue !== '', fn ($q) => $q->where('venue', $venue))
                ->orderByDesc('id')->value('id');
        }

        $row = BodyTestReport::updateOrCreate(
            ['body_test_id' => $parts['body_test_id']],
            [
                'venue' => $venue,
                'customer_id' => $customerId,
                'lead_id' => $leadId,
                'member_name' => (string) $r->input('memberName', $name),
                'phone' => (string) $r->input('phone', ''),
                'source_url' => $url,
                'tested_at' => $this->toDateTime($analysis['profile']['testedAt'] ?? ''),
                'score' => (float) ($analysis['profile']['score'] ?? 0),
                'profile' => $analysis['profile'],
                'composition' => $analysis['composition'],
                'abnormal' => $analysis['abnormal'],
                'posture' => $analysis['posture'],
                'observations' => $analysis['observations'],
                'directions' => $analysis['directions'],
                'health' => $analysis['health'],
                'red_flags' => $analysis['redFlags'],
                'raw' => $raw,
                'created_by' => $u->id,
            ]
        );
        audit($r, '新增', '体测报告', $row->id, $row->member_name, $row->venue,
            '归档体测报告：异常 '.count($analysis['abnormal']).' 项 / 体态 '.count($analysis['posture']).' 项');

        return ok(['id' => $row->id] + $this->present($row));
    }

    /** GET /body-test-reports：按会员/客资查询历史报告（同一会员多次体测可纵向对比） */
    public function index(Request $r)
    {
        $u = $r->user();
        $q = BodyTestReport::query();
        if (! userHasRole($u, 'R_SUPER')) {
            $q->where('venue', $u->venue);
        }
        if ($id = $r->query('customerId')) {
            $q->where('customer_id', (int) $id);
        }
        if ($id = $r->query('leadId')) {
            $q->where('lead_id', (int) $id);
        }
        if (userHasRole($u, 'R_TEACHER')) {
            $q->where('created_by', $u->id);
        }

        return ok(['records' => $q->orderByDesc('tested_at')->limit(50)->get()
            ->map(fn ($x) => $this->present($x))->all()]);
    }

    /** GET /body-test-reports/{id} */
    public function show(Request $r, int $id)
    {
        $row = BodyTestReport::findOrFail($id);
        abort_unless($this->isVisible($r->user(), $row), 403, '无权查看该体测报告');

        return ok($this->present($row));
    }

    /** 报告里是 2026.07.22 18:16 这种格式，转成标准时间 */
    private function toDateTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $normalized = str_replace('.', '-', trim($value));

        return date('Y-m-d H:i:s', strtotime($normalized) ?: time());
    }

    private function present(BodyTestReport $row): array
    {
        return [
            'id' => $row->id,
            'venue' => $row->venue,
            'bodyTestId' => $row->body_test_id,
            'customerId' => $row->customer_id,
            'leadId' => $row->lead_id,
            'memberName' => $row->member_name,
            'testedAt' => $row->tested_at?->format('Y-m-d H:i'),
            'score' => $row->score,
            'profile' => $row->profile,
            'composition' => $row->composition,
            'abnormal' => $row->abnormal,
            'posture' => $row->posture,
            'observations' => $row->observations,
            'directions' => $row->directions,
            'health' => $row->health,
            'redFlags' => $row->red_flags,
            'sourceUrl' => $row->source_url,
        ];
    }
}
