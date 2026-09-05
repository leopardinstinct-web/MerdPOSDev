<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
  'controller' => $root . '/web/modules/custom/merdpos_core/src/Controller/AdministrationController.php',
  'gateway' => $root . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClient.php',
  'twig' => $root . '/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig',
  'libraries' => $root . '/web/modules/custom/merdpos_core/merdpos_core.libraries.yml',
  'dark' => $root . '/web/modules/custom/merdpos_core/css/dark-normalization.css',
];
foreach ($files as $label => $path) {
  if (!is_file($path)) { fwrite(STDERR, "Missing $label file: $path\n"); exit(1); }
  $files[$label] = (string) file_get_contents($path);
}
$required = [
  ['controller', "DateTimeZone::listIdentifiers()"],
  ['controller', "currencyOptions()"],
  ['controller', "store_timings"],
  ['controller', "store_logo"],
  ['controller', "logo_base64"],
  ['twig', 'name="timezone"'],
  ['twig', 'name="currency_code"'],
  ['twig', 'name="days[{{ d }}][start_time]"'],
  ['twig', 'name="logo"'],
  ['libraries', 'css/dark-normalization.css'],
  ['dark', ':root[data-theme="dark"]'],
];
foreach ($required as [$file, $needle]) {
  if (!str_contains($files[$file], $needle)) {
    fwrite(STDERR, "Missing contract marker in $file: $needle\n");
    exit(1);
  }
}
if (substr_count($files['libraries'], 'css/dark-normalization.css') < 7) {
  fwrite(STDERR, "Dark normalization is not attached after every MERDPOS surface stylesheet.\n");
  exit(1);
}
if (!str_contains($files['dark'], '.merdpos-dashboard-v2') || !str_contains($files['dark'], '.merdpos-operations-v2') || !str_contains($files['dark'], '.merdpos-reports-v2')) {
  fwrite(STDERR, "Primary app surfaces are missing dark-mode normalization.\n");
  exit(1);
}
echo "MERDPOS Store Settings + App Dark Mode v1 contract validated.\n";
