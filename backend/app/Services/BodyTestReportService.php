<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 体测报告解析（ruleye / 智能魔镜）。
 *
 * 数据来源：门店体测设备生成报告后，老师把报告链接贴进来，
 * 形如 https://bodytest.ruleye.com/#/report-new/{body_test_id}/{sign}/{timestamp}
 *
 * 接口无需鉴权，但必须表单编码（Content-Type: application/x-www-form-urlencoded），
 * 传 JSON 会返回「body_test_id 不能为空」。
 *
 * 报告自带解读元数据，因此不做任何硬编码判断：
 *  - body_base.intro[key].{flag_name,type_name,risk,suggest} 给出中文名、档位名称、各档风险与建议
 *  - body_base[key + '_range'] 给出标准范围边界，N+1 个边界对应 N 个档位
 *  - 体态 18 项的 {key}_risk / {key}_suggest 直接可用
 */
final class BodyTestReportService
{
    private const BASE = 'https://internalservice.ruleye.com';

    /** 体态项 → 训练观察项（PostClassPlanEngine::OBSERVATIONS 的 key） */
    private const POSTURE_OBSERVATIONS = [
        'shoulder_slope' => ['asymmetry'],
        'spine_lateral' => ['lr_diff'],
        'pelvis_rolling' => ['pelvis_lateral'],
        'x_leg' => ['knee_valgus'],
        'o_leg' => ['foot_arch'],
        'highlow_pelvis' => ['pelvis_lateral'],
        'longshort_leg' => ['asymmetry', 'pelvis_lateral'],
        'lupper_limbs' => ['shoulder_rom', 'upper_trap_tight'],
        'truncal_bones' => ['thoracic_kyphosis'],
        'lower_limbs' => ['ankle_knee_unstable'],
        'spine_restriction' => ['lr_diff'],
        'mascular_tension' => ['upper_trap_tight', 'breath_shallow'],
        'trunk_muscle' => ['core_weak'],
        'muscle_trunk_rigid' => ['thoracic_kyphosis'],
        'vertebra_flexible' => ['upper_back_weak'],
        'hip_flexion' => ['pelvis_control'],
        'dorsal_abdominal' => ['core_weak', 'lumbar_comp'],
        'leg_power' => ['glute_weak'],
    ];

    /** 体成分异常 → 训练观察项 */
    private const COMPOSITION_OBSERVATIONS = [
        'fat' => ['core_weak'],
        'body_fat_kg' => ['core_weak'],
        'body_fat_sub_cut_percentage' => ['core_weak'],
        'muscle_kg' => ['core_weak', 'glute_weak'],
        'muscle_percentage' => ['core_weak'],
        'body_skeletal_kg' => ['glute_weak'],
        'body_skeletal' => ['glute_weak'],
        'visceral_fat' => ['core_weak'],
        'dorsal_abdominal' => ['core_weak'],
    ];

    /** 体成分指标在报告里的中文名兜底（接口未提供 flag_name 时用） */
    private const FALLBACK_NAMES = [
        'weight' => '体重',
        'height' => '身高',
        'bmi' => 'BMI',
        'bmr' => '基础代谢',
        'body_age' => '身体年龄',
        'fat' => '脂肪率',
        'body_fat_kg' => '脂肪量',
        'body_fat_sub_cut_kg' => '皮下脂肪量',
        'body_fat_sub_cut_percentage' => '皮下脂肪率',
        'muscle_kg' => '肌肉量',
        'muscle_percentage' => '肌肉率',
        'body_skeletal_kg' => '骨骼肌量',
        'body_skeletal' => '骨骼肌率',
        'bone_kg' => '骨量',
        'protein_kg' => '蛋白质量',
        'protein_percentage' => '蛋白质率',
        'water_kg' => '水分量',
        'water_percentage' => '水分率',
        'visceral_fat' => '内脏脂肪等级',
        'cell_mass_kg' => '身体细胞量',
        'mineral_kg' => '无机盐量',
        'lose_fat_weight_kg' => '去脂体重',
        'obesity' => '肥胖度',
        'body_score' => '身体得分',
        'body_health' => '健康评估',
        'body_type' => '身体类型',
        'fat_grade' => '肥胖等级',
    ];

    /** 报告里需要展示的单位 */
    private const UNITS = [
        'weight' => 'kg', 'height' => 'cm', 'bmr' => 'kcal', 'fat' => '%',
        'body_fat_kg' => 'kg', 'body_fat_sub_cut_kg' => 'kg', 'body_fat_sub_cut_percentage' => '%',
        'muscle_kg' => 'kg', 'muscle_percentage' => '%', 'body_skeletal_kg' => 'kg', 'body_skeletal' => '%',
        'bone_kg' => 'kg', 'protein_kg' => 'kg', 'protein_percentage' => '%',
        'water_kg' => 'kg', 'water_percentage' => '%', 'obesity' => '%',
        'cell_mass_kg' => 'kg', 'mineral_kg' => 'kg', 'lose_fat_weight_kg' => 'kg',
        'body_age' => '岁', 'visceral_fat' => '级',
    ];

    /**
     * 「正常/良好」档位标签（精确匹配）。
     * 不能用包含匹配：'高于标准'、'低于标准' 都含「标准」二字，会被误判成正常。
     */
    private const GOOD_BANDS = [
        '标准', '正常', '优秀', '良好', '非常好', '健康',
        '身体年龄年轻', '标准型', '标准肌肉型', '肌肉发达型',
    ];

    /**
     * 汇总型指标：只做展示，不参与「异常项」判定。
     * 它们是打分/分类结果（身体得分 71 分＝一般、身体类型＝标准型），
     * 不是可直接排训练的身体指标，混进异常项只会干扰老师判断。
     */
    private const SUMMARY_KEYS = ['body_score', 'body_health', 'body_type', 'body_age'];

    /** 体成分里有分析价值的指标（顺序即展示顺序） */
    private const COMPOSITION_KEYS = [
        'weight', 'bmi', 'fat', 'body_fat_kg', 'visceral_fat',
        'muscle_kg', 'muscle_percentage', 'body_skeletal_kg', 'body_skeletal',
        'protein_percentage', 'water_percentage', 'bmr', 'body_age', 'body_score',
    ];

    /** 从报告链接里解析出三段参数 */
    public static function parseUrl(string $url): ?array
    {
        if (! preg_match('#report-new/(\d+)/([0-9a-fA-F]+)/(\d+)#', $url, $m)) {
            return null;
        }

        return ['body_test_id' => $m[1], 'sign' => $m[2], 'timestamp' => $m[3]];
    }

    /** 拉取原始报告 */
    public static function fetch(string $bodyTestId, string $sign, string $timestamp): ?array
    {
        try {
            $res = Http::asForm()->timeout(20)->post(self::BASE.'/service/v2.0.0/body_test/detail', [
                'body_test_id' => $bodyTestId,
                'sign' => $sign,
                'timestamp' => $timestamp,
            ]);
        } catch (\Throwable $e) {
            Log::warning('体测报告拉取失败', ['id' => $bodyTestId, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $res->ok()) {
            return null;
        }
        $json = $res->json();
        if (($json['errno'] ?? null) !== '0' && ($json['errno'] ?? null) !== 0) {
            Log::info('体测报告接口返回异常', ['id' => $bodyTestId, 'errno' => $json['errno'] ?? null, 'msg' => $json['error'] ?? '']);

            return null;
        }

        return $json['data'] ?? null;
    }

    /** 链接一步到位：解析 + 拉取 + 分析 */
    public static function fromUrl(string $url): ?array
    {
        $parts = self::parseUrl($url);
        if (! $parts) {
            return null;
        }
        $raw = self::fetch($parts['body_test_id'], $parts['sign'], $parts['timestamp']);
        if (! $raw) {
            return null;
        }
        $analysis = self::analyze($raw);

        return $analysis + ['bodyTestId' => $parts['body_test_id']];
    }

    /**
     * 档位判定。
     *
     * 两种数据形态：
     *  1. 带标准范围：range 有 N+1 个边界对应 N 个档位，值落在哪个区间就是哪一档
     *     （注意接口把 range 以 JSON 字符串返回，必须先解析）；
     *  2. 无标准范围：数值本身就是档位下标（身体类型、肥胖等级、健康评估这类枚举）。
     * 都判不出来时返回 -1，调用方按「无档位」展示，不硬猜好坏。
     */
    public static function bandIndex($value, $range, int $bandCount): int
    {
        $bounds = self::jsonList($range);
        if (count($bounds) >= 2) {
            $idx = 0;
            for ($i = 0; $i < count($bounds) - 1; $i++) {
                if ((float) $value >= (float) $bounds[$i]) {
                    $idx = $i;
                }
            }

            return max(0, min($bandCount - 1, $idx));
        }
        // 无范围：仅当取值是落在档位范围内的整数下标时才认
        if (is_numeric($value) && (int) $value == $value && (int) $value >= 0 && (int) $value < $bandCount) {
            return (int) $value;
        }

        return -1;
    }

    /**
     * 正常档位对应的数值区间。
     *
     * 正常档位不一定在中间：内脏脂肪等级是 ['标准','偏差','很差']，正常档在下标 0。
     * 因此不能靠「取 range 中间两个边界」来猜，必须按档位标签定位。
     */
    public static function normalRange(array $bounds, array $bands): ?array
    {
        if (count($bounds) < 2) {
            return null;
        }
        foreach ($bands as $i => $label) {
            if (! in_array((string) $label, self::GOOD_BANDS, true)) {
                continue;
            }
            $from = $bounds[$i] ?? null;
            $to = $bounds[$i + 1] ?? null;
            if ($from === null || $to === null) {
                continue;
            }

            return [(float) $from, (float) $to];
        }

        return null;
    }

    /**
     * 设备原文里是否出现门店的表达禁忌词。
     *
     * 体测设备的风险/建议文案是通用医学口径，会写出「矫正」「复位」这类词
     * （实测：骨盆高低项的 suggest 含「静力性复位矫正」），
     * 也可能出现「可能导致腰椎间盘突出」这种接近诊断的表述。
     * 这类文本老师可以参考，但不能原样对客——对客侧只给客观数据。
     */
    public static function containsForbidden(string $text): bool
    {
        foreach (PostClassPlanEngine::FORBIDDEN_WORDS as $word) {
            if ($word !== '' && mb_strpos($text, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 把接口返回的 JSON 字符串数组安全解出来 */
    private static function jsonList($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 对客安全摘要：只保留客观数据（指标、实测值、标准区间、档位），
     * 不带设备的风险/建议原文。供学员版页面使用。
     */
    public static function toCustomerView(?array $analysis): ?array
    {
        if (! $analysis) {
            return null;
        }
        $strip = fn (array $item): array => [
            'name' => $item['name'] ?? '',
            'value' => $item['value'] ?? null,
            'unit' => $item['unit'] ?? '',
            'normalRange' => $item['normalRange'] ?? null,
            'bandLabel' => $item['bandLabel'] ?? '',
        ];

        return [
            'testedAt' => $analysis['profile']['testedAt'] ?? '',
            'score' => $analysis['profile']['score'] ?? null,
            'bmi' => $analysis['profile']['bmi'] ?? null,
            'bodyFatRate' => $analysis['profile']['bodyFatRate'] ?? null,
            'abnormal' => array_map($strip, $analysis['abnormal'] ?? []),
            'postureCount' => count($analysis['posture'] ?? []),
        ];
    }

    /**
     * 分析报告 → 结构化解读。
     *
     * 返回：
     *  - profile     基础档案（年龄/性别/身高/体重/体脂/评分）
     *  - composition 体成分指标（含档位、是否正常、风险与建议）
     *  - abnormal    其中的异常项
     *  - posture     体态异常项（含风险与建议）
     *  - observations 映射出的训练观察项（可直接喂给 PostClassPlanEngine）
     *  - directions  会员自己登记的训练方向
     *  - health      健康问卷（红线排查用）
     */
    public static function analyze(array $raw): array
    {
        $base = (array) ($raw['body_base'] ?? []);
        $intro = (array) ($base['intro'] ?? []);

        // ---------- 体成分 ----------
        $composition = [];
        foreach (self::COMPOSITION_KEYS as $key) {
            if (! array_key_exists($key, $base)) {
                continue;
            }
            $value = $base[$key];
            if ($value === '' || $value === null) {
                continue;
            }
            $meta = (array) ($intro[$key] ?? []);
            $bands = self::jsonList($meta['type_name'] ?? null);
            $range = self::jsonList($base[$key.'_range'] ?? null);
            $idx = self::bandIndex($value, $range, count($bands));
            $label = $idx >= 0 ? (string) ($bands[$idx] ?? '') : '';
            $riskText = $idx >= 0 ? (string) (self::jsonList($meta['risk'] ?? null)[$idx] ?? '') : '';
            $suggestText = $idx >= 0 ? (string) (self::jsonList($meta['suggest'] ?? null)[$idx] ?? '') : '';
            // 精确匹配「正常档位」：判不出档位时不判好坏，避免误导
            $isNormal = $label !== '' && in_array($label, self::GOOD_BANDS, true);

            $composition[] = [
                'key' => $key,
                'name' => (string) ($meta['flag_name'] ?? self::FALLBACK_NAMES[$key] ?? $key),
                'value' => (float) $value,
                'unit' => self::UNITS[$key] ?? '',
                'range' => $range !== [] ? $range : null,
                // 正常档位的数值区间，展示「标准 18~28」时直接用，前端不需要再按档位猜
                'normalRange' => $range !== [] ? self::normalRange($range, $bands) : null,
                'bandLabel' => (string) $label,
                'isNormal' => $isNormal,
                'risk' => $riskText,
                'suggest' => $suggestText,
                // 设备原文含表达禁忌词时，对客侧只展示数值与标准区间，不引用这两段
                'deviceTextSafe' => ! self::containsForbidden($riskText.' '.$suggestText),
            ];
        }

        // ---------- 体态 ----------
        $posture = [];
        $postureKeys = array_keys(self::POSTURE_OBSERVATIONS);
        foreach ($postureKeys as $key) {
            $flag = (int) ($raw[$key] ?? 0);
            if ($flag !== 1) {
                continue;
            }
            $meta = (array) ($intro[$key] ?? []);
            $posture[] = [
                'key' => $key,
                'name' => (string) ($meta['flag_name'] ?? $key),
                'risk' => (string) ($raw[$key.'_risk'] ?? ''),
                'suggest' => (string) ($raw[$key.'_suggest'] ?? ''),
                // 设备原文含表达禁忌词：内部可参考，对客侧不得原样引用
                'deviceTextSafe' => ! self::containsForbidden(
                    (string) ($raw[$key.'_risk'] ?? '').' '.(string) ($raw[$key.'_suggest'] ?? '')
                ),
                'observations' => self::POSTURE_OBSERVATIONS[$key],
            ];
        }

        // ---------- 观察项映射 ----------
        $observed = [];
        foreach ($posture as $p) {
            foreach ($p['observations'] as $obs) {
                $observed[$obs] = true;
            }
        }
        $abnormal = array_values(array_filter(
            $composition,
            fn ($c) => ! $c['isNormal']
                && $c['bandLabel'] !== ''                      // 判不出档位 ≠ 异常，不能凭空报警
                && ! in_array($c['key'], self::SUMMARY_KEYS, true)
        ));
        foreach ($abnormal as $c) {
            foreach (self::COMPOSITION_OBSERVATIONS[$c['key']] ?? [] as $obs) {
                $observed[$obs] = true;
            }
        }
        // 只保留规则引擎认识的观察项
        $observed = array_values(array_filter(
            array_keys($observed),
            fn ($k) => isset(PostClassPlanEngine::OBSERVATIONS[$k])
        ));

        // ---------- 健康问卷 ----------
        $disease = (array) ($raw['user_disease_record'] ?? []);
        $redFlags = [];
        if (($disease['maternity_bool'] ?? 0) && trim((string) ($disease['maternity'] ?? '')) !== '') {
            // 产后有标注 → 提醒老师当面确认盆底症状（报告本身不做诊断）
            $redFlags[] = 'postpartum_pelvic';
        }
        if (($disease['operation_bool'] ?? 0) || ($disease['medication_bool'] ?? 0)) {
            $redFlags[] = 'post_surgery';
        }
        if (($disease['vertigo_bool'] ?? 0) || trim((string) ($disease['heart'] ?? '')) !== ''
            || trim((string) ($disease['blood_pressure'] ?? '')) !== '') {
            $redFlags[] = 'cardio';
        }
        if (trim((string) ($disease['joint'] ?? '')) !== '') {
            $redFlags[] = 'joint_acute';
        }

        return [
            'profile' => [
                'age' => (int) ($base['age'] ?? 0),
                'sex' => (int) ($base['sex'] ?? 0) === 2 ? '女' : '男',
                'height' => (float) ($base['height'] ?? 0),
                'weight' => (float) ($base['weight'] ?? 0),
                'bmi' => (float) ($base['bmi'] ?? 0),
                'bodyFatRate' => (float) ($base['fat'] ?? 0),
                'score' => (float) ($raw['score'] ?? 0),
                'bodyType' => (string) ($intro['body_type']['type_name'] ?? ''),
                'nickName' => (string) ($raw['nick_name'] ?? ''),
                'testedAt' => (string) ($raw['create_time'] ?? ''),
                'gymName' => (string) ($raw['yoga_gym_name'] ?? ''),
            ],
            'composition' => $composition,
            'abnormal' => $abnormal,
            'posture' => $posture,
            'observations' => $observed,
            'directions' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($disease['train_directions'] ?? ''))
            ))),
            'health' => [
                'hasDisease' => (bool) ($disease['has_disease'] ?? 0),
                'maternity' => (string) ($disease['maternity'] ?? ''),
                'joint' => (string) ($disease['joint'] ?? ''),
                'operation' => (string) ($disease['operation'] ?? ''),
                'medication' => (string) ($disease['medication'] ?? ''),
                'vertigo' => (string) ($disease['vertigo'] ?? ''),
                'sleep' => (string) ($disease['sleep'] ?? ''),
                'sportYears' => (int) ($disease['sport_years'] ?? 0),
            ],
            'redFlags' => array_values(array_unique($redFlags)),
        ];
    }
}
