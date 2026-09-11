<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$templates = [
  'dashboard' => 'web/modules/custom/merdpos_core/templates/merdpos-dashboard.html.twig',
  'administration' => 'web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig',
  'dev' => 'web/modules/custom/merdpos_core/templates/merdpos-dev.html.twig',
  'finance' => 'web/modules/custom/merdpos_core/templates/merdpos-finance.html.twig',
  'reports' => 'web/modules/custom/merdpos_core/templates/merdpos-reports.html.twig',
  'operations' => 'web/modules/custom/merdpos_core/templates/merdpos-operations.html.twig',
  'disputes' => 'web/modules/custom/merdpos_core/templates/merdpos-disputes.html.twig',
  'surface' => 'web/modules/custom/merdpos_core/templates/merdpos-surface.html.twig',
  'section' => 'web/modules/custom/merdpos_core/templates/merdpos-section.html.twig',
];
$required = ['merdpos-page-header','merdpos-page-header__copy','merdpos-page-header__eyebrow','merdpos-page-header__title','merdpos-page-header__description'];
$errors = [];
foreach ($templates as $name => $rel) {
  $text = file_get_contents($root . '/' . $rel);
  if ($text === false) { $errors[] = "$name: template unreadable"; continue; }
  foreach ($required as $class) if (!str_contains($text, $class)) $errors[] = "$name: missing $class";
  foreach (['roleline','merdpos-disputes-role','merdpos-admin-badges'] as $forbidden) {
    if (str_contains($text, $forbidden)) $errors[] = "$name: forbidden header role metadata token $forbidden";
  }
}
$libraries = file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.libraries.yml') ?: '';
if (!str_contains($libraries, 'css/page-header.css: {}')) $errors[] = 'base library: global page-header.css is not wired';
if (str_contains($libraries, 'hero-shared.css')) $errors[] = 'libraries: retired hero-shared.css is still wired';
$global = file_get_contents($root . '/web/modules/custom/merdpos_core/css/page-header.css') ?: '';
foreach ($required as $class) if (!str_contains($global, '.' . $class)) $errors[] = "page-header.css: missing .$class";
$legacyCss = [
  'dashboard-v2.css' => ['.merdpos-dashboard-hero{','.merdpos-dashboard-hero {','.merdpos-dashboard-hero h1'],
  'reports-v2.css' => ['.merdpos-reports-hero{','.merdpos-reports-hero {','.merdpos-reports-copy h1','.merdpos-reports-roleline'],
  'finance-v2.css' => ['.merdpos-finance-hero{','.merdpos-finance-hero {','.merdpos-finance-copy h1','.merdpos-finance-roleline'],
  'dev-v2.css' => ['.merdpos-dev-hero{','.merdpos-dev-hero {','.merdpos-dev-hero h1','.merdpos-dev-roleline'],
  'administration-v1.css' => ['.merdpos-admin-hero{','.merdpos-admin-hero {','.merdpos-admin-hero h1','.merdpos-admin-badges'],
  'operations-v2.css' => ['.merdpos-ops-hero{','.merdpos-ops-hero {','.merdpos-ops-hero h1','.merdpos-ops-roleline'],
  'disputes-v1.css' => ['.merdpos-disputes-hero{','.merdpos-disputes-hero {','.merdpos-disputes-hero h1','.merdpos-disputes-role'],
];
foreach ($legacyCss as $file => $tokens) {
  $text = file_get_contents($root . '/web/modules/custom/merdpos_core/css/' . $file) ?: '';
  foreach ($tokens as $token) if (str_contains($text, $token)) $errors[] = "$file: page-specific header CSS remains ($token)";
}
$shell = file_get_contents($root . '/web/themes/custom/merdpos_app/templates/page.html.twig') ?: '';
$pillsPos = strpos($shell, 'class="merdpos-account-pills"');
$triggerPos = strpos($shell, 'class="merdpos-account-trigger"');
if ($pillsPos === false || $triggerPos === false || $pillsPos > $triggerPos) $errors[] = 'shell: working-context pills must be before account trigger';
$dark = file_get_contents($root . '/web/modules/custom/merdpos_core/css/dark-normalization.css') ?: '';
foreach (['merdpos-dashboard-hero','merdpos-reports-hero','merdpos-finance-hero','merdpos-dev-hero','merdpos-ops-hero'] as $legacy) {
  if (str_contains($dark, $legacy)) $errors[] = "dark-normalization.css: legacy page-header selector remains ($legacy)";
}
if ($errors) {
  fwrite(STDERR, "Global page-header contract failed:\n - " . implode("\n - ", $errors) . "\n");
  exit(1);
}
echo "Global page-header contract OK: " . count($templates) . " page templates governed by css/page-header.css; rolelines absent; account pills precede trigger.\n";
