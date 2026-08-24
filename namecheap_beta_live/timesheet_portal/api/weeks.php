<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

$user = require_login();

try {
    $pdo = portal_db();
    $employeeFilter = $user['is_super'] ? null : $user['name'];
    $params = [];
    $sql = 'SELECT user_name, log_type, log_date FROM employee_logs WHERE client_id=1';
    if ($employeeFilter !== null) {
        $sql .= ' AND LOWER(user_name)=LOWER(?)';
        $params[] = $employeeFilter;
    }
    $sql .= ' ORDER BY log_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $weeks = [];
    $current = monday_of_week();
    $weeks[$current] = week_label($current);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (strtoupper((string)$row['log_type']) !== 'IN') continue;
        $dt = parse_date_value((string)$row['log_date']);
        if (!$dt) continue;
        $monday = $dt->modify('monday this week')->format('Y-m-d');
        $weeks[$monday] = week_label($monday);
    }
    krsort($weeks);
    $out = [];
    foreach ($weeks as $value => $label) {
        $out[] = ['value' => $value, 'label' => $label];
    }
    json_response([
        'success' => true,
        'source' => 'sql_employee_logs',
        'current_week' => monday_of_week(),
        'weeks' => $out,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
