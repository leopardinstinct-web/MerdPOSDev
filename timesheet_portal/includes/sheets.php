<?php
require_once __DIR__ . '/config.php';

function csv_url_for_sheet(string $sheetName): string
{
    return 'https://docs.google.com/spreadsheets/d/' . rawurlencode(SPREADSHEET_ID)
        . '/gviz/tq?tqx=out:csv&sheet=' . rawurlencode($sheetName);
}

function cache_path_for_sheet(string $sheetName): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $sheetName);
    return sys_get_temp_dir() . '/timesheet_portal_' . SPREADSHEET_ID . '_' . $safe . '.csv';
}

function fetch_url(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TimesheetPortal/1.0'
        ]);
        $data = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($data === false || $status >= 400) {
            throw new RuntimeException('Could not fetch Google Sheet CSV. HTTP ' . $status . ($error ? ' - ' . $error : ''));
        }
        return $data;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header' => "User-Agent: TimesheetPortal/1.0\r\n"
        ]
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        throw new RuntimeException('Could not fetch Google Sheet CSV. Enable cURL or allow_url_fopen on hosting.');
    }
    return $data;
}

function read_sheet_csv(string $sheetName): array
{
    $cachePath = cache_path_for_sheet($sheetName);
    if (CSV_CACHE_SECONDS > 0 && file_exists($cachePath) && (time() - filemtime($cachePath) < CSV_CACHE_SECONDS)) {
        $csv = file_get_contents($cachePath);
    } else {
        $url = csv_url_for_sheet($sheetName);
        $csv = fetch_url($url);
        if (CSV_CACHE_SECONDS > 0) {
            @file_put_contents($cachePath, $csv);
        }
    }

    if (stripos($csv, '<html') !== false || stripos($csv, 'Sorry, unable to open') !== false) {
        throw new RuntimeException('Google returned an HTML/error page. Make sure the spreadsheet is shared so anyone with the link can view it.');
    }

    return parse_csv_string($csv);
}

function parse_csv_string(string $csv): array
{
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);

    $rows = [];
    while (($row = fgetcsv($fp)) !== false) {
        if ($row === [null] || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $rows[] = array_map(fn($v) => trim((string)$v), $row);
    }
    fclose($fp);

    if (!$rows) {
        return ['headers' => [], 'rows' => [], 'raw' => []];
    }

    // Some tabs have an instruction/note row before the real header row.
    // Detect the real header row instead of blindly using row 1.
    $headerIndex = 0;
    foreach ($rows as $i => $candidate) {
        $keys = array_map('norm_key', $candidate);
        $hasEmployeeSetupHeaders = in_array('name', $keys, true) && in_array('userid', $keys, true) && in_array('password', $keys, true);
        $hasTimesheetHeaders = in_array('username', $keys, true) && in_array('store_name', $candidate, true);
        $hasTimesheetHeaders = $hasTimesheetHeaders || (in_array('username', $keys, true) && in_array('logtype', $keys, true));
        if ($hasEmployeeSetupHeaders || $hasTimesheetHeaders || in_array('payrate', $keys, true)) {
            $headerIndex = $i;
            break;
        }
    }

    $headers = $rows[$headerIndex];
    $dataRows = array_slice($rows, $headerIndex + 1);

    $assoc = [];
    foreach ($dataRows as $row) {
        $item = [];
        foreach ($headers as $i => $header) {
            $header = trim((string)$header);
            if ($header === '') continue;
            $item[$header] = $row[$i] ?? '';
        }
        $item['_raw'] = $row;
        $assoc[] = $item;
    }

    return ['headers' => $headers, 'rows' => $assoc, 'raw' => $dataRows];
}

function norm_key(string $s): string
{
    $s = strtolower(trim($s));
    return preg_replace('/[^a-z0-9]+/', '', $s);
}

function get_field(array $row, array $names, int $fallbackIndex = -1): string
{
    $normalized = [];
    foreach ($row as $k => $v) {
        if ($k === '_raw') continue;
        $normalized[norm_key((string)$k)] = $v;
    }
    foreach ($names as $name) {
        $key = norm_key($name);
        if (array_key_exists($key, $normalized)) {
            return trim((string)$normalized[$key]);
        }
    }
    if ($fallbackIndex >= 0 && isset($row['_raw'][$fallbackIndex])) {
        return trim((string)$row['_raw'][$fallbackIndex]);
    }
    return '';
}

function read_all_source_data(): array
{
    return [
        'timesheet' => read_sheet_csv(SHEET_TIME_SHEET)['rows'],
        'payrate' => read_sheet_csv(SHEET_PAY_RATE)['rows'],
        'start_time' => read_sheet_csv(SHEET_START_TIME)['rows'],
        'employee_setup' => read_sheet_csv(SHEET_EMPLOYEE_SETUP)['rows'],
    ];
}
