<?php

namespace App\Services;

use App\Models\PayrollProfile;
use App\Models\StaffAlias;
use App\Models\User;

/**
 * 姓名 → 薪酬档案解析（**唯一入口**）。
 *
 * ## 为什么必须合并四个来源
 *
 * 业绩表的 11 个销售员列名里，**9 个不是本名**（苏米→罗柳柳、娟子→徐秀娟、张芷晴→张情、
 * Nico→李芯萍、CC→吴艳、Lily→郑卫丽、小鹏→牟志鹏、婷婷→谭婷婷、阿玉→张卫玉）。
 * 不解析这 9 个，导入结果里 9 个人直接归零。而这些名字分散在四个地方：
 *
 * | 来源 | 覆盖谁 | 为什么不能只用它 |
 * |---|---|---|
 * | `payroll_profiles.name` | 全部薪酬人员（含无账号的兼职/保洁） | 只有本名，没有别名 |
 * | `payroll_profile_aliases.alias` | 主档「姓名别名」sheet 的 103 条 | 只覆盖薪酬人员 |
 * | `users.name` | 有登录账号的人 | 只有本名 |
 * | `staff_aliases.alias` | 账号侧维护的别名 | `user_id` 非空外键，无账号的人进不来 |
 *
 * 四者语义完全一致（「一个人还可能被写成哪些名字」），所以合并成一张映射，
 * **只解析一次**。禁止在导入器里另写一份硬编码映射表。
 *
 * ## 歧义处理：宁可不认，不猜
 *
 * 一个名字对上多个档案时返回 null，并记录歧义项供异常清单使用 —— 猜错就是把钱发错人。
 * 与 `staffUserId()`（`helpers.php`）的既有语义保持一致。
 */
class PayrollNameResolver
{
    /** @var array<string, int> 姓名/别名 → profile id */
    private array $map = [];

    /** @var array<string, true> 歧义姓名 */
    private array $ambiguous = [];

    /** @var array<int, PayrollProfile> */
    private array $profiles = [];

    private bool $loaded = false;

    public function __construct(private bool $lazy = true) {}

    /** 预加载全部映射（批量导入场景一次加载，避免逐行查库） */
    public function warm(): self
    {
        if ($this->loaded) {
            return $this;
        }
        $this->loaded = true;

        $byName = [];
        foreach (PayrollProfile::orderBy('id')->get() as $p) {
            $this->profiles[(int) $p->id] = $p;
            $byName[(string) $p->name][] = (int) $p->id;
        }
        // users.name → 该账号对应的档案（档案未建时忽略）
        $profileByUser = [];
        foreach ($this->profiles as $p) {
            if ($p->user_id !== null) {
                $profileByUser[(int) $p->user_id] = (int) $p->id;
            }
        }
        foreach (User::orderBy('id')->get(['id', 'name']) as $u) {
            $n = trim((string) $u->name);
            if ($n !== '' && isset($profileByUser[(int) $u->id])) {
                $byName[$n][] = $profileByUser[(int) $u->id];
            }
        }
        foreach (StaffAlias::orderBy('id')->get(['user_id', 'alias']) as $a) {
            $n = trim((string) $a->alias);
            if ($n !== '' && isset($profileByUser[(int) $a->user_id])) {
                $byName[$n][] = $profileByUser[(int) $a->user_id];
            }
        }
        foreach ($this->profiles as $p) {
            foreach ((array) ($p->aliases ?? []) as $alias) {
                $n = trim((string) $alias);
                if ($n !== '') {
                    $byName[$n][] = (int) $p->id;
                }
            }
        }

        foreach ($byName as $name => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) === 1) {
                $this->map[$name] = $ids[0];
            } else {
                $this->ambiguous[$name] = true;
            }
        }

        return $this;
    }

    /** 解析姓名 → 薪酬档案。歧义或未命中返回 null */
    public function resolve(?string $name): ?PayrollProfile
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        if (! $this->loaded) {
            $this->warm();
        }
        if (isset($this->ambiguous[$name])) {
            return null;
        }
        $id = $this->map[$name] ?? null;

        return $id === null ? null : ($this->profiles[$id] ?? null);
    }

    public function isAmbiguous(?string $name): bool
    {
        $name = trim((string) $name);
        if (! $this->loaded) {
            $this->warm();
        }

        return isset($this->ambiguous[$name]);
    }

    /** @return array<int, PayrollProfile> */
    public function allProfiles(): array
    {
        if (! $this->loaded) {
            $this->warm();
        }

        return $this->profiles;
    }

    /** @return array<string, int> */
    public function map(): array
    {
        if (! $this->loaded) {
            $this->warm();
        }

        return $this->map;
    }
}
