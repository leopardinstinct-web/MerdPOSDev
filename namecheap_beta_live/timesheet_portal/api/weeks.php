<?php
require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

$user = beta_require_active_user();
$clientId = (int)$user['client_id'];

try {
    $pdo = portal_db();
    $employeeFilter = $user['is_super'] ? null : $user['name'];
    $params = [$clientId];
    $sql = 'SELECT user_name, log_type, log_date FROM employee_logs WHERE client_id=?';
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
        'client_id' => $clientId,
        'current_week' => monday_of_week(),
        'weeks' => $out,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => 'The available weeks could not be loaded.'], 500);
}
