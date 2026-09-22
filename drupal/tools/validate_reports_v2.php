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
  public function __construct(private readonly string $role, private readonly bool $workforceVisible = true) {}
  public function call(string $route, string $method = 'GET', array $query = [], array $body = [], ?int $contextClientId = NULL): array {
    $isUser = $this->role === 'USER';
    $payload = match ($route) {
      'beta_state' => ['success'=>true,'permissions'=>$isUser?['disputes.submit_own']:array_values(array_filter(['disputes.review',$this->workforceVisible?'workforce.view':null])),'current_user_id'=>$isUser?'user-alice':'platform-dev','stores'=>[['id'=>1,'store_name'=>'Store A'],['id'=>2,'store_name'=>'Store B']],'recent_shifts'=>[
        ['shift_id'=>'11111111-1111-4111-8111-111111111111','full_name'=>'Alice','user_id'=>'user-alice','store_name'=>'Store A','clock_in_at'=>'2026-08-31 21:00:00','clock_out_at'=>'2026-09-01 05:00:00','status'=>'closed','timezone'=>'Australia/Sydney'],
        ['shift_id'=>'44444444-4444-4444-8444-444444444444','full_name'=>'Alice','user_id'=>'user-alice','store_name'=>'Store A','clock_in_at'=>'2026-09-04 01:00:00','clock_out_at'=>null,'status'=>'open','timezone'=>'Australia/Sydney'],
      ]],
      'dashboard_data' => [
        'success'=>true,'role'=>['role_key'=>$this->role,'role_label'=>$isUser?'User':'Developer','base_role'=>$this->role,'authority_level'=>$isUser?1:1000],
        'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],
      ],
      'client_context' => ['success'=>true,'active_client_id'=>1,'impersonating'=>false,'client'=>['id'=>1,'name'=>'Client One'],'effective_user'=>['id'=>$isUser?1:99,'full_name'=>$isUser?'Alice':'Developer','user_id'=>$isUser?'user-alice':'platform-dev']],
      'weeks' => ['success'=>true,'current_week'=>'2026-08-31','weeks'=>[
        ['value'=>'2026-08-31','label'=>'31 Aug - 6 Sep 2026'],['value'=>'2026-08-24','label'=>'24 Aug - 30 Aug 2026'],
      ]],
      'timesheet' => ['success'=>true,'report'=>[
        'week_label'=>'31 Aug - 6 Sep 2026','scope'=>$isUser?'own_employee':'all_employees','payroll_visible'=>true,'payroll_scope'=>$isUser?'own_employee':'authorized',
        'employees'=>[
          ['employee_name'=>'Alice','user_id'=>'user-alice','rows'=>[
            ['shift_id'=>'','employee_id'=>1,'store_id'=>1,'store_name'=>'Store A','in_date'=>'2026-09-01','out_date'=>'2026-09-01','actual_in_time'=>'07:00:00','actual_out_time'=>'15:00:00','total_hours'=>8,'is_late'=>false,'wage'=>200],
            ['shift_id'=>'22222222-2222-4222-8222-222222222222','employee_id'=>1,'store_id'=>2,'store_name'=>'Store B','in_date'=>'2026-09-02','out_date'=>'2026-09-02','actual_in_time'=>'07:20:00','actual_out_time'=>'15:00:00','total_hours'=>7.67,'is_late'=>true,'wage'=>191.75],
          ]],
          ...($isUser?[]:[['employee_name'=>'Bob','user_id'=>'user-bob','rows'=>[
            ['shift_id'=>'33333333-3333-4333-8333-333333333333','employee_id'=>2,'store_id'=>1,'store_name'=>'Store A','in_date'=>'2026-09-03','out_date'=>'2026-09-03','actual_in_time'=>'07:15:00','actual_out_time'=>'12:15:00','total_hours'=>5,'is_late'=>true,'wage'=>125],
          ]]]),
        ],
        'open_shifts'=>[
          ['shift_id'=>'55555555-5555-4555-8555-555555555555','employee_id'=>1,'user_id'=>'user-alice','store_id'=>1,'employee_name'=>'Alice','store_name'=>'Store A','date'=>'2026-09-04','actual_in_time'=>'09:10:00','actual_out_time'=>'','missing'=>'OUT'],
          ...($isUser?[]:[['employee_id'=>2,'user_id'=>'user-bob','store_id'=>2,'employee_name'=>'Bob','store_name'=>'Store B','date'=>'2026-09-05','actual_in_time'=>'','actual_out_time'=>'18:40:00','missing'=>'IN']]),
        ],
        'employee_summary'=>$isUser
          ? [['employee_name'=>'Alice','total_hours'=>15.67,'total_wage'=>391.75]]
          : [['employee_name'=>'Alice','total_hours'=>15.67,'total_wage'=>391.75],['employee_name'=>'Bob','total_hours'=>5,'total_wage'=>125]],
        'store_summary'=>$isUser
          ? [['store_name'=>'Store A','total_employees_worked'=>1,'total_hours_worked'=>8],['store_name'=>'Store B','total_employees_worked'=>1,'total_hours_worked'=>7.67]]
          : [['store_name'=>'Store A','total_employees_worked'=>2,'total_hours_worked'=>13,'total_amount'=>325],['store_name'=>'Store B','total_employees_worked'=>1,'total_hours_worked'=>7.67,'total_amount'=>191.75]],
      ]],
      'disputes' => ['success'=>true,'disputes'=>[
        ['dispute_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','shift_id'=>'22222222-2222-4222-8222-222222222222','user_id'=>'user-alice','full_name'=>'Alice','store_name'=>'Store B','dispute_type'=>'wrong_in','reason'=>'Clock-in correction','status'=>'pending','submitted_at'=>'2026-09-02 05:00:00'],
        ['dispute_id'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc','shift_id'=>'44444444-4444-4444-8444-444444444444','user_id'=>'user-alice','full_name'=>'Alice','store_name'=>'Store A','dispute_type'=>'other','reason'=>'Open shift question','status'=>'pending','submitted_at'=>'2026-09-04 02:00:00','clock_in_at'=>'2026-09-04 01:00:00'],
        ...($isUser?[]:[['dispute_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','shift_id'=>'33333333-3333-4333-8333-333333333333','user_id'=>'user-bob','full_name'=>'Bob','store_name'=>'Store A','dispute_type'=>'wrong_out','reason'=>'Clock-out correction','status'=>'approved','submitted_at'=>'2026-09-03 05:00:00']]),
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
reports_v2_check(count($dev['week_options']??[])===2,'Header view selector options missing.');
reports_v2_check(($dev['week_options'][0]['label']??'')==='31 Aug - 06 Sep 2026','View date range must use one consistent same-year format.');
reports_v2_check(($dev['week_options'][1]['label']??'')==='24 Aug - 30 Aug 2026','View date range formatting drifted.');
reports_v2_check(count($dev['filters']??[])===0,'Reporting lens filters must be retired.');
reports_v2_check(count($dev['chart_specs']??[])===0,'Timesheets charts must be removed for now.');
reports_v2_check(count($dev['export_rows']??[])===3,'DEV export row count mismatch.');
reports_v2_check(in_array('wage',array_column($dev['export_columns']??[],'key'),true),'DEV export must include authorized wage column.');
reports_v2_check(($dev['pending_disputes']??-1)===2,'DEV pending dispute count mismatch.');
reports_v2_check(array_column($dev['metrics']??[],'label')===['Shifts','Queries'],'Timesheets must expose exactly the two simplified KPI cards.');
reports_v2_check(($dev['metrics'][0]['lines']??[])===[['value'=>'2','label'=>'People'],['value'=>'3','label'=>'Shifts'],['value'=>'20.67','label'=>'Hours'],['value'=>'516.75','label'=>'Payroll (AUD)']],'Shifts summary card mismatch.');
reports_v2_check(($dev['metrics'][0]['lines'][3]['label']??'')==='Payroll (AUD)','Shifts payroll metric must include currency in its label.');
reports_v2_check(($dev['metrics'][1]['lines']??[])===[['value'=>'2','label'=>'Active'],['value'=>'0','label'=>'Rejected'],['value'=>'1','label'=>'Closed']],'Queries summary card must render Active, Rejected, Closed.');
reports_v2_check(count($dev['groups']??[])===4,'DEV/SUPER/Admin must expose Store, Employee, Open shifts and Closed shifts tables.');
$devOpenRows=$dev['groups'][2]['rows']??[];
reports_v2_check(($dev['groups'][1]['title']??'')==='Hours by employee' && ($dev['groups'][1]['eyebrow']??'')==='Employee summary','Employee summary heading mismatch.');
reports_v2_check(($dev['groups'][2]['title']??'')==='Open shifts' && ($dev['groups'][2]['eyebrow']??'')==='Incomplete Shifts','Open shifts table title/subheading mismatch.');
reports_v2_check(($dev['groups'][3]['title']??'')==='Closed shifts' && ($dev['groups'][3]['eyebrow']??'')==='Complete Shifts','Closed shifts table title/subheading mismatch.');
reports_v2_check(count($devOpenRows)===2,'Open shifts must contain the two employee-wise incomplete IN/OUT rows.');
reports_v2_check(array_intersect_key($devOpenRows[0],array_flip(['employee','store','date','in','out','missing']))===['employee'=>'Alice','store'=>'Store A','date'=>'2026-09-04','in'=>'09:10','out'=>'—','missing'=>'Missing OUT'],'Alice incomplete shift row mismatch.');
reports_v2_check(array_intersect_key($devOpenRows[1],array_flip(['employee','store','date','in','out','missing']))===['employee'=>'Bob','store'=>'Store B','date'=>'2026-09-05','in'=>'—','out'=>'18:40','missing'=>'Missing IN'],'Bob incomplete shift row mismatch.');
reports_v2_check((end($dev['groups'][2]['columns'])['key']??'')==='action','Action must be the final Incomplete Shifts column.');
reports_v2_check(($devOpenRows[0]['action']['shift_id']??'')==='55555555-5555-4555-8555-555555555555','Authoritative incomplete shift ID must reach the Action payload.');
$devShiftRows=$dev['groups'][3]['rows']??[];
reports_v2_check(!empty($devShiftRows[1]['action']['dispute']['can_review']),'Reviewer pending-dispute action missing from Shift Detail.');
$orphanReview=array_values(array_filter($devShiftRows,static fn(array $r): bool => ($r['action']['shift_id']??'')==='44444444-4444-4444-8444-444444444444'));
reports_v2_check(count($orphanReview)===1 && ($orphanReview[0]['start']??'')==='Open shift · query' && !empty($orphanReview[0]['action']['dispute']['can_review']),'Open-shift dispute must remain visible in Shift Detail.');

$hiddenQuery = (new ParityDataProvider(new ReportsGateway('DEV'), new ReportsWorkingNow()))->section('reports', [
  'store'=>'Store A','employee'=>'Bob','attendance'=>'late','week_start'=>'2026-08-31',
]);
reports_v2_check(count($hiddenQuery['export_rows']??[])===3,'Retired hidden filters must not silently constrain Timesheets.');
reports_v2_check(($hiddenQuery['selected_store']??'x')==='' && ($hiddenQuery['selected_employee']??'x')==='' && ($hiddenQuery['selected_attendance']??'x')==='all','Only the week selector may control the Timesheets view.');
reports_v2_check(($hiddenQuery['selected_week']??'')==='2026-08-31','Week selector must still control the report period.');

$user = (new ParityDataProvider(new ReportsGateway('USER'), new ReportsWorkingNow()))->section('reports', []);
reports_v2_check(($user['status']??'')==='ok','USER Reports must remain available.');
reports_v2_check(($user['role']['key']??'')==='USER','USER Reports role mismatch.');
reports_v2_check(($user['payroll_visible']??false)===true,'USER own payroll must be visible.');
reports_v2_check(count($user['export_rows']??[])===2,'USER export scope mismatch.');
reports_v2_check(in_array('wage',array_column($user['export_columns']??[],'key'),true),'USER own Closed shifts/export must include wage.');
reports_v2_check(count($user['chart_specs']??[])===0,'USER Timesheets charts must stay removed.');
reports_v2_check(count($user['groups']??[])===2 && ($user['groups'][0]['title']??'')==='Open shifts' && ($user['groups'][1]['title']??'')==='Closed shifts','USER must see Open shifts + Closed shifts, but not Store or Employee summaries.');
$userOpenRows=$user['groups'][0]['rows']??[];
reports_v2_check(count($userOpenRows)===1 && array_intersect_key($userOpenRows[0],array_flip(['employee','store','date','in','out','missing']))===['employee'=>'Alice','store'=>'Store A','date'=>'2026-09-04','in'=>'09:10','out'=>'—','missing'=>'Missing OUT'],'USER Open shifts must remain scoped to the user.');
reports_v2_check(!empty($userOpenRows[0]['action']['can_dispute']) && !empty($userOpenRows[0]['action']['can_add_missing']),'USER incomplete shift must expose Query this shift + Add missing shift capabilities.');
reports_v2_check(($user['metrics'][0]['lines']??[])===[['value'=>'2','label'=>'Shifts'],['value'=>'15.67','label'=>'Hours'],['value'=>'391.75','label'=>'Payroll (AUD)']],'USER must replace People with own Payroll while keeping Shifts and Hours.');
$limited = (new ParityDataProvider(new ReportsGateway('SUPER', false), new ReportsWorkingNow()))->section('reports', []);
reports_v2_check(array_column($limited['metrics'][0]['lines']??[],'label')===['Shifts','Hours','Payroll (AUD)'],'Workforce-hidden payroll role must evolve Shifts from four KPI cells to three.');

$userShiftRows=$user['groups'][1]['rows']??[];
reports_v2_check(($userShiftRows[0]['action']['shift_id']??'')==='11111111-1111-4111-8111-111111111111','USER legacy-linked closed row must recover its authoritative recent shift ID.');
reports_v2_check(!empty($userShiftRows[0]['action']['can_dispute']) && !empty($userShiftRows[0]['action']['can_add_missing']),'USER own-row Query this shift / Add missing shift actions missing.');
reports_v2_check(!empty($userShiftRows[1]['action']['dispute']['can_cancel']),'USER own pending dispute must be cancellable from Shift Detail.');
$orphanOwn=array_values(array_filter($userShiftRows,static fn(array $r): bool => ($r['action']['shift_id']??'')==='44444444-4444-4444-8444-444444444444'));
reports_v2_check(count($orphanOwn)===1 && !empty($orphanOwn[0]['action']['dispute']['can_cancel']),'USER open-shift dispute must remain cancellable from Shift Detail.');

$root = dirname(__DIR__);
$routing = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
reports_v2_check(str_contains($routing,'ReportsController::reports'),'Reports route is not wired to v2 controller.');
reports_v2_check(str_contains($routing,'ReportsController::exportCsv'),'Reports CSV export route missing.');
$template = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/templates/merdpos-reports.html.twig');
foreach (['>PDF<','data-timesheet-week-select','data-timesheet-view-search','Filter current view','>Select View<',"ui.icon('download')",'Query this shift','Add missing shift','Cancel Query'] as $needle) {
  reports_v2_check(str_contains($template,$needle),'Reports template missing: ' . $needle);
}
reports_v2_check(!str_contains($template,'>Export CSV<'),'Timesheets hero must not expose Export CSV.');
$viewPos=strpos($template,'data-timesheet-week-select'); $searchPos=strpos($template,'data-timesheet-view-search'); $pdfPos=strpos($template,'data-merdpos-print');
reports_v2_check($viewPos!==false && $searchPos!==false && $pdfPos!==false && $viewPos < $searchPos && $searchPos < $pdfPos,'Timesheets controls must render Select View, Search, then PDF.');
reports_v2_check(!str_contains($template,'merdpos-reports-note') && !str_contains($template,'merdpos-reports-footer'),'Timesheets note and footer must remain removed.');
reports_v2_check(!str_contains($template,'Reporting lens') && !str_contains($template,'merdpos-reports-filters'),'Reporting lens must be removed from every role.');
reports_v2_check(str_contains($template,'merdpos-reports-kpi-rail') && str_contains($template,'merdpos-reports-kpi-stat') && str_contains($template,"metric_key == 'queries' ? 'record_voice_over'") && str_contains($template,"line.label == 'Active' ? 'adjust'") && str_contains($template,"line.label == 'People'") && str_contains($template,"line.label == 'Rejected'") && str_contains($template,"name == 'payroll'") && str_contains($template,"name == 'closed'") && str_contains($template,"name == 'rejected'"),'Redesigned Shifts/Queries KPI markup or requested semantic icons missing.');
reports_v2_check(!str_contains($template,'merdpos-reports-kpi-art'),'Abstract KPI filler must remain removed.');
reports_v2_check(!str_contains($template,'merdpos-reports-charts') && !str_contains($template,'Payroll by store'),'Timesheets chart surfaces must be removed for now.');
$controller = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/src/Controller/ReportsController.php');
reports_v2_check(str_contains($controller,"Content-Type','text/csv"),'CSV response content type missing.');
reports_v2_check(str_contains($controller,"Cache-Control','private, no-store"),'CSV response must be private/no-store.');
reports_v2_check(str_contains($controller,"foreach (['week_start'] as \$key)") && !str_contains($controller,"url.query_args:store"),'Timesheets controller must expose only the week query selector.');
$css = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/css/reports-v2.css');
reports_v2_check(str_contains($css,'.merdpos-reports-header-actions') && str_contains($css,'.merdpos-reports-kpis { display:grid; grid-template-columns:repeat(2,minmax(0,1fr))') && str_contains($css,'grid-template-columns:minmax(6.4rem,7.2rem) minmax(0,1fr)') && str_contains($css,'min-height:8.6rem') && str_contains($css,'.merdpos-reports-kpi-rail { display:flex; align-items:center') && str_contains($template,'merdpos-ui-kpi-grid') && str_contains($template,'data-kpi-count="{{ metric.lines|length }}"') && str_contains($css,'.merdpos-reports-print-action svg'),'Adaptive compact Timesheets KPI/header styling missing.');
reports_v2_check(!str_contains($css,'.merdpos-reports-kpi-art'),'Abstract KPI filler CSS must remain removed.');
reports_v2_check(str_contains($css,'.merdpos-reports-week-form label > span') && str_contains($css,'text-transform:none'),'Select View label must preserve title case.');
reports_v2_check(str_contains($css,'.merdpos-reports-search') && str_contains($css,'.merdpos-reports-print-action { margin-top:.55rem; }'),'Timesheets search styling and PDF spacing missing.');
reports_v2_check(str_contains($css,'@media print'),'Reports print/PDF CSS missing.');
reports_v2_check(str_contains($css,'--rep-amber: var(--color-amber)') && !str_contains($css,'is-active .merdpos-reports-kpi-stat-value') && str_contains($css,'color:var(--color-amber)'), 'Active Queries KPI icon/label must use Amber while KPI numbers keep global text color.');
reports_v2_check(str_contains($css,'record_voice_over_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg') && str_contains($css,'adjust_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg'), 'Requested Queries KPI icon masks missing.');
$deploy = (string)file_get_contents($root . '/tools/namecheap_deploy.sh');
reports_v2_check(str_contains($deploy,'($p["groups"]??0)!==4'),'Reports live deploy self-test must expect Store + Employee + Open Shifts + Filtered Shifts groups.');
$js = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/js/reports-v2.js');
reports_v2_check(str_contains($js,'window.print()'),'Reports PDF/print action missing.');
reports_v2_check(str_contains($js,'data-timesheet-week-select') && str_contains($js,'requestSubmit'),'Week selector must update the report immediately.');
reports_v2_check(str_contains($js,'data-timesheet-view-search') && str_contains($js,"qa('.merdpos-reports-groups tbody tr')") && str_contains($js,'row.hidden = term'),'Timesheets search must filter the rendered view immediately.');
reports_v2_check(str_contains($js,'openDialog') && str_contains($js,"'new_shift'") && str_contains($js,'fetch(') && str_contains($js,'merdpos_query_queue_v1'),'Integrated Shift Detail Query dialog/offline submission wiring missing.');
$provider = (string)file_get_contents($root . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
reports_v2_check(str_contains($provider,'Track attendance, review and process wages'),'Timesheets description missing.');
reports_v2_check(($dev['title']??'')==='Attendance & Payroll','Timesheets title must present Attendance & Payroll.');
reports_v2_check(count($dev['groups']??[])===4,'DEV/SUPER/Admin Store + Employee + Open shifts + Closed shifts tables must remain available.');
reports_v2_check((end($dev['groups'][3]['columns'])['key']??'')==='action','Action must be the final Closed shifts column.');
reports_v2_check(!in_array('action',array_column($dev['export_columns']??[],'key'),true),'UI Action column must not leak into CSV export.');
reports_v2_check(!str_contains($template,'Dispute status') && !str_contains($template,'Current dispute queue'),'Standalone dispute report surfaces must stay retired.');
$queryMenuPos=strpos($template,'data-timesheet-menu-choice="query"'); $missingMenuPos=strpos($template,'data-timesheet-menu-choice="missing"');
reports_v2_check($queryMenuPos!==false && $missingMenuPos!==false && $queryMenuPos < $missingMenuPos,'Shift Action menu must render Query this shift before Add missing shift.');
reports_v2_check(str_contains($template,'<span>Query this shift</span>') && str_contains($template,'<span>Add missing shift</span>'),'Shift Action menu labels must match the requested wording exactly.');
reports_v2_check(str_contains($template,'merdpos-timesheet-action-icon') && !str_contains($template,">{{ dispute ? dispute.status_label : 'Actions' }}</span>"),'Row Action trigger must remain icon-only.');
$actionIcon=$root . '/web/themes/custom/merdpos_app/assets/error_24dp_E3E3E3_FILL0_wght400_GRAD0_opsz24.svg';
reports_v2_check(is_file($actionIcon) && str_contains((string)file_get_contents($actionIcon),'M508.5-291.5'),'User-supplied Shift Action icon asset missing or changed.');
$downloadIcon=$root . '/web/themes/custom/merdpos_app/assets/download_for_offline_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg';
reports_v2_check(is_file($downloadIcon) && str_contains((string)file_get_contents($downloadIcon),'M12 2C6.49 2 2 6.49 2 12'),'Requested Download For Offline PDF icon asset missing or changed.');
$voiceIcon=$root . '/web/themes/custom/merdpos_app/assets/record_voice_over_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg';
$adjustIcon=$root . '/web/themes/custom/merdpos_app/assets/adjust_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg';
reports_v2_check(is_file($voiceIcon) && str_contains((string)file_get_contents($voiceIcon),'m798-322-62-62'),'Requested record_voice_over Queries rail asset missing or changed.');
reports_v2_check(is_file($adjustIcon) && str_contains((string)file_get_contents($adjustIcon),'M565-395q35-35'),'Requested adjust Active Queries asset missing or changed.');
reports_v2_check(str_contains($js,'openMenu') && str_contains($js,"openDialog(trigger, mode)"),'Context menu must select a workflow before opening the dialog.');
reports_v2_check(str_contains($js,'focus({preventScroll:true})') && !str_contains($js,"window.addEventListener('scroll', closeMenu, true)"),'Shift Action menu must not close itself when focus causes scrolling.');
reports_v2_check(str_contains($js,"title.textContent = 'Query existing shift'") && str_contains($js,'Selected shift:') && str_contains($js,'bindQueryStore') && str_contains($js,'bindMissingStore'),'Simplified existing/missing shift Query contexts are not wired.');
reports_v2_check(!str_contains($js,'This submits a missing-shift Query') && !str_contains($js,'The shift log is prefilled below') && !str_contains($template,'data-query-shift-preview') && !str_contains($template,'data-dispute-type-select'),'Retired Query help/preview/Issue controls must stay removed.');
reports_v2_check(str_contains($template,'data-query-store-field hidden') && str_contains($template,'data-query-store-display readonly') && str_contains($template,'data-missing-store-field hidden') && str_contains($template,'data-missing-store-select') && str_contains($template,'<option value="">Choose Store</option>') && str_contains($template,'merdpos-dialog-actions merdpos-timesheet-form-actions'),'Conditional Store fields, Choose Store default, locked existing-shift Store or shared right-aligned dialog actions missing.');
reports_v2_check(str_contains($js,"missingStoreSelect.value = '';") && str_contains($js,"proposedStoreId.value = '';"),'Add missing shift must always reset Store to Choose Store.');
reports_v2_check(str_contains($provider,'$resolveRecentShiftId') && str_contains($provider,'$recentShiftRows') && str_contains($provider,'$candidates[0][\'score\'] <= 180'),'Recent authoritative shift fallback linking is missing for Query this shift.');
reports_v2_check(str_contains($routing,'ReportsController::legacyOperationsRedirect'),'Legacy Operations URL must redirect to Shift Detail.');

echo "MERDPOS Drupal Reports v2 validated.\n";
