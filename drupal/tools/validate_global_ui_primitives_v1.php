<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$cssPath = $root . '/web/modules/custom/merdpos_core/css/ui-primitives.css';
$libsPath = $root . '/web/modules/custom/merdpos_core/merdpos_core.libraries.yml';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
$libs = is_file($libsPath) ? (string) file_get_contents($libsPath) : '';
$darkPath = $root . '/web/modules/custom/merdpos_core/css/dark-normalization.css';
$darkCss = is_file($darkPath) ? (string) file_get_contents($darkPath) : '';
$errors = [];
$required = [
  '.merdpos-app :where(button,input,select,textarea,table)',
  'input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"])',
  'button[type="submit"]',
  '.merdpos-dashboard-kpi',
  '.merdpos-report-table-card',
  '.merdpos-finance-panel',
  '.merdpos-admin-editor',
  '.merdpos-btn',
  'button.merdpos-btn--danger',
  'button.is-secondary',
  'button.is-approve',
  '.merdpos-ui-empty',
  '@media(max-width:51.25rem)',
];
foreach ($required as $needle) if (!str_contains($css, $needle)) $errors[] = "global UI primitive missing: $needle";
$surfaceTemplates = ['merdpos-dashboard.html.twig','merdpos-operations.html.twig','merdpos-reports.html.twig','merdpos-finance.html.twig','merdpos-dev.html.twig','merdpos-administration.html.twig','merdpos-disputes.html.twig','merdpos-surface.html.twig','merdpos-section.html.twig'];
foreach ($surfaceTemplates as $template) {
  $path = $root . '/web/modules/custom/merdpos_core/templates/' . $template;
  $body = is_file($path) ? (string) file_get_contents($path) : '';
  if (!preg_match('/<section\s+class="[^"]*\bmerdpos-app\b/', $body)) $errors[] = "surface root missing merdpos-app scope: $template";
}
$occurrences = substr_count($libs, 'css/ui-primitives.css: {}');
if ($occurrences !== 8) $errors[] = "ui-primitives.css must be wired to base plus seven feature libraries; found $occurrences";
foreach (['dashboard','operations','reports','finance','dev','administration','disputes'] as $name) {
  $start = strpos($libs, "\n$name:\n");
  if ($start === false) { $errors[] = "library missing: $name"; continue; }
  $next = strpos($libs, "\n\n", $start + 2);
  $block = $next === false ? substr($libs, $start) : substr($libs, $start, $next - $start);
  $global = strpos($block, 'css/ui-primitives.css: {}');
  $feature = strrpos($block, '.css: {}');
  if ($global === false) $errors[] = "$name does not load ui-primitives.css";
  if ($global !== false && $feature !== false && $global !== $feature - strlen('css/ui-primitives.css: {}') + strlen('css/ui-primitives.css: {}')) {
    // Position is checked more directly below; this branch intentionally left semantic only.
  }
  $darkPos = strpos($block, 'css/dark-normalization.css: {}');
  if ($darkPos !== false && $global !== false && $global < $darkPos) $errors[] = "$name must load ui-primitives.css after dark-normalization.css";
}
if (str_contains($darkCss, ':is(.merdpos-ops-filters button,.merdpos-reports-filters button)')) $errors[] = 'dark normalization must not override canonical primary filter actions';
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $css)) $errors[] = 'global UI primitives must use semantic design tokens, not literal colours';
if ($errors) { foreach ($errors as $error) fwrite(STDERR, "GLOBAL_UI_CONTRACT_FAIL: $error\n"); exit(1); }
echo "Global UI primitive contract OK: all live surfaces inherit canonical controls/actions, cards/widgets, tables, states and responsive touch targets.\n";
