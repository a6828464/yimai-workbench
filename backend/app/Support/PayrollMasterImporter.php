<?php

namespace App\Support;

use RuntimeException;

/**
 * 「薪酬人员主档（新版）」导入器 —— 把用户的新版主档解析成**可落库的档案行**。
 *
 * ## 为什么需要它：新版主档与 `PayrollProfileSeeder` 期望的旧格式**不兼容**
 *
 * `PayrollProfileSeeder` 面向的是 2026-07 的旧交接包（sheet `人员主档` /
 * `姓名别名` / `特殊规则`，列 `底薪 / 小班 / 团课 / 企业课`）。用户 2026-09-24 给的
 * 新版主档换了一套结构，直接喂给旧 seeder 会抛「人员主档缺少 sheet」：
 *
 * | 旧格式（seeder 期望） | 新版实际 | 旧 seeder 的后果 |
 * |---|---|---|
 * | sheet `人员主档` | sheet `薪酬人员主档` | 直接抛异常 |
 * | sheet `姓名别名`（三列） | **无此 sheet**，别名叫列 `所有显示名/昵称`（逗号分隔） | 别名全丢 ⇒ 业绩表 9 个列名归零 |
 * | sheet `特殊规则`（双底薪白名单） | **无此 sheet**，规则写在列 `重点提醒` 的自由文本里 | 回退硬编码 3 人 |
 * | 列 `底薪 / 小班 / 团课 / 企业课` | 列 `基本底薪 / 小班课时费 / 团课课时费 / 企业课课时费` | 金额全部读成 0 |
 *
 * 本类只做**解析与归一化**（纯函数，不碰数据库），落库由
 * `PayrollProfileFromMasterSeeder` 负责 —— 解析可被单测直接覆盖，
 * 也避免把「读文件」与「写库」揉成一个难以验证的大事务。
 *
 * ## 🔴 PII：收款账户与联系方式（用户决策 2026-09-26：完整入库）
 *
 * 主档含身份证号、手机号、银行卡号、开户行（sheet 名自己就写着「含身份银行卡」）。
 * 此前按「公开仓库 PII 不入库」政策**刻意不读**这些列（v3.3.4 的交付口径）。
 * 用户 2026-09-26 明确拍板要在「人员档案」里展示银行卡信息并**完整入库**
 * ——该决策由用户显式做出，本类相应扩展 `ACCOUNT_FIELDS` 读取白名单
 * （迁移 `2026_09_26_000001` 建了对应列）。
 *
 * 边界仍然收紧：
 * - 这些字段只经超管端点下发（`/payroll/profiles` 全部 `requireSuper`）；
 * - 前端展示层默认**掩码**（卡号只显示后 4 位，点「显示」才露出完整卡号）；
 * - 仓库仍不提交主档 xlsx 本体，这些列由 seeder 从仓库外文件灌入；
 * - 列缺失不报错（旧版主档没有这些列也能导入），值为空存 `''`。
 *
 * ## 缺失 ≠ 0
 *
 * 主档里 `基本底薪` 有 29 人是显式 `0`，`绩效` 有 50 人是显式 `0` —— 这些是**真实的 0**
 * （兼职老师「底薪强制为 0」），必须原样落 0。而 `手机` 缺 41 人、`身份证号` 缺 40 人、
 * `银行卡号` 缺 5 人，缺就是**未知**，落 0 会把「不知道」写成「确定的零」。
 * 两个概念分开表达：金额空 → `null`（不覆盖既有值），文本空 → `''`。
 */
final class PayrollMasterImporter
{
    /** 新版主档的 sheet 名（旧版是 `人员主档`，两者**不可互换**） */
    public const SHEET = '薪酬人员主档';

    /** 「资料缺口」sheet（官方缺失清单，用于交叉校验我们的空白统计） */
    public const SHEET_GAPS = '资料缺口';

    /**
     * 两店人员映射文件的 sheet 名（**用于交叉校验，不是建档数据源**）。
     *
     * 映射文件与主档是同一批人的两种整理稿，别名列同名但**内容有差异**（实测 3 处：
     * 李芯萍 `Nico, nico` vs `Nico`；张娇娇 `娇娇` vs `张娇娇`；吴艳 `CC, cc` vs `CC`）。
     * 建档以**主档为准**（它是唯一带 `人员编号` 的文件），映射只用来核对别名覆盖，
     * 免得「映射里有的别名主档没登记」这种漏项无人发现。
     */
    public const SHEET_MAPPING = '人员映射_排序整理版';

    /**
     * 取值的**白名单**：只有这些列会被带进档案行。
     *
     * 键 = 主档列名，值 = 档案行字段名。收款账户/联系方式列在
     * `ACCOUNT_FIELDS` 里单独维护（用户 2026-09-26 决策入库，见类头注释）。
     */
    private const FIELDS = [
        '人员编号' => 'external_id',
        '真实姓名' => 'name',
        '所属门店' => 'venue',
        '岗位' => 'role',
        '人员状态' => 'status',
        '所有显示名/昵称' => 'alias_text',
        '基本底薪' => 'base_salary',
        '绩效' => 'performance',
        '60分钟课时费' => 'fee_private60',
        '45分钟课时费' => 'fee_private45',
        '小班课时费' => 'fee_small',
        '团课课时费' => 'fee_group',
        '企业课课时费' => 'fee_enterprise',
        '账户确认状态' => 'account_status',
        '重点提醒' => 'alert',
    ];

    /**
     * 收款账户与联系方式列（用户决策 2026-09-26：完整入库）。
     *
     * 与 `FIELDS` 分开维护的原因：这批列在旧版主档里**不存在**，读取必须
     * 「列缺失不报错」（`cell()` 对缺失列天然返回 `''`，恰好满足）；
     * 且 `stats()` 里的 `skippedColumnsPresent` 审计口径需要单独识别它们。
     * 文本空存 `''`（与金额列的 `null` 语义不同：卡号没有「未知 vs 0」的歧义）。
     */
    private const ACCOUNT_FIELDS = [
        '收款户名' => 'bank_account_name',
        '银行卡号' => 'bank_card_no',
        '开户行/网点' => 'bank_name',
        '联行号' => 'bank_cnaps',
        '转账类型' => 'transfer_type',
        '手机' => 'phone',
        '身份证号' => 'id_card_no',
        '企业微信账号' => 'wechat_work',
    ];

    /** 金额列（空 = 未知 ⇒ `null`，**绝不写 0**；显式 `0` 落 `0.00`） */
    private const MONEY_FIELDS = [
        '基本底薪' => 'base_salary',
        '绩效' => 'performance',
        '60分钟课时费' => 'fee_private60',
        '45分钟课时费' => 'fee_private45',
        '小班课时费' => 'fee_small',
        '团课课时费' => 'fee_group',
        '企业课课时费' => 'fee_enterprise',
    ];

    /** 主档里表示「没有别名」的占位符（实测有 7 人是 `-`） */
    private const ALIAS_PLACEHOLDERS = ['-', '—', '－', '/', '无', 'n/a', 'N/A'];

    /**
     * 逐行统计「哪些字段主档里是空的」—— 供补录指引，**不落库**（表里没有这些列）。
     *
     * 手机/身份证/银行卡这些我们本来就不入库（PII 政策），但「缺什么」是
     * 与 PII 无关的**元信息**，记进导入报告能直接告诉用户去补哪几项。
     */
    private const TRACKED_MISSING = ['手机', '身份证号', '银行卡号', '开户行/网点', '企业微信账号'];

    /**
     * 双底薪例外规则：`重点提醒` 里声明「两店分别固定底薪…」即为例外。
     *
     * **不硬编码姓名**（旧实现是 `PayrollRoles::DUAL_BASE_SALARY_WHITELIST` 的 3 人）：
     * 姓名会变（新人加入、旧人离职），规则写在数据里才跟得上。实测命中 3 人
     * （蒙澍南 / 谭婷婷 / 张卫玉），语义上与旧白名单**完全一致**。
     *
     * ⚠️ 不能简化为「提醒里含『两店』即算」——实测另有两人提「两店」但**不是**双底薪：
     * - `李芯萍`：`…两店Nico为同一人`（说的是别名归属，不是双底薪）
     * - `邱亿`：`…两店均有课时`（说的是课时分布，兼职老师底薪强制为 0）
     * 宽规则会把这两个人误判成「两店各发一份底薪」，即多发一份工资。
     * 因此必须要求「两店」与「固定底薪」**相邻出现**（中间最多 8 个非标点字符，
     * 容纳「分别」「每人」「各」等写法），且中文逗号/分号会截断匹配。
     */
    private const DUAL_BASE_PATTERN = '/两店[^；;，,。]{0,8}固定底薪/u';

    /**
     * 解析主档 → 档案行列表 + 统计。
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     stats: array<string, mixed>
     * }
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("薪酬人员主档文件不存在：{$path}");
        }

        $reader = XlsxReader::open($path);
        try {
            if (! $reader->hasSheet(self::SHEET)) {
                throw new RuntimeException(
                    '薪酬人员主档缺少 sheet「'.self::SHEET.'」（现有 sheet：'
                    .implode('、', $reader->sheetNames()).'）。'
                    .'若这是 2026-07 的旧版交接包，请改用 PayrollProfileSeeder。'
                );
            }

            $header = null;
            $rows = [];
            $problems = [];
            $blanks = [];

            foreach ($reader->rows(self::SHEET) as $rowNumber => $cells) {
                if ($rowNumber === 1) {
                    $header = self::header($cells);
                    $this->assertRequiredColumns($header);

                    continue;
                }
                if ($header === null || $cells === []) {
                    continue;
                }

                $parsed = $this->parseRow($cells, $header, $rowNumber);
                if (is_string($parsed)) {
                    $problems[] = $parsed;

                    continue;
                }
                if ($parsed === null) {
                    continue;
                }

                // 逐列空白统计（「缺失 ≠ 0」的对账依据）。在遍历里顺带做，
                // 免得为了这份统计把整份文件再读一遍。
                foreach ($header as $col => $colIndex) {
                    if (trim((string) ($cells[$colIndex] ?? '')) === '') {
                        $blanks[$col] = ($blanks[$col] ?? 0) + 1;
                    }
                }
                $rows[] = $parsed;
            }

            return [
                'rows' => $rows,
                'stats' => $this->stats($rows, $header, $problems, $blanks),
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * 表头行 → `列名 => 列号`。
     *
     * 主档表头实测在第 1 行（没有标题行），与 `XlsxReader::rows()` 的 1-based 行号一致。
     *
     * @param  array<int, mixed>  $cells
     * @return array<string, int>
     */
    private static function header(array $cells): array
    {
        $header = [];
        foreach ($cells as $col => $name) {
            $name = trim((string) $name);
            // 全角括号/斜杠统一成半角，容忍 `开户行（网点）` 这类写法差异
            $name = str_replace(['（', '）', '／'], ['(', ')', '/'], $name);
            if ($name !== '' && ! isset($header[$name])) {
                $header[$name] = $col;
            }
        }

        return $header;
    }

    /**
     * 必需的薪酬列必须存在 —— 缺列时**显式报错**而不是静默把金额读成 0。
     *
     * 这条守卫是整个导入器的安全底线：金额读成 0 的失败是**静默的**
     * （工资表照常出，只是每个人都是 0），没人会当场发现。
     *
     * @param  array<string, int>  $header
     */
    private function assertRequiredColumns(array $header): void
    {
        $missing = [];
        foreach (array_keys(self::MONEY_FIELDS) as $col) {
            if (! isset($header[$col])) {
                $missing[] = $col;
            }
        }
        foreach (['人员编号', '真实姓名', '所属门店', '岗位', '所有显示名/昵称'] as $col) {
            if (! isset($header[$col])) {
                $missing[] = $col;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                '薪酬人员主档缺少必需列：'.implode('、', $missing)
                .'（实际列：'.implode('、', array_keys($header)).'）'
            );
        }
    }

    /** 单元格取值（按表头定位；列不存在或为空 → `''`） */
    private static function cell(array $cells, array $header, string $colName): string
    {
        $col = $header[$colName] ?? null;
        if ($col === null) {
            return '';
        }

        return trim((string) ($cells[$col] ?? ''));
    }

    /**
     * 单行 → 档案行；无法识别时**返回原因字符串**（由调用方上报，不静默丢行）。
     *
     * @return array<string, mixed>|string|null  null = 空行（跳过且不算异常）
     */
    private function parseRow(array $cells, array $header, int $rowNumber)
    {
        $externalId = mb_substr(self::cell($cells, $header, '人员编号'), 0, 32);
        $name = mb_substr(self::cell($cells, $header, '真实姓名'), 0, 60);
        if ($externalId === '' && $name === '') {
            return null; // 尾部空行
        }
        if ($name === '') {
            return "第 {$rowNumber} 行有人员编号「{$externalId}」但没有姓名，已跳过";
        }

        $rawRole = self::cell($cells, $header, '岗位');
        // 归一化「东部店:顾问」→「顾问」（含全角冒号）。`normalizeRole` 是**唯一**实现处，
        // 这里不另写一份 explode(':')。
        $role = PayrollRoles::normalizeRole($rawRole);
        if ($role === '') {
            return "第 {$rowNumber} 行「{$name}」缺少岗位，已跳过";
        }
        if (! PayrollRoles::isValidRole($role)) {
            // 枚举外的岗位**不猜**：塞进「全职老师」会让口径悄悄变掉（按实际课时发底薪奖励）。
            // 明确上报，让人决定是补枚举还是修数据。
            return "第 {$rowNumber} 行「{$name}」的岗位「{$rawRole}」不在薪酬身份枚举内"
                .'（归一化为「'.$role.'」后仍无效），已跳过该行';
        }

        $venue = trim(self::cell($cells, $header, '所属门店'));
        if (! PayrollRoles::isValidVenue($venue)) {
            return "第 {$rowNumber} 行「{$name}」的门店「{$venue}」不是 东部店/绿地店，已跳过";
        }

        $status = self::cell($cells, $header, '人员状态');
        [$status, $statusNote] = self::normalizeStatus($status);

        $row = [
            'external_id' => $externalId,
            'name' => $name,
            'venue' => $venue,
            'role' => $role,
            'status' => mb_substr($status, 0, 16),
            'aliases' => self::aliases(self::cell($cells, $header, '所有显示名/昵称'), $name),
            'alert' => mb_substr(self::cell($cells, $header, '重点提醒'), 0, 200),
            'account_status' => mb_substr(self::cell($cells, $header, '账户确认状态'), 0, 60),
            'dual_base_salary' => self::isDualBase(self::cell($cells, $header, '重点提醒')),
            'status_note' => $statusNote,
            'row_number' => $rowNumber,
        ];

        // 金额：空 ⇒ null（未知，不覆盖既有值）；显式值（含 "0"）⇒ 两位小数字符串
        foreach (self::MONEY_FIELDS as $col => $field) {
            $raw = self::cell($cells, $header, $col);
            $row[$field] = $raw === '' ? null : PayrollMoney::fmt(PayrollMoney::cents($raw));
        }

        // 收款账户与联系方式（用户 2026-09-26 决策入库）。列缺失 → `''`（旧版主档兼容）；
        // 卡号统一只留数字（Excel 可能把长卡号读成科学计数法或带空格，录入侧无法约束上游）。
        foreach (self::ACCOUNT_FIELDS as $col => $field) {
            $raw = self::cell($cells, $header, $col);
            if (in_array($field, ['bank_card_no', 'bank_cnaps', 'id_card_no', 'phone'], true)) {
                $raw = preg_replace('/\s+/', '', $raw);
            }
            $row[$field] = mb_substr($raw, 0, 120);
        }

        // 「待补录」清单：主档里留空的字段。**这不是错误**，是给用户的补录指引 ——
        // 未知就留空（不填 0），但必须在档案上看得见「什么还没填」，
        // 否则 41 个缺手机号的人和一个「本来就没有手机号」的人无法区分。
        $missing = [];
        foreach (self::TRACKED_MISSING as $col) {
            if (self::cell($cells, $header, $col) === '') {
                $missing[] = $col;
            }
        }
        $row['missing'] = $missing;

        return $row;
    }

    /**
     * 别名列（`所有显示名/昵称`，逗号分隔）→ 去重后的别名数组。
     *
     * 去掉占位符（`-`）与**与本名重复**的项（本名恒可解析，登记成别名反而多一条冗余路径，
     * 且与 `PayrollController::syncAliases()` 的口径一致）。
     *
     * @return array<int, string>
     */
    public static function aliases(string $raw, string $ownName = ''): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[,，、\/;；]+/u', $raw) ?: [] as $alias) {
            $alias = mb_substr(trim($alias), 0, 60);
            if ($alias === '' || in_array($alias, self::ALIAS_PLACEHOLDERS, true)) {
                continue;
            }
            if ($ownName !== '' && $alias === $ownName) {
                continue;
            }
            $out[$alias] = true;
        }

        return array_keys($out);
    }

    /**
     * 「重点提醒」是否声明了双底薪例外。
     *
     * 规则见 `DUAL_BASE_PATTERN` 的注释：必须「两店」与「固定底薪」相邻出现。
     */
    public static function isDualBase(string $alert): bool
    {
        return preg_match(self::DUAL_BASE_PATTERN, $alert) === 1;
    }

    /**
     * 主档人员状态的**同义写法**（左 = 主档实际写法，右 = `PayrollRoles::STATUSES` 枚举值）。
     *
     * 主档实测有 7 行不是枚举值，全部是离职/停用态：
     * `停用` 6 行（「重点提醒」都写着「已离职」）、`已离职` 1 行（枚举内）。
     * 即主档把同一件事写成了两种：状态的列叫「停用」，提醒里叫「已离职」。
     *
     * 映射而不是跳过：跳过会让人从档案表和工资表里**直接消失**（离职结算的人
     * 当月仍要发工资），而这两种写法在业务上是同一件事。
     */
    private const STATUS_ALIASES = [
        '停用' => '已离职',
        '已停用' => '已离职',
        '离职' => '已离职',
        '已辞退' => '已离职',
    ];

    /**
     * 读两店人员映射文件 → `[行, 统计]`（**仅用于交叉校验别名覆盖**）。
     *
     * 列名与主档不同（`底薪` vs `基本底薪`、`60min` vs `60分钟课时费`），
     * 且带 `身份证`/`手机`/`银行卡号` 等 PII 列 —— 同样**不取**这些列，
     * 只取 `真实姓名`/`所属门店`/`所有显示名/昵称` 三项。
     *
     * @return array{rows: array<int, array{name: string, venue: string, aliases: array<int, string>}>, stats: array<string, mixed>}
     */
    public function readMapping(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("两店人员映射文件不存在：{$path}");
        }

        $reader = XlsxReader::open($path);
        try {
            if (! $reader->hasSheet(self::SHEET_MAPPING)) {
                throw new RuntimeException(
                    '映射文件缺少 sheet「'.self::SHEET_MAPPING.'」（现有 sheet：'
                    .implode('、', $reader->sheetNames()).'）'
                );
            }

            $header = null;
            $rows = [];
            foreach ($reader->rows(self::SHEET_MAPPING) as $rowNumber => $cells) {
                if ($rowNumber === 1) {
                    $header = self::header($cells);

                    continue;
                }
                if ($header === null || $cells === []) {
                    continue;
                }
                $name = self::cell($cells, $header, '真实姓名');
                if ($name === '') {
                    continue;
                }
                $rows[] = [
                    'name' => mb_substr($name, 0, 60),
                    'venue' => trim(self::cell($cells, $header, '所属门店')),
                    'aliases' => self::aliases(
                        self::cell($cells, $header, '所有显示名/昵称'),
                        $name
                    ),
                ];
            }

            $aliasMap = [];
            foreach ($rows as $row) {
                foreach ($row['aliases'] as $alias) {
                    $aliasMap[$alias][] = $row['name'];
                }
            }

            return [
                'rows' => $rows,
                'stats' => [
                    'total' => count($rows),
                    'aliasCount' => count($aliasMap),
                    'aliasMap' => $aliasMap,
                ],
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * 归一化人员状态 → `[存储值, 说明|null]`。
     *
     * 空 → `有效`（与旧 seeder 一致）。未知写法**保留原值并附说明**，不丢行：
     * 引擎全程不读 `payroll_profiles.status`（只用于人员管理页展示与 `?status=` 筛选），
     * 所以状态文字未知**不会**让金额算错；而丢掉一行会让人彻底不出现。
     * 两种失败方向里，后者严重得多 —— 因此这里选「保留 + 上报」。
     *
     * @return array{0: string, 1: ?string}
     */
    public static function normalizeStatus(string $status): array
    {
        $status = trim($status);
        if ($status === '') {
            return ['有效', null];
        }
        if (in_array($status, PayrollRoles::STATUSES, true)) {
            return [$status, null];
        }
        $alias = self::STATUS_ALIASES[$status] ?? null;
        if ($alias !== null) {
            return [$alias, "人员状态「{$status}」按同义写法归入「{$alias}」"];
        }

        return [mb_substr($status, 0, 16), "人员状态「{$status}」不在枚举内（".implode('/', PayrollRoles::STATUSES).'），已按原值保留'];
    }

    /**
     * 统计与对账信息。
     *
     * `valid` = 有效人数（其余为停用/已离职），`problems` = 被跳过的行与原因
     * （调用方必须打印，不得静默）。
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $header
     * @param  array<int, string>  $problems
     * @return array<string, mixed>
     */
    private function stats(array $rows, array $header, array $problems, array $blanks = []): array
    {
        $valid = 0;
        $byVenue = [];
        $byRole = [];
        $dualBase = [];
        $aliasRows = 0;
        $statusNotes = [];
        $moneyMissing = array_fill_keys(array_keys(self::MONEY_FIELDS), 0);

        foreach ($rows as $row) {
            if ($row['status'] === '有效') {
                $valid++;
            }
            $byVenue[$row['venue']] = ($byVenue[$row['venue']] ?? 0) + 1;
            $byRole[$row['role']] = ($byRole[$row['role']] ?? 0) + 1;
            if ($row['dual_base_salary']) {
                $dualBase[] = $row['name'];
            }
            if ($row['aliases'] !== []) {
                $aliasRows++;
            }
            if (($row['status_note'] ?? null) !== null) {
                $statusNotes[] = $row['name'].'：'.$row['status_note'];
            }
            // 金额留空（未知）的行数 —— 「缺失 ≠ 0」的对账依据：
            // 这些行的字段是 `null`，不是 `0.00`
            foreach (array_keys(self::MONEY_FIELDS) as $col) {
                if ($row[self::MONEY_FIELDS[$col]] === null) {
                    $moneyMissing[$col]++;
                }
            }
        }

        // 主档里存在、但刻意不导入的敏感列（供交付说明与测试断言「确实没读」）
        $skippedPresent = [];
        foreach (array_keys($header) as $col) {
            if (self::isPiiColumn($col)) {
                $skippedPresent[] = $col;
            }
        }

        return [
            'total' => count($rows),
            'valid' => $valid,
            'byVenue' => $byVenue,
            'byRole' => $byRole,
            'dualBase' => $dualBase,
            'aliasRows' => $aliasRows,
            'moneyMissing' => $moneyMissing,
            'problems' => $problems,
            // 逐列空白数（列名 => 空值行数），供「未知字段留空」对账与补录指引
            'blanks' => $blanks,
            // 状态同义写法 / 枚举外写法的逐条说明（必须打印，不得静默）
            'statusNotes' => $statusNotes,
            // 主档里真有哪些敏感列（证明我们看见了、但按政策没读）
            'skippedColumnsPresent' => $skippedPresent,
            'fieldsImported' => array_values(self::FIELDS),
        ];
    }

    /** 主档里存在、但**刻意不导入**的列（PII 与付款资料；落库表没有对应列） */
    /**
     * 仍然**刻意不导入**的列（2026-09-26 收紧口径后仅剩无业务含义的杂项）。
     *
     * 历史版本这里列着全部 PII 列（手机/身份证/银行卡等）；用户 2026-09-26
     * 拍板收款账户入库后，那批列已迁入 `ACCOUNT_FIELDS`。
     * 保留 `收款银行`（全空列，`开户行/网点` 已覆盖）与两个纯文档列。
     */
    public const SKIPPED_COLUMNS = [
        '收款银行', '资料状态', '来源文件',
    ];

    /** 该列是否属于「不得入库」的列（杂项/无业务含义） */
    public static function isPiiColumn(string $column): bool
    {
        return in_array($column, self::SKIPPED_COLUMNS, true);
    }

    /**
     * 收款账户字段名列表（档案表列名）。
     *
     * 供 seeder 遍历写入用（文本字段空值存 `''`，与金额列的「留空不覆盖」语义不同：
     * 主档就是唯一权威来源，重跑覆盖为空串即「主档确实没这项」）。
     * 顺序稳定，便于测试断言。
     *
     * @return array<int, string>
     */
    public static function accountFields(): array
    {
        return array_values(self::ACCOUNT_FIELDS);
    }

    /**
     * 金额字段名列表（档案表列名，如 `base_salary`）。
     *
     * 供 seeder 遍历「主档留空 ⇒ 不覆盖」用；顺序稳定，便于测试断言。
     *
     * @return array<int, string>
     */
    public static function moneyFields(): array
    {
        return array_values(self::MONEY_FIELDS);
    }

    /**
     * 该档案行在导入时**无法确定**的金额字段（主档留空 ⇒ 值被置 `null`）。
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    public static function unknownMoneyFields(array $row): array
    {
        $out = [];
        foreach (self::MONEY_FIELDS as $col => $field) {
            if (($row[$field] ?? null) === null) {
                $out[] = $col;
            }
        }

        return $out;
    }

}
