<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Support\CardLifecycle;
use Illuminate\Console\Command;

/**
 * 单会员「待续费」判定解释（只读）。
 *
 * 回答的是**「这个人为什么在 / 不在待续费清单里」**，因此必须打印每个命中项的
 * 布尔表达式与实参，而不是只给一句 why 文案（规范 §7.5）。
 *
 * 只读承诺：本命令不写库、不重算分层、不失效缓存 —— 它的用途正是「在不动数据的前提下
 * 复核口径」。唯一的口径来源是 `customerDecision()`，与五清单/分层/续费评估同一份结论。
 *
 * 用法：
 *   php artisan members:explain-cards 1
 *   php artisan members:explain-cards 1 --json
 *   php artisan members:explain-cards 1 --rules=/tmp/rules.json
 */
class MembersExplainCards extends Command
{
    protected $signature = 'members:explain-cards
        {customer : 会员 id / external_id / 手机号}
        {--json : 以 JSON 输出（供脚本与前端对接）}
        {--rules= : 用 JSON 文件覆盖阈值配置（只影响本次输出，不落库）}';

    protected $description = '逐卡解释某个会员的「待续费」判定（状态/系列/命中/未命中原因，只读）';

    /** 主名之外的兼容别名（任务简报用 members:explain-cards，规范 §7.5 用 members:explain） */
    protected $aliases = ['members:explain'];

    public function handle(): int
    {
        $rules = rules();
        $path = $this->option('rules');
        if (is_string($path) && $path !== '') {
            if (! is_file($path)) {
                $this->error("规则文件不存在：{$path}");

                return self::FAILURE;
            }
            $override = json_decode((string) file_get_contents($path), true);
            if (! is_array($override)) {
                $this->error("规则文件不是合法 JSON 对象：{$path}");

                return self::FAILURE;
            }
            $rules = array_merge($rules, $override);
        }

        $customer = $this->resolveCustomer((string) $this->argument('customer'));
        if (! $customer) {
            $this->error('未找到该会员（可用 id / external_id / 手机号）');

            return self::FAILURE;
        }

        $decision = customerDecision($customer, $rules);
        $payload = $this->payload($customer, $decision, $rules);

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($payload, $rules);

        return self::SUCCESS;
    }

    private function resolveCustomer(string $key): ?Customer
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        if (ctype_digit($key)) {
            $found = Customer::find((int) $key);
            if ($found) {
                return $found;
            }
        }
        $byExternal = Customer::where('external_id', $key)->first();
        if ($byExternal) {
            return $byExternal;
        }
        $phone = (string) preg_replace('/\D+/', '', $key);
        if ($phone !== '') {
            return Customer::where('phone', $phone)->first();
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function payload(Customer $customer, array $decision, array $rules): array
    {
        $renewal = $decision['renewal'];

        $sets = ['current' => [], 'unactivated' => [], 'history' => [], 'usable' => [], 'exhausted' => [], 'unknown' => [], 'expiredBalance' => []];
        foreach ($renewal['instances'] as $instance) {
            $id = (string) ($instance['id'] ?? $instance['cardNo'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($instance['state'] === 'current') {
                $sets['current'][] = $id;
            } elseif ($instance['state'] === 'unactivated') {
                $sets['unactivated'][] = $id;
            } else {
                $sets['history'][] = $id;
            }
        }
        foreach ($decision['expiredBalance']['cards'] as $card) {
            $id = (string) ($card['id'] ?? $card['cardNo'] ?? '');
            if ($id !== '') {
                $sets['expiredBalance'][] = $id;
            }
        }
        // USABLE/EXHAUSTED/UNKNOWN 用命中判定同源表达式复算（不信任任何缓存字段）
        foreach ($renewal['instances'] as $instance) {
            if ($instance['state'] !== 'current') {
                continue;
            }
            $normalized = $instance + ['statusCode' => $instance['statusCode'] ?? null, 'isTaste' => false];
            $id = (string) ($instance['id'] ?? $instance['cardNo'] ?? '');
            if ($id === '') {
                continue;
            }
            if (CardLifecycle::isUsable($normalized)) {
                $sets['usable'][] = $id;
            } elseif (CardLifecycle::isExhausted($normalized)) {
                $sets['exhausted'][] = $id;
            } elseif (CardLifecycle::isUnknown($normalized)) {
                $sets['unknown'][] = $id;
            }
        }

        return [
            'customerId' => $customer->id,
            'name' => $customer->name,
            'source' => $customer->external_id,
            'rules' => [
                'renewalThreshold' => (int) ($rules['renewalThreshold'] ?? 10),
                'renewalCountPercent' => (int) ($rules['renewalCountPercent'] ?? 0),
                'renewalExpireDays' => (int) ($rules['renewalExpireDays'] ?? 30),
                'renewalExpirePercent' => (int) ($rules['renewalExpirePercent'] ?? 0),
                'expiredBalanceWindowDays' => CardLifecycle::expiredBalanceWindow($rules),
                'renewalStoredValueThreshold' => (int) ($rules['renewalStoredValueThreshold'] ?? 0),
                'tasteTitlePattern' => CardLifecycle::tastePattern($rules),
            ],
            'dataVersion' => $decision['dataVersion'],
            'flags' => $renewal['flags'],
            'instances' => array_map(function (array $instance) {
                return [
                    'id' => $instance['id'],
                    'cardNo' => $instance['cardNo'],
                    'cardId' => $instance['cardId'],
                    'title' => $instance['title'],
                    'type' => $instance['type'],
                    'unit' => $instance['unit'],
                    'statusCode' => $instance['statusCode'],
                    'state' => $instance['state'],
                    'historyReason' => $instance['historyReason'],
                    'deadline' => $instance['deadline'],
                    'daysUntil' => $instance['daysUntil'],
                    'remaining' => $instance['remaining'],
                    'remainingSource' => $instance['remainingSource'],
                    'validDays' => $instance['validDays'],
                    'bound' => $instance['bound'],
                    'seriesIndex' => $instance['seriesIndex'],
                    'seriesCount' => $instance['seriesCount'],
                    'isTaste' => $instance['isTaste'],
                    'note' => $instance['note'],
                    'degraded' => $instance['degraded'],
                ];
            }, $renewal['instances']),
            'sets' => $sets,
            'hits' => $renewal['hits'],
            'why' => $renewal['why'],
            'whyCodes' => $renewal['whyCodes'],
            'suppressedCodes' => $renewal['suppressedCodes'],
            'degraded' => $renewal['degraded'],
            'degradedCodes' => $renewal['degradedCodes'],
            'bucket' => $renewal['bucket'],
            'urgent' => $renewal['urgent'],
            'in' => $renewal['in'],
            'layer' => $decision['layer'],
            'expiredWithBalance' => $decision['expiredWithBalance'],
            'expiredBalance' => $decision['expiredBalance'],
            'bomb' => $decision['bomb'],
            'display' => $decision['display'],
        ];
    }

    private function renderHuman(array $payload, array $rules): void
    {
        $source = (string) ($payload['source'] ?? '') !== '' ? (string) $payload['source'] : '—';
        $this->line("会员 #{$payload['customerId']} {$payload['name']}（{$source}）dataVersion={$payload['dataVersion']} layer={$payload['layer']}");
        $this->line('');
        $this->line('实例：');
        foreach ($payload['instances'] as $instance) {
            $head = sprintf(
                '  %s「%s」type=%d/%s status=%s state=%s%s deadline=%s daysUntil=%s remaining=%s',
                $instance['id'] ?? '-',
                $instance['title'] !== '' ? $instance['title'] : '(无卡名)',
                $instance['type'],
                $instance['unit'],
                $instance['statusCode'] ?? 'null',
                $instance['state'],
                $instance['historyReason'] ? '('.$instance['historyReason'].')' : '',
                $instance['deadline'] ?? 'null',
                $instance['daysUntil'] === null ? 'null' : (string) $instance['daysUntil'],
                $instance['remaining'] === null ? 'null' : (string) $instance['remaining'].'('.$instance['remainingSource'].')'
            );
            $this->line($head);
            if ($instance['seriesCount'] !== null && $instance['seriesCount'] > 1) {
                $this->line("    ↳ 同名第 {$instance['seriesIndex']} 张 / 共 {$instance['seriesCount']} 张");
            }
            if ($instance['note'] !== null) {
                $this->line('    ↳ '.$instance['note']);
            }
            foreach ($this->ruleLines($instance, $rules) as $line) {
                $this->line($line);
            }
        }

        $this->line('');
        $this->line('集合：'.implode(' ', array_map(
            fn ($key, $ids) => $key.'='.($ids === [] ? '∅' : '{'.implode(',', $ids).'}'),
            array_keys($payload['sets']),
            array_values($payload['sets'])
        )));
        $this->line('');
        if ($payload['hits'] === []) {
            $this->line('命中：∅');
        } else {
            $this->line('命中：');
            foreach ($payload['hits'] as $hit) {
                $this->line("  {$hit['code']}: {$hit['expr']} → true");
                $this->line('    '.$hit['why']);
            }
        }
        if ($payload['suppressedCodes'] !== []) {
            $this->line('被 K1（待开卡）压制、只进诊断的命中码：'.implode(', ', $payload['suppressedCodes']));
        }
        if ($payload['degraded'] !== []) {
            $this->line('');
            $this->line('降级（degraded）：');
            foreach ($payload['degraded'] as $message) {
                $this->line('  - '.$message);
            }
        }
        if ($payload['expiredWithBalance']) {
            $this->line('');
            $this->line('过期有余额（不进待续费，仅 P3/筛选标签）：');
            foreach ($payload['expiredBalance']['why'] as $message) {
                $this->line('  - '.$message);
            }
        }
        if ($payload['bomb']['in']) {
            $this->line('');
            $this->line('炸弹会员：沉睡 '.$payload['bomb']['sections'].' 节 / '.count($payload['bomb']['cards']).' 张卡');
        }
        $this->line('');
        $this->line(sprintf(
            '结论：in=%s bucket=%s urgent=%s layer=%s（K0 %s；K1 %s；K2 %s）',
            $payload['in'] ? 'true' : 'false',
            $payload['bucket'],
            $payload['urgent'] ? 'true' : 'false',
            $payload['layer'],
            $payload['flags']['hasAnyCard'] ? '不成立（有卡）' : '成立（无资产→P5）',
            $payload['bucket'] === '待开卡' ? '成立（USABLE=∅ ∧ UNACT≠∅）' : '不成立',
            $payload['hits'] === [] ? '不成立（HITS=∅）' : '成立（HITS≠∅）'
        ));
        if ($payload['in']) {
            $this->line('why：'.implode('；', $payload['why']));
        }
    }

    /**
     * 逐实例规则求值（打印表达式与实参，而不是结论口号）。
     *
     * @return string[]
     */
    private function ruleLines(array $instance, array $rules): array
    {
        $threshold = (int) ($rules['renewalThreshold'] ?? 10);
        $countPercent = (int) ($rules['renewalCountPercent'] ?? 0);
        $expireDays = (int) ($rules['renewalExpireDays'] ?? 30);
        $expirePercent = (int) ($rules['renewalExpirePercent'] ?? 0);
        $storedThreshold = (int) ($rules['renewalStoredValueThreshold'] ?? 0);

        $normalized = $instance;
        // 实例快照里的 state/remaining 已经由 CardLifecycle 归一化过，可直接复用谓词。
        if ($instance['state'] !== 'current') {
            $reason = $instance['state'] === 'unactivated' ? '未开卡实例（无 deadline）' : '历史实例';

            return ["    → {$reason}，不在命中域（§4.3）"];
        }

        $lines = [];
        if (in_array($instance['type'], [CardLifecycle::TYPE_COUNT, CardLifecycle::TYPE_TIME], true)) {
            $eligible = CardLifecycle::deadlineEligible($normalized, $expireDays);
            $expr = sprintf(
                'state=current ∧ type=%d ∧ 0 <= daysUntil(%s) <= %d ∧ (usable ∨ lastDay)',
                $instance['type'],
                $instance['daysUntil'] === null ? 'null' : (string) $instance['daysUntil'],
                $expireDays
            );
            $lines[] = '    R-'.($instance['type'] === CardLifecycle::TYPE_TIME ? 'T1' : 'D1')." deadline_near: {$expr} → ".($eligible ? 'true' : 'false');
        }
        if ($instance['type'] === CardLifecycle::TYPE_TIME) {
            if ($expirePercent <= 0) {
                $lines[] = '    R-T2 validity_percent: renewalExpirePercent=0 → 跳过';
            } else {
                $usable = CardLifecycle::isUsable($normalized);
                $valid = (int) ($instance['validDays'] ?? 0);
                $pct = $usable && $valid > 0 ? round($instance['remaining'] / $valid * 100, 1) : null;
                $hit = $usable && $valid > 0 && $pct <= $expirePercent;
                $lines[] = "    R-T2 validity_percent: remaining({$instance['remaining']}) / validDays({$valid}) * 100 = ".($pct ?? 'n/a')." <= {$expirePercent} → ".($hit ? 'true' : 'false');
            }
        }
        if ($instance['type'] === CardLifecycle::TYPE_COUNT) {
            $usable = CardLifecycle::isUsable($normalized);
            $hitLow = $usable && $instance['remaining'] <= $threshold;
            $lines[] = "    R-C1 count_card_low: usable=".($usable ? 'true' : 'false')." ∧ remaining({$instance['remaining']}) <= {$threshold} → ".($hitLow ? 'true' : 'false');
            if ($countPercent <= 0) {
                $lines[] = '    R-C2 count_card_percent_low: renewalCountPercent=0 → 跳过';
            } else {
                $bound = (float) ($instance['bound'] ?? 0);
                $pct = $usable && $bound > 0 ? round($instance['remaining'] / $bound * 100, 1) : null;
                $hitPct = $usable && ! $hitLow && $bound > 0 && $pct <= $countPercent;
                $lines[] = "    R-C2 count_card_percent_low: remaining({$instance['remaining']}) / bound({$bound}) * 100 = ".($pct ?? 'n/a')." <= {$countPercent} → ".($hitPct ? 'true' : 'false').($hitLow ? '（被 R-C1 吸收）' : '');
            }
        }
        if ($instance['type'] === CardLifecycle::TYPE_STORED) {
            $usable = CardLifecycle::isUsable($normalized);
            if ($storedThreshold <= 0) {
                $lines[] = '    R-S1 stored_value_low: renewalStoredValueThreshold=0 → 跳过（默认关闭）';
            } else {
                $hit = $usable && $instance['remaining'] <= $storedThreshold;
                $lines[] = "    R-S1 stored_value_low: remaining({$instance['remaining']} 元) <= {$storedThreshold} 元 → ".($hit ? 'true' : 'false');
            }
        }

        return $lines;
    }
}
