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

$swatches = ['#01102B','#0747FC','#005FF7','#0A91FB','#3B3FA0','#591DE9','#7943F5'];
foreach ($swatches as $hex) if (!str_contains($manager, $hex)) $errors[] = "approved logo swatch missing: $hex";
foreach (['foundation','accent','secondary'] as $role) if (!str_contains($manager, "'$role'")) $errors[] = "required palette role missing: $role";
if (str_contains($template, 'type="color"') || preg_match('/name="(?:hex|color)"/i', $template)) $errors[] = 'palette UI must not expose arbitrary color entry';
if (!str_contains($template, 'data-brand-palette-form') || !str_contains($template, 'Add logo colour') || !str_contains($template, 'Reset defaults')) $errors[] = 'DEV palette editor controls missing';
if (!str_contains($routing, "path: '/merdpos/dev/palette'") || !str_contains($routing, 'methods: [POST]')) $errors[] = 'palette route must be POST-only';
if (!str_contains($controller, 'private function isActualDev()') || !str_contains($controller, '$key === \'DEV\'')) $errors[] = 'palette write path must require actual DEV identity';
if (!str_contains($controller, "validate((string) \$request->request->get('form_token', ''), self::PALETTE_TOKEN_ID)")) $errors[] = 'palette write path must validate CSRF token';
if (!str_contains($services, 'merdpos_core.brand_palette:') || !str_contains($services, "arguments: ['@state']")) $errors[] = 'palette state service wiring missing';
if (!str_contains($theme, "service('merdpos_core.brand_palette')->cssVariables()")) $errors[] = 'runtime palette variables are not injected globally';
if (!str_contains($js, "root.style.setProperty(variable, hex)")) $errors[] = 'DEV live palette preview missing';
if (!str_contains($deploy, 'BRAND_PALETTE_V1_PROBE=') || !str_contains($deploy, 'Brand Palette v1 self-test failed.')) $errors[] = 'deployment runtime palette probe missing';

foreach (['--color-brand-navy: #01102B','--color-brand-cyan: #0A91FB','--color-brand-violet: #591DE9'] as $fallback) if (!str_contains($tokens, $fallback)) $errors[] = "logo-derived fallback token missing: $fallback";
if (!str_contains($tokens, '--color-brand-background: color-mix(')) $errors[] = 'page canvas must derive from active foundation role';
if (!str_contains($tokens, '--color-info: var(--color-brand-cyan)')) $errors[] = 'information colour must use active brand accent';
if (!str_contains($tokens, '--color-amber: #8A5300') || !str_contains($tokens, '--color-warning: var(--color-amber)')) $errors[] = 'global Amber semantic token / warning alias missing';
if (str_contains($tokens, '--color-chart-4: var(--color-success)') || str_contains($tokens, '--color-chart-5: var(--color-warning)')) $errors[] = 'chart palette must remain brand-derived';
foreach (['Red, green and amber are the only non-brand hue exceptions','Arbitrary color input is not permitted'] as $needle) if (!str_contains($invariants, $needle)) $errors[] = "binding palette invariant missing: $needle";
if (!str_contains($manager, "count(\$state['entries']) <= 3")) $errors[] = 'palette minimum-entry deletion guard missing';
if (!str_contains($manager, "in_array(\$id, array_values(\$state['roles']), true)")) $errors[] = 'in-use palette deletion guard missing';
if (!str_contains($manager, 'count(array_unique($cleanRoles))')) $errors[] = 'distinct required role guard missing';

if ($errors) {
  foreach ($errors as $error) fwrite(STDERR, "BRAND_PALETTE_V1_FAIL: $error\n");
  exit(1);
}
echo "MERDPOS Drupal brand palette v1 validated.\n";
