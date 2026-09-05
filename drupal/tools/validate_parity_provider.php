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
      'beta_state' => [
        'success'=>true,'role'=>'DEV','role_key'=>'DEV','role_label'=>'Developer','authority_level'=>1000,
        'permissions'=>['dashboard.view','workforce.view','workforce.manage','stores.view','stores.profile.manage','stores.timings.manage','timesheets.view_own','timesheets.view_all','disputes.view_own','disputes.review','attendance_flags.resolve'],
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
        'working'=>[['full_name'=>'Test Employee','user_id'=>'999','store_id'=>1,'store_name'=>'Test Store','clock_in_at'=>'2026-09-04 01:00:00','working_minutes'=>91]],
        'disputes'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','dispute_type'=>'edit_shift','status'=>'pending','submitted_at'=>'2026-09-04 00:00:00']],
        'attendance_flags'=>[['full_name'=>'Test Employee','attempted_store'=>'Test Store','reason'=>'simultaneous_qr_at_different_store','created_at'=>'2026-09-04 00:10:00','status'=>'open']],
        'recent_shifts'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','clock_in_at'=>'2026-09-04 01:00:00','clock_out_at'=>'2026-09-04 09:00:00','status'=>'closed','timezone'=>'Australia/Sydney']],
        'management'=>['active_employees'=>44],
      ],
      'dashboard_data' => [
        'success'=>true,
        'role'=>['role_key'=>'DEV','role_label'=>'Developer','base_role'=>'DEV','authority_level'=>1000],
        'allowed_widgets'=>[
          'working_now_count','pending_disputes','active_employees','sync_attention','working_now','workforce_by_store',
          'store_cash_position','cash_mix','today_sales_by_store','recent_attendance','my_shift','my_disputes',
          'sales_change','attendance_change','sales_trend_7d','attendance_trend_7d','top_stores_sales','sync_status_table',
        ],
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
        'filters'=>['store_id'=>0,'days'=>7,'period'=>'7','period_label'=>'7 days'],
        'filter_options'=>['stores'=>[['id'=>1,'store_name'=>'Test Store']],'periods'=>['current_week',7,14,30]],
        'working_count'=>1,
        'working'=>[['full_name'=>'Test Employee','user_id'=>'999','store_id'=>1,'store_name'=>'Test Store','clock_in_at'=>'2026-09-04 01:00:00','working_minutes'=>91,'timezone'=>'Australia/Sydney']],
        'my_working'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','clock_in_at'=>'2026-09-04 01:00:00','timezone'=>'Australia/Sydney']],
        'disputes'=>[['status'=>'pending']],
        'pending_disputes_count'=>2,
        'recent_shifts'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','clock_in_at'=>'2026-09-04 01:00:00','clock_out_at'=>'2026-09-04 09:00:00','timezone'=>'Australia/Sydney']],
        'stores'=>[['id'=>1,'store_name'=>'Test Store']],
        'management'=>[
          'business_date'=>'2026-09-04','currency_code'=>'AUD','timezone'=>'Australia/Sydney','active_employees'=>44,'sync_attention'=>1,
          'sales_by_store'=>[['store_id'=>1,'store_name'=>'Test Store','currency_code'=>'AUD','today_sales'=>123.45]],
          'financial_by_store'=>[['store_id'=>1,'store_name'=>'Test Store','currency_code'=>'AUD','register_balance'=>100,'petty_balance'=>25]],
          'analytics'=>[
            'sales_period'=>[['date'=>'2026-09-03','value'=>100],['date'=>'2026-09-04','value'=>123.45]],
            'attendance_period'=>[['date'=>'2026-09-03','value'=>3],['date'=>'2026-09-04','value'=>4]],
            'sync_statuses'=>[['status'=>'failed','count'=>1],['status'=>'processing','count'=>0],['status'=>'pending','count'=>0]],
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
            'rows'=>[['store_name'=>'Test Store','in_date'=>'2026-09-01','actual_in_time'=>'07:00:00','actual_out_time'=>'15:00:00','total_hours'=>8,'is_late'=>true,'scheduled_start_time'=>'06:30:00']],
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
  if ($key === 'home') {
    parity_check(($surface['role']['key'] ?? '') === 'DEV', 'Home role did not resolve DEV.');
    parity_check(($surface['role']['loa'] ?? 0) === 1000, 'Home role LOA mismatch.');
    parity_check(count($surface['allowed_widgets'] ?? []) === 18, 'Home allowed widget count mismatch.');
    parity_check(($surface['visible_widget_count'] ?? -1) === 18, 'Home did not render every authorized widget.');
    parity_check(count($surface['metrics'] ?? []) === 8, 'Home role-aware KPI count mismatch.');
    parity_check(count($surface['dashboard_widgets'] ?? []) === 10, 'Home rich widget count mismatch.');
    parity_check(count($surface['chart_specs'] ?? []) === 6, 'Home chart spec count mismatch.');
    parity_check(count($surface['filters'] ?? []) === 2, 'Home dashboard filters missing.');
  }
  elseif ($key === 'operations') {
    parity_check(($surface['role']['key'] ?? '') === 'DEV', 'Operations role did not resolve DEV.');
    parity_check(($surface['role']['loa'] ?? 0) === 1000, 'Operations role LOA mismatch.');
    parity_check(count($surface['metrics'] ?? []) >= 4, 'Operations rich metrics missing.');
    parity_check(count($surface['working_people'] ?? []) === 1, 'Operations live workforce mismatch.');
    parity_check(($surface['pending_dispute_count'] ?? -1) === 1, 'Operations dispute count mismatch.');
    parity_check(count($surface['late_arrivals'] ?? []) === 1, 'Operations late arrivals mismatch.');
    parity_check(!empty($surface['directory_available']), 'Operations management directory should be available.');
    parity_check(count($surface['chart_specs'] ?? []) >= 2, 'Operations charts missing.');
  }
  elseif ($key === 'reports') {
    parity_check(($surface['role']['key'] ?? '') === 'DEV', 'Reports role did not resolve DEV.');
    parity_check(($surface['role']['loa'] ?? 0) === 1000, 'Reports role LOA mismatch.');
    parity_check(count($surface['metrics'] ?? []) >= 6, 'Reports rich metrics missing.');
    parity_check(count($surface['filters'] ?? []) === 4, 'Reports filters missing.');
    parity_check(count($surface['chart_specs'] ?? []) >= 4, 'Reports charts missing.');
    parity_check(!empty($surface['export_rows']), 'Reports export rows missing.');
    parity_check(!empty($surface['groups']), 'Reports surface groups missing.');
  }
  else {
    parity_check(count($surface['metrics'] ?? []) === 4, $key . ' surface requires four metrics.');
    parity_check(!empty($surface['groups']), $key . ' surface groups missing.');
  }
}

parity_check(($surfaces['home']['metrics'][6]['value'] ?? '') === 'AUD 123.45', 'Home sales change current value mismatch.');
parity_check(($surfaces['reports']['meta']['payroll_visible'] ?? '') === 'yes', 'Reports payroll visibility mismatch.');
parity_check(count($surfaces['reports']['filters'] ?? []) === 4, 'Reports v2 filters missing.');
parity_check(($surfaces['reports']['payroll_visible'] ?? false) === true, 'Reports payroll visibility flag mismatch.');
parity_check(count($surfaces['reports']['chart_specs'] ?? []) >= 4, 'Reports v2 charts missing.');
parity_check(count($surfaces['finance']['filters'] ?? []) === 2, 'Finance filters missing.');
$routes = array_map(static fn(array $call): string => (string)$call[0], $gateway->calls);
foreach (['beta_state','dashboard_data','admin_directory','store_identity','store_timings','weeks','timesheet','disputes','financials','dev_status','clients','role_authority','client_context'] as $route) {
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
