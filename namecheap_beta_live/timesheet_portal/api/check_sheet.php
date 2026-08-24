<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sheets.php';

// Diagnostic access requires an authenticated portal session.
require_login();

try {
    $setup = read_sheet_csv(SHEET_EMPLOYEE_SETUP);

    json_response([
        'success' => true,
        'message' => 'Sheet read OK',
        'sheet' => SHEET_EMPLOYEE_SETUP,
        'column_count' => count($setup['headers']),
        'row_count' => count($setup['rows']),
        'note' => 'This diagnostic does not show passwords.'
    ]);
} catch (Throwable $e) {
    error_log('check_sheet.php: ' . $e->getMessage());

    json_response([
        'success' => false,
        'error' => 'Unable to read the employee setup sheet.'
    ], 500);
}