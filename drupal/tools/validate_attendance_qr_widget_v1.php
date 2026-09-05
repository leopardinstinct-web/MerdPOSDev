<?php
declare(strict_types=1);

function attendance_widget_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = file_get_contents($module . '/src/Controller/DashboardController.php');
$provider = file_get_contents($module . '/src/Integration/ParityDataProvider.php');
$template = file_get_contents($module . '/templates/merdpos-dashboard.html.twig');
$js = file_get_contents($module . '/js/attendance-scan.js');
$css = file_get_contents($module . '/css/attendance-scan.css');
$routing = file_get_contents($module . '/merdpos_core.routing.yml');
$libraries = file_get_contents($module . '/merdpos_core.libraries.yml');
foreach ([$controller,$provider,$template,$js,$css,$routing,$libraries] as $source) {
  attendance_widget_check(is_string($source), 'Attendance QR widget source is unreadable.');
}
attendance_widget_check(str_contains($routing, "path: '/merdpos/attendance/scan'"), 'Drupal attendance scan route missing.');
attendance_widget_check(str_contains($routing, 'DashboardController::attendanceScan'), 'Attendance scan controller route missing.');
attendance_widget_check(str_contains($routing, 'methods: [POST]'), 'Attendance scan route must be POST-only.');
attendance_widget_check(str_contains($controller, "csrf->validate"), 'Attendance scan Drupal CSRF validation missing.');
attendance_widget_check(str_contains($controller, "call('attendance_scan', 'POST'"), 'Attendance scan must use the signed MERDPOS gateway.');
attendance_widget_check(str_contains($controller, 'extractAttendanceToken'), 'Attendance QR token extraction missing.');
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE '] as $forbidden) {
  attendance_widget_check(!str_contains($controller, $forbidden), "Dashboard attendance scan must not contain operational SQL: {$forbidden}");
}
attendance_widget_check(str_contains($provider, "in_array('attendance.scan', \$permissions, true)"), 'Attendance widget permission gate missing.');
attendance_widget_check(str_contains($provider, "'attendance_scan'"), 'Attendance scan system widget key missing.');
attendance_widget_check(str_contains($template, 'data-attendance-scan'), 'Attendance scanner widget markup missing.');
attendance_widget_check(str_contains($template, 'data-attendance-video'), 'Attendance camera preview missing.');
attendance_widget_check(str_contains($template, 'data-attendance-manual'), 'Attendance QR fallback input missing.');
attendance_widget_check(!str_contains($template, '|raw'), 'Attendance widget must preserve Twig escaping.');
attendance_widget_check(str_contains($js, 'navigator.mediaDevices?.getUserMedia'), 'Attendance camera access missing.');
attendance_widget_check(str_contains($js, "'BarcodeDetector' in window"), 'QR BarcodeDetector support missing.');
attendance_widget_check(str_contains($js, "'X-MERDPOS-CSRF': csrf"), 'Attendance browser CSRF header missing.');
attendance_widget_check(str_contains($js, 'data-attendance-manual'), 'Attendance manual fallback behavior missing.');
attendance_widget_check(!str_contains($js, 'innerHTML'), 'Attendance result rendering must not use innerHTML.');
attendance_widget_check(str_contains($css, '.merdpos-attendance-panel[hidden]'), 'Attendance hidden-state CSS guard missing.');
attendance_widget_check(str_contains($css, '@media (max-width:35rem)'), 'Attendance phone layout CSS missing.');
attendance_widget_check(str_contains($libraries, 'js/attendance-scan.js'), 'Attendance scanner JS is not attached to Home.');
attendance_widget_check(str_contains($libraries, 'css/attendance-scan.css'), 'Attendance scanner CSS is not attached to Home.');

echo "MERDPOS Drupal Home attendance QR widget v1 contract validated.\n";
