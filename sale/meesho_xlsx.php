<?php
/**
 * meesho_xlsx.php — tiny XLSX reader (no Composer, no PhpSpreadsheet).
 *
 * An .xlsx is a ZIP of XML parts. We only need:
 *   xl/workbook.xml          -> sheet names + rIds
 *   xl/_rels/workbook.xml.rels -> rId -> file target
 *   xl/sharedStrings.xml     -> the string table
 *   xl/worksheets/sheetN.xml -> the cells
 *
 * Requires only ext-zip + ext-simplexml, both standard on Hostinger.
 *
 * Usage:
 *   $sheets = xlsx_sheet_names('/path/file.xlsx');
 *   $rows   = xlsx_read_sheet('/path/file.xlsx', 'Order Payments');
 *   // $rows is a 0-indexed array of rows; each row is a 0-indexed array
 *   // of cell strings, already aligned to column A,B,C... (gaps filled '').
 */

if (!function_exists('xlsx_open')) {

/** Internal: open the zip and return [ZipArchive, sharedStrings[]]. */
function xlsx_open(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP zip extension is not enabled on this server.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Could not open the .xlsx file (is it a real Excel file?).');
    }

    // Shared strings table
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && $ss !== '') {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($ss);
        libxml_use_internal_errors($prev);
        if ($x !== false) {
            foreach ($x->si as $si) {
                // <si> may be a plain <t>, or many <r><t> runs.
                $txt = '';
                if (isset($si->t)) {
                    $txt = (string)$si->t;
                } else {
                    foreach ($si->r as $r) $txt .= (string)$r->t;
                }
                $shared[] = $txt;
            }
        }
    }
    return [$zip, $shared];
}

/** Convert a cell ref like "AB12" to a 0-based column index. */
function xlsx_col_index(string $ref): int {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) return 0;
    $letters = $m[1];
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/** Map sheet display name -> internal worksheet xml path. */
function xlsx_sheet_map(ZipArchive $zip): array {
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        throw new Exception('This file does not look like a valid .xlsx workbook.');
    }
    $prev = libxml_use_internal_errors(true);
    $xwb = simplexml_load_string($wb);
    $xrel = simplexml_load_string($rels);
    libxml_use_internal_errors($prev);
    if ($xwb === false || $xrel === false) {
        throw new Exception('Could not parse the workbook structure.');
    }

    // rId -> target
    $target = [];
    foreach ($xrel->Relationship as $r) {
        $target[(string)$r['Id']] = (string)$r['Target'];
    }

    $map = [];
    foreach ($xwb->sheets->sheet as $sh) {
        $name = (string)$sh['name'];
        // r:id lives in the relationships namespace
        $rid = '';
        foreach ($sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $k => $v) {
            if ($k === 'id') $rid = (string)$v;
        }
        if ($rid !== '' && isset($target[$rid])) {
            $t = ltrim($target[$rid], '/');
            if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
            $map[$name] = $t;
        }
    }
    return $map;
}

/** Public: list sheet names. */
function xlsx_sheet_names(string $path): array {
    [$zip, ] = xlsx_open($path);
    $map = xlsx_sheet_map($zip);
    $zip->close();
    return array_keys($map);
}

/**
 * Public: read one sheet into a rectangular array of strings.
 * Dates stored as serial numbers are returned as 'Y-m-d' when the cell
 * is not a shared string and looks like an Excel date serial.
 */
function xlsx_read_sheet(string $path, string $sheetName): array {
    [$zip, $shared] = xlsx_open($path);
    $map = xlsx_sheet_map($zip);
    if (!isset($map[$sheetName])) {
        $zip->close();
        throw new Exception('Sheet "' . $sheetName . '" not found in this file.');
    }
    $xml = $zip->getFromName($map[$sheetName]);
    $zip->close();
    if ($xml === false) throw new Exception('Could not read sheet "' . $sheetName . '".');

    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if ($x === false) throw new Exception('Could not parse sheet "' . $sheetName . '".');

    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $cells = [];
        $maxIdx = -1;
        foreach ($row->c as $c) {
            $ref  = (string)$c['r'];
            $type = (string)$c['t'];
            $idx  = xlsx_col_index($ref);

            $val = '';
            if ($type === 's') {
                $i = (int)$c->v;
                $val = $shared[$i] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = isset($c->is->t) ? (string)$c->is->t : '';
            } elseif ($type === 'b') {
                $val = ((string)$c->v === '1') ? 'TRUE' : 'FALSE';
            } else {
                // number, date serial, or formula result
                $val = isset($c->v) ? (string)$c->v : '';
            }
            $cells[$idx] = $val;
            if ($idx > $maxIdx) $maxIdx = $idx;
        }
        // fill gaps so columns line up
        $flat = [];
        for ($i = 0; $i <= $maxIdx; $i++) $flat[$i] = $cells[$i] ?? '';
        $rows[] = $flat;
    }
    return $rows;
}

/**
 * Normalise a value from the panel file into Y-m-d, or null.
 * Handles "2026-06-18 09:53:43", "2026-06-19", and Excel serials.
 */
function xlsx_to_date($raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    // Excel serial (days since 1899-12-30)
    if (is_numeric($raw) && (float)$raw > 20000 && (float)$raw < 80000) {
        $ts = ((float)$raw - 25569) * 86400;
        return gmdate('Y-m-d', (int)$ts);
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

} // end function_exists guard
