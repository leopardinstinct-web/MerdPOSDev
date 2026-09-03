<?php
declare(strict_types=1);

function merd_service_signature_payload(
    string $operation,
    string $method,
    int $timestamp,
    int $clientId,
    string $actorUserId
): string {
    return implode("\n", [
        'merdpos-service-v1',
        strtolower(trim($operation)),
        strtoupper(trim($method)),
        (string)$timestamp,
        (string)$clientId,
        $actorUserId,
    ]);
}

function merd_service_sign(
    string $operation,
    string $method,
    int $timestamp,
    int $clientId,
    string $actorUserId,
    string $secret
): string {
    return hash_hmac('sha256', merd_service_signature_payload($operation, $method, $timestamp, $clientId, $actorUserId), $secret);
}

function merd_service_authenticate(
    array $server,
    string $operation,
    ?int $now = null,
    ?string $secretOverride = null
): array {
    $service = trim((string)($server['HTTP_X_MERDPOS_SERVICE'] ?? ''));
    $timestampRaw = trim((string)($server['HTTP_X_MERDPOS_TIMESTAMP'] ?? ''));
    $clientRaw = trim((string)($server['HTTP_X_MERDPOS_CLIENT_ID'] ?? ''));
    $actorUserId = trim((string)($server['HTTP_X_MERDPOS_ACTOR_USER_ID'] ?? ''));
    $signature = strtolower(trim((string)($server['HTTP_X_MERDPOS_SIGNATURE'] ?? '')));

    if ($service !== 'drupal-web'
        || !preg_match('/^\d{10}$/', $timestampRaw)
        || !preg_match('/^[1-9]\d*$/', $clientRaw)
        || !preg_match('/^\d{1,20}$/', $actorUserId)
        || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
        throw new MerdRequestException('service_unauthorized', 401, 'Service authorization failed.');
    }

    $secret = $secretOverride;
    if ($secret === null) {
        $secret = (string)getenv('MERDPOS_DRUPAL_SERVICE_SECRET');
        if ($secret === '' && defined('MERDPOS_DRUPAL_SERVICE_SECRET')) {
            $secret = (string)constant('MERDPOS_DRUPAL_SERVICE_SECRET');
        }
    }
    if (strlen($secret) < 32) {
        throw new MerdRequestException('service_unavailable', 503, 'Service authorization is unavailable.');
    }

    $timestamp = (int)$timestampRaw;
    $clientId = (int)$clientRaw;
    $now ??= time();
    if (abs($now - $timestamp) > 90) {
        throw new MerdRequestException('service_unauthorized', 401, 'Service authorization failed.');
    }

    $expected = merd_service_sign(
        $operation,
        (string)($server['REQUEST_METHOD'] ?? ''),
        $timestamp,
        $clientId,
        $actorUserId,
        $secret
    );
    if (!hash_equals($expected, $signature)) {
        throw new MerdRequestException('service_unauthorized', 401, 'Service authorization failed.');
    }

    return [
        'service' => $service,
        'client_id' => $clientId,
        'actor_user_id' => $actorUserId,
        'timestamp' => $timestamp,
    ];
}
