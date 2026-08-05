<?php
declare(strict_types=1);

interface MerdSecurityLogStore
{
    public function write(array $event): void;
}

final class MerdPdoSecurityLogStore implements MerdSecurityLogStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function write(array $event): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO security_audit_events '
            . '(client_id, employee_id, device_id, event_type, outcome, actor_type, actor_id, request_id, ip_address, user_agent, metadata) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $event['client_id'], $event['employee_id'], $event['device_id'],
            $event['event_type'], $event['outcome'], $event['actor_type'],
            $event['actor_id'], $event['request_id'], $event['ip_address'],
            $event['user_agent'], $event['metadata'],
        ]);
    }
}

function merd_security_log_is_sensitive_key(string $key): bool
{
    return (bool)preg_match('/token|grant|pin|password|setup[_-]?key|payroll|wage|salary/i', $key);
}

function merd_security_log_redact(mixed $value, int $depth = 0): mixed
{
    if ($depth > 4) {
        return '[truncated]';
    }
    if (is_array($value)) {
        $clean = [];
        foreach (array_slice($value, 0, 30, true) as $key => $item) {
            $name = (string)$key;
            $clean[$name] = merd_security_log_is_sensitive_key($name)
                ? '[redacted]'
                : merd_security_log_redact($item, $depth + 1);
        }
        return $clean;
    }
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }
    return substr((string)$value, 0, 255);
}

function merd_security_log_event(
    MerdSecurityLogStore $store,
    array $server,
    string $eventType,
    string $outcome,
    array $context = [],
    array $metadata = []
): void {
    $allowedMetadata = [
        'endpoint', 'action', 'transport', 'reason_code', 'failure_count',
        'lock_scope', 'status_code', 'store_id',
    ];
    $selectedMetadata = array_intersect_key($metadata, array_flip($allowedMetadata));
    $safeMetadata = merd_security_log_redact($selectedMetadata);
    $encodedMetadata = json_encode($safeMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encodedMetadata)) {
        $encodedMetadata = '{}';
    }
    $event = [
        'client_id' => isset($context['client_id']) ? (int)$context['client_id'] : null,
        'employee_id' => isset($context['employee_id']) ? (int)$context['employee_id'] : null,
        'device_id' => isset($context['device_id']) ? (int)$context['device_id'] : null,
        'event_type' => substr($eventType, 0, 80),
        'outcome' => substr($outcome, 0, 32),
        'actor_type' => isset($context['actor_type']) ? substr((string)$context['actor_type'], 0, 32) : null,
        'actor_id' => isset($context['actor_id']) ? substr((string)$context['actor_id'], 0, 80) : null,
        'request_id' => isset($context['request_id']) ? substr((string)$context['request_id'], 0, 64) : null,
        'ip_address' => isset($server['REMOTE_ADDR']) ? substr((string)$server['REMOTE_ADDR'], 0, 64) : null,
        'user_agent' => isset($server['HTTP_USER_AGENT']) ? substr((string)$server['HTTP_USER_AGENT'], 0, 255) : null,
        'metadata' => substr($encodedMetadata, 0, 2000),
    ];
    try {
        $store->write($event);
    } catch (Throwable $e) {
        error_log('security event persistence failed');
    }
}
