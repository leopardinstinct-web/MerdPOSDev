<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manager = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/src/Presentation/BrandPaletteManager.php');
$controller = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/src/Controller/DevController.php');
$template = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-dev.html.twig');
$tokens = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/design-tokens.css');
$theme = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$routing = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
$services = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.services.yml');
$js = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/js/dev-v2.js');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');
$invariants = (string) file_get_contents(dirname($root) . '/.ai/invariants.md');
$errors = [];

// DEV palette proposal editor: approved logo-swatch constraints and required roles.
$swatches = ['#01102B','#0747FC','#005FF7','#0A91FB','#3B3FA0','#591DE9','#7943F5'];
foreach ($swatches as $hex) if (!str_contains($manager, $hex)) $errors[] = "approved logo swatch missing: $hex";
foreach (['foundation','accent','secondary'] as $role) if (!str_contains($manager, "'$role'")) $errors[] = "required palette role missing: $role";
if (str_contains($template, 'type="color"') || preg_match('/name="(?:hex|color)"/i', $template)) $errors[] = 'palette UI must not expose arbitrary color entry';
if (!str_contains($template, 'data-brand-palette-form') || !str_contains($template, 'Add logo colour') || !str_contains($template, 'Reset proposal defaults')) $errors[] = 'DEV palette proposal editor controls missing';

// Actual-DEV-only, POST-only, CSRF-protected write path.
if (!str_contains($routing, "path: '/merdpos/dev/palette'") || !str_contains($routing, 'methods: [POST]')) $errors[] = 'palette route must be POST-only';
if (!str_contains($controller, 'private function isActualDev()') || !str_contains($controller, '$key === \'DEV\'')) $errors[] = 'palette write path must require actual DEV identity';
if (!str_contains($controller, "validate((string) \$request->request->get('form_token', ''), self::PALETTE_TOKEN_ID)")) $errors[] = 'palette write path must validate CSRF token';
if (!str_contains($services, 'merdpos_core.brand_palette:') || !str_contains($services, "arguments: ['@state']")) $errors[] = 'palette state service wiring missing';

// Proposal state is preview/handoff only: nothing global may consume cssVariables().
if (str_contains($theme, "service('merdpos_core.brand_palette')->cssVariables()")) $errors[] = 'merdpos_app.theme must not globally inject persisted DEV palette variables; canonical design-tokens.css owns runtime';

// DEV template must state preview/handoff-only semantics truthfully.
foreach (['PREVIEW / HANDOFF ONLY', 'not the live master', 'Save palette proposal', 'do not affect live design-system state'] as $needle) {
  if (!str_contains($template, $needle)) $errors[] = "DEV template preview/handoff wording missing: $needle";
}
if (!str_contains($manager, 'Palette proposal saved for DEV preview/handoff only')) $errors[] = 'palette save success message must describe a DEV preview/handoff proposal, not a global/master apply';

// Page-local JS live preview, applied on attach.
if (!str_contains($js, "root.style.setProperty(variable, hex)")) $errors[] = 'DEV live palette preview missing';
if (!str_contains($js, 'preview();')) $errors[] = 'saved proposal must preview on the DEV page immediately on attach';
if (!str_contains($js, 'on this page only')) $errors[] = 'JS preview must state it is page-local and never global';

if (!str_contains($deploy, 'BRAND_PALETTE_V1_PROBE=') || !str_contains($deploy, 'Brand Palette v1 self-test failed.')) $errors[] = 'deployment runtime palette probe missing';

// Binding five-color master palette owned by canonical design tokens.
foreach ([
  '--color-brand-white: #FFFFFF',
  '--color-brand-background: #F5F7FC',
  '--color-brand-navy: #031B4B',
  '--color-brand-cyan: #12BDF3',
  '--color-brand-violet: #8B2EFF',
] as $token) {
  if (!str_contains($tokens, $token)) $errors[] = "canonical five-color master token missing: $token";
}
if (!str_contains($tokens, '--gradient-brand: linear-gradient(115deg, var(--color-brand-cyan) 0%, var(--color-brand-violet) 100%)')) $errors[] = 'canonical cyan/violet brand gradient missing';
if (!str_contains($tokens, '--gradient-brand-action:')) $errors[] = '--gradient-brand-action token must be preserved';

// Operational semantic tokens remain separate from the brand master palette.
// Canonical info is exactly #175CD3, warning is #8A5300 with an amber alias;
// chart-4 must map to success and chart-5 to warning.
foreach (['--color-success: #18794E', '--color-warning: #8A5300', '--color-amber: var(--color-warning)', '--color-danger:', '--color-info: #175CD3'] as $needle) {
  if (!str_contains($tokens, $needle)) $errors[] = "separate operational semantic token missing: $needle";
}
foreach (['--color-chart-4: var(--color-success)', '--color-chart-5: var(--color-warning)'] as $needle) {
  if (!str_contains($tokens, $needle)) $errors[] = "chart slot must be exactly: $needle";
}

// Binding invariant wording must match current five-color master / preview semantics.
foreach (['#031B4B', '#12BDF3', '#8B2EFF', 'preview/handoff'] as $needle) {
  if (!str_contains($invariants, $needle)) $errors[] = "binding palette invariant missing or stale: $needle";
}

// Proposal state guards.
if (!str_contains($manager, "count(\$state['entries']) <= 3")) $errors[] = 'palette minimum-entry deletion guard missing';
if (!str_contains($manager, "in_array(\$id, array_values(\$state['roles']), true)")) $errors[] = 'in-use palette deletion guard missing';
if (!str_contains($manager, 'count(array_unique($cleanRoles))')) $errors[] = 'distinct required role guard missing';

if ($errors) {
  foreach ($errors as $error) fwrite(STDERR, "BRAND_PALETTE_V1_FAIL: $error\n");
  exit(1);
}
echo "MERDPOS Drupal brand palette v1 validated.\n";
