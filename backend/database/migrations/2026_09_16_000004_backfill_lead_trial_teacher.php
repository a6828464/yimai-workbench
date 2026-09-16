<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 把逐节体验课卡片里的老师回填到顶层 trial_teacher
 *
 * 背景：留资的老师原先填在顶层的单节字段 `trial_teacher`，后来体验课改成**逐节卡片**
 * （`trial_cards[]`，每节各有上课时间/主题/老师/券信息），编辑入口也搬到卡片里，
 * 但顶层字段没有跟着维护 —— 于是：
 *
 * 1. 列表的「上课老师」列一直显示空（它读的是顶层字段）；
 * 2. 老师的**可见性**受影响：`scopeLeadsForUser` 用 `trial_teacher` 判断"这条留资是不是
 *    我上的课"，只填卡片的老师会看不到自己上过的课。
 *
 * 这里把每条第 1 个非空老师的卡片值回填上去，并把对应账号 id 一并落库。
 * 之后由 LeadController::withStaffIds() 在每次保存时保持同步（跟着卡片走）。
 *
 * 多节不同老师时只镜像第一节：顶层字段只有一个位置，它的定位是"主要上课老师"；
 * 完整信息在卡片里，列表页会从卡片取全部老师名展示。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'trial_cards')) {
            return;
        }

        // 姓名 → 账号 id（含别名），一次取好，避免逐行查库
        $staffMap = staffNameToIdMap();

        $filled = 0;
        DB::table('leads')
            ->whereNotNull('trial_cards')
            ->where('trial_cards', '!=', '[]')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($staffMap, &$filled) {
                foreach ($rows as $row) {
                    $cards = json_decode((string) $row->trial_cards, true);
                    if (! is_array($cards)) {
                        continue;
                    }

                    $teacher = '';
                    foreach ($cards as $card) {
                        $t = trim((string) (is_array($card) ? ($card['teacher'] ?? '') : ''));
                        if ($t !== '') {
                            $teacher = $t;
                            break;
                        }
                    }
                    if ($teacher === '') {
                        continue; // 卡片里也没填老师，保持原样
                    }

                    $patch = ['trial_teacher' => $teacher];
                    if (isset($staffMap[$teacher])) {
                        $patch['trial_teacher_user_id'] = $staffMap[$teacher];
                    }
                    DB::table('leads')->where('id', $row->id)->update($patch);
                    $filled++;
                }
            });

        if ($filled > 0) {
            logger()->info('留资上课老师回填完成', ['rows' => $filled]);
        }
    }

    public function down(): void
    {
        // 回填是幂等的派生数据，不提供回滚（清掉反而会让列表和可见性退回原来的问题）
    }
};
