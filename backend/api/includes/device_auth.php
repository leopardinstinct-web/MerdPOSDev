<?php
declare(strict_types=1);

require_once __DIR__ . '/request.php';

final class MerdSecurityControlUnavailable extends RuntimeException
{
}

interface MerdActivationGrantStore
{
    public function insertGrantHash(int $clientId, string $grantHash, DateTimeImmutable $expiresAt): void;

    public function consumeGrantHash(int $clientId, string $grantHash, DateTimeImmutable $now): bool;
}

final class MerdPdoActivationGrantStore implements MerdActivationGrantStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertGrantHash(int $clientId, string $grantHash, DateTimeImmutable $expiresAt): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO activation_grants (client_id, grant_hash, expires_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$clientId, $grantHash, $expiresAt->format('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            throw new MerdSecurityControlUnavailable('Activation grant persistence unavailable.', 0, $e);
        }
    }

    public function consumeGrantHash(int $clientId, string $grantHash, DateTimeImmutable $now): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE activation_grants SET consumed_at = ? '
                . 'WHERE client_id = ? AND grant_hash = ? AND consumed_at IS NULL AND expires_at > ?'
            );
            $timestamp = $now->format('Y-m-d H:i:s');
            $stmt->execute([$timestamp, $clientId, $grantHash, $timestamp]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            throw new MerdSecurityControlUnavailable('Activation grant persistence unavailable.', 0, $e);
        }
    }
}

function merd_activation_grant_issue(
    MerdActivationGrantStore $store,
    int $clientId,
    ?DateTimeImmutable $now = null,
    ?callable $randomBytes = null
): array {
    $clock = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $generator = $randomBytes ?? static fn (int $length): string => random_bytes($length);
    $plaintext = bin2hex($generator(32));
    $hash = hash('sha256', $plaintext);
    $expiresAt = $clock->modify('+10 minutes');
    $store->insertGrantHash($clientId, $hash, $expiresAt);
    return ['grant' => $plaintext, 'expires_at' => $expiresAt];
}

function merd_activation_grant_consume(
    MerdActivationGrantStore $store,
    int $clientId,
    string $plaintextGrant,
    ?DateTimeImmutable $now = null
): bool {
    if ($plaintextGrant === '' || strlen($plaintextGrant) > 512) {
        return false;
    }
    $clock = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $store->consumeGrantHash($clientId, hash('sha256', $plaintextGrant), $clock);
}

function merd_setup_key_matches(mixed $stored, string $presented): bool
{
    if (!is_string($stored) || $stored === '' || $presented === '') {
        return false;
    }
    return hash_equals(hash('sha256', $stored), hash('sha256', $presented));
}

function merd_device_token_generate(?callable $randomBytes = null): string
{
    $generator = $randomBytes ?? static fn (int $length): string => random_bytes($length);
    return bin2hex($generator(32));
}

function merd_device_token_hash(string $token): string
{
    return hash('sha256', $token);
}

interface MerdDeviceActivationStore
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function eligibleStoreExists(int $clientId, int $storeId): bool;
    public function activate(
        int $clientId,
        int $storeId,
        string $deviceUuid,
        string $deviceName,
        string $tokenHash,
        DateTimeImmutable $tokenExpiresAt,
        DateTimeImmutable $previousTokenValidUntil,
        DateTimeImmutable $now
    ): int;
}

final class MerdPdoDeviceActivationStore implements MerdDeviceActivationStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function begin(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new RuntimeException('Could not begin activation transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->commit()) {
            throw new RuntimeException('Could not commit activation transaction.');
        }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function eligibleStoreExists(int $clientId, int $storeId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM stores WHERE id = ? AND client_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$storeId, $clientId]);
        return (bool)$stmt->fetchColumn();
    }

    public function activate(
        int $clientId,
        int $storeId,
        string $deviceUuid,
        string $deviceName,
        string $tokenHash,
        DateTimeImmutable $tokenExpiresAt,
        DateTimeImmutable $previousTokenValidUntil,
        DateTimeImmutable $now
    ): int {
        $select = $this->pdo->prepare(
            'SELECT id, client_id, token_hash FROM devices WHERE device_uuid = ? LIMIT 1 FOR UPDATE'
        );
        $select->execute([$deviceUuid]);
        $existing = $select->fetch(PDO::FETCH_ASSOC);
        $timestamp = $now->format('Y-m-d H:i:s');
        if (is_array($existing)) {
            if ((int)$existing['client_id'] !== $clientId) {
                throw new MerdActivationDenied('Device activation failed.');
            }
            $previousHash = trim((string)($existing['token_hash'] ?? ''));
            $update = $this->pdo->prepare(
                "UPDATE devices SET store_id = ?, device_name = ?, previous_token_hash = ?, "
                . 'previous_token_valid_until = ?, token_hash = ?, token_expires_at = ?, '
                . "token_rotated_at = ?, revoked_at = NULL, activated_at = COALESCE(activated_at, ?), status = 'active' "
                . 'WHERE id = ? AND client_id = ?'
            );
            $update->execute([
                $storeId,
                $deviceName,
                $previousHash !== '' ? $previousHash : null,
                $previousHash !== '' ? $previousTokenValidUntil->format('Y-m-d H:i:s') : null,
                $tokenHash,
                $tokenExpiresAt->format('Y-m-d H:i:s'),
                $timestamp,
                $timestamp,
                $existing['id'],
                $clientId,
            ]);
            return (int)$existing['id'];
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO devices '
            . '(client_id, store_id, device_uuid, device_name, activation_token, token_hash, '
            . "token_expires_at, revoked_at, activated_at, status) VALUES (?, ?, ?, ?, NULL, ?, ?, NULL, ?, 'active')"
        );
        $insert->execute([
            $clientId,
            $storeId,
            $deviceUuid,
            $deviceName,
            $tokenHash,
            $tokenExpiresAt->format('Y-m-d H:i:s'),
            $timestamp,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}

final class MerdActivationDenied extends RuntimeException
{
}

function merd_activate_device(
    MerdActivationGrantStore $grantStore,
    MerdDeviceActivationStore $deviceStore,
    int $clientId,
    int $storeId,
    string $grant,
    string $deviceUuid,
    string $deviceName,
    ?DateTimeImmutable $now = null,
    ?callable $randomBytes = null
): array {
    $clock = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    try {
        $deviceStore->begin();
        if (!$deviceStore->eligibleStoreExists($clientId, $storeId)
            || !merd_activation_grant_consume($grantStore, $clientId, $grant, $clock)) {
            throw new MerdActivationDenied('Device activation failed.');
        }
        $token = merd_device_token_generate($randomBytes);
        $expiresAt = $clock->modify('+180 days');
        $deviceId = $deviceStore->activate(
            $clientId,
            $storeId,
            $deviceUuid,
            $deviceName,
            merd_device_token_hash($token),
            $expiresAt,
            $clock->modify('+7 days'),
            $clock
        );
        $deviceStore->commit();
        return ['token' => $token, 'expires_at' => $expiresAt, 'device_id' => $deviceId];
    } catch (MerdActivationDenied $e) {
        $deviceStore->rollback();
        throw $e;
    } catch (Throwable $e) {
        $deviceStore->rollback();
        throw new MerdSecurityControlUnavailable('Device activation unavailable.', 0, $e);
    }
}

function merd_device_auth_extract_token(array $server, array $body = [], array $query = []): array
{
    $bearer = merd_request_bearer_token($server);
    $bodyLegacy = merd_device_auth_legacy_value($body['activation_token'] ?? null);
    $queryLegacy = merd_device_auth_legacy_value($query['activation_token'] ?? null);
    if ($bodyLegacy !== '' && $queryLegacy !== '' && !hash_equals($bodyLegacy, $queryLegacy)) {
        throw new MerdRequestException('device_unauthorized', 401, 'Device authorization failed.');
    }
    $legacy = $bodyLegacy !== '' ? $bodyLegacy : $queryLegacy;
    if ($bearer !== null && $legacy !== '' && !hash_equals($bearer, $legacy)) {
        throw new MerdRequestException('device_unauthorized', 401, 'Device authorization failed.');
    }
    $token = $bearer ?? ($legacy !== '' ? $legacy : null);
    if ($token === null || strlen($token) > 512) {
        throw new MerdRequestException('device_unauthorized', 401, 'Device authorization failed.');
    }
    return ['token' => $token, 'transport' => $bearer !== null ? 'bearer' : 'legacy'];
}

function merd_device_auth_legacy_value(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_string($value) && !is_int($value)) {
        throw new MerdRequestException('device_unauthorized', 401, 'Device authorization failed.');
    }
    $token = trim((string)$value);
    if ($token === '' || strlen($token) > 512) {
        throw new MerdRequestException('device_unauthorized', 401, 'Device authorization failed.');
    }
    return $token;
}

interface MerdDeviceStore
{
    public function findDevice(int $clientId, int $storeId, string $deviceUuid): ?array;
}

final class MerdPdoDeviceStore implements MerdDeviceStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findDevice(int $clientId, int $storeId, string $deviceUuid): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, client_id, store_id, device_uuid, status, revoked_at, '
                . 'token_hash, token_expires_at, previous_token_hash, previous_token_valid_until '
                . 'FROM devices WHERE client_id = ? AND store_id = ? AND device_uuid = ? LIMIT 1'
            );
            $stmt->execute([$clientId, $storeId, $deviceUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            throw new MerdSecurityControlUnavailable('Device authorization unavailable.', 0, $e);
        }
    }
}

function merd_device_authorize(
    MerdDeviceStore $store,
    int $clientId,
    int $storeId,
    string $deviceUuid,
    string $token,
    ?DateTimeImmutable $now = null
): ?array {
    $clock = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $device = $store->findDevice($clientId, $storeId, $deviceUuid);
    if ($device === null || ($device['status'] ?? '') !== 'active' || !empty($device['revoked_at'])) {
        return null;
    }
    $presentedHash = merd_device_token_hash($token);
    $currentHash = (string)($device['token_hash'] ?? '');
    $currentExpiry = merd_device_auth_timestamp($device['token_expires_at'] ?? null);
    if ($currentHash !== '' && hash_equals($currentHash, $presentedHash)
        && $currentExpiry !== false && $currentExpiry > $clock->getTimestamp()) {
        return $device;
    }
    $previousHash = (string)($device['previous_token_hash'] ?? '');
    $previousExpiry = merd_device_auth_timestamp($device['previous_token_valid_until'] ?? null);
    if ($previousHash !== '' && hash_equals($previousHash, $presentedHash)
        && $previousExpiry !== false && $previousExpiry > $clock->getTimestamp()) {
        return $device;
    }
    return null;
}

function merd_device_auth_timestamp(mixed $value): int|false
{
    if (!is_string($value) || $value === '') {
        return false;
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        return false;
    }
}
