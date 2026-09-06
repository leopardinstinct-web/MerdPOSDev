<?php
declare(strict_types=1);

$root = dirname(__DIR__);
function shell_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function shell_read(string $path): string { $s=file_get_contents($path); if(!is_string($s)) throw new RuntimeException('Unreadable: '.$path); return $s; }

$page = shell_read($root . '/web/themes/custom/merdpos_app/templates/page.html.twig');
$theme = shell_read($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$shellCss = shell_read($root . '/web/themes/custom/merdpos_app/css/app-shell.css');
$admin = shell_read($root . '/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig');
$dashboard = shell_read($root . '/web/modules/custom/merdpos_core/templates/merdpos-dashboard.html.twig');

shell_check(!str_contains($page, 'merdpos-context-strip'), 'Legacy context strip is still rendered.');
shell_check(!str_contains($page, 'merdpos-shell-context'), 'Redundant shell context pill is still rendered.');
shell_check(str_contains($page, 'merdpos-section-tabs'), 'Shared section tabs are missing from the shell.');
shell_check(str_contains($page, "merdpos_active_section == 'admin' ? ' merdpos-admin-tabs'"), 'Admin tabs are not promoted to the shell tab row.');
shell_check(str_contains($theme, "['key'=>'onboarding','label'=>'Onboard']"), 'Permission-aware Admin top tabs missing.');
shell_check(str_contains($shellCss, '.merdpos-section-tabs a.is-active'), 'Shared section tab styling missing.');
shell_check(!str_contains($admin, 'merdpos-admin-editor--new" open'), 'Create client/store/workforce panels must start collapsed.');
shell_check(!str_contains($admin, '<div class="merdpos-admin-tabs"'), 'Admin tabs are duplicated inside page content.');
shell_check(!str_contains($dashboard, 'merdpos-attendance-widget-main'), 'Standalone attendance widget remains on Home.');
shell_check(str_contains($dashboard, 'LOG IN STORE'), 'My current shift does not expose LOG IN STORE.');
shell_check(str_contains($dashboard, 'merdpos-attendance-open--icon'), 'My current shift QR scan action missing.');
shell_check(str_contains($dashboard, 'data-attendance-scan'), 'Attendance scanner is not integrated into current shift.');

echo "MERDPOS shell declutter + integrated attendance v1 contract validated.\n";
