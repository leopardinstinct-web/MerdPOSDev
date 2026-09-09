<?php
declare(strict_types=1);

$root = dirname(__DIR__);
function favicon_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function favicon_read(string $path): string { $s=file_get_contents($path); if(!is_string($s)) throw new RuntimeException('Unreadable: '.$path); return $s; }

$html = favicon_read($root . '/web/themes/custom/merdpos_app/templates/html.html.twig');
$icon = $root . '/web/themes/custom/merdpos_app/assets/merdpos-mark.png';
favicon_check(str_contains($html, 'rel="icon"'), 'MERDPOS HTML shell is missing an explicit favicon link.');
favicon_check(str_contains($html, "assets/merdpos-mark.png"), 'MERDPOS favicon must use the approved existing mark asset.');
favicon_check(is_file($icon) && filesize($icon) > 0, 'MERDPOS favicon mark asset is missing or empty.');

echo "MERDPOS favicon v1 contract validated.\n";
