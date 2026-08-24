<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';
require_once __DIR__ . '/includes/workforce_beta.php';

$requestId = merd_request_id();
try {
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $data = merd_request_json(file_get_contents('php://input'));
    $auth = merd_device_authenticate_request($pdo, $_SERVER, $data);
    $previousId = merd_request_positive_int($data['previous_employee_id'] ?? null, 'previous_employee_id');
    $replacementId = merd_request_positive_int($data['replacement_employee_id'] ?? null, 'replacement_employee_id');
    if ($previousId === $replacementId) throw new MerdRequestException('invalid_request', 400, 'Choose a different user.');
    $employee = $pdo->prepare("SELECT id,client_id,full_name FROM employees WHERE id=? AND client_id=? AND status='active' LIMIT 1");
    $employee->execute([$previousId, (int)$auth['client_id']]);
    $previous = $employee->fetch(PDO::FETCH_ASSOC);
    $employee->execute([$replacementId, (int)$auth['client_id']]);
    if (!is_array($previous) || !$employee->fetchColumn()) throw new MerdRequestException('invalid_request', 400, 'Employee not found.');
    $shift = $pdo->prepare("SELECT public_id FROM attendance_shifts WHERE client_id=? AND employee_id=? AND store_id=? AND status='open' LIMIT 1");
    $shift->execute([(int)$auth['client_id'], $previousId, (int)$auth['store_id']]);
    $shiftId = $shift->fetchColumn();
    $result = is_string($shiftId) && $shiftId !== ''
        ? merd_create_dispute($pdo, $previous, $shiftId, 'missing_out', null, gmdate('Y-m-d H:i:s'), null, 'POS handover: previous user was reported as having forgotten to log out.', 'awaiting_employee', 'pos_handover')
        : ['status' => 'no_open_attendance_shift', 'duplicate' => false];
    merd_device_touch_last_sync($pdo, $auth);
    merd_api_send(merd_api_success(['handover' => $result]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdWorkforceException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), 409, $requestId);
} catch (Throwable $e) {
    error_log('report_pos_handover failed');
    merd_api_fail('internal_error', 'Could not report POS handover.', 500, $requestId);
}
