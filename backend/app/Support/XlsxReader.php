<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * 极简 xlsx 读取器（只读、流式）。
 *
 * ## 为什么自己写而不是引依赖
 *
 * 本仓库**不允许新增 composer 依赖**（t7 契约边界），而 PhpSpreadsheet 体积大且
 * 需要 ext-gd/ext-fileinfo 等一串扩展。业绩表结构简单（纯值、无公式、无图片），
 * 用 `ext-zip` + `XMLReader` 已经足够，且能顺手绕开两个真实的坑：
 *
 * 1. **声明维度不可信**：样表 `<dimension ref="A1:XFD150"/>`，`max_column` 是 16384。
 *    用「按声明宽度遍历」的读法会构造 16384 × 150 的矩阵（本机实测 openpyxl 读这
 *    张表要几秒、几十 MB），而真实有效列只有 19 列。本读取器**只认 XML 里真实出现
 *    的 `<c>` 元素**，不按声明维度铺开。
 * 2. **空单元格**：WPS 写出的行里，`<c r="H3" s="32"/>` 这种无 `<v>` 的占位单元会一直
 *    延伸到 XFD 列（样表实测每行 2000+ 个）。这些必须**当成不存在**，否则
 *    「从金额列后一列到最后一个非空表头列」的分配列区间会被撑到 16384。
 */
final class XlsxReader
{
    /** 日期型数字格式 id（内置） */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    private array $sharedStrings = [];

    /** @var array<int, bool> cellXfs 索引 → 是否日期格式 */
    private array $dateStyles = [];

    /** @var array<string, string> sheet 名 → sheet xml 路径（zip 内） */
    private array $sheetPaths = [];

    /** @var array<string, int> */
    private array $numFmts = [];

    private function __construct(private ZipArchive $zip) {}

    public static function open(string $path): self
    {
        // 缺扩展时的失败前置：业绩导入是超管在页面上点的操作，没有这层守卫，
        // 用户看到的是 `Class "ZipArchive" not found` 这类 500，排查不到「去宝塔装扩展」这一步。
        // 文案与 BackupService.php:135 的同类守卫保持一致。
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('服务器 PHP 缺少 zip 扩展，请在宝塔「PHP 设置 → 安装扩展」中启用 php_zip');
        }
        if (! is_file($path)) {
            throw new RuntimeException('文件不存在：'.$path);
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('不是有效的 xlsx（无法作为 zip 打开）');
        }
        $self = new self($zip);
        $self->loadSharedStrings();
        $self->loadStyles();
        $self->loadSheetIndex();

        return $self;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /** @return string[] */
    public function sheetNames(): array
    {
        return array_keys($this->sheetPaths);
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheetPaths[$name]);
    }

    /**
     * 逐行读取某个 sheet 的**真实单元格**。
     *
     * @param  int  $maxCols  只取前 N 列（0 = 不限制）；用于「按有效列扫描」的场景
     * @return \Generator<int, array<int, string|float|int|null>> 行号(1-based) => [列号(1-based) => 值]
     */
    public function rows(string $sheetName, int $maxCols = 0): \Generator
    {
        if (! isset($this->sheetPaths[$sheetName])) {
            throw new RuntimeException("找不到 sheet：{$sheetName}");
        }
        $inner = $this->zip->getFromName($this->sheetPaths[$sheetName]);
        if ($inner === false) {
            throw new RuntimeException("sheet 内容读取失败：{$sheetName}");
        }

        $reader = new \XMLReader;
        $reader->XML($inner, null, LIBXML_NONET);
        $rowNumber = 0;
        $row = [];
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'row') {
                $rowNumber = (int) ($reader->getAttribute('r') ?: $rowNumber + 1);
                $row = [];
                if ($reader->isEmptyElement) {
                    yield $rowNumber => $row;
                    continue;
                }
                $rowDepth = $reader->depth;
                while (@$reader->read()) {
                    if ($reader->nodeType === \XMLReader::END_ELEMENT
                        && $reader->localName === 'row'
                        && $reader->depth === $rowDepth) {
                        break;
                    }
                    if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'c') {
                        continue;
                    }
                    $ref = (string) $reader->getAttribute('r');
                    $col = self::columnIndex($ref);
                    if ($col <= 0 || ($maxCols > 0 && $col > $maxCols)) {
                        continue;
                    }
                    $value = $this->readCellValue($reader, $ref);
                    if ($value !== null && $value !== '') {
                        $row[$col] = $value;
                    }
                }
                yield $rowNumber => $row;
                $row = [];
            }
        }
        $reader->close();
    }

    /** 读取一个单元格的值；调用时 reader 停在 `<c>` 上 */
    private function readCellValue(\XMLReader $reader, string $ref): string|float|int|null
    {
        $type = (string) $reader->getAttribute('t');
        $styleIdx = (int) $reader->getAttribute('s');
        if ($reader->isEmptyElement) {
            return null;
        }
        $depth = $reader->depth;
        $raw = null;
        $inline = null;
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'c' && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'v') {
                $raw = $reader->readString();
            } elseif ($reader->localName === 'is') {
                $inline = $reader->readInnerXml();
            }
        }
        if ($type === 'inlineStr') {
            return $inline === null ? null : self::stripTags($inline);
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($type === 's') {
            return $this->sharedStrings[(int) $raw] ?? null;
        }
        if ($type === 'str') {
            return $raw;
        }
        if ($type === 'b') {
            return $raw === '1' ? 1 : 0;
        }
        if ($type === 'e') {
            return null;
        }
        // 数字：日期样式时换算成 Y-m-d / Y-m-d H:i:s，其余保留数值
        if ($this->dateStyles[$styleIdx] ?? false) {
            return self::excelSerialToDate((float) $raw);
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            $int = (int) $raw;

            return (string) $int === ltrim($raw, '+') ? $int : (float) $raw;
        }

        return (float) $raw;
    }

    /** Excel 序列号 → 日期字符串。基准 1899-12-30（Excel 误把 1900 当闰年的历史补偿） */
    public static function excelSerialToDate(float $serial): string
    {
        $days = (int) floor($serial);
        $fraction = $serial - $days;
        $base = new \DateTimeImmutable('1899-12-30 00:00:00');
        $dt = $base->modify("+{$days} days");
        if ($fraction > 0) {
            $seconds = (int) round($fraction * 86400);
            $dt = $dt->modify("+{$seconds} seconds");

            return $dt->format('Y-m-d H:i:s');
        }

        return $dt->format('Y-m-d');
    }

    /** `A3` / `AB12` → 列号（1-based）；非法返回 0 */
    public static function columnIndex(string $ref): int
    {
        if (! preg_match('/^([A-Z]+)\d+$/', strtoupper($ref), $m)) {
            return 0;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    private static function stripTags(string $xml): string
    {
        // 富文本 inline string：取所有 <t> 的文本拼接
        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $xml, $m)) {
            return html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function loadSharedStrings(): void
    {
        $inner = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($inner === false) {
            return;
        }
        $xml = @simplexml_load_string($inner, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            return;
        }
        foreach ($xml->si as $si) {
            $this->sharedStrings[] = self::siText($si);
        }
    }

    private static function siText(SimpleXMLElement $si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }
        $buf = '';
        foreach ($si->r as $r) {
            $buf .= (string) $r->t;
        }

        return $buf;
    }

    /**
     * 取「父节点 → 子节点列表」，把 SimpleXML 的三种空值形态（null / 空节点 / 单节点）
     * 统一成可安全 foreach 的数组。
     *
     * 为什么需要它：`$xml->cellXfs` 缺失时 `$xml->cellXfs->xf` 是 `null`，
     * 直接 `foreach` 抛 Error、`count()` 抛 TypeError —— 而缺 `<cellXfs>` 的
     * xlsx（我们自己的测试夹具、以及某些精简导出的表）完全合法。
     * 这里刻意**不用** `?? []`：`??` 走 `__isset`，在「同名多子节点」上只返回第一个，
     * 会让 38 个样式只解析出 1 个（见下方注释）。
     *
     * @return array<int, \SimpleXMLElement>
     */
    private static function nodeList(\SimpleXMLElement $parent, string $child, string $grandChild): array
    {
        $node = $parent->{$child};
        if ($node === null) {
            return [];
        }
        // 父节点存在但子节点一个都没有时（如 `<styleSheet>` 里压根没有 `<cellXfs>`），
        // `$node->{$grandChild}` 同样是 **null** —— 这里必须再判一次，
        // 否则仍是 `foreach(null)` 的 500。两层都要判。
        $list = $node->{$grandChild};
        if ($list === null) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $out[] = $item;
        }

        return $out;
    }

    private function loadStyles(): void
    {
        $inner = $this->zip->getFromName('xl/styles.xml');
        if ($inner === false) {
            return;
        }
        $xml = @simplexml_load_string($inner, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            return;
        }
        // ⚠️ 这里**不能**写 `$xml->cellXfs->xf ?? []`：`??` 会先走 SimpleXML 的
        // `__isset`/`__get`，在「同名多子节点」上只返回第一个子节点 —— 本机实测
        // 38 个 `xf` 只解析出 1 个，日期样式集体丢失（日期列于是返回 46235 这种序列号，
        // 落库时全部行都会被判成「日期不在目标月份」而静默跳过）。先赋值再 foreach 才完整。
        //
        // 同时：`$xml->numFmts` 缺失时可能是 null、也可能是**空 SimpleXMLElement**，
        // 对它取 `->numFmt` 又会得到 null —— `foreach(null)` 与 `count(null)` 在 PHP 8
        // 分别是 Error 与 TypeError（实测都是 500）。所以统一走 `nodeList()` 归一化，
        // 不在这里猜 SimpleXML 的空值形态。
        foreach (self::nodeList($xml, 'numFmts', 'numFmt') as $fmt) {
            $this->numFmts[(int) $fmt['numFmtId']] = (string) $fmt['formatCode'];
        }
        $idx = 0;
        foreach (self::nodeList($xml, 'cellXfs', 'xf') as $xf) {
            $fmtId = (int) $xf['numFmtId'];
            $this->dateStyles[$idx] = in_array($fmtId, self::BUILTIN_DATE_FORMATS, true)
                || $this->looksLikeDateFormat($this->numFmts[$fmtId] ?? '');
            $idx++;
        }
    }

    private function looksLikeDateFormat(string $code): bool
    {
        if ($code === '') {
            return false;
        }
        // 去掉引号内的字面量，避免把 "月" 这类中文当日期标记
        $stripped = preg_replace('/"[^"]*"/', '', $code) ?? $code;

        return (bool) preg_match('/[yYmMdDhHsS]/', $stripped) && ! preg_match('/^[#0.,%]+$/', $stripped);
    }

    private function loadSheetIndex(): void
    {
        $wb = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            throw new RuntimeException('xlsx 缺少 workbook.xml / rels');
        }
        $relMap = [];
        $relXml = @simplexml_load_string($rels, SimpleXMLElement::class, LIBXML_NONET);
        if ($relXml !== false) {
            foreach ($relXml->Relationship as $rel) {
                $relMap[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
            }
        }
        $wbXml = @simplexml_load_string($wb, SimpleXMLElement::class, LIBXML_NONET);
        if ($wbXml === false) {
            throw new RuntimeException('workbook.xml 解析失败');
        }
        foreach ($wbXml->sheets->sheet as $sheet) {
            $name = (string) $sheet['name'];
            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $relMap[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            $this->sheetPaths[$name] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }
    }
}
