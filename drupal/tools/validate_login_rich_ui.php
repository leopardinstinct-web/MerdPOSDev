<?php
declare(strict_types=1);

$root = dirname(__DIR__);
function rich_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
function rich_read(string $path): string {
  $data = file_get_contents($path);
  if (!is_string($data)) throw new RuntimeException('Unreadable file: ' . $path);
  return $data;
}

$twig = rich_read($root . '/web/themes/custom/merdpos_app/templates/page.html.twig');
$theme = rich_read($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$services = rich_read($root . '/web/modules/custom/merdpos_core/merdpos_core.services.yml');
$route = rich_read($root . '/web/modules/custom/merdpos_core/src/Routing/MerdposRouteSubscriber.php');
$denied = rich_read($root . '/web/modules/custom/merdpos_core/src/Routing/MerdposAccessDeniedSubscriber.php');
$runtime = rich_read($root . '/tools/namecheap_resolve_runtime.php');
$deploy = rich_read($root . '/tools/namecheap_deploy.sh');
$composer = json_decode(rich_read($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);

rich_check(is_file($root . '/web/themes/custom/merdpos_app/assets/merdpos-logo-approved.png'), 'Approved MERDPOS login graphic missing.');
rich_check(is_file($root . '/web/themes/custom/merdpos_app/assets/merdpos-mark.png'), 'Approved MERDPOS mark missing.');
rich_check(str_contains($twig, 'Welcome back.'), 'Previous MERDPOS login heading missing.');
rich_check(str_contains($twig, 'merdpos-logo-approved.png'), 'Approved MERDPOS login logo not rendered.');
rich_check(str_contains($twig, 'Operations') && str_contains($twig, 'Reports') && str_contains($twig, 'Finance') && str_contains($twig, 'DEV'), 'Rich capability graphics missing.');
rich_check(str_contains($theme, 'merdpos_core.identity_manager'), 'MERDPOS profile-aware shell missing.');
rich_check(str_contains($route, 'MerdposLoginForm'), 'Drupal login route is not replaced.');
rich_check(str_contains($denied, 'merdpos_core.'), 'Anonymous app redirect guard missing.');
rich_check(str_contains($services, 'merdpos_core.authenticator') && str_contains($services, 'merdpos_core.identity_manager'), 'MERDPOS auth services missing.');
rich_check(str_contains($runtime, 'MERDPOS_DRUPAL_LOGIN_URL='), 'Private runtime login URL missing.');
rich_check(str_contains($deploy, 'config:set system.site page.front /merdpos -y'), 'Drupal app domain front page is not routed to MERDPOS.');

$required = $composer['require'] ?? [];
foreach (['drupal/dashboard','drupal/charts','drupal/ui_patterns','drupal/ui_icons','drupal/gin','drupal/gin_toolbar','drupal/better_exposed_filters'] as $package) {
  rich_check(isset($required[$package]), 'Free UI package missing: ' . $package);
}

$authDir = $root . '/web/modules/custom/merdpos_core/src/Auth';
foreach (glob($authDir . '/*.php') ?: [] as $file) {
  $source = rich_read($file);
  rich_check(!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+/i', $source), 'Direct SQL found in Drupal auth boundary: ' . basename($file));
  rich_check(!str_contains($source, 'login_password') && !str_contains($source, 'pin_code'), 'MERDPOS credential storage fields leaked into Drupal auth.');
}

$surface = rich_read($root . '/web/modules/custom/merdpos_core/templates/merdpos-surface.html.twig');
foreach (['merdpos-kpi-icon', 'M16 21v-2a4 4', 'M4 20V10', 'M3 7h15', 'M21 15a4'] as $needle) {
  rich_check(str_contains($surface, $needle), 'Canonical MERDPOS widget icon treatment missing: ' . $needle);
}

echo "MERDPOS Drupal login and rich free-UI contract validated.\n";

