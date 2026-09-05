<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php';

use Drupal\merdpos_core\Integration\ParityDataProvider;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Integration\WorkingNowProviderInterface;

function ops_v2_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

final class OpsWorkingNow implements WorkingNowProviderInterface {
  public function load(): array { return ['status'=>'ok','count'=>0,'people'=>[],'message'=>'ok']; }
}

final class OpsGateway implements PortalGatewayClientInterface {
  public array $calls = [];
  public function __construct(private readonly string $role) {}

  public function call(string $route, string $method = 'GET', array $query = [], array $body = []): array {
    $this->calls[] = [$route,$method,$query,$body];
    $isUser = $this->role === 'USER';
    if ($isUser && in_array($route, ['admin_directory','store_identity','store_timings'], true)) {
      return ['status'=>'forbidden','http_status'=>403,'payload'=>['success'=>false],'message'=>'forbidden'];
    }
    $payload = match ($route) {
      'beta_state' => [
        'success'=>true,'role'=>$this->role,'role_key'=>$this->role,'role_label'=>$this->role === 'DEV' ? 'Developer' : 'User',
        'authority_level'=>$isUser ? 1 : 1000,
        'permissions'=>$isUser
          ? ['dashboard.view','timesheets.view_own','disputes.view_own','disputes.submit_own']
          : ['dashboard.view','workforce.view','workforce.manage','stores.view','stores.profile.manage','stores.timings.manage','timesheets.view_own','timesheets.view_all','disputes.view_own','disputes.review','attendance_flags.resolve'],
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
        'working'=>[['full_name'=>'Test Employee','user_id'=>'100','store_id'=>1,'store_name'=>'Test Store','clock_in_at'=>'2026-09-05 01:00:00','working_minutes'=>90]],
        'disputes'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','dispute_type'=>'edit_shift','status'=>'pending','submitted_at'=>'2026-09-05 02:00:00','reason'=>'Correction']],
        'attendance_flags'=>$isUser ? [] : [['full_name'=>'Test Employee','attempted_store'=>'Test Store','reason'=>'simultaneous_qr_at_different_store','created_at'=>'2026-09-05 02:10:00','status'=>'open']],
        'recent_shifts'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','clock_in_at'=>'2026-09-05 01:00:00','clock_out_at'=>'2026-09-05 09:00:00','status'=>'closed','timezone'=>'Australia/Sydney']],
        'management'=>$isUser ? null : ['active_employees'=>24],
      ],
      'dashboard_data' => [
        'success'=>true,
        'role'=>['role_key'=>$this->role,'role_label'=>$this->role === 'DEV' ? 'Developer' : 'User','authority_level'=>$isUser ? 1 : 1000],
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
        'filters'=>['store_id'=>0,'period'=>'7','period_label'=>'7 days'],
        'filter_options'=>['stores'=>[['id'=>1,'store_name'=>'Test Store']],'periods'=>['current_week',7,14,30]],
        'working'=>$isUser ? [] : [['full_name'=>'Test Employee','user_id'=>'100','store_id'=>1,'store_name'=>'Test Store','clock_in_at'=>'2026-09-05 01:00:00','working_minutes'=>90]],
        'recent_shifts'=>[['full_name'=>'Test Employee','store_name'=>'Test Store','clock_in_at'=>'2026-09-05 01:00:00','clock_out_at'=>'2026-09-05 09:00:00','status'=>'closed','timezone'=>'Australia/Sydney']],
        'management'=>$isUser ? null : [
          'active_employees'=>24,
          'analytics'=>['attendance_period'=>[['date'=>'2026-09-04','value'=>3],['date'=>'2026-09-05','value'=>4]]],
        ],
      ],
      'disputes' => ['success'=>true,'disputes'=>[[
        'full_name'=>'Test Employee','store_name'=>'Test Store','dispute_type'=>'edit_shift','status'=>'pending',
        'submitted_at'=>'2026-09-05 02:00:00','reason'=>'Correction',
      ]]],
      'weeks' => ['success'=>true,'current_week'=>'2026-08-31','weeks'=>[['value'=>'2026-08-31','label'=>'31 Aug - 6 Sep 2026']]],
      'timesheet' => ['success'=>true,'report'=>[
        'week_label'=>'31 Aug - 6 Sep 2026','employees'=>[[
          'employee_name'=>'Test Employee','rows'=>[[
            'store_name'=>'Test Store','in_date'=>'2026-09-05','actual_in_time'=>'07:15:00','scheduled_start_time'=>'07:00:00','is_late'=>true,
          ]],
        ]],
      ]],
      'admin_directory' => ['success'=>true,'employees'=>[['full_name'=>'Test Employee','role_label'=>'DEV','store_name'=>'Test Store','status'=>'active']]],
      'store_identity' => ['success'=>true,'stores'=>[['id'=>1,'store_name'=>'Test Store','store_code'=>'TS','status'=>'active','timezone'=>'Australia/Sydney']]],
      'store_timings' => ['success'=>true,'timings'=>[['store_id'=>1,'day_of_week'=>5,'start_time'=>'07:00:00','end_time'=>'19:00:00','is_closed'=>0]]],
      default => ['success'=>false],
    };
    $status = ($payload['success'] ?? false) ? 'ok' : 'unavailable';
    return ['status'=>$status,'http_status'=>200,'payload'=>$payload,'message'=>'stub'];
  }
}

$devGateway = new OpsGateway('DEV');
$dev = (new ParityDataProvider($devGateway, new OpsWorkingNow()))->section('operations', ['period'=>'7']);
ops_v2_check(($dev['status'] ?? '') === 'ok', 'DEV Operations did not resolve OK.');
ops_v2_check(($dev['role']['key'] ?? '') === 'DEV', 'DEV role mismatch.');
ops_v2_check(($dev['role']['loa'] ?? 0) === 1000, 'DEV LOA mismatch.');
ops_v2_check(count($dev['working_people'] ?? []) === 1, 'DEV Working Now mismatch.');
ops_v2_check(($dev['pending_dispute_count'] ?? 0) === 1, 'DEV dispute count mismatch.');
ops_v2_check(count($dev['attendance_flags'] ?? []) === 1, 'DEV attendance flag missing.');
ops_v2_check(count($dev['late_arrivals'] ?? []) === 1, 'DEV late arrival missing.');
ops_v2_check(!empty($dev['directory_available']), 'DEV workforce directory missing.');
ops_v2_check(!empty($dev['store_admin_available']), 'DEV store administration read slice missing.');
ops_v2_check(count($dev['chart_specs'] ?? []) >= 2, 'DEV Operations charts missing.');

$userGateway = new OpsGateway('USER');
$user = (new ParityDataProvider($userGateway, new OpsWorkingNow()))->section('operations', ['period'=>'7']);
ops_v2_check(($user['status'] ?? '') === 'ok', 'USER Operations must remain available.');
ops_v2_check(($user['role']['key'] ?? '') === 'USER', 'USER role mismatch.');
ops_v2_check(($user['role']['loa'] ?? -1) === 1, 'USER LOA mismatch.');
ops_v2_check(count($user['working_people'] ?? []) === 1, 'USER should see own open shift from beta_state.');
ops_v2_check(($user['pending_dispute_count'] ?? 0) === 1, 'USER own dispute missing.');
ops_v2_check(empty($user['attendance_flags']), 'USER must not receive attendance security flags.');
ops_v2_check(empty($user['directory_available']), 'USER must not receive workforce management directory.');
ops_v2_check(empty($user['store_admin_available']), 'USER must not receive store management panels.');
ops_v2_check(count($user['late_arrivals'] ?? []) === 1, 'USER own authoritative late start missing.');

$root = dirname(__DIR__);
$routing = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
ops_v2_check(str_contains($routing, "OperationsController::operations"), 'Operations route is not wired to v2 controller.');
ops_v2_check(str_contains($routing, "_permission: 'access merdpos portal'"), 'Operations route is not available to MERDPOS USER role.');
$template = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-operations.html.twig');
ops_v2_check(str_contains($template, 'Pending disputes'), 'Operations dispute panel missing.');
ops_v2_check(str_contains($template, "path('merdpos_core.reports')"), 'Dispute drill-down link missing.');
ops_v2_check(str_contains($template, 'Attendance security flags'), 'Attendance flags panel missing.');
ops_v2_check(str_contains($template, 'Late arrivals'), 'Late-arrival panel missing.');
ops_v2_check(str_contains($template, 'Employee directory'), 'Employee directory panel missing.');
$css = (string) file_get_contents($root . '/web/modules/custom/merdpos_core/css/operations-v2.css');
ops_v2_check(str_contains($css, '.merdpos-ops-grid'), 'Operations rich grid CSS missing.');
ops_v2_check(str_contains($css, '@media'), 'Operations responsive CSS missing.');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');
ops_v2_check(str_contains($deploy, 'Operations HR v2 self-test failed.'), 'Operations deployment fail-closed probe missing.');
ops_v2_check(str_contains($deploy, 'operations_v2'), 'Operations release-marker evidence missing.');

echo "MERDPOS Drupal Operations and HR v2 validated.\n";
