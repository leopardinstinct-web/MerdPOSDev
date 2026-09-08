<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function roles_shell_read(string $path): string { $s=file_get_contents($path); if(!is_string($s)) throw new RuntimeException('Unreadable: '.$path); return $s; }
function roles_shell_check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
$controller=roles_shell_read($root.'/web/modules/custom/merdpos_core/src/Controller/AdministrationController.php');
$template=roles_shell_read($root.'/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig');
$module=roles_shell_read($root.'/web/modules/custom/merdpos_core/merdpos_core.module');
$theme=roles_shell_read($root.'/web/themes/custom/merdpos_app/merdpos_app.theme');
$page=roles_shell_read($root.'/web/themes/custom/merdpos_app/templates/page.html.twig');
roles_shell_check(str_contains($controller,"call('role_authority', 'GET'") && str_contains($controller,"call('role_authority', 'POST'"),'Admin Roles is not wired through the signed role_authority gateway.');
roles_shell_check(str_contains($controller,"'create_role'") && str_contains($controller,"'save_role'") && str_contains($controller,"'delete_role'") && str_contains($controller,"'save_role_permissions'"),'Admin role write actions are incomplete.');
roles_shell_check(str_contains($controller,"'workforce', 'roles'") && str_contains($controller,"'workforce','roles'"),'Roles tab is not preserved across Admin GET/POST navigation.');
roles_shell_check(str_contains($module,"'role_state' => []") && str_contains($module,"'can_manage_roles' => false"),'Admin Roles Twig contract is incomplete.');
roles_shell_check(str_contains($template,'data-admin-panel="roles"') && str_contains($template,'roles.manage'),'Roles panel or permission marker missing.');
roles_shell_check(str_contains($template,'role_state.can_manage_system_roles') && str_contains($template,'can_manage_permissions'),'System-role and permission-threshold boundaries are not represented in Admin Roles.');
$home=strpos($theme,"['key' => 'home'"); $admin=strpos($theme,"['key' => 'admin'"); $finance=strpos($theme,"['key' => 'finance'"); $reports=strpos($theme,"['key' => 'reports'");
roles_shell_check($home!==false && $admin>$home && $finance>$admin && $reports>$finance,'Bottom navigation is not ordered Home, Admin, Finance, Reports.');
roles_shell_check(!str_contains($theme,"['key' => 'operations'") && !str_contains($theme,"['key' => 'dev'"),'Operations or DEV still appears in bottom-navigation items.');
roles_shell_check(str_contains($theme,"$"."variables['merdpos_dev_url']") && str_contains($page,'{% if merdpos_dev_url %}<a href="{{ merdpos_dev_url }}">DEV</a>{% endif %}'),'DEV is not in the account menu.');
roles_shell_check(str_contains($theme,"['key'=>'roles','label'=>'Roles']"),'Permission-aware Admin Roles shell tab missing.');
echo "MERDPOS Admin Roles + four-item shell v1 contract validated.\n";
