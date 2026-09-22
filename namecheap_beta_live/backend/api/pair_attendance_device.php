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
    $grant = merd_request_text($data['activation_grant'] ?? null, 'activation_grant', 512);
    $deviceCode = merd_request_text($data['device_code'] ?? null, 'device_code', 4);
    $publicKeyB64 = merd_request_text($data['public_key_b64'] ?? null, 'public_key_b64', 64);

    if (!preg_match('/^[0-9]{4}$/', $deviceCode)) {
        throw new MerdRequestException('invalid_device_code', 400, 'Device code must be exactly four digits.');
    }
    if (!defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
        merd_api_fail('crypto_unavailable', 'POS pairing is temporarily unavailable.', 503, $requestId);
    }
    $publicKey = base64_decode($publicKeyB64, true);
    if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new MerdRequestException('invalid_public_key', 400, 'Invalid Ed25519 public key.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pdo->beginTransaction();
    try {
        $deviceStmt = $pdo->prepare(
            "SELECT d.id,d.client_id,d.store_id,d.device_code,d.device_name,d.status,d.revoked_at,d.token_hash,"
            . "s.store_name,s.store_code,s.status AS store_status "
            . "FROM devices d INNER JOIN stores s ON s.id=d.store_id AND s.client_id=d.client_id "
            . "WHERE d.client_id=? AND d.device_code=? LIMIT 1 FOR UPDATE"
        );
        $deviceStmt->execute([$clientId, $deviceCode]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($device)
            || ($device['status'] ?? '') !== 'active'
            || ($device['store_status'] ?? '') !== 'active'
            || !empty($device['revoked_at'])) {
            throw new MerdRequestException('pairing_device_unavailable', 404, 'The selected POS is not available for pairing.');
        }

        $keyStmt = $pdo->prepare(
            "SELECT public_key_b64,status FROM attendance_device_keys WHERE device_id=? LIMIT 1 FOR UPDATE"
        );
        $keyStmt->execute([(int)$device['id']]);
        $existingKey = $keyStmt->fetch(PDO::FETCH_ASSOC);
        $hasActiveKey = is_array($existingKey)
            && strtolower(trim((string)($existingKey['status'] ?? ''))) === 'active';
        if (trim((string)($device['token_hash'] ?? '')) !== '' || $hasActiveKey) {
            throw new MerdRequestException('device_already_paired', 409, 'The selected POS has already been paired.');
        }

        if (!merd_activation_grant_consume(new MerdPdoActivationGrantStore($pdo), $clientId, $grant, $now)) {
            throw new MerdRequestException('pairing_grant_invalid', 401, 'The pairing session has expired or has already been used.');
        }

        $deviceUuid = 'attendance-' . substr(hash('sha256', $publicKey), 0, 32);
        $uuidStmt = $pdo->prepare("SELECT id FROM devices WHERE device_uuid=? AND id<>? LIMIT 1 FOR UPDATE");
        $uuidStmt->execute([$deviceUuid, (int)$device['id']]);
        if ($uuidStmt->fetchColumn()) {
            throw new MerdRequestException('pairing_key_in_use', 409, 'This POS signing key is already paired to another device.');
        }

        $token = merd_device_token_generate();
        $expiresAt = $now->modify('+180 days');
        $timestamp = $now->format('Y-m-d H:i:s');
        $update = $pdo->prepare(
            "UPDATE devices SET device_uuid=?,token_hash=?,token_expires_at=?,previous_token_hash=NULL,"
            . "previous_token_valid_until=NULL,token_rotated_at=?,activated_at=COALESCE(activated_at,?),"
            . "revoked_at=NULL,status='active' WHERE id=? AND client_id=?"
        );
        $update->execute([
            $deviceUuid,
            merd_device_token_hash($token),
            $expiresAt->format('Y-m-d H:i:s'),
            $timestamp,
            $timestamp,
            (int)$device['id'],
            $clientId,
        ]);

        $keyWrite = $pdo->prepare(
            "INSERT INTO attendance_device_keys "
            . "(device_id,client_id,store_id,public_key_b64,key_version,status,revoked_at) "
            . "VALUES (?,?,?,?,1,'active',NULL) "
            . "ON DUPLICATE KEY UPDATE public_key_b64=VALUES(public_key_b64),"
            . "key_version=key_version+1,status='active',registered_at=UTC_TIMESTAMP(),revoked_at=NULL"
        );
        $keyWrite->execute([
            (int)$device['id'],
            $clientId,
            (int)$device['store_id'],
            $publicKeyB64,
        ]);

        $pdo->commit();

        merd_security_log_event(
            $log,
            $_SERVER,
            'attendance_device_pairing',
            'success',
            ['client_id' => $clientId, 'device_id' => (int)$device['id'], 'request_id' => $requestId],
            ['endpoint' => 'pair_attendance_device.php', 'store_id' => (int)$device['store_id'], 'device_code' => $deviceCode]
        );

        merd_api_send(merd_api_success([
            'api' => 'pair_attendance_device.php',
            'version' => 'attendance-pairing-v1',
            'paired' => true,
            'device' => [
                'device_code' => $deviceCode,
                'device_name' => (string)$device['device_name'],
                'device_uuid' => $deviceUuid,
                'store_id' => (int)$device['store_id'],
                'store_name' => (string)$device['store_name'],
                'store_code' => (string)$device['store_code'],
            ],
            'activation_token' => $token,
            'token_type' => 'Bearer',
            'token_expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
        ]));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (MerdRequestException $e) {
    try {
        merd_security_log_event(
            $log,
            $_SERVER,
            'attendance_device_pairing',
            'denied',
            ['client_id' => isset($clientId) ? $clientId : null, 'request_id' => $requestId],
            ['endpoint' => 'pair_attendance_device.php', 'reason_code' => $e->errorCode]
        );
    } catch (Throwable) {}
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'POS pairing is temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('attendance device pairing failed');
    merd_api_fail('internal_error', 'Could not pair this POS device.', 500, $requestId);
}
