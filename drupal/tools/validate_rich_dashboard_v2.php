<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/web/modules/custom/merdpos_core/src/Presentation/DashboardChartBuilder.php';
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
function dashboard_v2_check(bool $ok,string $m): void {if(!$ok)throw new RuntimeException($m);}
$root=dirname(__DIR__);$composer=json_decode((string)file_get_contents($root.'/composer.json'),true);$required=$composer['require']??[];
dashboard_v2_check(isset($required['drupal/charts'])&&isset($required['google/charts']),'Dashboard chart dependencies missing.');
$deploy=(string)file_get_contents($root.'/tools/namecheap_deploy.sh');dashboard_v2_check(str_contains($deploy,'charts_google')&&str_contains($deploy,'libraries/google_charts/loader.js'),'Google Charts deploy guard missing.');
$provider=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');$template=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/templates/merdpos-dashboard.html.twig');$css=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/css/dashboard-v2.css');
$qa=strpos($provider,'private function dashboardQuery');$qb=strpos($provider,'private function permissionKeys',$qa);$dashboardQuery=($qa!==false&&$qb!==false)?substr($provider,$qa,$qb-$qa):'';
dashboard_v2_check(str_contains($provider,"['value'=>'current_week','label'=>'Working Week']")&&str_contains($dashboardQuery,"?? 'current_week'"),'Working Week is not the default Dashboard period.');
dashboard_v2_check(str_contains($provider,"'name'=>'period', 'label'=>'Select View'")&&str_contains($provider,'Centralized view of your key metrics, activity, and controls. Monitor status in real time, catch issues early, and act without leaving the page.'),'Dashboard copy/View label contract missing.');
dashboard_v2_check(!str_contains($template,'name="store_id"')&&!str_contains($dashboardQuery,"store_id")&&!str_contains($dashboardQuery,'$storeId'),'Drupal Dashboard must remain fixed to All stores.');
dashboard_v2_check(str_contains($template,'merdpos-dashboard-filters--hero')&&str_contains($template,'aria-label="Dashboard view"'),'Select View control is not in the Dashboard hero.');
dashboard_v2_check(!str_contains($template,'merdpos-dashboard-roleline')&&!str_contains($template,'merdpos-dashboard-layout-bar'),'Removed Dashboard roleline/layout bar returned.');
dashboard_v2_check(str_contains($template,'surface.dashboard_widgets')&&str_contains($template,'attribute(charts, widget.chart_key)'),'Dashboard widget/chart output regressed.');
dashboard_v2_check(str_contains($template,'No direct operational DB access'),'Operational DB boundary label missing.');
dashboard_v2_check(str_contains($css,'.merdpos-dashboard-v2 { overflow-x: clip; }')&&str_contains($css,'.merdpos-dashboard-filters--hero'),'Dashboard mobile/hero filter styling missing.');
$builder=new DashboardChartBuilder();$charts=$builder->build([['key'=>'sales_trend_7d','type'=>'line','labels'=>['09-03'],'values'=>[100],'series_label'=>'Sales','color'=>'#1c4587'],['key'=>'cash_mix','type'=>'donut','labels'=>['Register','Petty Cash'],'values'=>[100,25],'series_label'=>'Balance','color'=>'#1c4587','colors'=>['#1c4587','#23a6a8']]]);
dashboard_v2_check(count($charts)===2&&($charts['sales_trend_7d']['#chart_library']??'')==='google'&&($charts['cash_mix']['#chart_type']??'')==='donut','Dashboard chart builder regression.');
echo "MERDPOS Drupal rich Dashboard + Working Week contract validated.\n";
