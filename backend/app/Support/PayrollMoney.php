<?php

namespace App\Support;

/**
 * 薪酬金额定点运算（一律以「分」为单位的整数运算）。
 *
 * ## 为什么不能用 float
 *
 * 生产引擎（`03_计算程序与校验器/系统工具/统一工资计算引擎.py:15`）用的是
 * `Decimal.quantize(Decimal('0.01'), rounding=ROUND_HALF_UP)`：**逐笔先舍入到分、再累加**。
 * PHP 里如果换成 float 累加，会在第 15 位有效数字上产生漂移，且 `round()` 的
 * `PHP_ROUND_HALF_EVEN`（banker's rounding）与 half-up 在 `.005` 这一档结果不同。
 *
 * 绿地店 2026-08 的实测后果（规格 §3.6）：`89,857.50 × 7% = 6,290.025` 与
 * `64,101.50 × 7% = 4,487.105` 两笔，half-up 得 `6,290.03 / 4,487.11`，
 * 提成合计 **21,068.10**；banker's rounding 得 `6,290.02 / 4,487.10`，合计 21,068.08 ——
 * 差 0.02，用户对不上账。
 *
 * 所以本类**不使用浮点**：解析时按 half-up 舍入到分，求和是整数加法，
 * 乘法按「分子 / 分母」做整数运算后再 half-up。
 */
final class PayrollMoney
{
    /** 任何值 → 分（半进位到分；非法值按 0） */
    public static function cents(mixed $value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            // 先降到 6 位小数再去掉尾零，避免 0.1+0.2 这类二进制漂移被放大到分位
            $s = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

            return self::fromString($s);
        }

        return self::fromString((string) $value);
    }

    /** 十进制字符串 → 分（第 3 位小数按 half-up 进位；负数按「远离零」进位，与 Python ROUND_HALF_UP 一致） */
    public static function fromString(string $s): int
    {
        $s = str_replace([',', ' ', '，', '¥', '￥'], '', $s);
        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $s, $m)) {
            return 0;
        }
        $intPart = $m[2] === '' ? '0' : $m[2];
        $frac = (string) ($m[3] ?? '');
        if ($intPart === '0' && $frac === '') {
            return 0;
        }
        $frac = str_pad($frac, 3, '0');
        $cents = ((int) $intPart) * 100 + (int) substr($frac, 0, 2);
        if (((int) $frac[2]) >= 5) {
            $cents += 1;
        }

        return ($m[1] === '-' ? -1 : 1) * $cents;
    }

    /** 分 × (分子/分母)，结果 half-up 到分 */
    public static function mulRate(int $cents, int $num, int $den): int
    {
        if ($den === 0) {
            return 0;
        }
        $n = $cents * $num;
        $q = intdiv($n, $den);
        $r = $n % $den;
        if (2 * abs($r) >= abs($den)) {
            $q += $n < 0 ? -1 : 1;
        }

        return $q;
    }

    /** 分 × 十进制系数（如 '0.75'） */
    public static function mulFactor(int $cents, string $factor): int
    {
        [$num, $den] = self::ratio($factor);

        return self::mulRate($cents, $num, $den);
    }

    /** 十进制字符串 → [分子, 分母]（保留 6 位小数精度） */
    public static function ratio(string $factor): array
    {
        $factor = trim($factor);
        if ($factor === '' || ! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $factor, $m)) {
            return [0, 1];
        }
        $frac = str_pad((string) ($m[3] ?? ''), 6, '0');
        $num = ((int) ($m[2] === '' ? '0' : $m[2])) * 1000000 + (int) substr($frac, 0, 6);
        if ($m[1] === '-') {
            $num = -$num;
        }
        $g = self::gcd(abs($num), 1000000);

        return [intdiv($num, $g), intdiv(1000000, $g)];
    }

    /** 分 → 展示字符串（两位小数，负数带符号） */
    public static function fmt(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** 分 → float（仅用于 JSON 输出；金额一律 < 2^53/100，不会丢精度到分） */
    public static function toFloat(int $cents): float
    {
        return (float) self::fmt($cents);
    }

    /** 分 → 参与 JSON 输出的金额（null 保持 null，用于「未输入」与「显式 0」的区分） */
    public static function toFloatOrNull(?int $cents): ?float
    {
        return $cents === null ? null : self::toFloat($cents);
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }
}
