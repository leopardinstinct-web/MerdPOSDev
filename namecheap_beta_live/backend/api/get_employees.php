<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';

$requestId = merd_request_id();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    merd_request_require_method($_SERVER, 'GET');
    $auth = merd_device_authenticate_request($pdo, $_SERVER, [], $_GET);
    $stmt = $pdo->prepare(
        'SELECT id, client_id, store_id, full_name, user_id, employee_type, role_name, hourly_rate, status '
        . "FROM employees WHERE client_id = ? AND status = 'active' ORDER BY full_name"
    );
    $stmt->execute([$auth['client_id']]);
    merd_api_send(merd_api_success([
        'api' => 'get_employees.php',
        'version' => 'client-wide-employees-v4-secure-device',
        'scope' => [
            'client_id' => $auth['client_id'],
            'authorized_device_store_id' => $auth['store_id'],
            'employee_store_filter' => null,
        ],
        'employees' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('get_employees request failed');
    merd_api_fail('internal_error', 'Could not load employees.', 500, $requestId);
}
