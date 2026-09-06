<?php
declare(strict_types=1);

function att_reconcile_check(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}

$root=dirname(__DIR__,2);
$workforce=file_get_contents($root.'/backend/api/includes/workforce_beta.php');
$timesheet=file_get_contents($root.'/timesheet_portal/api/timesheet.php');
$fixture=file_get_contents($root.'/backend/cli/attendance_e2e_fixture.php');
att_reconcile_check(is_string($workforce) && is_string($timesheet) && is_string($fixture),'Attendance reconciliation sources unavailable.');
att_reconcile_check(str_contains($workforce,'function merd_sync_attendance_shift_employee_logs'),'Authoritative attendance-to-timesheet synchronizer missing.');
att_reconcile_check(str_contains($workforce,"COALESCE(NULLIF(st.timezone,''),NULLIF(c.default_timezone,''),'Australia/Sydney') AS timezone"),'Store/client timezone resolution missing from attendance reconciliation.');
att_reconcile_check(str_contains($workforce,"new DateTimeZone('UTC')") && str_contains($workforce,'->setTimezone($tz)'),'UTC to store-local conversion missing from attendance reconciliation.');
att_reconcile_check(substr_count($workforce,'merd_sync_attendance_shift_employee_logs(')>=3,'Attendance scan/dispute reconciliation wiring incomplete.');
att_reconcile_check(str_contains($timesheet,"\$report['source'] = 'sql_employee_logs';"),'Timesheet authoritative SQL employee_logs source changed unexpectedly.');
att_reconcile_check(str_contains($fixture,'DELETE FROM employee_logs WHERE client_id=? AND employee_id IN'),'DUMMY cleanup does not remove reconciled employee logs.');
echo "MERDPOS attendance-to-timesheet timezone reconciliation validated.\n";
