<?php

const XLSX_MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlsxXmlText(string $value): string
{
    $value = mb_scrub($value, 'UTF-8');
    $value = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FFFE}\x{FFFF}]/u', '', $value) ?? '';

    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxColumnName(int $number): string
{
    $name = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $number = intdiv($number - 1, 26);
    }

    return $name;
}

function sendXlsxDownload(
    string $filename,
    array $headers,
    array $rows,
    array $columnWidths = [],
    array $wrapTextColumns = []
): never
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('XLSX support requires the PHP ZIP extension.');
    }

    $filePath = tempnam(sys_get_temp_dir(), 'farmer-portal-xlsx-');
    if ($filePath === false) {
        throw new RuntimeException('A temporary XLSX file could not be created.');
    }

    $archive = new ZipArchive();
    $archiveOpened = false;
    try {
        if ($archive->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The XLSX workbook could not be created.');
        }
        $archiveOpened = true;

        $wrapTextColumns = array_fill_keys(array_map('intval', $wrapTextColumns), true);
        $sheetRows = array_merge([$headers], $rows);
        $sheetData = '';
        foreach ($sheetRows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $sheetData .= '<row r="' . $rowNumber . '">';
            foreach (array_values($row) as $columnIndex => $value) {
                $columnNumber = $columnIndex + 1;
                $reference = xlsxColumnName($columnNumber) . $rowNumber;
                $styleAttribute = isset($wrapTextColumns[$columnNumber]) ? ' s="1"' : '';
                $text = xlsxXmlText((string) ($value ?? ''));
                $sheetData .= '<c r="' . $reference . '"' . $styleAttribute . ' t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
            $sheetData .= '</row>';
        }

        $columnXml = '';
        foreach ($columnWidths as $columnNumber => $width) {
            if (
                filter_var($columnNumber, FILTER_VALIDATE_INT) === false
                || (int) $columnNumber < 1
                || !is_numeric($width)
                || (float) $width < 1
                || (float) $width > 255
            ) {
                throw new InvalidArgumentException('XLSX column widths must use valid positive column numbers and widths.');
            }
            $widthValue = number_format((float) $width, 2, '.', '');
            $columnXml .= '<col min="' . (int) $columnNumber . '" max="' . (int) $columnNumber
                . '" width="' . $widthValue . '" customWidth="1"/>';
        }
        $columnsXml = $columnXml === '' ? '' : '<cols>' . $columnXml . '</cols>';
        $hasWrappedCells = $wrapTextColumns !== [];
        $lastColumn = xlsxColumnName(max(1, count($headers)));
        $lastRow = max(1, count($sheetRows));
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . ($hasWrappedCells ? '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' : '')
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="' . XLSX_MAIN_NAMESPACE . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Complaints" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . ($hasWrappedCells ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' : '')
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="' . XLSX_MAIN_NAMESPACE . '">'
                . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                . $columnsXml
                . '<sheetData>' . $sheetData . '</sheetData>'
                . '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>'
                . '</worksheet>'
        ];
        if ($hasWrappedCells) {
            $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="' . XLSX_MAIN_NAMESPACE . '">'
                . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>';
        }

        foreach ($files as $name => $contents) {
            if (!$archive->addFromString($name, $contents)) {
                throw new RuntimeException('An XLSX workbook section could not be written.');
            }
        }

        if (!$archive->close()) {
            $archiveOpened = false;
            throw new RuntimeException('The XLSX workbook could not be finalized.');
        }
        $archiveOpened = false;

        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'export.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, no-store');
        if (readfile($filePath) === false) {
            throw new RuntimeException('The XLSX workbook could not be sent to the browser.');
        }
    } finally {
        if ($archiveOpened) {
            $archive->close();
        }
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    exit;
}

function xlsxXPath(SimpleXMLElement $element, string $query): array
{
    $element->registerXPathNamespace('x', XLSX_MAIN_NAMESPACE);

    return $element->xpath($query) ?: [];
}

function xlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): string
{
    $attributes = $cell->attributes();
    $type = (string) ($attributes['t'] ?? '');

    if ($type === 'inlineStr') {
        $text = '';
        foreach (xlsxXPath($cell, './x:is//x:t') as $textNode) {
            $text .= (string) $textNode;
        }

        return $text;
    }

    $values = xlsxXPath($cell, './x:v');
    $value = isset($values[0]) ? (string) $values[0] : '';
    if ($type === 's') {
        $index = filter_var($value, FILTER_VALIDATE_INT);
        if ($index === false || !array_key_exists($index, $sharedStrings)) {
            throw new RuntimeException('The XLSX file contains an invalid shared string reference.');
        }

        return $sharedStrings[$index];
    }

    return $value;
}

function readXlsxTable(string $filePath): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('XLSX upload requires the PHP ZIP extension.');
    }

    $archive = new ZipArchive();
    if ($archive->open($filePath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Upload a valid .xlsx workbook.');
    }
    try {
        if ($archive->numFiles > 1000) {
            throw new RuntimeException('The XLSX workbook contains too many internal files.');
        }

        $totalUncompressedSize = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            $totalUncompressedSize += (int) ($stat['size'] ?? 0);
            if ($totalUncompressedSize > 20 * 1024 * 1024) {
                throw new RuntimeException('The XLSX workbook is too large to process.');
            }
        }

        $worksheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $sharedStringsXml = $archive->getFromName('xl/sharedStrings.xml');
    } finally {
        $archive->close();
    }

    if ($worksheetXml === false) {
        throw new RuntimeException('The first worksheet could not be read from this workbook.');
    }
    $worksheet = simplexml_load_string($worksheetXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    if (!$worksheet) {
        throw new RuntimeException('The first worksheet contains invalid XML.');
    }

    $sharedStrings = [];
    if ($sharedStringsXml !== false) {
        $sharedStringDocument = simplexml_load_string(
            $sharedStringsXml,
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_NOBLANKS
        );
        if (!$sharedStringDocument) {
            throw new RuntimeException('The workbook shared strings are invalid.');
        }
        foreach (xlsxXPath($sharedStringDocument, '/x:sst/x:si') as $sharedString) {
            $text = '';
            foreach (xlsxXPath($sharedString, './/x:t') as $textNode) {
                $text .= (string) $textNode;
            }
            $sharedStrings[] = $text;
        }
    }

    $worksheetRows = xlsxXPath($worksheet, '/x:worksheet/x:sheetData/x:row');
    if ($worksheetRows === []) {
        throw new RuntimeException('The worksheet is empty.');
    }
    if (count($worksheetRows) > 10001) {
        throw new RuntimeException('Import up to 10,000 rows at a time.');
    }

    $readRow = static function (SimpleXMLElement $row) use ($sharedStrings): array {
        $values = [];
        foreach (xlsxXPath($row, './x:c') as $cell) {
            $reference = (string) ($cell->attributes()['r'] ?? '');
            if (!preg_match('/\A([A-Z]+)/i', $reference, $matches)) {
                throw new RuntimeException('The XLSX worksheet contains a cell with an invalid address.');
            }
            $columnIndex = 0;
            foreach (str_split(strtoupper($matches[1])) as $letter) {
                $columnIndex = ($columnIndex * 26) + ord($letter) - 64;
            }
            if ($columnIndex < 1 || $columnIndex > 100) {
                throw new RuntimeException('The XLSX worksheet contains too many columns.');
            }
            $values[$columnIndex - 1] = xlsxCellValue($cell, $sharedStrings);
        }

        if ($values === []) {
            return [];
        }
        ksort($values);
        $lastColumn = (int) array_key_last($values);

        return array_replace(array_fill(0, $lastColumn + 1, ''), $values);
    };

    $headers = array_map('trim', $readRow($worksheetRows[0]));
    if ($headers === [] || count(array_filter($headers, static fn ($value) => $value !== '')) === 0) {
        throw new RuntimeException('The first worksheet must contain column headers.');
    }

    $rows = [];
    foreach (array_slice($worksheetRows, 1) as $worksheetRow) {
        $values = $readRow($worksheetRow);
        if ($values === [] || count(array_filter($values, static fn ($value) => trim($value) !== '')) === 0) {
            continue;
        }
        $rows[] = array_replace(array_fill(0, count($headers), ''), array_slice($values, 0, count($headers)));
    }

    return ['headers' => $headers, 'rows' => $rows];
}

function readXlsxProductNames(string $filePath): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('XLSX upload requires the PHP ZIP extension.');
    }

    $archive = new ZipArchive();
    if ($archive->open($filePath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Upload a valid .xlsx workbook.');
    }
    if ($archive->numFiles > 1000) {
        $archive->close();
        throw new RuntimeException('The XLSX workbook contains too many internal files.');
    }

    $totalUncompressedSize = 0;
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $stat = $archive->statIndex($index);
        $totalUncompressedSize += (int) ($stat['size'] ?? 0);
        if ($totalUncompressedSize > 20 * 1024 * 1024) {
            $archive->close();
            throw new RuntimeException('The XLSX workbook is too large to process.');
        }
    }

    $worksheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
    $sharedStringsXml = $archive->getFromName('xl/sharedStrings.xml');
    $archive->close();

    if ($worksheetXml === false) {
        throw new RuntimeException('The first worksheet could not be read from this workbook.');
    }

    $worksheet = simplexml_load_string($worksheetXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    if (!$worksheet) {
        throw new RuntimeException('The first worksheet contains invalid XML.');
    }

    $sharedStrings = [];
    if ($sharedStringsXml !== false) {
        $sharedStringDocument = simplexml_load_string(
            $sharedStringsXml,
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_NOBLANKS
        );
        if (!$sharedStringDocument) {
            throw new RuntimeException('The workbook shared strings are invalid.');
        }

        foreach (xlsxXPath($sharedStringDocument, '/x:sst/x:si') as $sharedString) {
            $text = '';
            foreach (xlsxXPath($sharedString, './/x:t') as $textNode) {
                $text .= (string) $textNode;
            }
            $sharedStrings[] = $text;
        }
    }

    $rows = xlsxXPath($worksheet, '/x:worksheet/x:sheetData/x:row');
    if ($rows === []) {
        throw new RuntimeException('The worksheet is empty.');
    }

    $productColumn = null;
    foreach (xlsxXPath($rows[0], './x:c') as $cell) {
        $reference = (string) ($cell->attributes()['r'] ?? '');
        if (!preg_match('/\A([A-Z]+)/', $reference, $matches)) {
            continue;
        }

        $columnIndex = 0;
        foreach (str_split($matches[1]) as $letter) {
            $columnIndex = ($columnIndex * 26) + ord($letter) - 64;
        }
        if (mb_strtolower(trim(xlsxCellValue($cell, $sharedStrings)), 'UTF-8') === 'product name') {
            $productColumn = $columnIndex;
            break;
        }
    }
    if ($productColumn === null) {
        throw new RuntimeException('The first worksheet must contain a "Product Name" header.');
    }

    if (count($rows) > 10001) {
        throw new RuntimeException('Import up to 10,000 product rows at a time.');
    }

    $products = [];
    foreach (array_slice($rows, 1) as $row) {
        foreach (xlsxXPath($row, './x:c') as $cell) {
            $reference = (string) ($cell->attributes()['r'] ?? '');
            if (!preg_match('/\A([A-Z]+)/', $reference, $matches)) {
                continue;
            }

            $columnIndex = 0;
            foreach (str_split($matches[1]) as $letter) {
                $columnIndex = ($columnIndex * 26) + ord($letter) - 64;
            }
            if ($columnIndex !== $productColumn) {
                continue;
            }

            $productName = trim(xlsxCellValue($cell, $sharedStrings));
            if ($productName !== '') {
                if (mb_strlen($productName, 'UTF-8') > 300) {
                    throw new RuntimeException('Product names must be 300 characters or fewer.');
                }
                $products[] = $productName;
            }
            break;
        }
    }

    if ($products === []) {
        throw new RuntimeException('No product names were found beneath the "Product Name" header.');
    }

    return $products;
}
