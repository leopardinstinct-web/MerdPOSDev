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
    $shifts = merd_request_list($data['shifts'] ?? [], 1000);
    $employee = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND client_id = ? AND status = 'active' LIMIT 1");
    $insert = $pdo->prepare(
        'INSERT INTO shifts (client_id, store_id, employee_id, device_uuid, local_shift_id, clock_in, '
        . 'clock_out, break_minutes, total_minutes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE clock_out = VALUES(clock_out), break_minutes = VALUES(break_minutes), '
        . 'total_minutes = VALUES(total_minutes), status = VALUES(status), synced_at = CURRENT_TIMESTAMP'
    );
    $pdo->beginTransaction();
    $synced = [];
    foreach ($shifts as $shift) {
        if (!is_array($shift) || array_is_list($shift)) { throw new MerdRequestException('invalid_request', 400, 'Invalid request.'); }
        $employeeId = merd_request_positive_int($shift['employee_id'] ?? null, 'employee_id');
        $employee->execute([$employeeId, $auth['client_id']]);
        if (!$employee->fetchColumn()) { throw new MerdRequestException('invalid_request', 400, 'Invalid request.'); }
        $status = merd_request_text($shift['status'] ?? 'open', 'status', 6);
        if (!in_array($status, ['open', 'closed'], true)) { throw new MerdRequestException('invalid_request', 400, 'Invalid request.'); }
        $localId = merd_request_text($shift['local_shift_id'] ?? null, 'local_shift_id', 150);
        $clockIn = merd_request_datetime($shift['clock_in'] ?? null);
        if ($clockIn === null) { throw new MerdRequestException('invalid_request', 400, 'Invalid request.'); }
        $insert->execute([
            $auth['client_id'], $auth['store_id'], $employeeId, $auth['device_uuid'], $localId,
            $clockIn, merd_request_datetime($shift['clock_out'] ?? null),
            merd_request_nonnegative_int($shift['break_minutes'] ?? 0),
            merd_request_nonnegative_int($shift['total_minutes'] ?? 0), $status,
        ]);
        $synced[] = $localId;
    }
    merd_device_touch_last_sync($pdo, $auth);
    $pdo->commit();
    merd_api_send(merd_api_success(['api' => 'sync_shifts.php', 'version' => 'shift-sync-v2-secure-device', 'synced_shift_ids' => $synced]));
} catch (MerdRequestException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('sync_shifts request failed');
    merd_api_fail('internal_error', 'Shift sync failed.', 500, $requestId);
}
