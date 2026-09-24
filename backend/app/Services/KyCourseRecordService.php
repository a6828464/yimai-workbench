<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * 随心瑜「课时记录」取数（`course/api/getcoursesummaryrecordstat`）。
 *
 * ## 为什么必须单独一个服务
 *
 * 在此之前，薪酬的课时费**只有单侧来源**：`ky_bookings` 由 `KyMemberSyncService` 从
 * **预约记录**两个接口（`course/api/queryreversionleague` / `queryreversionprivate`）灌入，
 * `PayrollService::classRows()` 只读它。而随心瑜官方的「财务报表 → 课时费统计」是另一套
 * 统计（按老师汇总团课/精品课/私教的上课次数），两者在「整节课无人预约」「私教按人排课」
 * 等情形下必然分叉 —— 只看预约记录会漏课时。
 *
 * 口径（《随心瑜后台完整解读》notes.md:368-373）：
 * > 按老师汇总：团课/精品课/私教上课次数与课时费金额；**统计规则：过课表开始时间且未取消才计；
 * > 团课多人同节课只计一次；私教有预约才计**。
 *
 * 本服务**只读、不落库**，与 `KyMemberSyncService`（写入侧，另一个任务独占）解耦：
 * 课时记录是核验来源，不是事实表，落库只会凭空多出一份需要维护的副本。
 *
 * ## ⚠️ 两个接口陷阱（captain 2026-09-24 实测，勿改回去）
 *
 * **(陷阱 A) 日期参数名必须是 `start` / `end`。**
 * 传 `s_date`/`e_date`、`start_date`/`end_date`、`begin_date`、`time_start`… 时接口
 * **不报错**，而是静默返回**全量历史**（实测不论传哪个错误名字都返回同一份
 * `count=646 / total_num=5619`，与完全不传日期一模一样）。只有 `start`/`end` 生效：
 *
 * ```
 * start=2026-09-23, end=2026-09-23, venue_id=4250 → 节次 20，老师 11 人
 * start=2026-09-01, end=2026-09-24, venue_id=4250 → 节次 413，老师 25 人
 * ```
 *
 * 这是**最危险的一类 bug**：日期过滤失效不会报错，只会把「本月课时」悄悄变成
 * 「开店至今课时」，课时费随之失真。因此参数名在 {@see self::DATE_KEY_START} /
 * {@see self::DATE_KEY_END} 两处定义，`PayrollHoursTest` 有回归用例锁死。
 *
 * **(陷阱 B) 默认分页只有 15 行。** 必须显式传 `page_index` + `page_size`
 * （实测 `page_size=5000` 可一次取全）；否则「全量历史」被截断成 15 行，看起来
 * 像「只有 15 节课」，比报错更难发现。
 *
 * 四个参数（`start` / `end` / `page_index` / `page_size`）**必须齐传**，缺一即静默走错口径。
 */
class KyCourseRecordService
{
    public const PATH = 'course/api/getcoursesummaryrecordstat';

    /** 日期参数名：**必须是这两个**，理由见类注释陷阱 A */
    public const DATE_KEY_START = 'start';

    public const DATE_KEY_END = 'end';

    /** 分页参数名：默认 15 行，必须显式抬到足够大（陷阱 B） */
    public const PAGE_KEY_INDEX = 'page_index';

    public const PAGE_KEY_SIZE = 'page_size';

    /** 单页大小：实测 5000 可一次取全（单店单月课时记录量级为百级） */
    public const PAGE_SIZE = 5000;

    /** 翻页硬上限：防止上游分页异常时无限循环 */
    private const MAX_PAGES = 50;

    /**
     * 取数（多门店合并）。
     *
     * @param  array<string, string>  $venueIds  门店名 => 随心瑜 venue_id（来自 `kyStores()`）
     * @return array{
     *     available:bool,
     *     error:?string,
     *     period:array{start:string,end:string},
     *     request:array{start:string,end:string,pageIndex:int,pageSize:int},
     *     pageSize:int,
     *     pages:int,
     *     rows:array<int,array>,
     *     venues:array<string,array{ok:bool,error:?string,sessions:int,teachers:int,courseFee:float}>,
     *     byTeacher:array<string,array{sessions:int,courseFee:float,courses:array<int,string>}>
     * }
     */
    public function fetch(array $venueIds, Carbon $start, Carbon $end): array
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $out = [
            'available' => false,
            'error' => null,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'request' => [
                'start' => $startDate,
                'end' => $endDate,
                'pageIndex' => 1,
                'pageSize' => self::PAGE_SIZE,
            ],
            'pageSize' => self::PAGE_SIZE,
            'pages' => 0,
            'rows' => [],
            'venues' => [],
            'byTeacher' => [],
        ];

        if ($venueIds === []) {
            $out['error'] = '未配置门店与随心瑜 venue_id 的映射（config services.ky.stores），无法取课时记录';

            return $out;
        }

        $errors = [];
        $anyOk = false;

        foreach ($venueIds as $venue => $venueId) {
            try {
                $rows = $this->fetchVenue((string) $venueId, $start, $end);
                $anyOk = true;
                $out['pages'] += $rows['pages'];
                foreach ($rows['rows'] as $row) {
                    // 加上门店归属：两店合并取数时，装配侧要按店分组核对
                    $row['venue'] = (string) $venue;
                    $out['rows'][] = $row;
                }
                $out['venues'][$venue] = [
                    'ok' => true,
                    'error' => null,
                    'sessions' => $rows['sessions'],
                    'teachers' => $rows['teachers'],
                    'teacherSessions' => $rows['teacherSessions'],
                    'courseFee' => $rows['courseFee'],
                ];
            } catch (Throwable $e) {
                // 单店失败不拖垮另一店：门店级显式降级，由装配侧作为「课时记录不可用」上报
                $errors[] = "{$venue}: ".$e->getMessage();
                $out['venues'][$venue] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'sessions' => 0,
                    'teachers' => 0,
                    'teacherSessions' => [],
                    'courseFee' => 0.0,
                ];
            }
        }

        $out['available'] = $anyOk;
        $out['error'] = $errors === [] ? null : implode('；', $errors);

        foreach ($out['rows'] as $row) {
            $name = $row['coachName'];
            if ($name === '') {
                continue;
            }
            $out['byTeacher'][$name] ??= ['sessions' => 0, 'courseFee' => 0.0, 'courses' => []];
            $out['byTeacher'][$name]['sessions'] += $row['count'];
            $out['byTeacher'][$name]['courseFee'] += $row['courseFee'];
            if ($row['courseName'] !== '' && count($out['byTeacher'][$name]['courses']) < 8
                && ! in_array($row['courseName'], $out['byTeacher'][$name]['courses'], true)) {
                $out['byTeacher'][$name]['courses'][] = $row['courseName'];
            }
        }

        return $out;
    }

    /**
     * 单店取数（含翻页）。
     *
     * @return array{pages:int,sessions:int,teachers:int,teacherSessions:array<string,int>,courseFee:float,rows:array<int,array>}
     */
    public function fetchVenue(string $venueId, Carbon $start, Carbon $end): array
    {
        $rows = [];
        $pages = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $batch = $this->fetchPage($venueId, $start, $end, $page);
            $pages++;

            $list = $this->rows($batch);
            foreach ($list as $row) {
                $rows[] = $row;
            }

            // 上游可能忽略 page_size 一次返回全量；只有「满页」才继续翻页
            if (count($list) < self::PAGE_SIZE) {
                break;
            }
        }

        $byTeacher = [];
        $courseFee = 0.0;
        foreach ($rows as $row) {
            $courseFee += $row['courseFee'];
            if ($row['coachName'] !== '') {
                // 每个老师的节数：用于两源逐人差额（谁多、谁少、差几节）
                $byTeacher[$row['coachName']] = ($byTeacher[$row['coachName']] ?? 0) + $row['count'];
            }
        }

        return [
            'pages' => $pages,
            'sessions' => array_sum(array_column($rows, 'count')),
            'teachers' => count($byTeacher),
            'teacherSessions' => $byTeacher,
            'courseFee' => round($courseFee, 2),
            'byTeacher' => $byTeacher,
            'rows' => $rows,
        ];
    }

    /**
     * 单页请求。**四个参数齐传**；缺任何一个都会静默走错口径（陷阱 A / B）。
     *
     * @return array<string,mixed> 上游原始 JSON
     */
    private function fetchPage(string $venueId, Carbon $start, Carbon $end, int $pageIndex): array
    {
        $form = [
            self::DATE_KEY_START => $start->toDateString(),
            self::DATE_KEY_END => $end->toDateString(),
            self::PAGE_KEY_INDEX => $pageIndex,
            self::PAGE_KEY_SIZE => self::PAGE_SIZE,
            'venue_id' => $venueId,
        ];

        $resp = KyClient::call(self::PATH, $form);
        $data = $resp['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException(self::PATH.' 响应缺少 data');
        }

        return $data;
    }

    /**
     * 把上游 `data.course[]` 归一化成统一行。
     *
     * 关键字段：`coach_name`（老师）/ `course_name`（课程）/ `count`（**该 (老师,课程) 的
     * 课时节数**）/ `course_fee`（课时费金额，实测 = count × 单价）。
     *
     * @param  array<string,mixed>  $data
     * @return array<int,array{coachName:string,courseName:string,courseType:string,count:int,courseFee:float,startTime:string,venue:string}>
     */
    private function rows(array $data): array
    {
        $list = $data['course'] ?? $data['list'] ?? $data['rows'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'coachName' => $this->text($row, ['coach_name', 'coachName', 'teacher_name', 'coach']),
                'courseName' => $this->text($row, ['course_name', 'courseName', 'name']),
                'courseType' => $this->text($row, ['course_type', 'courseType', 'type_name']),
                'count' => (int) round($this->num($row['count'] ?? $row['course_count'] ?? 0)),
                'courseFee' => round($this->num($row['course_fee'] ?? $row['courseFee'] ?? 0), 2),
                'startTime' => $this->text($row, ['start_time', 'startTime', 'start_date']),
                'venue' => '',
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    private function num(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        preg_match('/-?\d+(\.\d+)?/', (string) $value, $m);

        return isset($m[0]) ? (float) $m[0] : 0.0;
    }
}
