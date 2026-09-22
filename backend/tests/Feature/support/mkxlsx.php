<?php

/**
 * 测试夹具：生成最小 xlsx。
 *
 * 刻意模仿样表的真实写法：第 1 行标题、第 2 行表头、`<dimension>` 声明成 XFD 宽度、
 * 每行尾部带一堆无 `<v>` 的占位 `<c>`（WPS 就是这样写的）。
 * 这样测出来的解析行为与真实样表一致，而不是只在「干净」的合成数据上通过。
 */

/**
 * 生成一个最小 xlsx（供测试夹具使用）。
 * 结构刻意模仿样表：第 1 行标题、第 2 行表头、dimension 声明成 XFD 宽度、
 * 每行尾部带一堆无 <v> 的占位 <c>（复现 WPS 的真实写法）。
 */
function mkXlsx(string $path, string $sheetName, array $rows, int $declaredMaxCol = 16384, bool $padEmptyCells = true): void
{
    $z = new ZipArchive;
    @unlink($path);
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $colLetter = function (int $n): string {
        $s = '';
        while ($n > 0) { $n--; $s = chr(65 + $n % 26).$s; $n = intdiv($n, 26); }
        return $s;
    };

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<dimension ref="A1:'.$colLetter($declaredMaxCol).count($rows).'"/><sheetData>';
    foreach ($rows as $i => $row) {
        $rn = $i + 1;
        $sheet .= '<row r="'.$rn.'" spans="1:'.$declaredMaxCol.'">';
        foreach ($row as $ci => $val) {
            if ($val === null || $val === '') continue;
            $ref = $colLetter($ci + 1).$rn;
            if (is_numeric($val) && !is_string($val)) {
                $sheet .= '<c r="'.$ref.'"><v>'.$val.'</v></c>';
            } else {
                $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string)$val, ENT_QUOTES | ENT_XML1).'</t></is></c>';
            }
        }
        if ($padEmptyCells) {
            for ($c = count($row) + 1; $c <= count($row) + 30; $c++) {
                $sheet .= '<c r="'.$colLetter($c).$rn.'" s="0"/>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        .'</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="'.htmlspecialchars($sheetName, ENT_QUOTES | ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'</Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $z->close();
}
