<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/sheets.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    beta_require_permission($user, 'dev.status', $pdo);

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
    if ($e instanceof MerdWorkforceException) beta_api_error($e);
    error_log('check_sheet.php failure: ' . get_class($e));
    json_response([
        'success' => false,
        'error' => 'Unable to read the employee setup sheet.'
    ], 500);
}
