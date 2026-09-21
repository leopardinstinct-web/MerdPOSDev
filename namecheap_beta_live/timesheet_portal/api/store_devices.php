<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

function merd_store_device_public_key(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (!defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
        throw new MerdWorkforceException('crypto_unavailable', 'POS key registration is temporarily unavailable.');
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new MerdWorkforceException('invalid_public_key', 'Enter a valid Ed25519 public key.');
    }
    return $value;
}

function merd_next_device_code(PDO $pdo, int $clientId): string {
    $stmt = $pdo->prepare("SELECT device_code FROM devices WHERE client_id=? AND device_code REGEXP '^[0-9]{4}$' FOR UPDATE");
    $stmt->execute([$clientId]);
    $used = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) $used[(string)$code] = true;
    for ($i = 1; $i <= 9999; $i++) {
        $code = str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        if (!isset($used[$code])) return $code;
    }
    throw new MerdWorkforceException('device_limit', 'This client has no available four-digit POS IDs.');
}
function merd_store_device_rows(PDO $pdo, int $clientId): array {
    $stmt = $pdo->prepare(
        "SELECT d.id,d.store_id,s.store_name,d.device_code,d.device_name,d.device_uuid,d.status,d.created_at," .
        "k.public_key_b64,k.status AS key_status,k.key_version,k.registered_at " .
        "FROM devices d INNER JOIN stores s ON s.id=d.store_id AND s.client_id=d.client_id " .
        "LEFT JOIN attendance_device_keys k ON k.device_id=d.id " .
        "WHERE d.client_id=? ORDER BY s.store_name,d.device_code,d.id"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    beta_require_permission($user, 'stores.devices.manage', $pdo);
    $clientId = (int)$user['client_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(['success'=>true,'devices'=>merd_store_device_rows($pdo,$clientId)]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['success'=>false,'error'=>'GET or POST required.'],405);
    }

    $input = request_input();
    require_csrf($input);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, ['add_device','save_device'], true)) {
        throw new MerdWorkforceException('invalid_action', 'Unsupported POS device action.');
    }
    $name = trim((string)($input['device_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        throw new MerdWorkforceException('invalid_device_name', 'Enter a POS device name up to 150 characters.');
    }
    $publicKey = merd_store_device_public_key($input['public_key_b64'] ?? null);
    $status = strtolower(trim((string)($input['status'] ?? 'active')));
    if (!in_array($status, ['active','inactive'], true)) {
        throw new MerdWorkforceException('invalid_device_status', 'Choose Active or Inactive.');
    }

    $pdo->beginTransaction();
    try {
        if ($action === 'add_device') {
            $storeId = filter_var($input['store_id'] ?? null, FILTER_VALIDATE_INT);
            if ($storeId === false || $storeId < 1) throw new MerdWorkforceException('invalid_store', 'Choose a valid store.');
            $store = $pdo->prepare("SELECT id FROM stores WHERE id=? AND client_id=? AND status='active' LIMIT 1 FOR UPDATE");
            $store->execute([(int)$storeId,$clientId]);
            if (!$store->fetchColumn()) throw new MerdWorkforceException('store_not_found', 'Store not found or inactive.');

            $code = merd_next_device_code($pdo,$clientId);
            $deviceUuid = 'admin-' . merd_uuid_v4();
            $insert = $pdo->prepare(
                "INSERT INTO devices (client_id,store_id,device_code,device_uuid,device_name,activation_token,status) " .
                "VALUES (?,?,?,?,?,NULL,?)"
            );
            $insert->execute([$clientId,(int)$storeId,$code,$deviceUuid,$name,$status]);
            $deviceId = (int)$pdo->lastInsertId();
        } else {
            $deviceId = filter_var($input['device_id'] ?? null, FILTER_VALIDATE_INT);
            if ($deviceId === false || $deviceId < 1) throw new MerdWorkforceException('invalid_device', 'Choose a valid POS device.');
            $lock = $pdo->prepare("SELECT id,store_id,device_code FROM devices WHERE id=? AND client_id=? LIMIT 1 FOR UPDATE");
            $lock->execute([(int)$deviceId,$clientId]);
            $existing = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) throw new MerdWorkforceException('device_not_found', 'POS device not found.');
            $pdo->prepare("UPDATE devices SET device_name=?,status=? WHERE id=? AND client_id=?")
                ->execute([$name,$status,(int)$deviceId,$clientId]);
            $storeId = (int)$existing['store_id'];
            $code = (string)$existing['device_code'];
        }
        if ($publicKey !== null) {
            $keyStatus = $status === 'active' ? 'active' : 'revoked';
            $revoked = $status === 'active' ? null : gmdate('Y-m-d H:i:s');
            $key = $pdo->prepare(
                "INSERT INTO attendance_device_keys (device_id,client_id,store_id,public_key_b64,key_version,status,revoked_at) " .
                "VALUES (?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE public_key_b64=VALUES(public_key_b64)," .
                "key_version=key_version+1,status=VALUES(status),registered_at=UTC_TIMESTAMP(),revoked_at=VALUES(revoked_at)"
            );
            $key->execute([(int)$deviceId,$clientId,(int)$storeId,$publicKey,$keyStatus,$revoked]);
        } elseif ($status === 'inactive') {
            $pdo->prepare("UPDATE attendance_device_keys SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE device_id=?")
                ->execute([(int)$deviceId]);
        }

        try {
            beta_admin_audit($pdo,$user,$action,'device',(string)$deviceId,[
                'store_id'=>(int)$storeId,'device_code'=>$code,'device_name'=>$name,'status'=>$status,
                'public_key_updated'=>$publicKey !== null,
            ]);
        } catch (Throwable) {}
        $pdo->commit();
        json_response([
            'success'=>true,'device_id'=>(int)$deviceId,'device_code'=>$code,
            'message'=>$action === 'add_device' ? "POS {$code} added." : "POS {$code} saved.",
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) { beta_api_error($e); }
