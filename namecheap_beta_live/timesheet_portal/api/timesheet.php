<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

$user = require_login();
$weekStart = $_GET['week_start'] ?? monday_of_week();
$weekStart = monday_of_week($weekStart);

function sql_source_data(PDO $pdo): array
{
    $timesheetRows = [];
    $stmt = $pdo->query(
        'SELECT user_name, store_name, log_type, log_date, log_time '
        . 'FROM employee_logs WHERE client_id=1 ORDER BY log_datetime, id'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $timesheetRows[] = [
            'USER_NAME' => (string)$row['user_name'],
            'STORE_NAME' => (string)$row['store_name'],
            'LOG_TYPE' => (string)$row['log_type'],
            'DATE' => (string)$row['log_date'],
            'TIME' => (string)$row['log_time'],
            '_raw' => [
                (string)$row['user_name'],
                (string)$row['store_name'],
                (string)$row['log_type'],
                (string)$row['log_date'],
                (string)$row['log_time'],
            ],
        ];
    }

    $payrateRows = [];
    $employeeSetupRows = [];
    $stmt = $pdo->query(
        'SELECT full_name,user_id,employee_type,status,store_id,hourly_rate FROM employees WHERE client_id=1 ORDER BY full_name'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rate = is_numeric($row['hourly_rate']) ? (string)(float)$row['hourly_rate'] : '';
        $payrateRows[] = [
            'NAME' => (string)$row['full_name'],
            'PAY_RATE' => $rate,
            '_raw' => [(string)$row['full_name'], $rate],
        ];
        $employeeSetupRows[] = [
            'NAME' => (string)$row['full_name'],
            'TYPE' => (string)$row['employee_type'],
            'USER_ID' => (string)$row['user_id'],
            'STATUS' => strtoupper((string)$row['status']),
            'PAY_RATE' => $rate,
            '_raw' => [(string)$row['full_name'], (string)$row['employee_type'], (string)$row['user_id'], '', strtoupper((string)$row['status']), '', $rate],
        ];
    }

    $startRows = [];
    try {
        $stmt = $pdo->query(
            'SELECT store_name, shift_start_time FROM store_shift_start_times WHERE client_id=1 ORDER BY store_name'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $startRows[] = [
                'Store Name' => (string)$row['store_name'],
                'Shift Start Time' => (string)$row['shift_start_time'],
                '_raw' => [(string)$row['store_name'], (string)$row['shift_start_time']],
            ];
        }
    } catch (Throwable $e) {
        $fallback = [
            ['Rosebay Tobacco', '06:00:00'],
            ['Enmore Tobacco', '07:00:00'],
            ['Marrickville Xpress', '07:00:00'],
            ['Double Bay Tobacco', '07:00:00'],
        ];
        foreach ($fallback as $row) {
            $startRows[] = ['Store Name' => $row[0], 'Shift Start Time' => $row[1], '_raw' => $row];
        }
    }

    return [
        'timesheet' => $timesheetRows,
        'payrate' => $payrateRows,
        'start_time' => $startRows,
        'employee_setup' => $employeeSetupRows,
    ];
}

try {
    $pdo = portal_db();
    $source = sql_source_data($pdo);
    $employeeFilter = $user['is_super'] ? null : $user['name'];
    $report = build_report($source, $weekStart, $employeeFilter, (bool)$user['is_super']);
    $report['source'] = 'sql_employee_logs';
    json_response(['success' => true, 'report' => $report]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
