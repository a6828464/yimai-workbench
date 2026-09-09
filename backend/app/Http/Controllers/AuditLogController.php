<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    /** GET /audit-logs */
    public function index(Request $r)
    {
        $u = $r->user();
        abort_unless($u->role === 'R_SUPER', 403, '仅老板可查看操作留痕');
        $r->validate(['start' => 'nullable|date_format:Y-m-d', 'end' => 'nullable|date_format:Y-m-d|after_or_equal:start']);
        $q = AuditLog::query()->orderByDesc('id');
        if ($o = $r->query('operator')) {
            $q->where('operator_name', 'like', "%{$o}%");
        }
        if ($m = $r->query('module')) {
            $q->where('module', $m);
        }
        if ($a = $r->query('action')) {
            $q->where('action', $a);
        }
        if ($start = $r->query('start')) {
            $q->where('time', '>=', CarbonImmutable::parse($start)->startOfDay());
        }
        if ($end = $r->query('end')) {
            $q->where('time', '<=', CarbonImmutable::parse($end)->endOfDay());
        }
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok([
            'records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size,
            'metadata' => [
                'operators' => AuditLog::query()->where('operator_name', '!=', '')->distinct()->orderBy('operator_name')->pluck('operator_name'),
                'modules' => AuditLog::query()->where('module', '!=', '')->distinct()->orderBy('module')->pluck('module'),
                'actions' => AuditLog::query()->where('action', '!=', '')->distinct()->orderBy('action')->pluck('action'),
            ],
        ]);
    }
}
