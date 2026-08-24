<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';
require_once __DIR__ . '/includes/security_log.php';

$requestId = merd_request_id();
$log = new MerdPdoSecurityLogStore($pdo);

try {
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $data = merd_request_json(file_get_contents('php://input'));
    $clientId = merd_request_positive_int($data['client_id'] ?? null, 'client_id');
    $storeId = merd_request_positive_int($data['store_id'] ?? null, 'store_id');
    $grant = merd_request_text($data['activation_grant'] ?? null, 'activation_grant', 512);
    $deviceUuid = merd_request_text($data['device_uuid'] ?? null, 'device_uuid', 150);
    $deviceName = merd_request_text($data['device_name'] ?? 'Android POS', 'device_name', 150);

    $activated = merd_activate_device(
        new MerdPdoActivationGrantStore($pdo),
        new MerdPdoDeviceActivationStore($pdo),
        $clientId,
        $storeId,
        $grant,
        $deviceUuid,
        $deviceName
    );
    merd_security_log_event(
        $log,
        $_SERVER,
        'device_activation',
        'success',
        ['client_id' => $clientId, 'device_id' => $activated['device_id'], 'request_id' => $requestId],
        ['endpoint' => 'activate_device.php', 'store_id' => $storeId]
    );
    merd_api_send(merd_api_success([
        'api' => 'activate_device.php',
        'version' => 'secure-activation-v2',
        'activation_token' => $activated['token'],
        'token_type' => 'Bearer',
        'expires_at' => $activated['expires_at']->format(DateTimeInterface::ATOM),
    ]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdActivationDenied $e) {
    merd_api_fail('activation_denied', 'Device activation failed.', 401, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('activate_device request failed');
    merd_api_fail('internal_error', 'Service temporarily unavailable.', 500, $requestId);
}
