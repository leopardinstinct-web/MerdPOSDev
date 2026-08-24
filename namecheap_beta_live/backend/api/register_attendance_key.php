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
    $clientId = merd_request_positive_int($data['client_id'] ?? null, 'client_id');
    $storeId = merd_request_positive_int($data['store_id'] ?? null, 'store_id');
    $deviceUuid = merd_request_text($data['device_uuid'] ?? null, 'device_uuid', 150);
    $publicKeyB64 = merd_request_text($data['public_key_b64'] ?? null, 'public_key_b64', 64);
    if (!defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
        merd_api_fail('crypto_unavailable', 'Attendance key registration is unavailable.', 503, $requestId);
    }
    $publicKey = base64_decode($publicKeyB64, true);
    if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid attendance public key.');
    }
    $credential = merd_device_auth_extract_token($_SERVER, $data);
    $device = merd_device_authorize(new MerdPdoDeviceStore($pdo), $clientId, $storeId, $deviceUuid, $credential['token']);
    if ($device === null) merd_api_fail('device_unauthorized', 'Device authorization failed.', 401, $requestId);
    $stmt = $pdo->prepare(
        "INSERT INTO attendance_device_keys (device_id,client_id,store_id,public_key_b64,key_version,status) "
        . "VALUES (?,?,?,?,1,'active') ON DUPLICATE KEY UPDATE public_key_b64=VALUES(public_key_b64),"
        . "key_version=key_version+1,status='active',registered_at=UTC_TIMESTAMP(),revoked_at=NULL"
    );
    $stmt->execute([(int)$device['id'], $clientId, $storeId, $publicKeyB64]);
    merd_api_send(merd_api_success(['api' => 'register_attendance_key.php', 'key_registered' => true]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (Throwable $e) {
    error_log('attendance key registration failed');
    merd_api_fail('internal_error', 'Could not register attendance key.', 500, $requestId);
}
