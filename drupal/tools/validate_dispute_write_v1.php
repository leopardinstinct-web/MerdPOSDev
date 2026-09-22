<?php
declare(strict_types=1);
function query_v1_check(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
$root=dirname(__DIR__);
$controller=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Controller/DisputesController.php');
$template=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/templates/merdpos-reports.html.twig');
$js=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/js/reports-v2.js');
$css=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/css/reports-v2.css');
$routing=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
$provider=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
foreach(compact('controller','template','js','css','routing','provider') as $value) query_v1_check($value!=='','Integrated Query source is unreadable.');
query_v1_check(str_contains($routing,"path: '/merdpos/queries'")&&str_contains($routing,'DisputesController::queries'),'Query POST route missing.');
query_v1_check(str_contains($controller,'merdpos_queries_v1')&&str_contains($controller,'csrf->validate'),'Query CSRF validation missing.');
query_v1_check(str_contains($controller,"call('disputes', 'POST'")&&str_contains($controller,'expected_client_id')&&str_contains($controller,'expected_employee_id'),'Query write must use signed gateway with bound context.');
query_v1_check(str_contains($controller,'submission_id')&&str_contains($controller,'retryable'),'Idempotent/offline Query controller contract missing.');
foreach(['data-query-root','data-timesheet-menu-choice="query"','Query this shift','data-timesheet-menu-choice="missing"','Add missing shift','data-clock-in-local','data-clock-out-local','data-query-store-display readonly','data-missing-store-select','Submit Query','data-query-notice','data-query-active-value'] as $needle) query_v1_check(str_contains($template,$needle),'Timesheet Query UI missing: '.$needle);
foreach(['data-query-shift-preview','data-dispute-type-select','The shift log is prefilled below. Adjust the clock times only if they need correcting.','This submits a missing-shift Query; it does not alter an existing shift until approved.'] as $retired) query_v1_check(!str_contains($template,$retired) && !str_contains($js,$retired),'Retired Timesheet Query UI returned: '.$retired);
$queryMenuPos=strpos($template,'data-timesheet-menu-choice="query"'); $missingMenuPos=strpos($template,'data-timesheet-menu-choice="missing"');
query_v1_check($queryMenuPos!==false && $missingMenuPos!==false && $queryMenuPos < $missingMenuPos,'Timesheet Action menu must render Query this shift before Add missing shift.');
query_v1_check(!str_contains($template,'Dispute existing shift')&&!str_contains($template,'Submit dispute'),'Retired Dispute wording remains in active Timesheets UI.');
query_v1_check(str_contains($provider,"call('client_context')")&&str_contains($provider,"'clock_in_local'")&&str_contains($provider,"'clock_out_local'")&&str_contains($provider,"'query_context'"),'Query context/prefill provider wiring missing.');
query_v1_check(str_contains($js,'merdpos_query_queue_v1')&&str_contains($js,'localStorage')&&str_contains($js,'navigator.onLine')&&str_contains($js,"window.addEventListener('online', flushQueue)"),'Offline Query queue/sync missing.');
query_v1_check(str_contains($js,'expected_client_id')&&str_contains($js,'expected_employee_id')&&str_contains($js,'submission_id')&&str_contains($js,"'Query submitted.'"),'Client/user binding or immediate Query feedback missing.');
query_v1_check(str_contains($provider,'$this->call(\'weeks\')')&&str_contains($provider,"'week_options'")&&str_contains($provider,"'selected_week'"),'Historical week options are not wired through the authoritative weeks endpoint.');
query_v1_check(str_contains($css,'.merdpos-query-notice')&&str_contains($css,'.merdpos-query-sync-badge')&&!str_contains($css,'.merdpos-query-shift-preview'),'Query state styling or retired preview cleanup missing.');
query_v1_check(str_contains($js,'Selected shift:')&&str_contains($js,'bindQueryStore')&&str_contains($js,'bindMissingStore'),'Query selected-date / locked-store behavior missing.');
echo "MERDPOS integrated Timesheet Query contract validated.\n";
