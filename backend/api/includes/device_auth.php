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

function merd_device_token_generate(?callable $randomBytes = null): string
{
    $generator = $randomBytes ?? static fn (int $length): string => random_bytes($length);
    return bin2hex($generator(32));
}

function merd_device_token_hash(string $token): string
{
    return hash('sha256', $token);
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
