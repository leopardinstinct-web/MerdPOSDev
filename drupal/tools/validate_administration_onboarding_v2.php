<?php
declare(strict_types=1);

function onboarding_v2_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = file_get_contents($module . '/src/Controller/AdministrationController.php');
$provisioner = file_get_contents($module . '/src/Integration/AdministrationOnboardingProvisioner.php');
$template = file_get_contents($module . '/templates/merdpos-administration.html.twig');
$js = file_get_contents($module . '/js/administration-v1.js');
$css = file_get_contents($module . '/css/administration-v1.css');
$services = file_get_contents($module . '/merdpos_core.services.yml');
foreach ([$controller,$provisioner,$template,$js,$css,$services] as $source) onboarding_v2_check(is_string($source), 'Onboarding v2 source is unreadable.');

onboarding_v2_check(str_contains($controller, "'onboard_client' =>"), 'Onboarding action is not wired.');
onboarding_v2_check(str_contains($controller, "administration_onboarding"), 'Onboarding service injection is missing.');
onboarding_v2_check(str_contains($services, 'merdpos_core.administration_onboarding:'), 'Onboarding service definition is missing.');
onboarding_v2_check(str_contains($controller, "->provision("), 'Onboarding controller delegation is missing.');
onboarding_v2_check(substr_count($provisioner, "call('admin_directory'") >= 3, 'Onboarding must reuse authoritative administration gateway calls.');
onboarding_v2_check(str_contains($provisioner, "call('clients', 'POST'"), 'Onboarding client write is not signed through the gateway.');
onboarding_v2_check(str_contains($provisioner, "'redirect_tab'=>'workforce'"), 'Successful onboarding must land in workforce context.');
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE '] as $forbidden) {
  onboarding_v2_check(!str_contains($controller, $forbidden) && !str_contains($provisioner, $forbidden), "Drupal onboarding must not contain operational SQL: {$forbidden}");
}
foreach ([
  'data-admin-panel="onboarding"',
  'name="entity_action" value="onboard_client"',
  'onboard_client_name','onboard_store_name','onboard_admin_name',
  'onboard_admin_password','data-onboard-submit',
] as $marker) onboarding_v2_check(str_contains($template, $marker), "Onboarding template marker missing: {$marker}");

onboarding_v2_check(str_contains($js, "new URLSearchParams(window.location.search).get('tab')"), 'Onboarding redirect tab restoration is missing.');
onboarding_v2_check(str_contains($js, "data-onboard-form"), 'Onboarding submit guard is missing.');
onboarding_v2_check(str_contains($css, '.merdpos-onboard-steps'), 'Onboarding stepper CSS is missing.');
onboarding_v2_check(str_contains($css, '@media (max-width:700px)'), 'Onboarding mobile CSS is missing.');
onboarding_v2_check(!str_contains($template, '|raw'), 'Onboarding template must preserve Twig escaping.');

echo "MERDPOS Drupal Administration & Onboarding v2 contract validated.\n";
