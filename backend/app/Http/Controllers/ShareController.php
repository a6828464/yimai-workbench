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

    /** 销售分享管理端点允许的角色（与前端菜单 MGMT 对齐，见 router/modules/yimai.ts:7） */
    private const SALES_MGMT_ROLES = ['R_SUPER', 'R_MANAGER'];

    /*
    |--------------------------------------------------------------------------
    | 白名单与信任/启用判定：单一来源
    |--------------------------------------------------------------------------
    |
    | 这些常量与判定原先在 ShareController / PublicShareController 各写一份，
    | 两侧口径需要人工同步 —— 一边改了另一边没改就是「入库过滤了、下发没过滤」这类
    | 静默缺口。现在统一放在这里，PublicShareController 直接委托。
    |
    | 放控制器而不是模型/专用 helper 的原因：本轮修复的 inScope 只含这两个控制器
    | （PublishedShare.php 与 helpers.php 均不在范围内）。等下次动那两个文件时，
    | 都应把这块整体搬过去。
    */

    /** 销售分享允许对外的顶层字段 */
    public const SALES_TOP_FIELDS = ['share', 'info', 'products', 'coaches', 'cases'];

    /**
     * 各容器内的字段白名单（结构性白名单，不只是顶层）。
     *
     * 只挡顶层键是不够的：`cases[].stages`、`info.intro`、`products[].desc`、
     * `coaches[].intro` 这类**内层**位置同样能承载文本，把未授权内容换个容器
     * 就能出网。这里按「对客页真正会渲染的字段」逐层收口。
     */
    public const NESTED_FIELDS = [
        'share' => ['enabled', 'code', 'views'],
        'info' => ['name', 'industry', 'slogan', 'intro', 'address', 'phone'],
    ];

    /** 列表型容器的元素白名单 */
    public const ITEM_FIELDS = [
        'products' => ['id', 'name', 'desc', 'showPrice', 'cols', 'rows'],
        'coaches' => ['id', 'name', 'title', 'tags', 'intro'],
        'cases' => ['id', 'coachId', 'goal', 'desc', 'stages', 'authorized'],
    ];

    /** 案例阶段允许的键（前端只渲染 duration；其余键无对外用途） */
    public const STAGE_FIELDS = ['duration'];

    /** `published_shares.enabled` 列是否存在 */
    public static function hasEnabledColumn(): bool
    {
        return Schema::hasTable('published_shares')
            && Schema::hasColumn('published_shares', 'enabled');
    }

    /** `published_shares.token_source` 列是否存在（迁移未跑完时的兜底） */
    public static function hasTokenSourceColumn(): bool
    {
        return Schema::hasTable('published_shares')
            && Schema::hasColumn('published_shares', 'token_source');
    }

    /** `published_shares.created_by_user_id` 列是否存在 */
    public static function hasOwnerIdColumn(): bool
    {
        return Schema::hasTable('published_shares')
            && Schema::hasColumn('published_shares', 'created_by_user_id');
    }

    /*
     * 关于 `looksLikeServerToken()`（上一轮存在的「16hex 形态」判定）：**已删除**。
     * 它在生产代码里零调用（只有测试断言它在断言形态），而名字容易被后来者当成
     * 安全判定使用 —— 形态是可伪造的，任何 16 位 hex 都满足。信任根只有一个：
     * 数据库里由服务端写入的 `token_source='server'`（见 isTrustedToken()）。
     * 保留一句注释而不是保留函数，是为了让「为什么没有它」可被检索到。
     */

    /** 签发一个不可猜测的对外分享码 */
    public static function issueToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * 该行的分享码是否来源可信：`token_source='server'`（服务端签发时写入）。
     *
     * token_source 列不存在时（迁移窗口）保守判为不可信：宁可让本人重新发布换发一次码，
     * 也不把来源不明的码当成权威链接下发。
     */
    public static function isTrustedToken(PublishedShare $share): bool
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
    public static function isEnabled(PublishedShare $share): bool
    {
        if (! self::hasEnabledColumn()) {
            return true;
        }

        return filter_var($share->enabled, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /*
    |--------------------------------------------------------------------------
    | 读可见范围 与 写选择器 —— 刻意分开
    |--------------------------------------------------------------------------
    |
    | 上一轮把「读可见范围」（超管不过滤）直接当「写选择器」用：超管 publish 取到的是
    | 全表最新一条（可能是他人行）并 update() 改写它，原主人既看不到也停不掉自己
    | 已发出去的链接；超管 disable 又不带目标 → 一次停用全库。
    | 根因是**两个语义共用了一个查询**，所以这里从方法名上就分开：
    |   - scopedReadQuery()  读：id OR 姓名并集（容忍 id 悬挂，宁可多看见不可漏看）
    |   - ownWriteQuery()    写：只认 created_by_user_id 单键（同名不参与写选择）
    */

    /**
     * 读可见范围：本人记录（id 命中 OR 姓名/别名命中），超管不加条件。
     *
     * 复用既有归属口径 `staffOwnerFilter()`（与 leads/customers/tasks 一致）。
     * **只用于读**（展示、可见性判断）。写路径一律走 ownWriteQuery()。
     */
    private static function scopedReadQuery(Request $r, string $type)
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
     * 本人「可以写」的姓名集合：排除**同名歧义**的名字。
     *
     * 归属并集（id OR 姓名）用在读侧是对的：id 可能悬挂，姓名那一路避免漏看自己的数据。
     * 但它不能当写选择器 —— 两个同名账号会互相命中对方的历史行，进而改写归属、
     * 替换内容、互相停用（t23-03 实测：B 发布后 A 的链接变成 B 的内容）。
     *
     * 这里只保留「没有任何**其他**账号用这个名字」的姓名，把同名歧义挡在写选择之外；
     * 这类行读侧仍看得见，并会出现在孤儿/归属映射面板里等管理员处置。
     */
    private static function unambiguousOwnNames(Request $r): array
    {
        $user = $r->user();
        $names = array_values(array_unique(array_map('trim', staffNames($user))));
        if ($names === []) {
            return [];
        }

        // 其他账号占用的姓名：任何一个都不能作为我的写归属依据
        $taken = DB::table('users')
            ->whereIn('name', $names)
            ->where('id', '!=', $user->id)
            ->pluck('name')
            ->map(fn ($n) => trim((string) $n))
            ->all();
        $taken = array_flip($taken);

        return array_values(array_filter($names, fn ($n) => $n !== '' && ! isset($taken[$n])));
    }

    /**
     * 写选择器：只认 `created_by_user_id = 当前用户` 这一个确定键。
     *
     * 历史兼容只覆盖「id 为空 **且** 姓名无歧义」的行 —— 迁移窗口或回填漏掉的行仍能被
     * 本人接管，但用户改名/同名都不会让写操作命中别人。姓名并集不参与写选择。
     */
    private static function ownWriteQuery(Request $r, string $type)
    {
        $q = PublishedShare::where('type', $type);
        if (self::hasOwnerIdColumn()) {
            $names = self::unambiguousOwnNames($r);
            $q->where(function ($w) use ($r, $names) {
                $w->where('created_by_user_id', $r->user()->id);
                if ($names !== []) {
                    // 仅历史行（id 未回填）；有 id 的行绝不被姓名命中
                    $w->orWhere(function ($h) use ($names) {
                        $h->whereNull('created_by_user_id')->whereIn('created_by', $names);
                    });
                }
            });
        } else {
            $q->whereIn('created_by', self::unambiguousOwnNames($r));
        }

        return $q;
    }

    /** 单条记录是否本人可写（与 ownWriteQuery 同口径的内存版本） */
    private static function ownsForWrite(Request $r, PublishedShare $share): bool
    {
        $user = $r->user();
        if (! $user) {
            return false;
        }
        if (self::hasOwnerIdColumn() && (int) ($share->created_by_user_id ?? 0) === (int) $user->id) {
            return true;
        }
        if (self::hasOwnerIdColumn() && (int) ($share->created_by_user_id ?? 0) !== 0) {
            // 有主且不是我：姓名不作数（否则同名即可接管）
            return false;
        }

        return in_array(trim((string) $share->created_by), self::unambiguousOwnNames($r), true);
    }

    /**
     * `current` 的选址：优先**本人 id 名下的行**，没有才回退到读可见范围。
     *
     * 两个方向都要顾到：
     *  - 超管的读可见范围是「全表」，直接用 `orderByDesc('id')->first()` 必然取到
     *    最新一条（常是他人行），前端 onMounted 会把它 setShareCode 落本地并预览
     *    → 超管看到的、预览的都是别人的链接。所以超管只回本人；
     *  - 普通角色也不能只看读可见范围：同名账号（或本人改名后留下的历史行）会命中
     *    对方的行，`first()` 取到最新那条同样会显示别人的码。
     *
     * 因此统一按「先本人 id → 再历史命名行」的顺序取，两者都不越过他人 id 名下的行。
     */
    private static function currentShare(Request $r, string $type): ?PublishedShare
    {
        if (self::hasOwnerIdColumn()) {
            $own = PublishedShare::where('type', $type)
                ->where('created_by_user_id', $r->user()->id)
                ->orderByDesc('id')->first();
            if ($own) {
                return $own;
            }
        }

        // 没有 id 名下的行：只认「无主的历史命名行」（id 为空且姓名无歧义），
        // 这样同名者不会互相看到对方的记录。
        return self::ownWriteQuery($r, $type)->orderByDesc('id')->first();
    }

    /**
     * 销售分享管理端点的角色门禁（与前端菜单 MGMT 对齐）。
     *
     * 前端 `admin-web/src/router/modules/yimai.ts:7` 的谈单工具菜单是 MGMT =
     * ['R_SUPER','R_MANAGER']，后端此前没有对应判定 —— 只能靠「拿不到菜单」这种
     * 前端约束兜着，任何能带 token 发请求的角色都能调用发布/停用接口。
     */
    private static function assertCanManageSales(Request $r): void
    {
        abort_unless(
            userHasAnyRole($r->user(), self::SALES_MGMT_ROLES),
            403,
            '仅超管与店长可管理对外分享'
        );
    }

    /**
     * POST /shares/publish
     *
     * 销售分享（type=sales）的分享码一律由服务端签发：客户端传入的值不参与
     * 公开 URL 的确定（参见 publishSales）。
     */
    public function publish(Request $r)
    {
        $rules = [
            'type' => 'required|string|in:'.self::TYPES,
            'payload' => 'required|array',
            // 超管替某人发布时必须显式给目标；普通角色不接受该参数（不静默忽略）
            'ownerUserId' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
        $isSales = $r->input('type') === 'sales';
        if (! $isSales) {
            $rules['token'] = ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'];
        }
        $d = $r->validate($rules);

        // 按 type 分流门禁：sales 走 MGMT；training 保持原行为（该端点当前无前端调用方，
        // 上一轮把 MGMT 加在 publish() 入口，顺带把 training 的既有发布权限也收窄了）。
        if ($isSales) {
            self::assertCanManageSales($r);
        }

        abort_if(
            strlen((string) json_encode($d['payload'], JSON_UNESCAPED_UNICODE)) > 200000,
            422, '分享内容过大，请精简后重试'
        );

        if ($isSales) {
            return $this->publishSales($r, $d['payload']);
        }

        // 归属校验：已有同 token 分享非本人创建时，仅超管可覆盖，防止劫持他人对外 H5
        $existing = PublishedShare::where('type', $d['type'])->where('token', $d['token'])->first();
        abort_if(
            $existing && ! self::ownsForWrite($r, $existing) && ! userHasRole($r->user(), 'R_SUPER'),
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
     * GET /shares/current?type=sales[&ownerUserId=N]
     *
     * 默认返回**本人**记录的权威状态（分享码 + 是否启用 + 是否需要重新发布）。
     * 超管可显式传 `ownerUserId` 查看他人记录，此时响应带 `viewingOther=true` 与
     * `ownerName` —— 前端据此标注「这是 XX 的分享」，且**不把它当自己的码**。
     *
     * 上一轮这里用读可见范围查询，超管会拿到全表最新一条（可能是他人行），
     * 前端 onMounted 把它 setShareCode 落本地并预览 —— 超管看到的、预览的都是别人的链接。
     */
    public function current(Request $r)
    {
        $d = $r->validate([
            'type' => 'required|string|in:'.self::TYPES,
            'ownerUserId' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        if ($d['type'] === 'training') {
            return $this->trainingUnsupported();
        }
        self::assertCanManageSales($r);

        $viewingOther = false;
        $ownerName = null;

        if (! empty($d['ownerUserId']) && (int) $d['ownerUserId'] !== (int) $r->user()->id) {
            // 查看他人：仅超管，且必须在响应里说清楚「这不是你的分享」
            abort_unless(userHasRole($r->user(), 'R_SUPER'), 403, '仅超管可查看他人的对外分享');
            $viewingOther = true;
            $target = DB::table('users')->where('id', (int) $d['ownerUserId'])->first(['id', 'name']);
            abort_if($target === null, 422, '目标账号不存在');
            $ownerName = (string) $target->name;
            $share = PublishedShare::where('type', 'sales')
                ->where('created_by_user_id', (int) $d['ownerUserId'])
                ->orderByDesc('id')->first();
        } else {
            // 只回本人名下的行（超管的读可见范围是全表；同名账号也会命中对方的行）
            $share = self::currentShare($r, 'sales');
        }

        if (! $share) {
            return ok([
                'enabled' => false, 'token' => null, 'code' => null, 'views' => 0,
                'needsRepublish' => false, 'viewingOther' => $viewingOther,
                'ownerName' => $ownerName, 'ownerUserId' => $viewingOther ? (int) $d['ownerUserId'] : null,
            ]);
        }

        $trusted = self::isTrustedToken($share);
        $enabled = self::isEnabled($share);

        return ok([
            'enabled' => $enabled,
            // 只有服务端标记过来源的码才下发；形态像但来源不明的（legacy）一律不下发
            'token' => $trusted ? (string) $share->token : null,
            'code' => $trusted ? (string) $share->token : null,
            // 启用中但码不可信 → 链接实际打不开，必须显式告知「请重新开启分享换发新码」。
            // 否则界面显示「分享中」而客户打开是 404（升级瞬间的存量链接正是这种状态）。
            'needsRepublish' => $enabled && ! $trusted,
            'viewingOther' => $viewingOther,
            'ownerName' => $ownerName,
            'ownerUserId' => $viewingOther ? (int) $d['ownerUserId'] : (int) ($share->created_by_user_id ?? 0),
            'views' => (int) ($share->payload['share']['views'] ?? 0),
        ]);
    }

    /**
     * POST /shares/disable
     *
     * 关闭分享开关必须落到服务端：修复前停用只改前端状态，记录原样保留，
     * 公开接口 GET /public/sales/{token} 依旧把内容下发出去。
     *
     * 归属（这是上一轮的 high：超管关一次开关 = 停用全库）：
     *  - 默认按**本人**归属处理（写选择器单键），不带目标时不得命中他人行；
     *  - 超管的跨归属停用必须**显式指定目标**：`token`（停用具体泄漏链接）
     *    或 `ownerUserId`（停用某人的分享）。显式指定才放行，保留运维兜底能力。
     */
    public function disable(Request $r)
    {
        $d = $r->validate([
            'type' => 'required|string|in:'.self::TYPES,
            'token' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ownerUserId' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        if ($d['type'] === 'training') {
            return $this->trainingUnsupported();
        }
        self::assertCanManageSales($r);

        if (! self::hasEnabledColumn()) {
            // 迁移还没跑完：明确 503 而不是抛「列不存在」的 500，前端能给出可操作提示
            return response()->json([
                'errno' => 503,
                'emsg' => '数据库结构升级尚未完成（published_shares.enabled 缺失），请稍后重试或先完成迁移',
            ], 503);
        }

        $isSuper = userHasRole($r->user(), 'R_SUPER');
        $token = trim((string) ($d['token'] ?? ''));
        $ownerUserId = isset($d['ownerUserId']) ? (int) $d['ownerUserId'] : 0;
        $crossTarget = $token !== '' || ($ownerUserId > 0 && $ownerUserId !== (int) $r->user()->id);

        if ($crossTarget && ! $isSuper) {
            // 非超管只能停自己的；带他人目标直接拒绝，而不是静默按自己处理
            if ($token !== '') {
                $row = PublishedShare::where('type', 'sales')->where('token', $token)->first();
                abort_if($row === null, 422, '指定的分享码不存在');
                abort_unless(self::ownsForWrite($r, $row), 403, '无权停用他人创建的分享');
            } else {
                abort(403, '仅超管可停用他人的对外分享');
            }
        }

        if ($crossTarget) {
            // 显式目标：超管通道（或已通过上面的归属校验）
            $q = PublishedShare::where('type', 'sales');
            if ($token !== '') {
                $q->where('token', $token);
            }
            if ($ownerUserId > 0) {
                $q->where('created_by_user_id', $ownerUserId);
            }
        } else {
            // 无目标：只按本人归属（单键写选择器）—— 绝不落到全表
            $q = self::ownWriteQuery($r, 'sales');
        }

        $affected = $q->update(['enabled' => false]);

        if ($affected === 0 && $isSuper && ! $crossTarget) {
            // 上一轮这里返回 affected=0 却带 200，超管无从知道「其实什么都没停」；
            // 更早的形态是静默停全库。给出可读错误并指明该怎么跨归属操作。
            return response()->json([
                'errno' => 422,
                'emsg' => '未找到你本人的对外分享，未停用任何记录。如需停用他人的分享，请显式指定 token 或 ownerUserId（仅超管）',
            ], 422);
        }

        audit(
            $r, '修改', 'H5分享', 0, '对外分享', '双店',
            "类型：{$d['type']}；停用对外分享，影响 {$affected} 条"
            .($crossTarget ? '（显式目标：'.($token !== '' ? "分享码[{$token}]" : '').($ownerUserId > 0 ? "账号#{$ownerUserId}" : '').'）' : '（本人归属）')
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
     */
    private function trainingUnsupported(): JsonResponse
    {
        return response()->json([
            'errno' => 422,
            'emsg' => '训练分享请在「训练计划」页用该计划的分享开关操作（其状态存于训练计划自身）',
        ], 422);
    }

    /**
     * 销售分享发布：服务端签发（或沿用）权威 token。
     *
     * **选址只按本人**（created_by_user_id 单键；仅 id 为空且姓名无歧义的历史行可兼容命中）。
     * 超管的发布不得命中他人行 —— 上一轮用读可见范围选址，超管会取到全表最新一条
     * 并 update() 改写：复用他人 token、覆盖内容、改写归属，原主人既看不到也停不掉
     * 自己已发出的链接。超管确需替某人发布时显式传 `ownerUserId`（服务端校验账号存在）。
     *
     * 只有 `token_source='server'` 的行才沿用旧码（避免已发出的二维码作废）；
     * 其余（legacy）一律换发。
     */
    private function publishSales(Request $r, array $payload): JsonResponse
    {
        $ownerParam = $r->input('ownerUserId');
        $targetUserId = ($ownerParam !== null && $ownerParam !== '') ? (int) $ownerParam : 0;

        $actingForOther = $targetUserId > 0 && $targetUserId !== (int) $r->user()->id;
        if ($actingForOther) {
            abort_unless(userHasRole($r->user(), 'R_SUPER'), 403, '仅超管可替他人发布对外分享');
            $target = DB::table('users')->where('id', $targetUserId)->first(['id', 'name']);
            abort_if($target === null, 422, '目标账号不存在');
            $ownerName = (string) $target->name;
        } else {
            $targetUserId = (int) $r->user()->id;
            $ownerName = (string) $r->user()->name;
        }

        // 选址：显式目标按目标 id；否则纯本人写选择器（不使用读可见范围）
        $share = $actingForOther
            ? PublishedShare::where('type', 'sales')->where('created_by_user_id', $targetUserId)
                ->orderByDesc('id')->first()
            : self::ownWriteQuery($r, 'sales')->orderByDesc('id')->first();

        $token = $share && self::isTrustedToken($share)
            ? (string) $share->token
            : self::issueToken();
        // 摘要取「提交了什么」与「实际发出什么」两个数：前者是操作事实，后者是对客事实，
        // 两者不一致（被过滤掉未授权案例）正是最需要事后可查的情形。
        $summary = self::payloadSummary($payload);
        $payload = self::sanitizeSalesPayload($payload, $token, $share?->payload);

        // created_by/created_by_user_id 一律写成**目标**归属：超管替人发布后，
        // 该行仍属于被替者（而不是把归属改成超管自己）。
        $fields = ['token' => $token, 'payload' => $payload, 'created_by' => $ownerName];
        if (self::hasEnabledColumn()) {
            $fields['enabled'] = true;
        }
        if (self::hasOwnerIdColumn()) {
            $fields['created_by_user_id'] = $targetUserId;
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
        audit(
            $r, '发布', 'H5分享', 0, "分享码[{$token}]", '双店',
            '类型：sales；'.$summary.($actingForOther ? "；代发归属[{$ownerName}#{$targetUserId}]" : '')
        );

        return ok([
            'ok' => true, 'token' => $token, 'code' => $token, 'enabled' => true,
            'ownerUserId' => $targetUserId, 'ownerName' => $ownerName,
        ]);
    }

    /**
     * 快照内容摘要（进审计）。
     *
     * 原先只记「分享码 + 类型」，事后无法回答「这次对外到底发出去了什么」。
     * 摘要记**提交条数与实际发布的授权条数**，不记案例原文 —— 审计日志本身也是敏感面，
     * 不能把未授权内容换个地方再存一份。
     *
     * 「已授权」的语义必须写清楚：它是**操作者声明**，不是服务端核验过的授权事实
     * （系统里目前没有会员授权凭据的存档，见 SALES_AUTHORIZATION_SOURCE）。
     * 含糊写成「已授权」会被后来者当成核验结论，而它其实只代表发布者勾了这个框。
     */
    private static function payloadSummary(array $payload): string
    {
        $cases = is_array($payload['cases'] ?? null) ? $payload['cases'] : [];
        $authorized = count(array_filter(
            $cases,
            fn ($c) => is_array($c) && filter_var($c['authorized'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ));

        return sprintf(
            '快照摘要：产品%d项、教练%d人、案例%d条（发布者声明已授权%d/%s）',
            count(is_array($payload['products'] ?? null) ? $payload['products'] : []),
            count(is_array($payload['coaches'] ?? null) ? $payload['coaches'] : []),
            count($cases),
            $authorized,
            self::SALES_AUTHORIZATION_SOURCE
        );
    }

    /**
     * 案例「授权」标记的语义与来源。
     *
     * 目前是 `operator_declared`：服务端**没有**任何会员授权凭据的存档，
     * 该布尔来自请求体，只能代表「发布者声明已取得授权」。它足以让未勾选的内容
     * 不下发（fail-closed 过滤），但**不足以**当成「已核验授权」的证据。
     *
     * 要升级为可核验事实，需要先有服务端来源（例如会员/客户表上的授权标记、
     * 或独立的授权记录表），发布时以该记录为准而不是采信请求体布尔。
     * 在那之前，接口文档、审计与页面文案都必须保持这个措辞，避免高估其约束力。
     */
    public const SALES_AUTHORIZATION_SOURCE = 'operator_declared';

    /**
     * 组装对外快照：逐层字段白名单 + 只留已授权案例 + 权威分享码。
     *
     * 白名单是**纵深防御**：前端提交的 payload 是任意结构（`payload` 只校验了 `array`），
     * 任何多塞的字段都会原样进库、原样出公开接口。但只挡顶层键不够 ——
     * `cases[].stages[].xxx`、`info.intro` 这类内层位置一样能承载文本，
     * 换个容器就能把未授权内容带出去。所以按「对客页真正会渲染的字段」逐层收口。
     *
     * 入库与下发**共用本方法**（PublicShareController::sales 也调它），
     * 避免两侧口径漂移成「入库过滤了、下发没过滤」。
     */
    public static function sanitizeSalesPayload(array $payload, string $token, ?array $previous): array
    {
        $out = [];
        foreach (self::SALES_TOP_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $out[$field] = $payload[$field];
            }
        }

        // 单对象容器（share / info）
        foreach (self::NESTED_FIELDS as $container => $allowed) {
            if (! isset($out[$container]) || ! is_array($out[$container])) {
                continue;
            }
            $kept = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $out[$container])) {
                    $kept[$f] = $out[$container][$f];
                }
            }
            $out[$container] = $kept;
        }

        // 列表容器（products / coaches / cases）
        foreach (self::ITEM_FIELDS as $container => $allowed) {
            if (! isset($out[$container]) || ! is_array($out[$container])) {
                continue;
            }
            $out[$container] = array_values(array_filter(array_map(
                static function ($item) use ($allowed, $container) {
                    if (! is_array($item)) {
                        return null;
                    }
                    // 案例：授权是真值判定，'false'/'off'/'no' 一律视为未授权（fail-closed）
                    if ($container === 'cases'
                        && filter_var($item['authorized'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
                        return null;
                    }
                    $kept = [];
                    foreach ($allowed as $f) {
                        if (array_key_exists($f, $item)) {
                            $kept[$f] = $item[$f];
                        }
                    }
                    if ($container === 'cases') {
                        $kept['authorized'] = true;
                        $kept['stages'] = self::sanitizeStages($item['stages'] ?? null);
                    }

                    return $kept;
                },
                $out[$container]
            )));
        }

        // views：无 $previous（首次发布）时固定 0，不接受客户端传来的首值 ——
        // 客户端能随便填一个数，等于让访问量从一开始就是假的。之后只沿用库里的值。
        $views = $previous === null ? 0 : (int) ($previous['share']['views'] ?? 0);
        $out['share'] = ['enabled' => true, 'code' => $token, 'views' => $views];

        return $out;
    }

    /**
     * 阶段节点白名单：每个阶段只保留 `duration`（字符串）。
     *
     * 阶段是数组元素，形如 `[{duration:''},{duration:'第4周'}]`。若原样放行，
     * 未授权文本可以藏在 `stages[0].anything` 里随公开接口出网 ——
     * 「白名单只挡顶层键」正是上一轮被指出的缺口。纯字符串数组也接受（等价 duration）。
     */
    public static function sanitizeStages(mixed $stages): array
    {
        if (! is_array($stages)) {
            return [];
        }

        $out = [];
        foreach ($stages as $stage) {
            if (is_string($stage)) {
                $out[] = ['duration' => $stage];
                continue;
            }
            if (! is_array($stage)) {
                continue;
            }
            $kept = [];
            foreach (self::STAGE_FIELDS as $f) {
                if (array_key_exists($f, $stage)) {
                    // 只接受标量，避免在 duration 里再嵌一个任意结构
                    $kept[$f] = is_scalar($stage[$f]) || $stage[$f] === null ? (string) $stage[$f] : '';
                }
            }
            $out[] = $kept === [] ? ['duration' => ''] : $kept;
        }

        return $out;
    }

    /**
     * GET /shares/orphans（仅超管）
     *
     * 「归属孤儿行」清单：姓名查无此人 / 缺少归属 user_id / 归属 user_id 悬挂。
     * 这类行不会自己消失，且姓名那一路仍参与读可见性判断 —— 需要让管理员看见并处置，
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
     * 一次性清理/重新归属入口所需的数据：归属可疑的分享行。
     *
     * 三档 reason（与 staleOwnerUserIds() 的「id 指不到人」同口径）：
     *  - `姓名查无此人`   created_by 对不上任何 users.name / staff_aliases.alias
     *  - `缺少归属 user_id` created_by_user_id 为 null/0（迁移回填漏掉或外系统写入）
     *  - `归属 user_id 悬挂` created_by_user_id 非空但 users 表里没有该 id
     *     （账号删掉重建留下的悬挂 id，永远指错人 —— 上一轮只查 0/null，漏了这一档）
     *
     * @return array<int, array{id: int, type: string, token: string, created_by: string, created_by_user_id: int|null, reason: string}>
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
        $validIds = array_flip(
            DB::table('users')->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $out = [];
        foreach (PublishedShare::orderByDesc('id')->limit($limit)->get() as $row) {
            $name = trim((string) $row->created_by);
            $ownerId = (int) ($row->created_by_user_id ?? 0);
            $hasIdColumn = self::hasOwnerIdColumn();

            // 归一成一个「谁是这行的主人」的判定，三档 reason 互斥且按严重度排列
            $reason = null;
            if ($hasIdColumn && $ownerId > 0 && ! isset($validIds[$ownerId])) {
                $reason = '归属 user_id 悬挂';
            } elseif ($name === '' || ! isset($names[$name])) {
                $reason = '姓名查无此人';
            } elseif ($hasIdColumn && $ownerId === 0) {
                $reason = '缺少归属 user_id';
            }

            if ($reason !== null) {
                $out[] = [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    'token' => (string) $row->token,
                    'created_by' => $name,
                    'created_by_user_id' => $hasIdColumn ? ($ownerId ?: null) : null,
                    'reason' => $reason,
                ];
            }
        }

        return $out;
    }

    /**
     * 一次性「清理/重新归属」：把孤儿行指派给指定账号（或停用）。
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
