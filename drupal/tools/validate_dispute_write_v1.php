<?php

declare(strict_types=1);

function dispute_v1_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/web/modules/custom/merdpos_core/src/Controller/DisputesController.php');
$template = file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-disputes.html.twig');
$js = file_get_contents($root . '/web/modules/custom/merdpos_core/js/disputes-v1.js');
$css = file_get_contents($root . '/web/modules/custom/merdpos_core/css/disputes-v1.css');
$routing = file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
$libraries = file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.libraries.yml');
$provider = file_get_contents($root . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
$theme = file_get_contents($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$page = file_get_contents($root . '/web/themes/custom/merdpos_app/templates/page.html.twig');
$api = file_get_contents(dirname($root) . '/namecheap_beta_live/timesheet_portal/api/disputes.php');

foreach (compact('controller','template','js','css','routing','libraries','provider','theme','page','api') as $value) {
  dispute_v1_check(is_string($value) && $value !== '', 'Dispute Write v1 source is unreadable.');
}

dispute_v1_check(str_contains($routing, "merdpos_core.disputes:"), 'Disputes Drupal route missing.');
dispute_v1_check(str_contains($routing, "path: '/merdpos/disputes'"), 'Disputes route path mismatch.');
dispute_v1_check(str_contains($controller, "csrf->validate"), 'Drupal dispute CSRF validation missing.');
dispute_v1_check(str_contains($controller, "call('disputes', 'POST'"), 'Dispute writes must use the signed MERDPOS gateway.');
foreach (['create','decide','cancel','confirm_handover','reject_handover','resolve_flag'] as $action) {
  dispute_v1_check(str_contains($controller, "'{$action}'"), "Missing dispute action {$action}.");
}
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE '] as $forbidden) {
  dispute_v1_check(!str_contains($controller, $forbidden), "Drupal dispute controller contains operational SQL marker: {$forbidden}");
}
dispute_v1_check(str_contains($template, 'data-dispute-create'), 'Dispute create form missing.');
dispute_v1_check(str_contains($template, 'data-dispute-decision'), 'Dispute decision controls missing.');
dispute_v1_check(str_contains($template, "dispute_action\" value=\"resolve_flag"), 'Attendance flag resolution form missing.');
dispute_v1_check(!str_contains($template, '|raw'), 'Dispute template must preserve Twig escaping.');
dispute_v1_check(str_contains($js, 'data-dispute-filter'), 'Dispute filters missing.');
dispute_v1_check(str_contains($js, 'window.confirm'), 'Destructive dispute confirmations missing.');
dispute_v1_check(!str_contains($js, 'fetch('), 'Dispute browser must not bypass server forms with direct API fetches.');
dispute_v1_check(str_contains($css, '@media (max-width:35rem)'), 'Dispute mobile layout missing.');
dispute_v1_check(str_contains($libraries, 'js/disputes-v1.js'), 'Dispute JS library is not wired.');
dispute_v1_check(str_contains($libraries, 'css/disputes-v1.css'), 'Dispute CSS library is not wired.');
dispute_v1_check(str_contains($provider, 'permissionKeys'), 'Operations permission-map support missing.');
dispute_v1_check(str_contains($theme, 'merdpos_pending_disputes'), 'Shell dispute badge source missing.');
dispute_v1_check(str_contains($page, 'merdpos-bottom-nav-badge'), 'Shell dispute badge markup missing.');
foreach (['disputes.submit_own','disputes.review','attendance_flags.resolve'] as $permission) {
  dispute_v1_check(str_contains($api, $permission), "Canonical dispute permission missing: {$permission}");
}
foreach (['create','decide','cancel','confirm_handover','reject_handover','resolve_flag'] as $action) {
  dispute_v1_check(str_contains($api, "action === '{$action}'") || str_contains($api, "action === \"{$action}\""), "Canonical disputes API action missing: {$action}");
}

echo "MERDPOS Drupal Dispute Write Parity v1 contract validated.\n";
