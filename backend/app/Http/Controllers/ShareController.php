<?php

namespace App\Http\Controllers;

use App\Models\PublishedShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ShareController extends Controller
{
    /** 允许对外分享的类型 */
    private const TYPES = 'sales,training';

    /**
     * 「看起来像服务端签发的码」的形态判定：16 位小写十六进制。
     *
     * ⚠️ 这**不是**安全边界。形状是可伪造的 —— 任何手里有 16 位 hex 的人都能造出
     * 满足本判定的码。真正的信任根是数据库里由服务端写入的 `token_source='server'`
     * 标记（见 migration 2026_09_21_000002）。本方法只用于：
     *  - 前端展示时过滤「显然不是服务端签发」的历史码；
     *  - 运维排查时快速识别可疑形态。
     * 上一轮它叫 `isServerIssuedToken()`，名字会让人以为形态=来源，故改名。
     */
    public static function looksLikeServerToken(string $token): bool
    {
        return preg_match('/^[0-9a-f]{16}$/', $token) === 1;
    }

    /** 签发一个不可猜测的对外分享码 */
    public static function issueToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * `published_shares.enabled` 列是否存在。
     *
     * 迁移与代码不是原子上线的（update.sh 先 rsync 覆盖代码、再执行 migrate），
     * 所以新代码必须能在「列还没建出来」的窗口里存活：读侧按等价修复前的语义处理
     * （视为启用，避免既有链接全 404），写侧跳过该列，停用侧给出明确提示而不是 500。
     *
     * 刻意**不做静态缓存**：结构可能在同进程内变化（迁移执行、测试里 drop 列），
     * 缓存会让判断在结构变更后仍返回旧结论 —— 正是这段兜底最不该出错的地方。
     */
    private static function hasEnabledColumn(): bool
    {
        return Schema::hasTable('published_shares')
            && Schema::hasColumn('published_shares', 'enabled');
    }

    /** `published_shares.token_source` 列是否存在（同上，迁移未跑完时的兜底） */
    private static function hasTokenSourceColumn(): bool
    {
        return Schema::hasTable('published_shares')
            && Schema::hasColumn('published_shares', 'token_source');
    }

    /** `published_shares.created_by_user_id` 列是否存在 */
    private static function hasOwnerIdColumn(): bool
    {
        return Schema::hasTable('published_shares')
            && Schema::hasColumn('published_shares', 'created_by_user_id');
    }

    /**
     * 记录是否由本人签发（id 为准，姓名/别名并集兼容）。
     *
     * 复用既有归属口径 `staffOwnsRow()`（id 命中 OR 姓名命中 staffNames），
     * 与 leads/customers/tasks 的归属判断完全一致 —— 不在这里另造一套比较逻辑。
     * created_by_user_id 列还没建出来时退化为纯姓名比较（迁移窗口内的兜底）。
     */
    private static function ownedBy(PublishedShare $share, Request $r): bool
    {
        $user = $r->user();
        if (! $user) {
            return false;
        }
        if (self::hasOwnerIdColumn()) {
            return staffOwnsRow($user, $share, 'created_by_user_id', 'created_by');
        }

        return in_array(trim((string) $share->created_by), staffNames($user), true);
    }

    /** 本人的分享范围（用于 current / publishSales 的定位；超管不过滤） */
    private static function scopedQuery(Request $r, string $type)
    {
        $q = PublishedShare::where('type', $type);
        if (! userHasRole($r->user(), 'R_SUPER')) {
            if (self::hasOwnerIdColumn()) {
                $q->where(staffOwnerFilter($r->user(), 'created_by_user_id', 'created_by'));
            } else {
                $q->whereIn('created_by', staffNames($r->user()));
            }
        }

        return $q;
    }

    /**
     * 是否可操作对外分享（与前端菜单 MGMT 对齐：R_SUPER / R_MANAGER）。
     *
     * 前端 `admin-web/src/router/modules/yimai.ts:7` 的谈单工具菜单就是 MGMT =
     * ['R_SUPER','R_MANAGER']，后端此前完全没有对应判定 —— 只能靠「拿不到菜单」这种
     * 前端约束兜着，任何能带 token 发请求的角色都能调用发布/停用接口。
     */
    private static function assertCanManageShares(Request $r): void
    {
        abort_unless(
            userHasAnyRole($r->user(), ['R_SUPER', 'R_MANAGER']),
            403,
            '仅超管与店长可管理对外分享'
        );
    }

    /** 销售分享允许对外的顶层字段（白名单，未知字段一律丢弃） */
    private const SALES_TOP_FIELDS = ['share', 'info', 'products', 'coaches', 'cases'];

    /** 案例允许对外的字段（白名单） */
    private const CASE_FIELDS = ['id', 'coachId', 'goal', 'desc', 'stages', 'authorized'];

    /**
     * POST /shares/publish
     *
     * 销售分享（type=sales）的分享码一律由服务端签发：客户端传入的值不参与
     * 公开 URL 的确定（参见 publishSales）。
     */
    public function publish(Request $r)
    {
        self::assertCanManageShares($r);

        $rules = [
            'type' => 'required|string|in:'.self::TYPES,
            'payload' => 'required|array',
        ];
        // 训练分享仍由调用方给码（校验与行为不变）；销售分享的码由服务端签发，
        // 因此这里的 token 规则只对非销售类型生效，客户端传什么都不会被当成权威值。
        if ($r->input('type') !== 'sales') {
            $rules['token'] = ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'];
        }
        $d = $r->validate($rules);
        abort_if(
            strlen((string) json_encode($d['payload'], JSON_UNESCAPED_UNICODE)) > 200000,
            422, '分享内容过大，请精简后重试'
        );

        if ($d['type'] === 'sales') {
            return $this->publishSales($r, $d['payload']);
        }

        // 归属校验：已有同 token 分享非本人创建时，仅超管可覆盖，防止劫持他人对外 H5
        $existing = PublishedShare::where('type', $d['type'])->where('token', $d['token'])->first();
        abort_if(
            $existing && ! self::ownedBy($existing, $r) && ! userHasRole($r->user(), 'R_SUPER'),
            403, '无权覆盖他人创建的分享'
        );
        $fields = ['payload' => $d['payload'], 'created_by' => $r->user()->name];
        if (self::hasEnabledColumn()) {
            $fields['enabled'] = true;
        }
        if (self::hasOwnerIdColumn()) {
            $fields['created_by_user_id'] = $r->user()->id;
        }
        if (self::hasTokenSourceColumn()) {
            // 训练码来自调用方，不是服务端签发的：如实标记，读侧才不会误信
            $fields['token_source'] = 'legacy';
        }
        PublishedShare::updateOrCreate(
            ['type' => $d['type'], 'token' => $d['token']],
            $fields
        );
        audit($r, '发布', 'H5分享', 0, "分享码[{$d['token']}]", '双店', "类型：{$d['type']}");

        return ok(['ok' => true, 'token' => $d['token'], 'code' => $d['token'], 'enabled' => true]);
    }

    /**
     * GET /shares/current?type=sales
     *
     * 返回本人当前对外分享的权威状态（分享码 + 是否启用）。前端打开分享开关时
     * 以服务端为准，避免「界面显示已停用/本地随机码，线上其实是另一个链接」。
     */
    public function current(Request $r)
    {
        self::assertCanManageShares($r);
        $d = $r->validate([
            'type' => 'required|string|in:'.self::TYPES,
        ]);
        if ($d['type'] === 'training') {
            return $this->trainingUnsupported();
        }

        $share = self::scopedQuery($r, $d['type'])->orderByDesc('id')->first();

        if (! $share) {
            return ok(['enabled' => false, 'token' => null, 'code' => null, 'views' => 0]);
        }

        // 只有服务端标记过来源的码才下发；形态像但来源不明的（legacy）一律不下发
        $trusted = self::isTrustedToken($share) ? (string) $share->token : null;

        return ok([
            'enabled' => self::isEnabled($share),
            'token' => $trusted,
            'code' => $trusted,
            'views' => (int) ($share->payload['share']['views'] ?? 0),
        ]);
    }

    /**
     * POST /shares/disable
     *
     * 关闭分享开关必须落到服务端：修复前停用只改前端状态，记录原样保留，
     * 公开接口 GET /public/sales/{token} 依旧把内容下发出去。
     *
     * 归属：本人创建的记录（id 为准、姓名并集）；超管可停用任意记录（运维通道）。
     * 可选 token 参数用于「按 token 的运维停用」——只停那一条，便于处置具体泄漏链接。
     */
    public function disable(Request $r)
    {
        // 超管兜底通道要能停用任意记录，但停止用仍属 MGMT 管理的功能面
        self::assertCanManageShares($r);
        $d = $r->validate([
            'type' => 'required|string|in:'.self::TYPES,
            'token' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        if ($d['type'] === 'training') {
            return $this->trainingUnsupported();
        }

        if (! self::hasEnabledColumn()) {
            // 迁移还没跑完：明确 503 而不是抛「列不存在」的 500，前端能给出可操作提示
            return response()->json([
                'errno' => 503,
                'emsg' => '数据库结构升级尚未完成（published_shares.enabled 缺失），请稍后重试或先完成迁移',
            ], 503);
        }

        $isSuper = userHasRole($r->user(), 'R_SUPER');
        $q = PublishedShare::where('type', $d['type']);
        if (! empty($d['token'])) {
            // 运维入口：按 token 停用单条，仍需归属或超管
            $q->where('token', $d['token']);
        }
        if (! $isSuper) {
            if (self::hasOwnerIdColumn()) {
                $q->where(staffOwnerFilter($r->user(), 'created_by_user_id', 'created_by'));
            } else {
                $q->whereIn('created_by', staffNames($r->user()));
            }
        }

        $affected = $q->update(['enabled' => false]);
        audit(
            $r, '修改', 'H5分享', 0, '对外分享', '双店',
            "类型：{$d['type']}；停用对外分享，影响 {$affected} 条"
            .($isSuper ? '（超管通道）' : '')
            .(! empty($d['token']) ? "；指定分享码[{$d['token']}]" : '')
        );

        return ok(['ok' => true, 'enabled' => false, 'affected' => $affected]);
    }

    /**
     * 训练分享的停用/查询：明确不支持，指向正确的入口。
     *
     * 训练计划的分享状态存在 `training_plans.share`（JSON），与 published_shares
     * 是两套存储；本控制器只负责 published_shares。此前 disable/current 对
     * type=training 也会去改 published_shares，返回 `affected=0` 却报 enabled=false，
     * 前端据「成功」显示已停用、而训练分享其实还开着 —— 返回值与事实不一致。
     * 这里改为 422 明确拒绝，并指出应使用的入口（训练计划页开关）。
     */
    private function trainingUnsupported(): JsonResponse
    {
        return response()->json([
            'errno' => 422,
            'emsg' => '训练分享请在「训练计划」页用该计划的分享开关操作（其状态存于训练计划自身）',
        ], 422);
    }

    /**
     * 该行的 token 是否来源可信（由本服务端签发并标记）。
     *
     * token_source 列不存在时（迁移窗口）保守判为不可信：宁可让本人重新发布换发一次码，
     * 也不把来源不明的码当成权威链接下发。
     */
    private static function isTrustedToken(PublishedShare $share): bool
    {
        if (! self::hasTokenSourceColumn()) {
            return false;
        }

        return (string) $share->token_source === 'server';
    }

    /**
     * 该行是否「启用」——显式真值比较，一切非真值 fail-closed。
     *
     * 不能写 `(bool) $share->enabled`：DB 里可能是字符串 '0'/'false'/'off'（不同驱动、
     * 手工改库、或历史脏数据），而 `(bool) 'false'` 在 PHP 里是 **true** ——
     * 一个写着「已停用」的记录会被判成启用并继续对外下发。
     * enabled 列不存在时（迁移窗口）按 true 处理：等价修复前语义，避免既有链接全 404。
     */
    private static function isEnabled(PublishedShare $share): bool
    {
        if (! self::hasEnabledColumn()) {
            return true;
        }

        return filter_var($share->enabled, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * 销售分享发布：服务端签发（或沿用）权威 token。
     *
     * 归属按「本人签发」判定，客户端传的 token 完全不参与 —— 可猜的编译期常量
     * 无法再决定公开 URL。只有 `token_source='server'`（本服务端签发并标记）的行
     * 才沿用旧码，避免每次发布都换 URL 让已发出的二维码作废；
     * 其余（legacy：形态像但不是本服务端签发、或客户端给的历史码）一律换发。
     */
    private function publishSales(Request $r, array $payload): JsonResponse
    {
        $share = self::scopedQuery($r, 'sales')->orderByDesc('id')->first();

        $token = $share && self::isTrustedToken($share)
            ? (string) $share->token
            : self::issueToken();
        // 摘要取「提交了什么」与「实际发出什么」两个数：前者是操作事实，后者是对客事实，
        // 两者不一致（被过滤掉未授权案例）正是最需要事后可查的情形。
        $summary = self::payloadSummary($payload);
        $payload = $this->prepareSalesPayload($payload, $token, $share?->payload);

        $fields = ['token' => $token, 'payload' => $payload, 'created_by' => $r->user()->name];
        if (self::hasEnabledColumn()) {
            $fields['enabled'] = true;
        }
        if (self::hasOwnerIdColumn()) {
            $fields['created_by_user_id'] = $r->user()->id;
        }
        if (self::hasTokenSourceColumn()) {
            // 本路径的码一律由 issueToken() 产生 —— 如实标记来源，读侧据此信任
            $fields['token_source'] = 'server';
        }

        if ($share) {
            $share->update($fields);
        } else {
            PublishedShare::create($fields + ['type' => 'sales']);
        }
        audit($r, '发布', 'H5分享', 0, "分享码[{$token}]", '双店', '类型：sales；'.$summary);

        return ok(['ok' => true, 'token' => $token, 'code' => $token, 'enabled' => true]);
    }

    /**
     * 快照内容摘要（进审计）。
     *
     * 原先只记「分享码 + 类型」，事后无法回答「这次对外到底发出去了什么」。
     * 摘要记**提交条数与实际发布的授权条数**，不记案例原文 —— 审计日志本身也是敏感面，
     * 不能把未授权内容换个地方再存一份。
     */
    private static function payloadSummary(array $payload): string
    {
        $cases = is_array($payload['cases'] ?? null) ? $payload['cases'] : [];
        $authorized = count(array_filter(
            $cases,
            fn ($c) => is_array($c) && filter_var($c['authorized'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ));

        return sprintf(
            '快照摘要：产品%d项、教练%d人、案例%d条（已授权%d）',
            count(is_array($payload['products'] ?? null) ? $payload['products'] : []),
            count(is_array($payload['coaches'] ?? null) ? $payload['coaches'] : []),
            count($cases),
            $authorized
        );
    }

    /**
     * 组装对外快照：字段白名单 + 只留已授权案例 + 权威分享码。
     *
     * 白名单是**纵深防御**：前端提交的 payload 是任意结构（`payload` 只校验了
     * `array`），任何多塞的字段都会原样进库、原样出公开接口。这里只放行对客页
     * 真正会渲染的字段，其余一律丢弃 —— 即使将来有人在 payload 里夹带内部备注、
     * 会员 id、体测原文，也不会随公开接口出网。
     */
    private function prepareSalesPayload(array $payload, string $token, ?array $previous): array
    {
        $out = [];
        foreach (self::SALES_TOP_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $out[$field] = $payload[$field];
            }
        }

        $cases = is_array($out['cases'] ?? null) ? $out['cases'] : [];
        $out['cases'] = array_values(array_filter(array_map(
            static function ($case) {
                if (! is_array($case)) {
                    return null;
                }
                // 授权是真值判定：'false'/'off'/'no' 一律视为未授权（fail-closed）
                if (filter_var($case['authorized'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
                    return null;
                }
                $kept = [];
                foreach (self::CASE_FIELDS as $f) {
                    if (array_key_exists($f, $case)) {
                        $kept[$f] = $case[$f];
                    }
                }
                $kept['authorized'] = true;

                return $kept;
            },
            $cases
        )));

        // views：无 $previous（首次发布）时固定 0，不接受客户端传来的首值 ——
        // 客户端能随便填一个数，等于让访问量从一开始就是假的。之后只沿用库里的值。
        $views = $previous === null ? 0 : (int) ($previous['share']['views'] ?? 0);
        $out['share'] = ['enabled' => true, 'code' => $token, 'views' => $views];

        return $out;
    }

    /**
     * GET /shares/orphans（仅超管）
     *
     * 「归属孤儿行」清单：created_by 查无此人、或缺少 created_by_user_id 的分享记录。
     * 这类行不会自己消失，且姓名那一路仍参与可见性判断 —— 需要让管理员看见并处置，
     * 而不是靠查询时静默取舍。
     */
    public function orphans(Request $r)
    {
        requireSuper($r);

        return ok([
            'records' => self::orphanShares(),
            'ownershipColumnsReady' => self::ownershipColumnsReady(),
        ]);
    }

    /**
     * POST /shares/orphans/repair（仅超管）
     *
     * 一次性清理/重新归属：mode=reassign 指派给 userId；mode=disable 停用该行。
     */
    public function repairOrphan(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'id' => 'required|integer|min:1',
            'mode' => 'required|string|in:reassign,disable',
            'userId' => 'required_if:mode,reassign|nullable|integer|min:1',
        ]);

        $okDone = self::repairOrphanShare(
            (int) $d['id'],
            (string) $d['mode'],
            isset($d['userId']) ? (int) $d['userId'] : null
        );
        abort_unless($okDone, 422, '处置失败：记录不存在、目标账号无效，或数据库结构尚未就绪');

        audit(
            $r, '修改', 'H5分享', (string) $d['id'], '归属孤儿行', '双店',
            '模式：'.$d['mode'].(isset($d['userId']) ? "；指派给账号#{$d['userId']}" : '')
        );

        return ok(['ok' => true]);
    }

    /**
     * 为 published_shares 增加 created_by_user_id / token_source 的迁移是否已应用。
     *
     * 供「归属映射」面板与运维排查判断当前库结构是否支持按 id 归属，
     * 避免面板在没有该列时报错。
     */
    public static function ownershipColumnsReady(): bool
    {
        return self::hasOwnerIdColumn() && self::hasTokenSourceColumn();
    }

    /**
     * 一次性清理/重新归属入口所需的数据：created_by 查无此人的孤儿行。
     *
     * 「孤儿行」= created_by 里写着一个当前 users 表里不存在的姓名（账号删掉重建、
     * 或历史脏数据）。这类行的 id 归属永远回填不上，但姓名那一路仍会影响可见性 ——
     * 需要让管理员能看见并处置。返回 [{id, type, token, created_by, reason}]。
     *
     * @return array<int, array{id: int, type: string, token: string, created_by: string, reason: string}>
     */
    public static function orphanShares(int $limit = 200): array
    {
        if (! Schema::hasTable('published_shares')) {
            return [];
        }

        $names = DB::table('users')->pluck('name')->map(fn ($n) => trim((string) $n))->all();
        $names = array_flip($names);
        if (Schema::hasTable('staff_aliases')) {
            foreach (DB::table('staff_aliases')->pluck('alias') as $alias) {
                $names[trim((string) $alias)] = true;
            }
        }

        $out = [];
        foreach (PublishedShare::orderByDesc('id')->limit($limit)->get() as $row) {
            $name = trim((string) $row->created_by);
            $idMissing = self::hasOwnerIdColumn() && (int) ($row->created_by_user_id ?? 0) === 0;
            $nameUnknown = $name === '' || ! isset($names[$name]);
            if ($nameUnknown || $idMissing) {
                $out[] = [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    'token' => (string) $row->token,
                    'created_by' => $name,
                    'reason' => $nameUnknown ? '姓名查无此人' : '缺少归属 user_id',
                ];
            }
        }

        return $out;
    }

    /**
     * 一次性「清理/重新归属」：把孤儿行指派给指定账号（或停用并标记 legacy）。
     *
     * $mode='reassign' 时把 created_by/created_by_user_id 改为目标账号；
     * $mode='disable'  时停用该行（无法确定归属又不想删记录时的稳妥处置）。
     */
    public static function repairOrphanShare(int $shareId, string $mode, ?int $userId = null): bool
    {
        $share = PublishedShare::find($shareId);
        if (! $share) {
            return false;
        }

        if ($mode === 'disable') {
            if (! self::hasEnabledColumn()) {
                return false;
            }
            $share->update(['enabled' => false]);

            return true;
        }

        if ($mode !== 'reassign' || $userId === null) {
            return false;
        }

        $user = DB::table('users')->where('id', $userId)->first(['id', 'name']);
        if (! $user) {
            return false;
        }

        $fields = ['created_by' => (string) $user->name];
        if (self::hasOwnerIdColumn()) {
            $fields['created_by_user_id'] = (int) $user->id;
        }
        $share->update($fields);

        return true;
    }
}
