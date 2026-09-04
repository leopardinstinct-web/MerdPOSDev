<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php';

use Drupal\merdpos_core\Integration\ParityDataProvider;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Integration\WorkingNowProviderInterface;

function parity_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

final class StubWorkingNow implements WorkingNowProviderInterface {
  public function load(): array {
    return [
      'status'=>'ok','count'=>1,'generated_at'=>'2026-09-04T00:00:00Z',
      'people'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','working_minutes'=>91]],
      'message'=>'ok',
    ];
  }
}

final class StubGateway implements PortalGatewayClientInterface {
  public array $calls = [];

  public function call(string $route, string $method = 'GET', array $query = [], array $body = []): array {
    $this->calls[] = [$route, $method, $query, $body];
    $payload = match ($route) {
      'dashboard_data' => [
        'success'=>true,
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
        'pending_disputes_count'=>2,
        'management'=>[
          'business_date'=>'2026-09-04','currency_code'=>'AUD',
          'sales_by_store'=>[['store_id'=>1,'store_name'=>'Test Store','currency_code'=>'AUD','today_sales'=>123.45]],
          'financial_by_store'=>[['store_id'=>1,'store_name'=>'Test Store','currency_code'=>'AUD','register_balance'=>100,'petty_balance'=>25]],
          'analytics'=>[
            'sales_period'=>[['date'=>'2026-09-03','value'=>100],['date'=>'2026-09-04','value'=>123.45]],
            'attendance_period'=>[['date'=>'2026-09-03','value'=>3],['date'=>'2026-09-04','value'=>4]],
          ],
        ],
      ],
      'admin_directory' => [
        'success'=>true,
        'employees'=>[['full_name'=>'Test Employee','role_label'=>'DEV','store_name'=>'Test Store','status'=>'active']],
        'stores'=>[['id'=>1,'store_name'=>'Test Store','store_code'=>'TS','status'=>'active','timezone'=>'Australia/Sydney']],
      ],
      'store_identity' => [
        'success'=>true,
        'stores'=>[['id'=>1,'store_name'=>'Test Store','store_code'=>'TS','status'=>'active','timezone'=>'Australia/Sydney']],
      ],
      'store_timings' => [
        'success'=>true,
        'timings'=>[['store_id'=>1,'day_of_week'=>1,'start_time'=>'07:00:00','end_time'=>'19:00:00','is_closed'=>0]],
      ],
      'weeks' => [
        'success'=>true,'current_week'=>'2026-08-31',
        'weeks'=>[['value'=>'2026-08-31','label'=>'31 Aug – 6 Sep 2026']],
      ],
      'timesheet' => [
        'success'=>true,
        'report'=>[
          'week_label'=>'31 Aug – 6 Sep 2026','grand_total_hours'=>8,'payroll_visible'=>true,
          'employee_summary'=>[['employee_name'=>'Test Employee','total_hours'=>8,'total_wage'=>200]],
          'store_summary'=>[['store_name'=>'Test Store','total_employees_worked'=>1,'total_hours_worked'=>8,'total_amount'=>200]],
          'employees'=>[[
            'employee_name'=>'Test Employee',
            'rows'=>[['store_name'=>'Test Store','in_date'=>'2026-09-01','actual_in_time'=>'07:00:00','actual_out_time'=>'15:00:00','total_hours'=>8,'is_late'=>false]],
          ]],
        ],
      ],
      'disputes' => [
        'success'=>true,
        'disputes'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','dispute_type'=>'edit_shift','status'=>'pending','submitted_at'=>'2026-09-04 00:00:00']],
      ],
      'financials' => [
        'success'=>true,
        'statement'=>[
          'store_id'=>1,'store_name'=>'Test Store','business_date'=>'2026-09-04','day_status'=>'open',
          'accounts'=>[
            ['account'=>'Register','opening'=>50,'cash_in'=>60,'cash_out'=>10,'available'=>100,'closing'=>null,'status'=>'open'],
            ['account'=>'Petty Cash','opening'=>20,'cash_in'=>10,'cash_out'=>5,'available'=>25,'closing'=>null,'status'=>'open'],
          ],
          'entries'=>[['account'=>'Register','entry_type'=>'IN','head'=>'Sale','amount'=>10,'full_name'=>'Test Employee','created_at'=>'2026-09-04 01:00:00']],
        ],
      ],
      'dev_status' => [
        'success'=>true,'php_version'=>'8.4.0','actor_loa'=>1000,'authorization_model'=>'client role → LOA → permission',
        'tables'=>['employees'=>44,'stores'=>4,'retail_sales'=>100],
      ],
      'clients' => [
        'success'=>true,
        'clients'=>[['name'=>'MERD','client_code'=>'MERD','status'=>'active','store_count'=>4,'employee_count'=>44]],
      ],
      'role_authority' => [
        'success'=>true,
        'roles'=>[['role_label'=>'Developer','role_key'=>'DEV','base_role'=>'DEV','authority_level'=>1000,'employee_count'=>1]],
        'permissions'=>[['permission_key'=>'dev.status','label'=>'DEV Status','category'=>'DEV','min_authority_level'=>1000,'dev_only'=>true]],
      ],
      'client_context' => [
        'success'=>true,
        'client'=>['name'=>'MERD','client_code'=>'MERD','status'=>'active'],
      ],
      default => ['success'=>false],
    };
    $status = ($payload['success'] ?? false) ? 'ok' : 'unavailable';
    return ['status'=>$status,'http_status'=>200,'payload'=>$payload,'message'=>'stub'];
  }
}

$gateway = new StubGateway();
$provider = new ParityDataProvider($gateway, new StubWorkingNow());

$surfaces = [
  'home' => $provider->home(),
  'operations' => $provider->section('operations'),
  'reports' => $provider->section('reports', ['week_start'=>'2026-08-31']),
  'finance' => $provider->section('finance', ['store_id'=>'1','business_date'=>'2026-09-04']),
  'dev' => $provider->section('dev'),
];

foreach ($surfaces as $key => $surface) {
  parity_check(($surface['key'] ?? '') === $key, $key . ' surface key mismatch.');
  parity_check(($surface['status'] ?? '') === 'ok', $key . ' surface did not resolve OK.');
  parity_check(($surface['status_label'] ?? '') === 'LIVE', $key . ' surface live label missing.');
  parity_check(count($surface['metrics'] ?? []) === 4, $key . ' surface requires four metrics.');
  parity_check(!empty($surface['groups']), $key . ' surface groups missing.');
}

parity_check(($surfaces['home']['metrics'][1]['value'] ?? '') === 'AUD 123.45', 'Home sales metric mismatch.');
parity_check(($surfaces['reports']['meta']['payroll_visible'] ?? '') === 'yes', 'Reports payroll visibility mismatch.');
parity_check(count($surfaces['reports']['filters'] ?? []) === 1, 'Reports week filter missing.');
parity_check(count($surfaces['finance']['filters'] ?? []) === 2, 'Finance filters missing.');
$routes = array_map(static fn(array $call): string => (string)$call[0], $gateway->calls);
foreach (['dashboard_data','admin_directory','store_identity','store_timings','weeks','timesheet','disputes','financials','dev_status','clients','role_authority','client_context'] as $route) {
  parity_check(in_array($route, $routes, true), 'Expected gateway route was not exercised: ' . $route);
}

final class ForbiddenGateway implements PortalGatewayClientInterface {
  public function call(string $route, string $method = 'GET', array $query = [], array $body = []): array {
    return ['status'=>'forbidden','http_status'=>403,'payload'=>['success'=>false],'message'=>'forbidden'];
  }
}
$blocked = new ParityDataProvider(new ForbiddenGateway(), new StubWorkingNow());
parity_check(($blocked->section('operations')['status'] ?? '') === 'forbidden', 'Forbidden gateway must fail closed.');

echo "MERDPOS Drupal five-surface parity provider validated.\n";
