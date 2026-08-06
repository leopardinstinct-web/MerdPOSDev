<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';
require_once __DIR__ . '/includes/catalogue_snapshot.php';

$requestId = merd_request_id();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    $body = merd_request_json(file_get_contents('php://input'));
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $auth = merd_device_authenticate_request($pdo, $_SERVER, $body);
    merd_catalogue_validate_request($body);
    merd_api_send(merd_catalogue_full_snapshot(
        $pdo,
        $auth,
        new DateTimeImmutable('now', new DateTimeZone('UTC'))
    ));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('sync_catalogue request failed');
    merd_api_fail('internal_error', 'Could not generate catalogue snapshot.', 500, $requestId);
}
