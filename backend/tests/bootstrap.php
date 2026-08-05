<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/includes/api_response.php';
require_once __DIR__ . '/../api/includes/request.php';
require_once __DIR__ . '/../api/includes/device_auth.php';
require_once __DIR__ . '/../api/includes/auth_lockout.php';
require_once __DIR__ . '/../api/includes/security_log.php';
require_once __DIR__ . '/../api/includes/maintenance_guard.php';

$GLOBALS['merd_tests'] = [];

function merd_test(string $name, callable $test): void
{
    $GLOBALS['merd_tests'][] = [$name, $test];
}

function merd_assert(bool $condition, string $message = 'Assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function merd_assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Values are not identical.');
    }
}

function merd_assert_throws(string $className, callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $e) {
        if ($e instanceof $className) {
            return $e;
        }
        throw new RuntimeException('Unexpected exception type: ' . get_class($e));
    }
    throw new RuntimeException('Expected exception was not thrown.');
}

final class MerdMemoryGrantStore implements MerdActivationGrantStore
{
    public array $rows = [];

    public function insertGrantHash(int $clientId, string $grantHash, DateTimeImmutable $expiresAt): void
    {
        $this->rows[$grantHash] = [
            'client_id' => $clientId,
            'expires_at' => $expiresAt,
            'consumed' => false,
        ];
    }

    public function consumeGrantHash(int $clientId, string $grantHash, DateTimeImmutable $now): bool
    {
        $row = $this->rows[$grantHash] ?? null;
        if ($row === null || $row['client_id'] !== $clientId || $row['consumed'] || $row['expires_at'] <= $now) {
            return false;
        }
        $this->rows[$grantHash]['consumed'] = true;
        return true;
    }
}

final class MerdMemoryDeviceStore implements MerdDeviceStore
{
    public function __construct(public ?array $device)
    {
    }

    public function findDevice(int $clientId, int $storeId, string $deviceUuid): ?array
    {
        if ($this->device === null
            || (int)$this->device['client_id'] !== $clientId
            || (int)$this->device['store_id'] !== $storeId
            || $this->device['device_uuid'] !== $deviceUuid) {
            return null;
        }
        return $this->device;
    }
}

final class MerdMemoryLockoutStore implements MerdAuthLockoutStore
{
    public bool $available = true;
    public array $deviceCounters = [];
    public array $globalLocks = [];
    public array $events = [];

    public function securityTablesAvailable(): bool { return $this->available; }
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}

    public function deviceLockUntil(int $clientId, string $userId, string $deviceUuid, string $action): ?DateTimeImmutable
    {
        return $this->deviceCounters[$this->deviceKey($clientId, $userId, $deviceUuid, $action)]['locked_until'] ?? null;
    }

    public function globalLockUntil(int $clientId, string $userId, string $action): ?DateTimeImmutable
    {
        return $this->globalLocks[$this->globalKey($clientId, $userId, $action)] ?? null;
    }

    public function recordDeviceFailure(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now, int $threshold, DateInterval $cooldown): int
    {
        $key = $this->deviceKey($clientId, $userId, $deviceUuid, $action);
        $row = $this->deviceCounters[$key] ?? ['failed' => 0, 'locked_until' => null];
        if ($row['locked_until'] instanceof DateTimeImmutable && $row['locked_until'] <= $now) {
            $row = ['failed' => 0, 'locked_until' => null];
        }
        $row['failed']++;
        if ($row['failed'] >= $threshold) {
            $row['locked_until'] = $now->add($cooldown);
        }
        $this->deviceCounters[$key] = $row;
        return $row['failed'];
    }

    public function insertFailureEvent(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void
    {
        $this->events[] = compact('clientId', 'employeeId', 'userId', 'deviceUuid', 'action', 'now');
    }

    public function countRecentFailures(int $clientId, string $userId, string $action, DateTimeImmutable $since): int
    {
        return count(array_filter($this->events, static fn (array $event): bool =>
            $event['clientId'] === $clientId
            && $event['userId'] === $userId
            && $event['action'] === $action
            && $event['now'] >= $since
        ));
    }

    public function setGlobalLock(int $clientId, ?int $employeeId, string $userId, string $action, DateTimeImmutable $until): void
    {
        $this->globalLocks[$this->globalKey($clientId, $userId, $action)] = $until;
    }

    public function resetDeviceCounter(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void
    {
        $this->deviceCounters[$this->deviceKey($clientId, $userId, $deviceUuid, $action)] = [
            'failed' => 0,
            'locked_until' => null,
        ];
    }

    private function deviceKey(int $clientId, string $userId, string $deviceUuid, string $action): string
    {
        return implode('|', [$clientId, $userId, $deviceUuid, $action]);
    }

    private function globalKey(int $clientId, string $userId, string $action): string
    {
        return implode('|', [$clientId, $userId, $action]);
    }
}

final class MerdMemorySecurityLogStore implements MerdSecurityLogStore
{
    public array $events = [];
    public bool $fail = false;

    public function write(array $event): void
    {
        if ($this->fail) {
            throw new RuntimeException('simulated storage failure');
        }
        $this->events[] = $event;
    }
}
