<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
function query_read(string $path): string {$v=file_get_contents($path);if(!is_string($v))throw new RuntimeException('Unreadable '.$path);return $v;}
function query_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$gateway=query_read($root.'/backend/api/integrations/portal_gateway.php');
$api=query_read($root.'/timesheet_portal/api/disputes.php');
$weeks=query_read($root.'/timesheet_portal/api/weeks.php');
$workforce=query_read($root.'/backend/api/includes/workforce_beta.php');
query_check(str_contains($gateway,'$contextEmployeeId !== null && $contextClientId === null')&&str_contains($gateway,'Working User context requires an explicit Working Client context'),'Gateway does not fail closed on an unpaired Working User context.');
query_check(str_contains($gateway,'merd_service_employee_actor_by_id($pdo,$contextClientId,$contextEmployeeId)'),'Gateway does not resolve the effective employee inside the explicit Working Client.');
query_check(str_contains($api,'expected_client_id')&&str_contains($api,'expected_employee_id')&&str_contains($api,'query_context_changed'),'Query API does not enforce submitted client/employee context.');
query_check(str_contains($api,"trim((string)(\$input['submission_id'] ?? ''))")&&str_contains($api,"'pending', 'employee'"),'Query API does not forward the idempotent submission reference.');
query_check(str_contains($workforce,'?string $submissionId = null')&&str_contains($workforce,'invalid_submission')&&str_contains($workforce,'submission_conflict'),'Query write helper does not validate/idempotently bind submission IDs.');
query_check(str_contains($workforce,'$publicId = $submissionId ?? merd_uuid_v4();'),'Query write helper does not persist the client submission ID as the authoritative Query ID.');
query_check(str_contains($weeks,"FROM employee_logs WHERE client_id=? AND UPPER(log_type)='IN'")&&str_contains($weeks,'employee_id=?')&&str_contains($weeks,'LOWER(user_name)=LOWER(?)'),'Week discovery is not aligned with the SQL Timesheet source/effective employee.');
query_check(str_contains($api,'dev.impersonation.dispute.')&&str_contains($api,'beta_admin_audit'),'Impersonated Query writes are not explicitly audit-attributed to DEV.');
echo "MERDPOS Query workflow v1 validated.\n";
