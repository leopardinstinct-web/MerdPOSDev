<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sheets.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

$user = require_login();
$weekStart = $_GET['week_start'] ?? monday_of_week();
$weekStart = monday_of_week($weekStart);

try {
    $source = read_all_source_data();
    $employeeFilter = $user['is_super'] ? null : $user['name'];
    $report = build_report($source, $weekStart, $employeeFilter, (bool)$user['is_super']);
    json_response(['success' => true, 'report' => $report]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
