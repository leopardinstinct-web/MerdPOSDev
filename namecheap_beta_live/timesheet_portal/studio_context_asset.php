<?php
declare(strict_types=1);

function studio_context_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$token = strtolower(trim((string)($_GET['t'] ?? '')));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    studio_context_fail(404, 'Studio context asset not found.');
}

$root = dirname(__DIR__) . '/backend/runtime/ui_studio_context_assets';
$manifestPath = $root . '/' . $token . '.json';
$assetPath = $root . '/' . $token . '.bin';
if (!is_file($manifestPath) || !is_file($assetPath)) {
    studio_context_fail(404, 'Studio context asset not found.');
}

try {
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    studio_context_fail(404, 'Studio context asset not found.');
}
if (!is_array($manifest) || !hash_equals((string)($manifest['token'] ?? ''), $token)) {
    studio_context_fail(404, 'Studio context asset not found.');
}

$allowedMimes = ['image/png','image/jpeg','image/webp','image/gif','image/svg+xml'];
$mime = strtolower((string)($manifest['mime'] ?? ''));
if (!in_array($mime, $allowedMimes, true)) {
    studio_context_fail(415, 'Unsupported Studio context asset.');
}

$name = basename((string)($manifest['name'] ?? 'studio-context'));
$name = preg_replace('/[\x00-\x1F\x7F"\\]+/u', '_', $name) ?: 'studio-context';
$size = filesize($assetPath);
if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
    studio_context_fail(404, 'Studio context asset not found.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)$size);
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
if ($mime === 'image/svg+xml') {
    header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
}
readfile($assetPath);
