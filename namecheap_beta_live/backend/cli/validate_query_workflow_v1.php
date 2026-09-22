<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
function query_read(string $path): string {$v=file_get_contents($path);if(!is_string($v))throw new RuntimeException('Unreadable '.$path);return $v;}
function query_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$gateway=query_read($root.'/backend/api/integrations/portal_gateway.php');
$api=query_read($root.'/timesheet_portal/api/disputes.php');
$weeks=query_read($root.'/timesheet_portal/api/weeks.php');
$timesheet=query_read($root.'/timesheet_portal/api/timesheet.php');
$workforce=query_read($root.'/backend/api/includes/workforce_beta.php');
query_check(str_contains($gateway,'$contextEmployeeId !== null && $contextClientId === null')&&str_contains($gateway,'Working User context requires an explicit Working Client context'),'Gateway does not fail closed on an unpaired Working User context.');
query_check(str_contains($gateway,'merd_service_employee_actor_by_id($pdo,$contextClientId,$contextEmployeeId)'),'Gateway does not resolve the effective employee inside the explicit Working Client.');
query_check(str_contains($api,'expected_client_id')&&str_contains($api,'expected_employee_id')&&str_contains($api,'query_context_changed'),'Query API does not enforce submitted client/employee context.');
query_check(str_contains($api,"trim((string)(\$input['submission_id'] ?? ''))")&&str_contains($api,"'pending', 'employee'"),'Query API does not forward the idempotent submission reference.');
query_check(str_contains($workforce,'?string $submissionId = null')&&str_contains($workforce,'invalid_submission')&&str_contains($workforce,'submission_conflict'),'Query write helper does not validate/idempotently bind submission IDs.');
query_check(str_contains($workforce,'$publicId = $submissionId ?? merd_uuid_v4();'),'Query write helper does not persist the client submission ID as the authoritative Query ID.');
query_check(str_contains($weeks,"FROM employee_logs WHERE client_id=? AND UPPER(log_type)='IN'")&&str_contains($weeks,'employee_id=?')&&str_contains($weeks,'LOWER(user_name)=LOWER(?)'),'Week discovery is not aligned with the SQL Timesheet source/effective employee.');
query_check(str_contains($api,'dev.impersonation.dispute.')&&str_contains($api,'beta_admin_audit'),'Impersonated Query writes are not explicitly audit-attributed to DEV.');
query_check(str_contains($timesheet,"'logpair:'")&&str_contains($timesheet,"\$row['employee_id']")&&str_contains($timesheet,"\$row['store_id']"),'Closed SQL Timesheet rows without attendance_shifts are not given stable Query targets.');
query_check(str_contains($workforce,'function merd_logpair_target')&&str_contains($workforce,'function merd_logpair_rows')&&str_contains($workforce,"'source_type'=>'employee_logs'")&&str_contains($workforce,"'attendance_log_correction'"),'Query backend does not securely support SQL employee_logs row-pair targets.');
query_check(str_contains($workforce,"merd_logpair_target((string)\$row['shift_public_id']) === null"),'Legacy log-pair Query approval must not enter attendance_shift resync.');
echo "MERDPOS Query workflow v1 validated.\n";
