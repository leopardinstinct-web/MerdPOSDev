<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';

$requestId = merd_request_id();
try {
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $data = merd_request_json(file_get_contents('php://input'));
    $auth = merd_device_authenticate_request($pdo, $_SERVER, $data);
    $storeName = merd_request_text($data['store_name'] ?? '', 'store_name', 150, true);
    $logs = merd_request_list($data['logs'] ?? [], 1000);
    $employee = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND client_id = ? AND status = 'active' LIMIT 1");
    $insert = $pdo->prepare(
        'INSERT INTO employee_logs (client_id, store_id, employee_id, user_name, store_name, log_type, '
        . 'log_date, log_time, log_datetime, device_uuid, local_log_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id), user_name = VALUES(user_name), '
        . 'store_name = VALUES(store_name), log_type = VALUES(log_type), log_date = VALUES(log_date), '
        . 'log_time = VALUES(log_time), log_datetime = VALUES(log_datetime), synced_at = CURRENT_TIMESTAMP'
    );
    $pdo->beginTransaction();
    $synced = [];
    foreach ($logs as $log) {
        if (!is_array($log) || array_is_list($log)) { throw new MerdRequestException('invalid_request', 400, 'Invalid request.'); }
        $employeeId = merd_request_positive_int($log['employee_id'] ?? null, 'employee_id');
        $employee->execute([$employeeId, $auth['client_id']]);
        if (!$employee->fetchColumn()) { throw new MerdRequestException('invalid_request', 400, 'Invalid request.'); }
        $logType = strtoupper(merd_request_text($log['log_type'] ?? null, 'log_type', 3));
        if (!in_array($logType, ['IN', 'OUT'], true)) { throw new MerdRequestException('invalid_request', 400, 'Invalid request.'); }
        $dateTime = merd_request_datetime($log['log_datetime'] ?? null);
        $date = substr((string)$dateTime, 0, 10);
        $time = substr((string)$dateTime, 11, 8);
        $localId = merd_request_text($log['local_log_id'] ?? null, 'local_log_id', 150);
        $insert->execute([
            $auth['client_id'], $auth['store_id'], $employeeId,
            merd_request_text($log['user_name'] ?? null, 'user_name', 150),
            $storeName !== '' ? $storeName : merd_request_text($log['store_name'] ?? '', 'store_name', 150, true),
            $logType, $date, $time, $dateTime, $auth['device_uuid'], $localId,
        ]);
        $synced[] = $localId;
    }
    merd_device_touch_last_sync($pdo, $auth);
    $pdo->commit();
    merd_api_send(merd_api_success(['api' => 'sync_employee_logs.php', 'version' => 'employee-log-sync-v2-secure-device', 'synced_log_ids' => $synced, 'count' => count($synced)]));
} catch (MerdRequestException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('sync_employee_logs request failed');
    merd_api_fail('internal_error', 'Employee log sync failed.', 500, $requestId);
}
