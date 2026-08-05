<?php
declare(strict_types=1);

function merd_api_success(array $data = []): array
{
    return array_merge(['success' => true], $data);
}

function merd_api_error_payload(
    string $errorCode,
    string $message,
    ?string $requestId = null,
    array $extra = []
): array {
    $payload = array_merge([
        'success' => false,
        'error_code' => $errorCode,
        'error' => $message,
    ], $extra);
    if ($requestId !== null && $requestId !== '') {
        $payload['request_id'] = $requestId;
    }
    return $payload;
}

function merd_api_send(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function merd_api_fail(
    string $errorCode,
    string $message,
    int $status,
    ?string $requestId = null,
    array $extra = []
): never {
    merd_api_send(merd_api_error_payload($errorCode, $message, $requestId, $extra), $status);
}
