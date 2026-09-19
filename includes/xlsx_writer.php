<?php
/**
 * Minimal XLSX writer.
 *
 * CLAUDE.md forbids Composer, so we can't reach for PhpSpreadsheet.
 * An .xlsx file is really just a ZIP archive containing a fixed set
 * of XML documents — the OpenXML spec calls it a "SpreadsheetML"
 * package. This writer builds those documents in-memory and hands
 * them to PHP's built-in ZipArchive.
 *
 * Scope, deliberately small:
 *   - Single worksheet per file.
 *   - Every cell written as inline string (no sharedStrings dedup).
 *     That trades a bit of on-disk size for a much shorter writer.
 *   - Header row is bold via a single named cell style.
 *
 * Usage:
 *   xlsx_send('tasks.xlsx', 'Tasks',
 *       ['Task', 'Title', 'Status'],
 *       [
 *           ['ADM-1', 'Fix bug', 'Open'],
 *           ['ADM-2', 'Add index', 'In Progress'],
 *       ]);
 *
 * The function sets the Content-Type / Content-Disposition headers
 * and writes the bytes to the response, then exits.
 */

/** XML-escape a string for use inside an .xlsx cell or attribute. */
function xlsx__esc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** 0-indexed column number → A, B, …, Z, AA, AB, … */
function xlsx__col(int $n): string
{
    $s = '';
    $n++;
    while ($n > 0) {
        $r = ($n - 1) % 26;
        $s = chr(65 + $r) . $s;
        $n = (int) (($n - 1) / 26);
    }
    return $s;
}

/**
 * Build the sheet1.xml body from a header row + data rows.
 * Every value is written as an inline string so we don't need to
 * distinguish numeric vs. text at write time — the operator opens
 * the file in Excel and it Just Works. Dates should be pre-formatted
 * as DD/MM/YYYY strings by the caller for consistency with the rest
 * of the app.
 */
function xlsx__sheet_xml(array $header, iterable $rows): string
{
    $out  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $out .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $out .= '<sheetData>';

    $rowIdx = 1;
    // Header
    if ($header !== []) {
        $out .= '<row r="' . $rowIdx . '">';
        foreach ($header as $c => $val) {
            $ref = xlsx__col($c) . $rowIdx;
            $out .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t xml:space="preserve">' . xlsx__esc((string) $val) . '</t></is></c>';
        }
        $out .= '</row>';
        $rowIdx++;
    }
    foreach ($rows as $row) {
        $out .= '<row r="' . $rowIdx . '">';
        foreach (array_values((array) $row) as $c => $val) {
            if ($val === null || $val === '') continue;
            $ref = xlsx__col($c) . $rowIdx;
            $out .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx__esc((string) $val) . '</t></is></c>';
        }
        $out .= '</row>';
        $rowIdx++;
    }
    $out .= '</sheetData></worksheet>';
    return $out;
}

/** The other files an .xlsx package needs. Constant apart from the sheet body. */
function xlsx__package_parts(string $sheetName): array
{
    $safe = xlsx__esc($sheetName === '' ? 'Sheet1' : $sheetName);

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $safe . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    // Two cell formats: 0 = default, 1 = bold (header).
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    return [
        '[Content_Types].xml'         => $contentTypes,
        '_rels/.rels'                 => $rels,
        'xl/workbook.xml'             => $workbook,
        'xl/_rels/workbook.xml.rels'  => $workbookRels,
        'xl/styles.xml'               => $styles,
    ];
}

/** Zip the package files + sheet body into a temp file and return the path. */
function xlsx__build(string $sheetName, array $header, iterable $rows): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive PHP extension is required to build XLSX files.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not open temporary file for XLSX write: ' . $tmp);
    }
    foreach (xlsx__package_parts($sheetName) as $path => $body) {
        $zip->addFromString($path, $body);
    }
    $zip->addFromString('xl/worksheets/sheet1.xml', xlsx__sheet_xml($header, $rows));
    $zip->close();
    return $tmp;
}

/**
 * Build the workbook and stream it back as an .xlsx download. Sends
 * Content-Type, Content-Disposition and Content-Length headers, then
 * writes the bytes and exits.
 */
function xlsx_send(string $filename, string $sheetName, array $header, iterable $rows): void
{
    $tmp = xlsx__build($sheetName, $header, $rows);
    $size = filesize($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/**
 * A → 0, B → 1, …, Z → 25, AA → 26, AB → 27, … used by the reader to
 * turn a cell reference like "AB12" into a 0-based column index.
 */
function xlsx__col_index(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) return 0;
    $letters = $m[1];
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $n - 1;
}

/**
 * Read every row from the first worksheet of an .xlsx file into a
 * plain array of arrays of strings. Empty rows are skipped. Cells
 * with a `t="s"` type are resolved through xl/sharedStrings.xml;
 * inline strings and raw values are copied verbatim. No date /
 * number formatting is applied — the caller decides how to interpret
 * numeric-looking values (the importer for this app treats them all
 * as text, which is fine for our columns).
 *
 * Throws RuntimeException if the file is unreadable, is not a valid
 * .xlsx package, or has no worksheet. The importer catches that and
 * surfaces it as a friendly flash message.
 */
function xlsx_read(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive PHP extension is required to read XLSX files.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the uploaded file as an XLSX archive.');
    }

    // Shared strings (optional).
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false && $ssXml !== '') {
        $prev = libxml_use_internal_errors(true);
        $ss   = @simplexml_load_string($ssXml);
        libxml_use_internal_errors($prev);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) { $shared[] = (string) $si->t; continue; }
                if (isset($si->r)) {
                    $s = '';
                    foreach ($si->r as $r) $s .= (string) $r->t;
                    $shared[] = $s;
                    continue;
                }
                $shared[] = '';
            }
        }
    }

    // First worksheet — usually xl/worksheets/sheet1.xml, but scan
    // for anything that matches the shape.
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === false || $sheetXml === '') {
        throw new RuntimeException('The uploaded file has no worksheet.');
    }

    $prev = libxml_use_internal_errors(true);
    $sheet = @simplexml_load_string($sheetXml);
    libxml_use_internal_errors($prev);
    if ($sheet === false || !isset($sheet->sheetData)) return [];

    $rows = [];
    foreach ($sheet->sheetData->row as $rowXml) {
        $indexed = [];
        foreach ($rowXml->c as $c) {
            $ref  = (string) $c['r'];
            $col  = xlsx__col_index($ref);
            $type = (string) $c['t'];
            $val  = '';
            if ($type === 's') {
                $idx = (int) $c->v;
                $val = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr' || $type === 'str') {
                if (isset($c->is->t)) $val = (string) $c->is->t;
                elseif (isset($c->v)) $val = (string) $c->v;
            } elseif (isset($c->v)) {
                $val = (string) $c->v;
            }
            $indexed[$col] = $val;
        }
        if ($indexed === []) continue;
        $max = max(array_keys($indexed));
        $row = [];
        for ($i = 0; $i <= $max; $i++) $row[] = $indexed[$i] ?? '';
        // Skip fully-empty rows so blank tail rows in Excel don't
        // count as import errors.
        $nonEmpty = false;
        foreach ($row as $cell) if (trim((string) $cell) !== '') { $nonEmpty = true; break; }
        if ($nonEmpty) $rows[] = $row;
    }
    return $rows;
}
