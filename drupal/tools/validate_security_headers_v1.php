<?php
declare(strict_types=1);

function security_headers_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$htaccess = (string) file_get_contents($root . '/web/.htaccess');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');
$required = [
    'Header always set Strict-Transport-Security "max-age=31536000"',
    'Header always set Referrer-Policy "strict-origin-when-cross-origin"',
    'Header always set Permissions-Policy "camera=(self), geolocation=(self), microphone=(), payment=(), usb=()"',
    'Header always set Content-Security-Policy "base-uri \'self\'; object-src \'none\'; frame-ancestors \'self\'"',
    'Header always set X-Content-Type-Options nosniff',
];
foreach ($required as $marker) security_headers_check(str_contains($htaccess, $marker), 'Missing security header contract: ' . $marker);
security_headers_check(str_contains($deploy, 'validate_security_headers_v1.php'), 'Security header validator is not wired into deployment.');
echo "MERDPOS Drupal go-live security header contract validated.\n";
