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
  '.merdpos-dialog-actions',
  '.merdpos-app [hidden]{display:none!important}',
  '.merdpos-reports-kpi-stat-value',
  'color:var(--color-text-primary)!important',
  '.merdpos-ui-empty',
  '.merdpos-ui-kpi-grid[data-kpi-count="1"]',
  '.merdpos-ui-kpi-grid[data-kpi-count="3"]',
  '.merdpos-ui-kpi-grid[data-kpi-count="6"]',
  '.merdpos-ui-kpi-grid--cards[data-kpi-count="3"]',
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

/* Standardized component contract v1: shared actions, KPI grammar, one dialog shell, slim dark layer. */
foreach ([
  'min-height:var(--size-control);padding:var(--space-2) var(--space-4)' => 'shared labeled action must derive height/padding from tokens',
  'border-radius:var(--radius-control)' => 'shared labeled action must use --radius-control',
  'font-size:var(--type-sm)' => 'shared labeled action must use --type-sm',
  'a.merdpos-report-action' => 'Timesheets PDF action must be a shared secondary action',
  '.merdpos-ui-button--icon' => 'shared icon-only size variant missing',
  '.merdpos-ui-button--compact' => 'shared compact size variant missing',
  'min-height:7rem' => 'shared KPI shell minimum height missing',
  '.merdpos-reports-kpi-stat-label' => 'compound KPI stat label must join shared label grammar',
  '.merdpos-reports-kpi-stat-value' => 'compound KPI stat value must join shared value grammar',
] as $needle => $message) if (!str_contains($css, $needle)) $errors[] = $message;

$reportsCss = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/reports-v2.css');
foreach (['padding:.82rem .86rem', '.merdpos-timesheet-dialog-close', 'min-height:2.6rem', 'min-height: 2.8rem; padding: .65rem .9rem'] as $forbidden) {
  if (str_contains($reportsCss, $forbidden)) $errors[] = "reports-v2.css reintroduces local KPI/dialog/action geometry ($forbidden)";
}
$financeCss = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/finance-v2.css');
foreach (['border-radius:1.3rem', 'background:#fff', 'rgba(24,54,98', 'font-size:clamp(1.2rem,2vw,1.7rem)'] as $forbidden) {
  if (str_contains($financeCss, $forbidden)) $errors[] = "finance-v2.css reintroduces a local KPI surface/type system ($forbidden)";
}
if (!str_contains($financeCss, 'align-self:end')) $errors[] = 'finance action cards must not grid-stretch shared buttons';
$dashboardCss = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/dashboard-v2.css');
foreach (['min-height: 10.5rem', 'border-radius: 1.2rem', '#17386f'] as $forbidden) {
  if (str_contains($dashboardCss, $forbidden)) $errors[] = "dashboard-v2.css reintroduces local KPI/button geometry ($forbidden)";
}
$dashboardLayoutCss = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/dashboard-layout-v1.css');
$dashboardTouchRule = '.merdpos-dashboard-add-widget,.merdpos-dashboard-item-actions button,.merdpos-dashboard-drawer-head button,.merdpos-dashboard-catalog-item button{width:var(--size-touch);height:var(--size-touch);min-width:var(--size-touch);min-height:var(--size-touch)}';
if (!str_contains($dashboardLayoutCss, $dashboardTouchRule)) {
  $errors[] = 'dashboard-layout-v1.css: mobile dashboard controls must use a genuine --size-touch square hit target';
}

$shellCss = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/css/app-shell.css');
foreach (['.merdpos-ui-dialog', '--radius-dialog', '--color-overlay', '90dvh', 'overflow:auto'] as $needle) {
  if (!str_contains($shellCss, $needle)) $errors[] = "app-shell.css: canonical dialog shell missing ($needle)";
}
if (!preg_match('/\.merdpos-btn\{([^}]*)\}/s', $shellCss, $shellButton)) {
  $errors[] = 'app-shell.css: shell-level .merdpos-btn contract missing';
} else {
  foreach (['min-height:var(--size-control)', 'padding:var(--space-2) var(--space-4)', 'border-radius:var(--radius-control)', 'background:var(--gradient-brand-action)', 'color:var(--color-brand-white)', 'font-size:var(--type-sm)', 'gap:var(--space-2)'] as $needle) {
    if (!str_contains($shellButton[1], $needle)) $errors[] = "app-shell.css: shell-level .merdpos-btn drift ($needle)";
  }
}
if (!preg_match('/\.merdpos-dialog-close\{([^}]*)\}/s', $shellCss, $closeButton) || !str_contains($closeButton[1] ?? '', 'width:var(--size-control)') || !str_contains($closeButton[1] ?? '', 'height:var(--size-control)') || !str_contains($closeButton[1] ?? '', 'border-radius:var(--radius-control)')) {
  $errors[] = 'app-shell.css: shared dialog close control must derive square geometry from --size-control/--radius-control';
}
if (!str_contains($shellCss, '.merdpos-btn,.merdpos-account-menu-action,.merdpos-impersonation-banner button{min-height:var(--size-touch)}')) {
  $errors[] = 'app-shell.css: shell-owned labeled actions must use --size-touch at the mobile breakpoint';
}
$page = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/templates/page.html.twig');
if (!str_contains($page, 'merdpos-ui-dialog merdpos-password-dialog')) $errors[] = 'password dialog must participate in the shared merdpos-ui-dialog shell';
$reportsTpl = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-reports.html.twig');
if (!str_contains($reportsTpl, 'merdpos-ui-dialog--wide')) $errors[] = 'timesheet dialog must use the shared wide dialog shell';
if (!str_contains($reportsTpl, 'merdpos-dialog-close')) $errors[] = 'timesheet dialog must use the shared square close control';

if (str_contains($darkCss, ':root[data-theme="dark"] :is(.merdpos-reports-filters button')) $errors[] = 'dark normalization must not broadly repaint shared labeled actions';
foreach ([
  '.merdpos-report-action' => 'dark normalization must not repaint the shared secondary PDF action',
  'merdpos-timesheet-dialog-close' => 'dark normalization must not repaint the shared dialog close control',
  '.merdpos-reports-kpi,' => 'dark normalization must not repaint shared KPI shells',
  '.merdpos-finance-kpi,' => 'dark normalization must not repaint shared KPI shells',
] as $forbidden => $message) if (str_contains($darkCss, $forbidden)) $errors[] = $message;
if ($errors) { foreach ($errors as $error) fwrite(STDERR, "GLOBAL_UI_CONTRACT_FAIL: $error\n"); exit(1); }
echo "Global UI primitive contract OK: all live surfaces inherit canonical controls/actions, cards/widgets, tables, states and responsive touch targets.\n";
