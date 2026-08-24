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
    $userId = merd_request_numeric_string($data['user_id'] ?? null, 1);
    $password = merd_request_numeric_string($data['password'] ?? null, 4);
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

    $lockout = new MerdAuthLockoutService(new MerdPdoAuthLockoutStore($pdo));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lockout->assertNotLocked($clientId, $userId, $deviceUuid, 'login', $now);
    $stmt = $pdo->prepare(
        'SELECT id, client_id, store_id, full_name, user_id, login_password, pin_code, '
        . 'employee_type, role_name, hourly_rate, status FROM employees '
        . "WHERE client_id = ? AND user_id = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$clientId, $userId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($employee) || !merd_employee_authenticates($employee, $password)) {
        $lockout->recordFailure(
            $clientId,
            is_array($employee) ? (int)$employee['id'] : null,
            $userId,
            $deviceUuid,
            'login',
            $now
        );
        merd_security_log_event(
            $log,
            $_SERVER,
            'employee_login',
            'denied',
            ['client_id' => $clientId, 'device_id' => (int)$device['id'], 'request_id' => $requestId],
            ['endpoint' => 'login.php', 'action' => 'login', 'reason_code' => 'invalid_credentials']
        );
        merd_api_fail('invalid_credentials', 'Invalid login.', 401, $requestId);
    }

    $employeeId = (int)$employee['id'];
    $passwordUpgraded = merd_employee_needs_hash_upgrade($employee);
    $pdo->beginTransaction();
    try {
        if ($passwordUpgraded) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upgrade = $pdo->prepare(
                'UPDATE employees SET login_password = ?, pin_code = ? '
                . 'WHERE client_id = ? AND id = ? LIMIT 1'
            );
            $upgrade->execute([$hash, $hash, $clientId, $employeeId]);
        }
        $lockout->recordSuccess($clientId, $employeeId, $userId, $deviceUuid, 'login', $now);
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
        'employee_login',
        'success',
        [
            'client_id' => $clientId,
            'employee_id' => $employeeId,
            'device_id' => (int)$device['id'],
            'actor_type' => 'employee',
            'actor_id' => $userId,
            'request_id' => $requestId,
        ],
        ['endpoint' => 'login.php', 'action' => 'login', 'transport' => $credential['transport']]
    );
    merd_api_send(merd_api_success([
        'api' => 'login.php',
        'version' => 'secure-login-v2',
        'password_storage' => 'password_hash',
        'password_upgraded' => $passwordUpgraded,
        'employee' => [
            'id' => $employeeId,
            'client_id' => (int)$employee['client_id'],
            'store_id' => isset($employee['store_id']) ? (int)$employee['store_id'] : null,
            'full_name' => $employee['full_name'] ?? 'Employee',
            'user_id' => (string)$employee['user_id'],
            'employee_type' => $employee['employee_type'] ?? null,
            'role_name' => $employee['role_name'] ?? ($employee['employee_type'] ?? 'Staff'),
            'hourly_rate' => $employee['hourly_rate'] ?? '',
            'status' => $employee['status'] ?? 'active',
        ],
    ]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdAuthLocked $e) {
    merd_api_fail('authentication_locked', 'Too many attempts. Try again later.', 429, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('login request failed');
    merd_api_fail('internal_error', 'Login failed.', 500, $requestId);
}
