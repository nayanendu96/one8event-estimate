<?php

class SimpleXlsxWriter
{
    private array $rows = [];
    private array $colWidths = [];
    private array $styles = [];

    public function __construct()
    {
        // 0: Title — orange banner
        $this->addStyle([
            'font' => ['bold' => true, 'size' => 14, 'color' => 'FFFFFFFF'],
            'fill' => ['color' => 'FFFF5400'],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);
        // 1: Meta label — light gray
        $this->addStyle([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['color' => 'FFF2F2F2'],
            'border' => true,
        ]);
        // 2: Meta value
        $this->addStyle([
            'font' => ['size' => 10],
            'border' => true,
        ]);
        // 3: Column header — orange
        $this->addStyle([
            'font' => ['bold' => true, 'size' => 10, 'color' => 'FFFFFFFF'],
            'fill' => ['color' => 'FFFF5400'],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'border' => true,
        ]);
        // 4: Data text
        $this->addStyle([
            'font' => ['size' => 10],
            'border' => true,
            'alignment' => ['vertical' => 'center'],
        ]);
        // 5: Data number
        $this->addStyle([
            'font' => ['size' => 10],
            'border' => true,
            'alignment' => ['horizontal' => 'right', 'vertical' => 'center'],
        ]);
        // 6: Total label
        $this->addStyle([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['color' => 'FFF2F2F2'],
            'border' => true,
            'alignment' => ['horizontal' => 'right', 'vertical' => 'center'],
        ]);
        // 7: Total amount
        $this->addStyle([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['color' => 'FFF2F2F2'],
            'border' => true,
            'alignment' => ['horizontal' => 'right', 'vertical' => 'center'],
        ]);
    }

    public function addStyle(array $style): int
    {
        $index = count($this->styles);
        $this->styles[] = $style;

        return $index;
    }

    public function setColumnWidths(array $widths): void
    {
        foreach ($widths as $index => $width) {
            $this->colWidths[(int) $index] = (float) $width;
        }
    }

    public function addRow(array $cells): void
    {
        $this->rows[] = $cells;

        foreach ($cells as $colIndex => $cell) {
            if (!is_int($colIndex)) {
                continue;
            }

            $text = is_array($cell) ? (string) ($cell['value'] ?? '') : (string) $cell;

            if ($text === '' && is_array($cell) && isset($cell['number'])) {
                $text = (string) $cell['number'];
            }

            $length = mb_strlen($text);
            $current = $this->colWidths[$colIndex] ?? 10;
            $this->colWidths[$colIndex] = min(55, max($current, $length + 2));
        }
    }

    public function mergeLastRow(int $fromCol, int $toCol): void
    {
        $row = count($this->rows) - 1;

        if ($row < 0) {
            return;
        }

        if (!isset($this->rows[$row]['_merges'])) {
            $this->rows[$row]['_merges'] = [];
        }

        $this->rows[$row]['_merges'][] = [$fromCol, $toCol];
    }

    public function writeToFile(string $path): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is required for Excel export.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create Excel file.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->relsXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml());
        $zip->close();
    }

    public function output(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($tmp === false) {
            http_response_code(500);
            exit('Could not create temporary file.');
        }

        try {
            $this->writeToFile($tmp);
        } catch (RuntimeException $e) {
            @unlink($tmp);
            http_response_code(500);
            exit($e->getMessage());
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $this->safeFilename($filename) . '"');
        header('Cache-Control: max-age=0');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^\w\-. ]+/u', '_', $filename) ?: 'export.xlsx';

        if (!preg_match('/\.xlsx$/i', $filename)) {
            $filename .= '.xlsx';
        }

        return $filename;
    }

    private function colLetter(int $index): string
    {
        $index++;
        $letters = '';

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index = intdiv($index - $mod, 26);
        }

        return $letters;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            . '<sheets><sheet name="Vendor Requirement" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function alignmentXml(array $alignment): string
    {
        $attrs = [];

        if (!empty($alignment['horizontal'])) {
            $attrs[] = 'horizontal="' . $this->escapeXml((string) $alignment['horizontal']) . '"';
        }

        if (!empty($alignment['vertical'])) {
            $attrs[] = 'vertical="' . $this->escapeXml((string) $alignment['vertical']) . '"';
        }

        if (!$attrs) {
            return '';
        }

        return '<alignment ' . implode(' ', $attrs) . '/>';
    }

    private function stylesXml(): string
    {
        $fills = [
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
        ];
        $styleFillIds = [];

        foreach ($this->styles as $index => $style) {
            if (empty($style['fill'])) {
                $styleFillIds[$index] = 0;
                continue;
            }

            $color = strtoupper(ltrim((string) ($style['fill']['color'] ?? 'FFFFFFFF'), '#'));

            if (strlen($color) === 6) {
                $color = 'FF' . $color;
            }

            $styleFillIds[$index] = count($fills);
            $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="' . $this->escapeXml($color) . '"/></patternFill></fill>';
        }

        $hasBorder = false;

        foreach ($this->styles as $style) {
            if (!empty($style['border'])) {
                $hasBorder = true;
                break;
            }
        }

        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];

        if ($hasBorder) {
            $borders[] = '<border>'
                . '<left style="thin"><color rgb="FFD0D0D0"/></left>'
                . '<right style="thin"><color rgb="FFD0D0D0"/></right>'
                . '<top style="thin"><color rgb="FFD0D0D0"/></top>'
                . '<bottom style="thin"><color rgb="FFD0D0D0"/></bottom>'
                . '<diagonal/></border>';
        }

        $fonts = ['<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'];

        foreach ($this->styles as $style) {
            $font = $style['font'] ?? [];
            $xml = '<font>';

            if (!empty($font['bold'])) {
                $xml .= '<b/>';
            }

            $size = (int) ($font['size'] ?? 11);
            $xml .= '<sz val="' . $size . '"/>';

            if (!empty($font['color'])) {
                $color = strtoupper(ltrim((string) $font['color'], '#'));

                if (strlen($color) === 6) {
                    $color = 'FF' . $color;
                }

                $xml .= '<color rgb="' . $this->escapeXml($color) . '"/>';
            } else {
                $xml .= '<color theme="1"/>';
            }

            $xml .= '<name val="Calibri"/><family val="2"/></font>';
            $fonts[] = $xml;
        }

        $cellXfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];

        foreach ($this->styles as $index => $style) {
            $fontId = $index + 1;
            $fillId = $styleFillIds[$index] ?? 0;
            $borderId = !empty($style['border']) && $hasBorder ? 1 : 0;
            $alignmentXml = !empty($style['alignment']) ? $this->alignmentXml($style['alignment']) : '';

            $attrs = [
                'numFmtId="0"',
                'fontId="' . $fontId . '"',
                'fillId="' . $fillId . '"',
                'borderId="' . $borderId . '"',
                'xfId="0"',
                'applyFont="1"',
            ];

            if ($fillId > 0) {
                $attrs[] = 'applyFill="1"';
            }

            if ($borderId > 0) {
                $attrs[] = 'applyBorder="1"';
            }

            if ($alignmentXml !== '') {
                $attrs[] = 'applyAlignment="1"';
            }

            if ($alignmentXml !== '') {
                $cellXfs[] = '<xf ' . implode(' ', $attrs) . '>' . $alignmentXml . '</xf>';
            } else {
                $cellXfs[] = '<xf ' . implode(' ', $attrs) . '/>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function cellXfIndex(array|string $cell): int
    {
        if (!is_array($cell) || !array_key_exists('style', $cell)) {
            return 0;
        }

        return (int) $cell['style'] + 1;
    }

    private function sheetXml(): string
    {
        $sheetData = '';
        $merges = [];

        foreach ($this->rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $cellsXml = '';
            $mergesForRow = $row['_merges'] ?? [];
            unset($row['_merges']);

            ksort($row);

            foreach ($row as $colIndex => $cell) {
                if (!is_int($colIndex)) {
                    continue;
                }

                $ref = $this->colLetter($colIndex) . $rowNumber;
                $xfIndex = $this->cellXfIndex($cell);

                if (is_array($cell) && isset($cell['number'])) {
                    $cellsXml .= '<c r="' . $ref . '" s="' . $xfIndex . '"><v>' . $this->escapeXml((string) $cell['number']) . '</v></c>';
                    continue;
                }

                $value = is_array($cell) ? (string) ($cell['value'] ?? '') : (string) $cell;

                if ($value === '') {
                    $cellsXml .= '<c r="' . $ref . '" s="' . $xfIndex . '"/>';
                    continue;
                }

                $cellsXml .= '<c r="' . $ref . '" s="' . $xfIndex . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escapeXml($value) . '</t></is></c>';
            }

            foreach ($mergesForRow as $merge) {
                $from = $this->colLetter($merge[0]) . $rowNumber;
                $to = $this->colLetter($merge[1]) . $rowNumber;
                $merges[] = $from . ':' . $to;
            }

            $sheetData .= '<row r="' . $rowNumber . '">' . $cellsXml . '</row>';
        }

        $colsXml = '';

        if ($this->colWidths) {
            ksort($this->colWidths);

            foreach ($this->colWidths as $index => $width) {
                $colsXml .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
        }

        $mergeXml = '';

        if ($merges) {
            $mergeXml = '<mergeCells count="' . count($merges) . '">';

            foreach ($merges as $merge) {
                $mergeXml .= '<mergeCell ref="' . $merge . '"/>';
            }

            $mergeXml .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:G' . max(1, count($this->rows)) . '"/>'
            . ($colsXml ? '<cols>' . $colsXml . '</cols>' : '')
            . '<sheetData>' . $sheetData . '</sheetData>'
            . $mergeXml
            . '<pageSetup orientation="portrait" fitToWidth="1"/>'
            . '</worksheet>';
    }
}
