<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function dui_read(string $path): string {$v=file_get_contents($path);if(!is_string($v))throw new RuntimeException('Unreadable '.$path);return $v;}
function dui_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$account=dui_read($root.'/web/modules/custom/merdpos_core/src/Controller/AccountController.php');
$gateway=dui_read($root.'/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClient.php');
$routing=dui_read($root.'/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
$theme=dui_read($root.'/web/themes/custom/merdpos_app/merdpos_app.theme');
$page=dui_read($root.'/web/themes/custom/merdpos_app/templates/page.html.twig');
$js=dui_read($root.'/web/themes/custom/merdpos_app/js/app-shell.js');
$dev=dui_read($root.'/web/modules/custom/merdpos_core/src/Controller/DevController.php');
dui_check(str_contains($routing,'/merdpos/account/working-user')&&!str_contains($routing,'/merdpos/account/working-role'),'Working User route did not replace Working Role.');
dui_check(str_contains($account,'WORKING_USER_TOKEN_ID')&&str_contains($account,"'action'=>'select_user'")&&str_contains($account,"'action'=>'exit_user'"),'Working User controller actions or CSRF contract missing.');
dui_check(str_contains($gateway,'context_employee_id')&&str_contains($gateway,'merdpos_context_employee_id'),'Signed employee context forwarding missing.');
dui_check(str_contains($theme,'merdpos_can_select_user')&&str_contains($theme,'merdpos_impersonating'),'Theme impersonation state contract missing.');
dui_check(str_contains($page,'data-merdpos-working-user')&&str_contains($page,'data-merdpos-impersonation-banner')&&str_contains($page,'data-merdpos-exit-impersonation'),'Working User selector/banner/exit controls missing.');
dui_check(str_contains($js,'JSON.stringify({employee_id:employeeId})')&&str_contains($js,'employeeId > 0 && onDevSurface'),'Working User client behavior incomplete.');
dui_check(str_contains($dev,'merdpos_context_employee_id')&&str_contains($dev,"new RedirectResponse('/merdpos', 302)"),'DEV-only surface does not fail closed while impersonating.');
dui_check(str_contains($theme,'merdpos_can_change_password')&&str_contains($theme,'password.change_own')&&str_contains($theme,'merdpos_impersonating'),'Password change must be hidden while impersonating.');
echo "MERDPOS Drupal DEV real-user impersonation UI/security contract validated.\n";
