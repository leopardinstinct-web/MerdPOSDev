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

    $tzStmt = $pdo->prepare('SELECT default_timezone FROM clients WHERE id=? LIMIT 1');
    $tzStmt->execute([$clientId]);
    $timezoneName = trim((string)($tzStmt->fetchColumn() ?: APP_TIMEZONE));
    try { $timezone = new DateTimeZone($timezoneName); }
    catch (Throwable) { $timezone = new DateTimeZone(APP_TIMEZONE); $timezoneName = APP_TIMEZONE; }

    $params = [$clientId];
    $sql = "SELECT log_date FROM employee_logs WHERE client_id=? AND UPPER(log_type)='IN'";
    if (!$canViewAll) {
        $sql .= ' AND (employee_id=? OR (COALESCE(employee_id,0)=0 AND LOWER(user_name)=LOWER(?)))';
        $params[] = (int)$user['id'];
        $params[] = (string)$user['name'];
    }
    $sql .= ' ORDER BY log_date DESC, id DESC LIMIT 10000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $weeks = [];
    $current = (new DateTimeImmutable('now', $timezone))->modify('monday this week')->format('Y-m-d');
    $weeks[$current] = week_label($current);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dt = parse_date_value((string)($row['log_date'] ?? ''));
        if (!$dt) continue;
        $monday = $dt->modify('monday this week')->format('Y-m-d');
        $weeks[$monday] = week_label($monday);
    }
    krsort($weeks);
    $out = [];
    foreach ($weeks as $value => $label) $out[] = ['value'=>$value,'label'=>$label];

    json_response([
        'success'=>true,
        'source'=>'sql_employee_logs',
        'client_id'=>$clientId,
        'scope'=>$canViewAll ? 'all_employees' : 'own',
        'timezone'=>$timezoneName,
        'current_week'=>$current,
        'weeks'=>$out,
    ]);
} catch (Throwable $e) {
    if ($e instanceof MerdWorkforceException) beta_api_error($e);
    error_log('MERDPOS weeks failure: ' . get_class($e));
    json_response(['success'=>false,'error'=>'The available weeks could not be loaded.'],500);
}
