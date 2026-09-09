<?php
declare(strict_types=1);

$root = dirname(__DIR__);
function favicon_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function favicon_read(string $path): string { $s=file_get_contents($path); if(!is_string($s)) throw new RuntimeException('Unreadable: '.$path); return $s; }

$html = favicon_read($root . '/web/themes/custom/merdpos_app/templates/html.html.twig');
$icon = $root . '/web/favicon.ico';
favicon_check(str_contains($html, 'rel="icon"'), 'MERDPOS HTML shell is missing an explicit favicon link.');
favicon_check(str_contains($html, 'href="/favicon.ico"'), 'MERDPOS favicon link must use the root fallback URL.');
favicon_check(is_file($icon) && filesize($icon) > 1024, 'MERDPOS root favicon is missing or empty.');
$header = file_get_contents($icon, false, NULL, 0, 4);
favicon_check($header === "\x00\x00\x01\x00", 'MERDPOS root favicon is not a valid ICO container.');

echo "MERDPOS favicon v1 contract validated.\n";
