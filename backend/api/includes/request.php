<?php
declare(strict_types=1);

final class MerdRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }
}

function merd_request_require_method(array $server, string $expected): void
{
    $actual = strtoupper((string)($server['REQUEST_METHOD'] ?? ''));
    if ($actual !== strtoupper($expected)) {
        throw new MerdRequestException('method_not_allowed', 405, 'Request method not allowed.');
    }
}

function merd_request_require_json_content_type(array $server): void
{
    $contentType = strtolower(trim((string)($server['CONTENT_TYPE'] ?? '')));
    if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
        throw new MerdRequestException('unsupported_media_type', 415, 'JSON request required.');
    }
}

function merd_request_json(string $raw): array
{
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    return $decoded;
}

function merd_request_positive_int(mixed $value, string $field): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if ($validated === false || (int)$validated <= 0) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    return (int)$validated;
}

function merd_request_text(mixed $value, string $field, int $maxLength, bool $allowEmpty = false): string
{
    if (!is_scalar($value) && $value !== null) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    $text = trim((string)$value);
    if ((!$allowEmpty && $text === '') || strlen($text) > $maxLength) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    return $text;
}

function merd_request_numeric_string(mixed $value, int $minLength = 1, int $maxLength = 20): string
{
    if (!is_string($value) && !is_int($value)) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    $text = trim((string)$value);
    if (!preg_match('/^\d+$/', $text)
        || strlen($text) < $minLength
        || strlen($text) > $maxLength) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    return $text;
}

function merd_request_authorization_header(array $server): ?string
{
    $value = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    return trim($value);
}

function merd_request_bearer_token(array $server): ?string
{
    $header = merd_request_authorization_header($server);
    if ($header === null) {
        return null;
    }
    if (!preg_match('/^Bearer[ ]+([^ ]+)$/i', $header, $matches)) {
        throw new MerdRequestException('device_unauthorized', 401, 'Device authorization failed.');
    }
    $token = trim($matches[1]);
    if ($token === '' || strlen($token) > 512) {
        throw new MerdRequestException('device_unauthorized', 401, 'Device authorization failed.');
    }
    return $token;
}

function merd_request_id(?callable $randomBytes = null): string
{
    $generator = $randomBytes ?? static fn (int $length): string => random_bytes($length);
    return bin2hex($generator(16));
}

function merd_request_cors_origin(array $server, array $approvedOrigins): ?string
{
    $origin = $server['HTTP_ORIGIN'] ?? null;
    if (!is_string($origin) || $origin === '') {
        return null;
    }
    foreach ($approvedOrigins as $approved) {
        if (is_string($approved) && hash_equals($approved, $origin)) {
            return $origin;
        }
    }
    throw new MerdRequestException('origin_not_allowed', 403, 'Request origin not allowed.');
}
