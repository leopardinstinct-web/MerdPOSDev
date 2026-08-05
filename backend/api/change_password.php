<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';
require_once __DIR__ . '/includes/auth_lockout.php';
require_once __DIR__ . '/includes/employee_auth.php';
require_once __DIR__ . '/includes/security_log.php';

$requestId = merd_request_id();
$log = new MerdPdoSecurityLogStore($pdo);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $data = merd_request_json(file_get_contents('php://input'));
    $clientId = merd_request_positive_int($data['client_id'] ?? null, 'client_id');
    $storeId = merd_request_positive_int($data['store_id'] ?? null, 'store_id');
    $deviceUuid = merd_request_text($data['device_uuid'] ?? null, 'device_uuid', 150);
    $employeeId = merd_request_positive_int($data['employee_id'] ?? null, 'employee_id');
    $oldPassword = merd_request_numeric_string($data['old_password'] ?? null, 1);
    $newPassword = merd_request_numeric_string($data['new_password'] ?? null, 4);
    $credential = merd_device_auth_extract_token($_SERVER, $data);
    $device = merd_device_authorize(
        new MerdPdoDeviceStore($pdo),
        $clientId,
        $storeId,
        $deviceUuid,
        $credential['token']
    );
    if ($device === null) {
        merd_api_fail('device_unauthorized', 'Device authorization failed.', 401, $requestId);
    }
    $stmt = $pdo->prepare(
        'SELECT id, full_name, user_id, login_password, pin_code FROM employees '
        . "WHERE client_id = ? AND id = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$clientId, $employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($employee)) {
        merd_api_fail('password_change_failed', 'Password change failed.', 404, $requestId);
    }
    $userId = (string)$employee['user_id'];
    $lockout = new MerdAuthLockoutService(new MerdPdoAuthLockoutStore($pdo));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lockout->assertNotLocked($clientId, $userId, $deviceUuid, 'change_password', $now);
    if (!merd_employee_authenticates($employee, $oldPassword)) {
        $lockout->recordFailure($clientId, $employeeId, $userId, $deviceUuid, 'change_password', $now);
        merd_security_log_event(
            $log,
            $_SERVER,
            'employee_password_change',
            'denied',
            [
                'client_id' => $clientId,
                'employee_id' => $employeeId,
                'device_id' => (int)$device['id'],
                'request_id' => $requestId,
            ],
            ['endpoint' => 'change_password.php', 'action' => 'change_password', 'reason_code' => 'invalid_credentials']
        );
        merd_api_fail('invalid_credentials', 'Current password is incorrect.', 403, $requestId);
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE employees SET login_password = ?, pin_code = ? '
            . 'WHERE client_id = ? AND id = ? LIMIT 1'
        );
        $update->execute([$hash, $hash, $clientId, $employeeId]);
        $lockout->recordSuccess($clientId, $employeeId, $userId, $deviceUuid, 'change_password', $now);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    merd_security_log_event(
        $log,
        $_SERVER,
        'employee_password_change',
        'success',
        [
            'client_id' => $clientId,
            'employee_id' => $employeeId,
            'device_id' => (int)$device['id'],
            'actor_type' => 'employee',
            'actor_id' => $userId,
            'request_id' => $requestId,
        ],
        ['endpoint' => 'change_password.php', 'action' => 'change_password', 'transport' => $credential['transport']]
    );
    merd_api_send(merd_api_success([
        'api' => 'change_password.php',
        'version' => 'secure-password-change-v3',
        'password_storage' => 'password_hash',
        'message' => 'Password changed successfully.',
        'employee_id' => (string)$employeeId,
        'employee_name' => $employee['full_name'] ?? '',
    ]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdAuthLocked $e) {
    merd_api_fail('authentication_locked', 'Too many attempts. Try again later.', 429, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('change_password request failed');
    merd_api_fail('internal_error', 'Password change failed.', 500, $requestId);
}
