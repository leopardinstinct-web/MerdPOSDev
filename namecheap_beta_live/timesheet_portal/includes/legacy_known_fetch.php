<?php
declare(strict_types=1);

/**
 * Deterministic reader for the proven MERDPOS legacy Google workbook.
 *
 * We do not score/guess known tabs. Each configured tab is matched against the
 * exact normalized header contract already observed in the live Google Sheets.
 * Header rows may appear below row 1 (Employee Setup currently does), so every
 * non-empty row is tested until the exact required header set is found.
 */

function legacy_known_header_contract(string $schema): array
{
    return match ($schema) {
        'timesheet' => ['username','storename','logtype','date','time'],
        'payrate' => ['name','payrate'],
        'start_time' => ['storename','shiftstarttime'],
        'employee_setup' => ['name','userid','logstore'],
        'general_ledger' => ['date','storename','account','type','head','amount'],
        'zreport_ledger' => ['date','storename','registertotal','pettycashaddin'],
        default => [],
    };
}

function legacy_parse_known_csv_rows(string $csv, string $schema, string $sheetName): array
{
    $required = legacy_known_header_contract($schema);
    if (!$required) {
        throw new MerdWorkforceException(
            'unsupported_legacy_schema',
            'Configured legacy tab "' . $sheetName . '" has no approved MERDPOS header contract.'
        );
    }

    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    $rawRows = [];
    $line = 0;
    while (($row = fgetcsv($fp)) !== false) {
        $line++;
        if ($row === [null] || count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) continue;
        $values = array_map(static function($v): string {
            $text = trim((string)$v);
            return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text,3) : $text;
        }, $row);
        $rawRows[] = ['line'=>$line,'values'=>$values];
    }
    fclose($fp);
    if (!$rawRows) {
        throw new MerdWorkforceException('legacy_sheet_empty','Configured legacy tab "' . $sheetName . '" is empty.');
    }

    $headerIndex = null;
    foreach ($rawRows as $i => $candidate) {
        $keys = array_values(array_unique(array_filter(array_map('legacy_norm',$candidate['values']))));
        $matched = true;
        foreach ($required as $key) {
            if (!in_array($key,$keys,true)) { $matched = false; break; }
        }
        if ($matched) { $headerIndex = $i; break; }
    }

    if ($headerIndex === null) {
        throw new MerdWorkforceException(
            'legacy_sheet_header_unrecognized',
            'MERDPOS could not match the expected headers in "' . $sheetName . '". Expected: ' . implode(', ',$required) . '. Preview stopped without importing anything.'
        );
    }

    $headers = $rawRows[$headerIndex]['values'];
    $rows = [];
    foreach (array_slice($rawRows,$headerIndex + 1) as $source) {
        $item = ['_source_row'=>(int)$source['line'],'_raw'=>$source['values']];
        foreach ($headers as $i => $header) {
            $header = trim((string)$header);
            if ($header === '') continue;
            $item[$header] = $source['values'][$i] ?? '';
        }
        $rows[] = $item;
    }
    return ['headers'=>$headers,'rows'=>$rows,'header_row'=>(int)$rawRows[$headerIndex]['line']];
}

function legacy_fetch_sources_known(array $sources): array
{
    $out = ['attendance'=>[],'financial'=>[],'snapshot_parts'=>[],'schemas'=>[]];

    if (isset($sources['attendance']) && $sources['attendance']['status'] === 'active') {
        foreach (['timesheet','payrate','start_time','employee_setup'] as $key) {
            $tab = trim((string)($sources['attendance']['sheet_names'][$key] ?? ''));
            if ($tab === '') {
                throw new MerdWorkforceException('legacy_sheet_missing','Attendance source is missing the configured ' . $key . ' tab.');
            }
            $csv = legacy_google_fetch_tab((string)$sources['attendance']['spreadsheet_id'],$tab);
            $parsed = legacy_parse_known_csv_rows($csv,$key,$tab);
            $out['attendance'][$key] = ['sheet'=>$tab,'rows'=>$parsed['rows'],'headers'=>$parsed['headers'],'header_row'=>$parsed['header_row']];
            $out['schemas'][$tab] = ['schema'=>$key,'header_row'=>$parsed['header_row'],'headers'=>$parsed['headers']];
            $out['snapshot_parts'][] = hash('sha256',$key.'|'.$tab.'|'.$csv);
        }
    }

    if (isset($sources['financial']) && $sources['financial']['status'] === 'active') {
        foreach ($sources['financial']['sheet_names'] as $tab) {
            $tab = trim((string)$tab);
            if ($tab === '') continue;
            $kind = legacy_known_financial_sheet_kind($tab);
            if ($kind === 'unsupported') {
                throw new MerdWorkforceException(
                    'unsupported_financial_tab',
                    'Financial tab "' . $tab . '" has no approved MERDPOS migration mapping. Use General Ledger and zReport Ledger.'
                );
            }
            $schema = $kind === 'general_ledger' ? 'general_ledger' : 'zreport_ledger';
            $csv = legacy_google_fetch_tab((string)$sources['financial']['spreadsheet_id'],$tab);
            $parsed = legacy_parse_known_csv_rows($csv,$schema,$tab);
            $out['financial'][] = ['sheet'=>$tab,'rows'=>$parsed['rows'],'headers'=>$parsed['headers'],'header_row'=>$parsed['header_row']];
            $out['schemas'][$tab] = ['schema'=>$schema,'header_row'=>$parsed['header_row'],'headers'=>$parsed['headers']];
            $out['snapshot_parts'][] = hash('sha256','financial|'.$tab.'|'.$csv);
        }
    }

    sort($out['snapshot_parts'],SORT_STRING);
    $out['snapshot_hash'] = hash('sha256',implode('|',$out['snapshot_parts']));
    return $out;
}
