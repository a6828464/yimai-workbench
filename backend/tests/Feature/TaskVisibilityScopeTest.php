<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 任务可见范围收口（t17）回归测试。
 *
 * 背景：任务可见范围此前在 6 处各写一遍（TaskController::index、TodayController 的
 * summary/alerts/todo、helpers::businessNotifications、AnalyticsController 的 totalTasks）。
 * 每个出口都得自己记得补兜底，于是「角色不明」账号（roles 漏写、或 role 是已下架的旧角色码）
 * 在 /api/today/todo、/api/today/alerts 上能拿到**他人**任务的标题与客户姓名，
 * /api/today/summary 与 /api/analytics/summary 还把它们计进数字。
 *
 * 现在收口到 helpers.php 的 scopeTasksForUser()，本文件钉三件事：
 *
 *  1. 角色不明账号在全部六个出口都拿不到任何任务（含本人名下那条，见下）；
 *  2. 既有五种角色与多角色叠加账号：六个出口的行为**与收口前逐字一致**。
 *     期望值不是照代码推的，而是收口前跑探针 dump 出来的实测矩阵（避免把「我以为的口径」
 *     写进测试，也避免这次重构顺手改了别人的产品行为）；
 *  3. 出口之间存在**历史口径差异**（同一账号在 /api/tasks 与 /api/today/summary 上口径不同），
 *     这里刻意保留并钉住，提醒后来者：统一它们需要产品确认，不是顺手改。
 */
class TaskVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    // ================================================== 1. 角色不明：六个出口全空

    /**
     * 角色不明账号在六个出口上都不返回任何任务数据。
     *
     * 两条形态都测：`roles` 里有旧角色码（R_LEGACY）、以及 `roles` 为空回退到 `role` 单值。
     * 修复前实测：todo 3 条（含「绿地他人」）、alerts 4 条（含跨店「东部他人」标题+客户姓名）、
     * riskCount=4、totalTasks=4、通知 tasks-6 —— 每一项都是他人的信息。
     */
    public function test_unknown_role_account_gets_no_tasks_from_any_surface(): void
    {
        $this->seedTasks();

        foreach (['rolecol' => ['R_LEGACY'], 'fallback' => null] as $label => $roles) {
            $user = $this->user("unknown-{$label}", '他人姓名', 'R_LEGACY', '绿地店');
            if ($roles !== null) {
                $user->forceFill(['roles' => $roles])->save();
            }
            Sanctum::actingAs(User::findOrFail($user->id));

            $this->assertSame([], $this->titles('/api/tasks'), "{$label}: /api/tasks 不该返回任务");
            $this->assertSame([], $this->titles('/api/today/todo', 'data.tasks'), "{$label}: /api/today/todo 不该返回任务");
            $this->assertSame([], $this->taskAlertTexts(), "{$label}: /api/today/alerts 不该返回任务提醒");
            $this->assertSame(0, $this->data('/api/today/summary')['riskCount'], "{$label}: riskCount 不该含他人任务");
            $this->assertSame(0, $this->data('/api/analytics/summary')['totalTasks'], "{$label}: totalTasks 不该含他人任务");
            $this->assertNull($this->taskNotification(), "{$label}: 通知不该出现任务待办");
        }
    }

    /**
     * 经营看板的 venue 口径（applyVenueScope）同样对角色不明账号失败关闭。
     *
     * 该函数是看板所有计数（任务/客资/卡项/课次）共用的唯一 venue 入口，
     * 原先的 else 分支会把范围压成 `venue = 本人门店` —— 角色不明账号的门店恰好是本人门店时，
     * 本店**他人**的数字就被计了进来。收口后看板对这类账号整体为 0。
     */
    public function test_unknown_role_account_gets_no_venue_scoped_analytics(): void
    {
        $this->seedTasks();
        \App\Models\Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '绿地他人客资', 'phone' => '13900000100',
            'source' => '到店', 'venue' => '绿地店', 'status' => '新留资',
        ]);

        $user = $this->user('unknown-venue', '他人姓名', 'R_LEGACY', '绿地店');
        Sanctum::actingAs(User::findOrFail($user->id));

        $summary = $this->data('/api/analytics/summary');
        $this->assertSame(0, $summary['totalTasks'], '看板 totalTasks 不该含他人任务');
        $this->assertSame(0, $summary['totalCustomers'], '看板会员数应为 0（与 t8 口径一致）');
        // 看板的客资口径同样不得泄露他人数据
        $this->assertSame(0, $this->data('/api/analytics/channels')['total'], '看板渠道分析不该含他人客资');
    }

    /**
     * 本人名下的任务同样不返回 —— 兜底是空集，不是「按人放行」。
     *
     * 理由（同 t8/t15 的口径）：角色不明意味着「这个账号是谁」未被确认，而本人判定走
     * staffOwnerFilter（id 或姓名/别名任一命中），此时按人放行仍是在猜；且必须与
     * /api/customers 对这类账号返回空集（t8）的结论一致，否则两个出口互相矛盾。
     */
    public function test_unknown_role_account_does_not_even_see_own_named_task(): void
    {
        Task::create([
            'title' => '挂本人名字的任务', 'customer_name' => '甲', 'venue' => '绿地店',
            'owner' => '他人姓名', 'status' => '已逾期',
            'deadline' => now()->subDay()->format('Y-m-d H:i'),
        ]);

        $user = $this->user('unknown-own', '他人姓名', 'R_LEGACY', '绿地店');
        Sanctum::actingAs($user);

        $this->assertSame(0, $this->data('/api/tasks')['total']);
        $this->assertSame(0, $this->data('/api/today/summary')['riskCount']);
    }

    // ============================== 2. 五种角色 + 多角色叠加：与收口前逐字一致

    /**
     * 既有角色的六个出口行为与收口前实测矩阵逐字一致（不得误伤）。
     *
     * 期望值来自收口前对 HEAD 代码跑探针的 dump（15 种角色组合 × 6 个出口），
     * 收口后重跑 diff 为**完全一致的零差异**。
     *
     * 任务数据（9 条，全部已逾期以便各出口的基础过滤退化一致）：
     *   G本人/G同事/G待认领/G老师本人/G顾问二（绿地店）、E本人/E同事/E待认领/E老师本人（东部店）
     *   「本人」按姓名判定：绿地顾问 / 绿地老师。
     */
    public function test_known_role_task_scopes_match_pre_refactor_matrix(): void
    {
        $this->seedTasks();

        // [角色, 门店, 期望 list, 期望 todo, 期望 alert, 期望 riskCount, 期望 totalTasks, 期望 notif 数]
        $matrix = [
            ['R_SUPER', null,
                'E同事 E待认领 E本人 E老师本人 G同事 G待认领 G本人 G老师本人 G顾问二',
                'E同事 E待认领 E本人 E老师本人 G同事 G待认领 G本人 G老师本人 G顾问二',
                'E本人-E本人 G同事-G同事 G待认领-G待认领 G本人-G本人', 9, 9, 9],
            ['R_MANAGER', '绿地店',
                'G同事 G待认领 G本人 G老师本人 G顾问二',
                'G同事 G待认领 G本人 G老师本人 G顾问二',
                'G同事-G同事 G待认领-G待认领 G本人-G本人 G老师本人-G老师本人', 5, 5, 5],
            ['R_SERVICE', '绿地店',
                'G待认领 G本人', 'G待认领 G本人',
                'G待认领-G待认领 G本人-G本人', 4, 5, 1],
            ['R_TEACHER', '绿地店',
                'G本人', 'G本人', 'G本人-G本人', 2, 5, 1],
            ['R_MEDIA', null,
                'E本人 G本人', 'E本人 G本人', '', 2, 9, 2],
        ];

        foreach ($matrix as [$role, $venue, $list, $todo, $alert, $risk, $total, $notif]) {
            $this->assertSurfaceMatrix($role, [$role], $venue, '绿地顾问', $list, $todo, $alert, $risk, $total, $notif);
        }
    }

    /** 多角色叠加：口径与收口前一致（不同出口的叠加规则不同，见下）。 */
    public function test_multi_role_task_scopes_match_pre_refactor_matrix(): void
    {
        $this->seedTasks();

        $combos = [
            // list/alerts/todo/summary 是逐条 AND（叠加更窄）；notify 是首个命中（更大范围）
            [['R_SUPER', 'R_MANAGER'], null, '', 'E同事 E待认领 E本人 E老师本人 G同事 G待认领 G本人 G老师本人 G顾问二', '', 0, 9, 0],
            [['R_SUPER', 'R_SERVICE'], null, '', 'E待认领 E本人 G待认领 G本人', '', 4, 9, 0],
            [['R_SUPER', 'R_TEACHER'], null, '', 'E本人 G本人', '', 2, 9, 0],
            [['R_SUPER', 'R_MEDIA'], null, 'E本人 G本人', 'E本人 G本人', '', 2, 9, 2],
            [['R_MANAGER', 'R_SERVICE'], '绿地店', 'G待认领 G本人', 'G待认领 G本人', 'G待认领-G待认领 G本人-G本人', 2, 5, 5],
            [['R_MANAGER', 'R_TEACHER'], '绿地店', 'G本人', 'G本人', 'G本人-G本人', 1, 5, 5],
            [['R_MANAGER', 'R_MEDIA'], '绿地店', 'G本人', 'E本人 G本人', '', 1, 5, 5],
            [['R_SERVICE', 'R_TEACHER'], '绿地店', 'G本人', 'G本人', 'G本人-G本人', 2, 5, 1],
            [['R_SERVICE', 'R_MEDIA'], '绿地店', 'G本人', 'E本人 G本人', '', 2, 5, 1],
            [['R_TEACHER', 'R_MEDIA'], '绿地店', 'G本人', 'E本人 G本人', '', 2, 5, 1],
        ];

        foreach ($combos as [$roles, $venue, $list, $todo, $alert, $risk, $total, $notif]) {
            $this->assertSurfaceMatrix(implode('+', $roles), $roles, $venue, '绿地顾问', $list, $todo, $alert, $risk, $total, $notif);
        }
    }

    /**
     * 出口之间的历史口径差异被刻意保留 —— 这些断言是「提醒」而不是「期望」。
     *
     * 同一服务老师账号：列表只含本人+待认领（`:list`）、但 summary 的 riskCount 更大、
     * 经营看板 totalTasks 又是整店口径。三个数字互不相等，用户在页面上就会看到「任务数对不上」。
     * 收口本次只消除重复实现，**没有**统一这些口径（统一需要产品确认）；
     * 这些用例把差异钉住，避免有人「顺手统一」而改变前端已适配的展示。
     */
    public function test_surface_specific_legacy_differences_are_pinned(): void
    {
        $this->seedTasks();

        $service = $this->user('diff-service', '绿地顾问', 'R_SERVICE', '绿地店');
        Sanctum::actingAs($service);

        // 列表口径：绿地店 + (本人 ∨ 待认领) = 2 条
        $this->assertSame('G待认领 G本人', $this->shrunk($this->titles('/api/tasks')));
        // summary 口径：不限门店的 (本人 ∨ 待认领) = 4 条（含东部店两条）
        $this->assertSame(4, $this->data('/api/today/summary')['riskCount']);
        // 经营看板口径：本店全部 = 5 条
        $this->assertSame(5, $this->data('/api/analytics/summary')['totalTasks']);
        // 通知口径：本店 + 本人（不含待认领）= 1 条
        $this->assertSame('tasks-1', $this->taskNotification()['key']);
    }

    // ------------------------------------------------------------------ 断言工具

    /** 断言某个角色在六个出口上的输出（期望值取自收口前实测矩阵）。 */
    private function assertSurfaceMatrix(
        string $label,
        array $roles,
        ?string $venue,
        string $name,
        string $list,
        string $todo,
        string $alert,
        int $risk,
        int $total,
        int $notif
    ): void {
        $user = $this->user('mx-'.md5($label.$name), $name, $roles[0], $venue);
        $user->forceFill(['roles' => $roles])->save();
        Sanctum::actingAs(User::findOrFail($user->id));

        $this->assertSame($list, $this->shrunk($this->titles('/api/tasks')), "{$label}: /api/tasks");
        $this->assertSame($todo, $this->shrunk($this->titles('/api/today/todo', 'data.tasks')), "{$label}: /api/today/todo");
        $this->assertSame($alert, $this->shrunk($this->taskAlertTexts()), "{$label}: /api/today/alerts");
        $this->assertSame($risk, $this->data('/api/today/summary')['riskCount'], "{$label}: riskCount");
        $this->assertSame($total, $this->data('/api/analytics/summary')['totalTasks'], "{$label}: totalTasks");

        $item = $this->taskNotification();
        $this->assertSame($notif === 0 ? null : "tasks-{$notif}", $item['key'] ?? null, "{$label}: 通知");
    }

    // ------------------------------------------------------------------ 数据准备

    /**
     * 9 条已逾期任务。全部「已逾期 + 有 deadline」，使各出口自身的基础过滤
     * （list 不过滤、alerts 卡已逾期、todo 卡未完成+有 deadline 且不晚于今天）
     * 退化为同一集合，这样横向比较才是在比「可见范围」而不是比各自的场景过滤。
     */
    private function seedTasks(): void
    {
        foreach ([
            ['绿地本人', '绿地店', '绿地顾问'],
            ['绿地同事', '绿地店', '其他老师'],
            ['绿地待认领', '绿地店', '未分配'],
            ['东部本人', '东部店', '绿地顾问'],
            ['东部同事', '东部店', '东部老师'],
            ['东部待认领', '东部店', '未分配'],
            ['绿地老师本人', '绿地店', '绿地老师'],
            ['东部老师本人', '东部店', '绿地老师'],
            ['绿地顾问二', '绿地店', '绿地顾问2'],
        ] as [$title, $venue, $owner]) {
            Task::create([
                'title' => $title,
                'customer_name' => $title,
                'venue' => $venue,
                'owner' => $owner,
                'status' => '已逾期',
                'deadline' => now()->subDay()->format('Y-m-d H:i'),
            ]);
        }
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    // ------------------------------------------------------------------ HTTP 取数

    private function data(string $url): array
    {
        return $this->getJson($url)->assertOk()->json('data');
    }

    /** @return string[] */
    private function titles(string $url, string $key = 'data.records'): array
    {
        return collect($this->getJson($url)->assertOk()->json($key))->pluck('title')->all();
    }

    /** @return string[] */
    private function taskAlertTexts(): array
    {
        return collect($this->data('/api/today/alerts'))
            ->pluck('text')
            ->filter(fn ($t) => str_starts_with((string) $t, '任务'))
            ->map(fn ($t) => preg_replace('/^任务「|」已逾期$/u', '', (string) $t))
            ->values()->all();
    }

    private function taskNotification(): ?array
    {
        return collect($this->data('/api/notifications')['items'])
            ->firstWhere(fn ($i) => str_starts_with((string) $i['key'], 'tasks-'));
    }

    /** 排序 + 门店名缩写，便于与紧凑矩阵逐字比对。 */
    private function shrunk(array $values): string
    {
        $values = array_map(fn ($v) => str_replace(['绿地', '东部'], ['G', 'E'], (string) $v), $values);
        sort($values);

        return implode(' ', $values);
    }
}
