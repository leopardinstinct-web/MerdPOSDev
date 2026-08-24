<?php
declare(strict_types=1);

require_once __DIR__ . '/device_auth.php';

interface MerdAuthLockoutStore
{
    public function securityTablesAvailable(): bool;
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function deviceLockUntil(int $clientId, string $userId, string $deviceUuid, string $action): ?DateTimeImmutable;
    public function globalLockUntil(int $clientId, string $userId, string $action): ?DateTimeImmutable;
    public function recordDeviceFailure(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now, int $threshold, DateInterval $cooldown): int;
    public function insertFailureEvent(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void;
    public function countRecentFailures(int $clientId, string $userId, string $action, DateTimeImmutable $since): int;
    public function setGlobalLock(int $clientId, ?int $employeeId, string $userId, string $action, DateTimeImmutable $until): void;
    public function resetDeviceCounter(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void;
}

final class MerdPdoAuthLockoutStore implements MerdAuthLockoutStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function securityTablesAvailable(): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?)'
            );
            $stmt->execute([
                'employee_auth_attempts',
                'employee_auth_failure_events',
                'employee_auth_global_locks',
            ]);
            return (int)$stmt->fetchColumn() === 3;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function begin(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new RuntimeException('Could not begin lockout transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->commit()) {
            throw new RuntimeException('Could not commit lockout transaction.');
        }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function deviceLockUntil(int $clientId, string $userId, string $deviceUuid, string $action): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT locked_until FROM employee_auth_attempts '
            . 'WHERE client_id = ? AND user_id = ? AND device_uuid = ? AND action = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $userId, $deviceUuid, $action]);
        return $this->dateOrNull($stmt->fetchColumn());
    }

    public function globalLockUntil(int $clientId, string $userId, string $action): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT locked_until FROM employee_auth_global_locks '
            . 'WHERE client_id = ? AND user_id = ? AND action = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $userId, $action]);
        return $this->dateOrNull($stmt->fetchColumn());
    }

    public function recordDeviceFailure(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now, int $threshold, DateInterval $cooldown): int
    {
        $select = $this->pdo->prepare(
            'SELECT id, failed_attempts, locked_until FROM employee_auth_attempts '
            . 'WHERE client_id = ? AND user_id = ? AND device_uuid = ? AND action = ? FOR UPDATE'
        );
        $select->execute([$clientId, $userId, $deviceUuid, $action]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        $failed = 1;
        if (is_array($row)) {
            $existingLock = $this->dateOrNull($row['locked_until'] ?? null);
            $failed = $existingLock !== null && $existingLock <= $now
                ? 1
                : (int)$row['failed_attempts'] + 1;
            $lockUntil = $failed >= $threshold ? $now->add($cooldown) : null;
            $update = $this->pdo->prepare(
                'UPDATE employee_auth_attempts SET employee_id = ?, failed_attempts = ?, '
                . 'locked_until = ?, last_failed_at = ? WHERE id = ?'
            );
            $update->execute([
                $employeeId,
                $failed,
                $lockUntil?->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                $row['id'],
            ]);
            return $failed;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO employee_auth_attempts '
            . '(client_id, employee_id, user_id, device_uuid, action, failed_attempts, locked_until, last_failed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $clientId,
            $employeeId,
            $userId,
            $deviceUuid,
            $action,
            1,
            $threshold <= 1 ? $now->add($cooldown)->format('Y-m-d H:i:s') : null,
            $now->format('Y-m-d H:i:s'),
        ]);
        return 1;
    }

    public function insertFailureEvent(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO employee_auth_failure_events '
            . '(client_id, employee_id, user_id, device_uuid, action, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$clientId, $employeeId, $userId, $deviceUuid, $action, $now->format('Y-m-d H:i:s')]);
    }

    public function countRecentFailures(int $clientId, string $userId, string $action, DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM employee_auth_failure_events '
            . 'WHERE client_id = ? AND user_id = ? AND action = ? AND occurred_at >= ?'
        );
        $stmt->execute([$clientId, $userId, $action, $since->format('Y-m-d H:i:s')]);
        return (int)$stmt->fetchColumn();
    }

    public function setGlobalLock(int $clientId, ?int $employeeId, string $userId, string $action, DateTimeImmutable $until): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO employee_auth_global_locks (client_id, employee_id, user_id, action, locked_until) '
            . 'VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id), '
            . 'locked_until = GREATEST(locked_until, VALUES(locked_until))'
        );
        $stmt->execute([$clientId, $employeeId, $userId, $action, $until->format('Y-m-d H:i:s')]);
    }

    public function resetDeviceCounter(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE employee_auth_attempts SET employee_id = ?, failed_attempts = 0, '
            . 'locked_until = NULL, last_success_at = ? '
            . 'WHERE client_id = ? AND user_id = ? AND device_uuid = ? AND action = ?'
        );
        $stmt->execute([
            $employeeId,
            $now->format('Y-m-d H:i:s'),
            $clientId,
            $userId,
            $deviceUuid,
            $action,
        ]);
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw new RuntimeException('Invalid lockout timestamp.', 0, $e);
        }
    }
}

final class MerdAuthLocked extends RuntimeException
{
    public function __construct(public readonly DateTimeImmutable $lockedUntil)
    {
        parent::__construct('Authentication temporarily locked.');
    }
}

final class MerdAuthLockoutService
{
    private const DEVICE_THRESHOLD = 5;
    private const GLOBAL_THRESHOLD = 15;

    public function __construct(private readonly MerdAuthLockoutStore $store)
    {
    }

    public function assertAvailable(): void
    {
        if (!$this->store->securityTablesAvailable()) {
            throw new MerdSecurityControlUnavailable('Authentication security controls unavailable.');
        }
    }

    public function assertNotLocked(int $clientId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void
    {
        $this->assertAvailable();
        try {
            foreach ([
                $this->store->deviceLockUntil($clientId, $userId, $deviceUuid, $action),
                $this->store->globalLockUntil($clientId, $userId, $action),
            ] as $until) {
                if ($until !== null && $until > $now) {
                    throw new MerdAuthLocked($until);
                }
            }
        } catch (MerdAuthLocked $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MerdSecurityControlUnavailable('Authentication security controls unavailable.', 0, $e);
        }
    }

    public function recordFailure(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void
    {
        $this->assertAvailable();
        $this->store->begin();
        try {
            $this->store->recordDeviceFailure(
                $clientId,
                $employeeId,
                $userId,
                $deviceUuid,
                $action,
                $now,
                self::DEVICE_THRESHOLD,
                new DateInterval('PT15M')
            );
            $this->store->insertFailureEvent($clientId, $employeeId, $userId, $deviceUuid, $action, $now);
            $since = $now->sub(new DateInterval('PT30M'));
            if ($this->store->countRecentFailures($clientId, $userId, $action, $since) >= self::GLOBAL_THRESHOLD) {
                $this->store->setGlobalLock(
                    $clientId,
                    $employeeId,
                    $userId,
                    $action,
                    $now->add(new DateInterval('PT15M'))
                );
            }
            $this->store->commit();
        } catch (Throwable $e) {
            $this->store->rollback();
            if ($e instanceof MerdSecurityControlUnavailable) {
                throw $e;
            }
            throw new MerdSecurityControlUnavailable('Authentication security controls unavailable.', 0, $e);
        }
    }

    public function recordSuccess(int $clientId, ?int $employeeId, string $userId, string $deviceUuid, string $action, DateTimeImmutable $now): void
    {
        $this->assertAvailable();
        try {
            $this->store->resetDeviceCounter($clientId, $employeeId, $userId, $deviceUuid, $action, $now);
        } catch (Throwable $e) {
            throw new MerdSecurityControlUnavailable('Authentication security controls unavailable.', 0, $e);
        }
    }
}
