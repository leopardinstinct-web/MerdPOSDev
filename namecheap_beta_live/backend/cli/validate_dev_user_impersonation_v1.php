<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
function imp_read(string $path): string {$v=file_get_contents($path);if(!is_string($v))throw new RuntimeException('Unreadable '.$path);return $v;}
function imp_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$gateway=imp_read($root.'/backend/api/integrations/portal_gateway.php');
$actor=imp_read($root.'/backend/api/includes/service_actor.php');
$context=imp_read($root.'/timesheet_portal/api/client_context.php');
$policy=imp_read($root.'/timesheet_portal/includes/beta_api.php');
$disputes=imp_read($root.'/timesheet_portal/api/disputes.php');
imp_check(str_contains($gateway,'context_employee_id')&&str_contains($gateway,'merd_service_employee_actor_by_id'),'Signed gateway does not resolve a real client employee.');
imp_check(str_contains($gateway,"'actor_identity_scope'=>'platform'")&&str_contains($gateway,"'is_user_impersonation'=>true"),'Gateway does not preserve DEV actor separately from effective user.');
imp_check(str_contains($gateway,'change_password')&&str_contains($gateway,'Password changes are unavailable while DEV is impersonating'),'Password mutation is not blocked during impersonation.');
imp_check(str_contains($actor,"status='active'")&&str_contains($actor,"UPPER(TRIM(employee_type))<>'DEV'"),'Impersonation target is not restricted to active non-DEV employees.');
imp_check(str_contains($context,"'can_select_user'")&&str_contains($context,"'select_user'")&&str_contains($context,"'exit_user'"),'Working User context actions are incomplete.');
imp_check(str_contains($context,'client_context_users')&&str_contains($context,'e.client_id=?')&&str_contains($context,"e.status='active'"),'Working User directory is not client-scoped and active-only.');
imp_check(str_contains($policy,'beta_actor_is_platform_dev')&&str_contains($policy,"'impersonated_employee_id'"),'Actor/effective-user audit separation is missing.');
imp_check(str_contains($disputes,'dev.impersonation.dispute.')&&str_contains($disputes,'beta_admin_audit'),'Impersonated dispute writes are not explicitly audited.');
echo "MERDPOS DEV real-user impersonation contract validated.\n";
