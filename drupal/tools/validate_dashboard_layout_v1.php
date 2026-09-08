<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php';

use Drupal\merdpos_core\Integration\ParityDataProvider;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Integration\WorkingNowProviderInterface;

function layout_v1_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

final class LayoutWorkingNowStub implements WorkingNowProviderInterface {
  public function load(): array {
    return ['status'=>'ok','count'=>0,'people'=>[],'message'=>'ok','generated_at'=>NULL];
  }
}

final class LayoutGatewayStub implements PortalGatewayClientInterface {
  public array $calls = [];

  public function __construct(private readonly array $savedLayout = [['widget_key'=>'active_employees','grid_x'=>3,'grid_y'=>2,'grid_w'=>4,'grid_h'=>2]]) {}

  public function call(string $route, string $method = 'GET', array $query = [], array $body = [], ?int $contextClientId = NULL): array {
    $this->calls[] = [$route,$method,$query,$body];
    $payload = match ($route) {      'dashboard_layout' => [
        'success'=>true,'can_edit'=>true,'can_select_role'=>true,'context_client_id'=>1,
        'selected_role'=>['id'=>7,'role_key'=>'DEV','role_label'=>'Developer','base_role'=>'DEV','authority_level'=>1000],
        'roles'=>[['id'=>7,'role_key'=>'DEV','role_label'=>'Developer','authority_level'=>1000,'allowed_widget_count'=>2]],
        'allowed_widgets'=>['working_now_count','active_employees'],
        'layout'=>$this->savedLayout,
        'grid'=>['columns'=>12,'max_rows'=>1000],
      ],
      'dashboard_data' => [
        'success'=>true,
        'role'=>['id'=>7,'role_key'=>'DEV','role_label'=>'Developer','base_role'=>'DEV','authority_level'=>1000],
        'allowed_widgets'=>['working_now_count','active_employees'],
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
        'filters'=>['store_id'=>0,'period'=>'7','days'=>7,'period_label'=>'7 days'],
        'filter_options'=>['stores'=>[]],
        'working_count'=>9,
        'management'=>['business_date'=>'2026-09-08','currency_code'=>'AUD','timezone'=>'Australia/Sydney','active_employees'=>23,'analytics'=>[]],
      ],
      'beta_state' => [
        'success'=>true,'role_key'=>'DEV','role_label'=>'Developer','authority_level'=>1000,
        'permissions'=>['dashboard.view'=>true,'dashboard.configure'=>true],
      ],
      default => ['success'=>false],
    };
    return ['status'=>!empty($payload['success'])?'ok':'unavailable','http_status'=>200,'payload'=>$payload,'message'=>'stub'];
  }
}
$gateway = new LayoutGatewayStub();
$provider = new ParityDataProvider($gateway, new LayoutWorkingNowStub());
$surface = $provider->home(['role_id'=>'7','period'=>'7']);

layout_v1_check(!empty($surface['layout_available']), 'Authoritative dashboard layout was not used.');
layout_v1_check(($surface['visible_widget_count'] ?? -1) === 1, 'Visible widget count must follow saved layout, not all allowed widgets.');
layout_v1_check(count($surface['allowed_widgets'] ?? []) === 2, 'Allowed widget catalog mismatch.');
layout_v1_check(count($surface['layout_items'] ?? []) === 1, 'Saved dashboard item count mismatch.');
$item = $surface['layout_items'][0] ?? [];
layout_v1_check(($item['widget_key'] ?? '') === 'active_employees', 'Wrong saved widget rendered.');
layout_v1_check(($item['content_type'] ?? '') === 'metric', 'Saved KPI type mismatch.');
layout_v1_check(($item['grid_x'] ?? -1) === 3 && ($item['grid_y'] ?? -1) === 2, 'Saved widget position mismatch.');
layout_v1_check(($item['grid_w'] ?? -1) === 4 && ($item['grid_h'] ?? -1) === 2, 'Saved widget dimensions mismatch.');
layout_v1_check(!empty($surface['dashboard_layout']['can_edit']), 'dashboard.configure capability missing.');
layout_v1_check(!empty($surface['dashboard_layout']['can_select_role']), 'Role selection capability missing.');
layout_v1_check(($surface['dashboard_layout']['selected_role_id'] ?? 0) === 7, 'Selected role ID mismatch.');
layout_v1_check(count($surface['dashboard_layout']['allowed_widgets'] ?? []) === 2, 'Authoritative save allowlist mismatch.');

$dashboardDataCall = array_values(array_filter($gateway->calls, static fn(array $call): bool => $call[0] === 'dashboard_data'))[0] ?? [];
layout_v1_check(($dashboardDataCall[2]['role_id'] ?? '') === '7', 'Selected role was not forwarded to dashboard_data.');
foreach ($gateway->calls as $call) {
  layout_v1_check(!array_key_exists('dev_studio', $call[2]) && !array_key_exists('dev_studio', $call[3]), 'DevStudio flag leaked into Drupal gateway call.');
}

$emptyProvider = new ParityDataProvider(new LayoutGatewayStub([]), new LayoutWorkingNowStub());
$emptySurface = $emptyProvider->home(['role_id'=>'7']);
layout_v1_check(!empty($emptySurface['layout_available']), 'Empty saved layout must remain authoritative.');
layout_v1_check(($emptySurface['visible_widget_count'] ?? -1) === 0, 'Empty saved dashboard must render zero widgets.');
layout_v1_check(count($emptySurface['layout_items'] ?? []) === 0, 'Empty saved dashboard unexpectedly received fallback widgets.');
$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/src/Controller/DashboardController.php');
$route = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
$template = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-dashboard.html.twig');
$library = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.libraries.yml');
$js = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/js/dashboard-layout-v1.js');
$css = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/dashboard-layout-v1.css');

require_once $root . '/vendor/autoload.php';
$twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
$twig->parse($twig->tokenize(new \Twig\Source($template, 'merdpos-dashboard.html.twig')));

layout_v1_check(str_contains($controller, "DASHBOARD_LAYOUT_TOKEN_ID = 'merdpos-dashboard-layout-v1'"), 'Drupal dashboard CSRF token boundary missing.');
layout_v1_check(str_contains($controller, 'array_key_exists(\'dev_studio\', $input)'), 'Controller does not reject DevStudio flags.');
layout_v1_check(str_contains($controller, "call('dashboard_layout', 'POST'"), 'Controller does not use signed dashboard_layout write.');
layout_v1_check(str_contains($route, "merdpos_core.dashboard_layout:") && str_contains($route, "methods: [POST]"), 'Dashboard layout route is not POST-only.');
layout_v1_check(str_contains($template, '>Dashboard role<') && str_contains($template, '>Edit dashboard<'), 'Beta dashboard editor labels are missing.');
layout_v1_check(str_contains($template, '>Add widget<') && str_contains($template, 'Clear this role dashboard'), 'Widget drawer parity labels are missing.');
layout_v1_check(str_contains($template, 'data-dashboard-up') && str_contains($template, 'data-dashboard-down'), 'Mobile dashboard ordering controls are missing.');
layout_v1_check(str_contains($library, 'css/dashboard-layout-v1.css') && str_contains($library, 'js/dashboard-layout-v1.js'), 'Dashboard layout assets are not attached.');
layout_v1_check(!str_contains($js, 'dev_studio'), 'Dashboard frontend must never emit a DevStudio flag.');
layout_v1_check(str_contains($js, "title:'Store operations'") && str_contains($js, "title:'Finance'") && str_contains($js, "title:'Workforce'"), 'Quick templates are incomplete.');
layout_v1_check(str_contains($js, 'pointerdown') && str_contains($js, 'data-dashboard-resize'), 'Desktop drag/resize behavior is missing.');
layout_v1_check(str_contains($css, '@media(max-width:51.25rem)') && str_contains($css, '[data-dashboard-up]'), 'Mobile dashboard editor CSS is missing.');

echo "MERDPOS Drupal Dashboard Layout Parity v1 validated.\n";