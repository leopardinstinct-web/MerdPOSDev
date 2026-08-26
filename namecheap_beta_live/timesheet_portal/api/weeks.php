<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    beta_require_any_permission($user, ['timesheets.view_own','timesheets.view_all'], $pdo);
    $clientId = (int)$user['client_id'];
    $canViewAll = beta_has_permission($user, 'timesheets.view_all', $pdo);
    $employeeFilter = $canViewAll ? null : (string)$user['name'];

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
    foreach ($weeks as $value => $label) $out[] = ['value' => $value, 'label' => $label];

    json_response([
        'success' => true,
        'source' => 'sql_employee_logs',
        'client_id' => $clientId,
        'scope' => $canViewAll ? 'all_employees' : 'own',
        'current_week' => monday_of_week(),
        'weeks' => $out,
    ]);
} catch (Throwable $e) {
    if ($e instanceof MerdWorkforceException) beta_api_error($e);
    error_log('MERDPOS weeks failure: ' . get_class($e));
    json_response(['success' => false, 'error' => 'The available weeks could not be loaded.'], 500);
}
