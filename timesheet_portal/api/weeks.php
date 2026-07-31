<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sheets.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

$user = require_login();
try {
    $timesheet = read_sheet_csv(SHEET_TIME_SHEET)['rows'];
    $employeeFilter = $user['is_super'] ? null : $user['name'];
    json_response([
        'success' => true,
        'current_week' => monday_of_week(),
        'weeks' => available_weeks($timesheet, $employeeFilter),
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
