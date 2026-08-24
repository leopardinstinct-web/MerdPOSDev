<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';
require_once __DIR__ . '/includes/workforce_beta.php';

$requestId = merd_request_id();
try {
    merd_request_require_method($_SERVER, 'GET');
    $auth = merd_device_authenticate_request($pdo, $_SERVER, [], $_GET);
    merd_api_send(merd_api_success(['people' => merd_working_now($pdo, (int)$auth['client_id']), 'server_time' => gmdate('c')]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (Throwable $e) {
    merd_api_fail('internal_error', 'Could not load attendance status.', 500, $requestId);
}
