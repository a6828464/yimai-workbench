<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * published_shares：归属落地 user_id + 分享码来源标记。
 *
 * 这一步修的是上一轮修复留下的两个软肋（都能被绕过，只是绕过成本不同）：
 *
 * ## 1. 归属只认姓名
 *
 * 上一轮 publishSales/current/disable 一律 `where('created_by', $user->name)`。
 * 姓名不是稳定标识：账号改名、同名、或历史行是别人代建时，归属判定就会错
 * （改过名的老师看不到自己的分享，同名时两个账号抢同一条记录）。
 * 这里照抄既有模式 `2026_09_16_000003_add_owner_user_ids.php`：**写入时就把 id 记下来**，
 * id 不随改名变化；同时**姓名这一路保留**作为兼容并集（`staffOwnerFilter()` 口径：
 * id 命中 OR 姓名/别名命中），所以回填不全也不会让人看不到自己的数据。
 *
 * ## 2. 「服务端签发」只靠形态判断
 *
 * 上一轮用 `token` 是否形如 16 位十六进制来判断「这是服务端签发的码」。形状是**可伪造的**
 * —— 任何 16 位 hex 字符串都满足，它只说明「看起来像」，不说明来源。
 * 真正的信任根必须是**服务端写入、客户端无法声称**的字段：这里加 `token_source`
 * （server | legacy）。读侧只认 token_source='server' 的行；publishSales 也只在
 * token_source='server' 时才沿用旧码，其余一律换发。
 *
 * 存量行的处理（保守、fail-closed）：迁移前库里所有行都不是本套新代码签发的，
 * 因此一律标为 'legacy' —— 宁可让本人下次发布时换发一次码（旧链接失效），
 * 也不放过任何可能是客户端可猜/伪造的码。这一步同时探测「存量里是否已有 16hex 的
 * sales 行」：有就记 warning，因为那说明线上可能存在被人猜中后长期有效的链接，
 * 需要人工确认后再发布换发。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('published_shares')) {
            return;
        }

        if (! Schema::hasColumn('published_shares', 'created_by_user_id')) {
            Schema::table('published_shares', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index()->after('created_by');
            });
        }

        if (! Schema::hasColumn('published_shares', 'token_source')) {
            Schema::table('published_shares', function (Blueprint $table) {
                // 默认 legacy：存量行没有一行是本套新代码签发的，保守起步
                $table->string('token_source', 16)->default('legacy')->after('token');
            });
        }

        $this->backfillOwnerIds();
        $this->flagLegacyTokenSources();
    }

    /**
     * 按姓名（含别名）回填 created_by_user_id，只认唯一命中（与既有 owner_user_ids 同口径）。
     *
     * 姓名同时对上多个账号时跳过：这种情况该由管理员在「人员管理 → 归属映射」里理清，
     * 凭猜测回填等于制造错误归属；跳过也不会丢数据，姓名那一路过滤仍能让本人看到。
     */
    private function backfillOwnerIds(): void
    {
        $map = [];
        $ambiguous = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $u) {
            $name = trim((string) $u->name);
            if ($name === '') {
                continue;
            }
            if (isset($map[$name])) {
                $ambiguous[$name] = true;
            } else {
                $map[$name] = (int) $u->id;
            }
        }
        if (Schema::hasTable('staff_aliases')) {
            foreach (DB::table('staff_aliases')->orderBy('id')->get(['user_id', 'alias']) as $a) {
                $alias = trim((string) $a->alias);
                if ($alias === '') {
                    continue;
                }
                if (isset($map[$alias])) {
                    $ambiguous[$alias] = true;
                } else {
                    $map[$alias] = (int) $a->user_id;
                }
            }
        }
        foreach (array_keys($ambiguous) as $name) {
            unset($map[$name]);
        }

        foreach ($map as $name => $userId) {
            DB::table('published_shares')
                ->where('created_by', $name)
                ->whereNull('created_by_user_id')
                ->update(['created_by_user_id' => $userId]);
        }
    }

    /**
     * 存量行一律 'legacy'；顺带探测「影响面」并告警。
     *
     * 标 legacy 之后，**所有**存量销售链接都会在下一次访问时 404（读侧只认
     * token_source='server'）—— 这是保守方向的必要代价，但运维必须能一眼看到
     * 「这次升级会让多少条正在流通的链接失效」，否则上线预案无从做起。
     *
     * 因此除了 16hex 计数（来源可疑、需人工确认），另记**生效中行的总数**
     * （升级瞬间会失效的链接数）。这里只记 warning、不阻断迁移：
     * 阻断会让线上停在半新半旧，比多一次重新发布更糟。
     */
    private function flagLegacyTokenSources(): void
    {
        DB::table('published_shares')->whereNull('token_source')->update(['token_source' => 'legacy']);

        $suspicious = 0;
        $affectedEnabled = 0;
        $affectedEnabledHex = 0;
        $hasEnabled = Schema::hasColumn('published_shares', 'enabled');

        DB::table('published_shares')
            ->where('type', 'sales')
            ->orderBy('id')
            ->select(array_values(array_filter(['id', 'token', $hasEnabled ? 'enabled' : null])))
            ->chunk(500, function ($rows) use (&$suspicious, &$affectedEnabled, &$affectedEnabledHex, $hasEnabled) {
                foreach ($rows as $row) {
                    $isHex = preg_match('/^[0-9a-f]{16}$/', (string) $row->token) === 1;
                    if ($isHex) {
                        $suspicious++;
                    }
                    // enabled 列不存在时（迁移顺序异常）按「视为启用」计，宁可高报影响面
                    $enabled = ! $hasEnabled || filter_var($row->enabled ?? true, FILTER_VALIDATE_BOOLEAN) === true;
                    if ($enabled) {
                        $affectedEnabled++;
                        if ($isHex) {
                            $affectedEnabledHex++;
                        }
                    }
                }
            });

        if ($affectedEnabled > 0 || $suspicious > 0) {
            logger()->warning(
                'published_shares 存量销售分享已标记为 legacy：升级后这些链接一律不可读，'
                .'需由各门店重新开启一次 H5 分享以换发新码。'
                .'另注意存量中存在 16hex 形态的码，来源不可信（迁移前无签发标记），'
                .'建议人工确认其是否仍在对外流通。',
                [
                    // 升级瞬间会失效的生效中链接数（运维据此评估影响面、安排重新发布）
                    'affectedEnabledRows' => $affectedEnabled,
                    // 其中形态像服务端签发、但来源不可信的那些（需人工确认）
                    'affectedEnabledHexRows' => $affectedEnabledHex,
                    'hexShapedRows' => $suspicious,
                    'risk' => 'high',
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('published_shares')) {
            return;
        }

        if (Schema::hasColumn('published_shares', 'token_source')) {
            Schema::table('published_shares', function (Blueprint $table) {
                $table->dropColumn('token_source');
            });
        }

        if (Schema::hasColumn('published_shares', 'created_by_user_id')) {
            Schema::table('published_shares', function (Blueprint $table) {
                $table->dropColumn('created_by_user_id');
            });
        }
    }
};
