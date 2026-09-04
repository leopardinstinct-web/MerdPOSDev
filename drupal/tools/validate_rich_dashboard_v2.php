<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Presentation/DashboardChartBuilder.php';

use Drupal\merdpos_core\Presentation\DashboardChartBuilder;

function dashboard_v2_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
dashboard_v2_check(is_array($composer), 'composer.json is invalid JSON.');
$required = $composer['require'] ?? [];
dashboard_v2_check(isset($required['drupal/charts']), 'Drupal Charts is not required.');
dashboard_v2_check(isset($required['google/charts']), 'Google Charts loader is not Composer-managed.');

$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');
dashboard_v2_check(str_contains($deploy, 'charts_google'), 'Deployment does not enable charts_google.');
dashboard_v2_check(str_contains($deploy, 'Rich dashboard v2 self-test failed.'), 'Deployment dashboard v2 self-test is missing.');
dashboard_v2_check(str_contains($deploy, 'libraries/google_charts/loader.js'), 'Deployment does not guard the Google Charts loader.');

$userRole = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/config/install/user.role.merdpos_user.yml');
dashboard_v2_check(str_contains($userRole, "view merdpos management dashboard"), 'MERDPOS USER role cannot access role-aware Home.');
dashboard_v2_check(str_contains($deploy, 'merdpos_user'), 'Deployment does not reconcile the existing MERDPOS USER role.');

$provider = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
dashboard_v2_check(str_contains($provider, "allowed_widgets"), 'Provider does not consume allowed_widgets.');
dashboard_v2_check(str_contains($provider, "visible_widget_count"), 'Provider does not expose visible widget count.');
dashboard_v2_check(str_contains($provider, "sales_trend_7d"), 'Sales trend widget is missing.');
dashboard_v2_check(str_contains($provider, "cash_mix"), 'Cash mix widget is missing.');
$template = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-dashboard.html.twig');
dashboard_v2_check(str_contains($template, 'surface.role.loa'), 'Dashboard does not expose role LOA.');
dashboard_v2_check(str_contains($template, 'surface.dashboard_widgets'), 'Dashboard widget loop is missing.');
dashboard_v2_check(str_contains($template, 'attribute(charts, widget.chart_key)'), 'Drupal Charts render output is not wired.');
dashboard_v2_check(str_contains($template, 'No direct operational DB access'), 'Operational DB boundary label is missing.');

$css = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/dashboard-v2.css');
dashboard_v2_check(str_contains($css, '.merdpos-dashboard-grid'), 'Rich dashboard grid CSS is missing.');
dashboard_v2_check(str_contains($css, '@media'), 'Responsive dashboard CSS is missing.');
dashboard_v2_check(str_contains($css, '.merdpos-dashboard-kpi'), 'Rich KPI treatment is missing.');

$builder = new DashboardChartBuilder();
$charts = $builder->build([
  [
    'key'=>'sales_trend_7d','type'=>'line','labels'=>['09-03','09-04'],
    'values'=>[100,123.45],'series_label'=>'Completed sales','color'=>'#1c4587','height'=>280,
  ],
  [
    'key'=>'cash_mix','type'=>'donut','labels'=>['Register','Petty Cash'],
    'values'=>[100,25],'series_label'=>'Balance','color'=>'#1c4587',
    'colors'=>['#1c4587','#23a6a8'],'height'=>280,
  ],
]);
dashboard_v2_check(count($charts) === 2, 'Chart builder output count mismatch.');
dashboard_v2_check(($charts['sales_trend_7d']['#chart_library'] ?? '') === 'google', 'Charts provider is not Google Charts.');
dashboard_v2_check(($charts['sales_trend_7d']['#chart_type'] ?? '') === 'line', 'Sales trend chart type mismatch.');
dashboard_v2_check(($charts['cash_mix']['#chart_type'] ?? '') === 'donut', 'Cash mix chart type mismatch.');
dashboard_v2_check(count($charts['cash_mix']['#colors'] ?? []) === 2, 'Cash mix chart colors missing.');

echo "MERDPOS Drupal rich dashboard v2 validated.\n";
