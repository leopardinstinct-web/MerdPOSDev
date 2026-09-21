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
attendance_widget_check(str_contains($controller, "set('merdpos_shop_context'") && str_contains($controller, "remove('merdpos_shop_context'"), 'Management Shop scan must persist/clear the validated Drupal server-side Shop context.');
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE '] as $forbidden) {
  attendance_widget_check(!str_contains($controller, $forbidden), "Dashboard attendance scan must not contain operational SQL: {$forbidden}");
}
attendance_widget_check(str_contains($provider, "in_array('attendance.scan', \$permissions, true)"), 'USER Shop scan attendance permission gate missing.');
attendance_widget_check(str_contains($provider, "in_array('finance.view', \$permissions, true)"), 'Management Shop scan finance permission gate missing.');
attendance_widget_check(str_contains($provider, "\$isActualDev = !empty(\$statePayload['is_dev']);"), 'Actual DEV preview protection missing from Shop scan capability.');
attendance_widget_check(str_contains($provider, "\$canScanShop = \$stateRoleKey === 'USER' && !\$isActualDev ? \$canScanAttendance : \$canViewFinance;"), 'Role-aware Shop scan capability missing.');
attendance_widget_check(str_contains($template, 'merdpos-dashboard-shop-login'), 'Shop Log IN / OUT header control missing.');
attendance_widget_check(str_contains($template, 'Shop Log IN / OUT'), 'Shop Log IN / OUT label missing.');
attendance_widget_check(str_contains($template, 'data-attendance-scan') && str_contains($template, 'data-shop-active'), 'Header Shop scanner state markers missing.');
attendance_widget_check(!str_contains($template, 'merdpos-current-shift-action'), 'Retired Current Shift scanner markup must not return.');
attendance_widget_check(!str_contains($template, 'LOG IN STORE'), 'Retired Current Shift store action must not return.');
attendance_widget_check(!str_contains($template, 'merdpos-attendance-widget-main'), 'Standalone attendance widget must remain retired.');
attendance_widget_check(str_contains($template, '<dialog class="merdpos-shop-dialog"'), 'Shop scanner must open as a native modal dialog.');
attendance_widget_check(str_contains($template, 'merdpos-dialog-close') && str_contains($template, 'aria-label="Close Shop QR scanner"'), 'Shop scanner modal close control missing.');
attendance_widget_check(str_contains($template, 'data-attendance-video'), 'Shop camera preview missing.');
attendance_widget_check(str_contains($template, 'data-attendance-manual'), 'Shop QR fallback input missing.');
attendance_widget_check(!str_contains($template, '|raw'), 'Attendance widget must preserve Twig escaping.');
attendance_widget_check(str_contains($js, 'navigator.mediaDevices?.getUserMedia'), 'Attendance camera access missing.');
attendance_widget_check(str_contains($js, "'BarcodeDetector' in window"), 'QR BarcodeDetector support missing.');
attendance_widget_check(str_contains($js, "'X-MERDPOS-CSRF': csrf"), 'Attendance browser CSRF header missing.');
attendance_widget_check(str_contains($js, 'data-attendance-manual'), 'Shop QR manual fallback behavior missing.');
attendance_widget_check(str_contains($js, 'SHOP LOGGED IN') && str_contains($js, 'SHOP LOGGED OUT'), 'Management Shop scan result states missing.');
attendance_widget_check(str_contains($js, 'attendanceMode = !!scan.attendance'), 'Attendance versus management scan state split missing.');
attendance_widget_check(str_contains($js, "root.classList.toggle('is-shop-active', shopActive)"), 'Header Shop active state update missing.');
attendance_widget_check(!str_contains($js, 'innerHTML'), 'Shop scan result rendering must not use innerHTML.');
attendance_widget_check(str_contains($js, 'panel.showModal') && str_contains($js, 'panel.close'), 'Shop scanner must use native modal open/close behavior.');
attendance_widget_check(str_contains($js, "event.target === panel") && str_contains($js, "addEventListener('close', stopCamera)"), 'Shop scanner backdrop/Escape close cleanup missing.');
attendance_widget_check(str_contains($css, '.merdpos-shop-dialog::backdrop'), 'Shop scanner modal backdrop styling missing.');
attendance_widget_check(str_contains($css, '.merdpos-shop-dialog-body'), 'Shop scanner modal layout missing.');
attendance_widget_check(!str_contains($css, '.merdpos-attendance-panel--header'), 'Retired inline header scanner panel styling must not return.');
attendance_widget_check(str_contains($css, '.merdpos-dashboard-shop-login.is-shop-active'), 'Header Shop active styling missing.');
attendance_widget_check(str_contains($css, 'qr-code-scanner.svg'), 'Approved QR scanner icon binding missing.');
attendance_widget_check(!str_contains($css, '.merdpos-current-shift-action'), 'Retired Current Shift scanner CSS must not return.');
attendance_widget_check(str_contains($css, 'max-width:35rem'), 'Shop scanner phone layout CSS missing.');
attendance_widget_check(str_contains($libraries, 'js/attendance-scan.js'), 'Attendance scanner JS is not attached to Home.');
attendance_widget_check(str_contains($libraries, 'css/attendance-scan.css'), 'Attendance scanner CSS is not attached to Home.');

echo "MERDPOS Drupal Home attendance QR widget v1 contract validated.\n";
