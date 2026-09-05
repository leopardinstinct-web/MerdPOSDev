<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php';

use Drupal\merdpos_core\Integration\ParityDataProvider;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Integration\WorkingNowProviderInterface;

function reports_v2_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}
final class ReportsWorkingNow implements WorkingNowProviderInterface {
  public function load(): array { return ['status'=>'ok','count'=>0,'people'=>[],'message'=>'ok']; }
}
final class ReportsGateway implements PortalGatewayClientInterface {
  public function __construct(private readonly string $role) {}
  public function call(string $route, string $method = 'GET', array $query = [], array $body = [], ?int $contextClientId = NULL): array {
    $isUser = $this->role === 'USER';
    $payload = match ($route) {
      'dashboard_data' => [
        'success'=>true,'role'=>['role_key'=>$this->role,'role_label'=>$isUser?'User':'Developer','base_role'=>$this->role,'authority_level'=>$isUser?1:1000],
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
      ],
      'weeks' => ['success'=>true,'current_week'=>'2026-08-31','weeks'=>[
        ['value'=>'2026-08-31','label'=>'31 Aug - 6 Sep 2026'],['value'=>'2026-08-24','label'=>'24 Aug - 30 Aug 2026'],
      ]],
      'timesheet' => ['success'=>true,'report'=>[
        'week_label'=>'31 Aug - 6 Sep 2026','scope'=>$isUser?'own_employee':'all_employees','payroll_visible'=>!$isUser,
        'employees'=>[
          ['employee_name'=>'Alice','rows'=>[
            ['store_name'=>'Store A','in_date'=>'2026-09-01','actual_in_time'=>'07:00:00','actual_out_time'=>'15:00:00','total_hours'=>8,'is_late'=>false] + ($isUser?[]:['wage'=>200]),
            ['store_name'=>'Store B','in_date'=>'2026-09-02','actual_in_time'=>'07:20:00','actual_out_time'=>'15:00:00','total_hours'=>7.67,'is_late'=>true] + ($isUser?[]:['wage'=>191.75]),
          ]],
          ...($isUser?[]:[['employee_name'=>'Bob','rows'=>[
            ['store_name'=>'Store A','in_date'=>'2026-09-03','actual_in_time'=>'07:15:00','actual_out_time'=>'12:15:00','total_hours'=>5,'is_late'=>true,'wage'=>125],
          ]]]),
        ],
        'employee_summary'=>$isUser
          ? [['employee_name'=>'Alice','total_hours'=>15.67]]
          : [['employee_name'=>'Alice','total_hours'=>15.67,'total_wage'=>391.75],['employee_name'=>'Bob','total_hours'=>5,'total_wage'=>125]],
        'store_summary'=>$isUser
          ? [['store_name'=>'Store A','total_employees_worked'=>1,'total_hours_worked'=>8],['store_name'=>'Store B','total_employees_worked'=>1,'total_hours_worked'=>7.67]]
          : [['store_name'=>'Store A','total_employees_worked'=>2,'total_hours_worked'=>13,'total_amount'=>325],['store_name'=>'Store B','total_employees_worked'=>1,'total_hours_worked'=>7.67,'total_amount'=>191.75]],
      ]],
      'disputes' => ['success'=>true,'disputes'=>[
        ['full_name'=>'Alice','store_name'=>'Store B','dispute_type'=>'wrong_in','reason'=>'Clock-in correction','status'=>'pending','submitted_at'=>'2026-09-02 05:00:00'],
        ...($isUser?[]:[['full_name'=>'Bob','store_name'=>'Store A','dispute_type'=>'wrong_out','reason'=>'Clock-out correction','status'=>'approved','submitted_at'=>'2026-09-03 05:00:00']]),
      ]],
      default => ['success'=>false],
    };
    return ['status'=>($payload['success']??false)?'ok':'unavailable','http_status'=>200,'payload'=>$payload,'message'=>'stub'];
  }
}

$dev = (new ParityDataProvider(new ReportsGateway('DEV'), new ReportsWorkingNow()))->section('reports', []);
reports_v2_check(($dev['status']??'')==='ok','DEV Reports did not resolve OK.');
reports_v2_check(($dev['role']['key']??'')==='DEV','DEV Reports role mismatch.');
reports_v2_check(($dev['role']['loa']??0)===1000,'DEV Reports LOA mismatch.');
reports_v2_check(($dev['payroll_visible']??false)===true,'DEV payroll visibility missing.');
reports_v2_check(count($dev['filters']??[])===4,'Reports v2 filters missing.');
reports_v2_check(count($dev['chart_specs']??[])===5,'DEV Reports chart set mismatch.');
reports_v2_check(count($dev['export_rows']??[])===3,'DEV export row count mismatch.');
reports_v2_check(in_array('wage',array_column($dev['export_columns']??[],'key'),true),'DEV export must include authorized wage column.');
reports_v2_check(($dev['pending_disputes']??-1)===1,'DEV pending dispute count mismatch.');

$filtered = (new ParityDataProvider(new ReportsGateway('DEV'), new ReportsWorkingNow()))->section('reports', [
  'store'=>'Store A','employee'=>'Bob','attendance'=>'late','week_start'=>'2026-08-31',
]);
reports_v2_check(count($filtered['export_rows']??[])===1,'Reports filters did not constrain export rows.');
reports_v2_check(($filtered['export_rows'][0]['employee']??'')==='Bob','Employee filter mismatch.');
reports_v2_check(($filtered['export_rows'][0]['store']??'')==='Store A','Store filter mismatch.');
reports_v2_check(($filtered['export_rows'][0]['start']??'')==='Late','Attendance filter mismatch.');

$user = (new ParityDataProvider(new ReportsGateway('USER'), new ReportsWorkingNow()))->section('reports', []);
reports_v2_check(($user['status']??'')==='ok','USER Reports must remain available.');
reports_v2_check(($user['role']['key']??'')==='USER','USER Reports role mismatch.');
reports_v2_check(($user['payroll_visible']??true)===false,'USER payroll must remain redacted.');
reports_v2_check(count($user['export_rows']??[])===2,'USER export scope mismatch.');
reports_v2_check(!in_array('wage',array_column($user['export_columns']??[],'key'),true),'USER export must not reveal wage.');
reports_v2_check(count($user['chart_specs']??[])===4,'USER payroll chart must be omitted.');

$root = dirname(__DIR__);
$routing = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
reports_v2_check(str_contains($routing,'ReportsController::reports'),'Reports route is not wired to v2 controller.');
reports_v2_check(str_contains($routing,'ReportsController::exportCsv'),'Reports CSV export route missing.');
$template = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-reports.html.twig');
foreach (['Export CSV','Print / PDF','Frozen reconciliation preserved','Dispute status','Payroll by store'] as $needle) {
  reports_v2_check(str_contains($template,$needle),'Reports template missing: ' . $needle);
}
$controller = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/src/Controller/ReportsController.php');
reports_v2_check(str_contains($controller,"Content-Type','text/csv"),'CSV response content type missing.');
reports_v2_check(str_contains($controller,"Cache-Control','private, no-store"),'CSV response must be private/no-store.');
$css = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/css/reports-v2.css');
reports_v2_check(str_contains($css,'.merdpos-reports-charts'),'Reports chart grid CSS missing.');
reports_v2_check(str_contains($css,'@media print'),'Reports print/PDF CSS missing.');
$js = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/js/reports-v2.js');
reports_v2_check(str_contains($js,'window.print()'),'Reports print action missing.');
$provider = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
reports_v2_check(str_contains($provider,'existing MERDPOS timesheet engine') || str_contains($provider,'existing MERDPOS reconciliation engine'),'Reports authority statement missing.');

echo "MERDPOS Drupal Reports v2 validated.\n";
