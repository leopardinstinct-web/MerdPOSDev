<?php
declare(strict_types=1);
$root=dirname(__DIR__);function roles_shell_read(string $p): string {$s=file_get_contents($p);if(!is_string($s))throw new RuntimeException('Unreadable '.$p);return $s;}function roles_shell_check(bool $ok,string $m): void {if(!$ok)throw new RuntimeException($m);}
$controller=roles_shell_read($root.'/web/modules/custom/merdpos_core/src/Controller/AdministrationController.php');$template=roles_shell_read($root.'/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig');$module=roles_shell_read($root.'/web/modules/custom/merdpos_core/merdpos_core.module');
$theme=roles_shell_read($root.'/web/themes/custom/merdpos_app/merdpos_app.theme');$page=roles_shell_read($root.'/web/themes/custom/merdpos_app/templates/page.html.twig');$shellJs=roles_shell_read($root.'/web/themes/custom/merdpos_app/js/app-shell.js');$devController=roles_shell_read($root.'/web/modules/custom/merdpos_core/src/Controller/DevController.php');
roles_shell_check(str_contains($controller,"call('role_authority', 'GET'")&&str_contains($controller,"call('role_authority', 'POST'"),'Admin Roles signed gateway wiring missing.');
roles_shell_check(str_contains($controller,"'create_role'")&&str_contains($controller,"'save_role'")&&str_contains($controller,"'save_role_usability'"),'Admin role write actions incomplete.');
roles_shell_check(str_contains($module,"'role_state' => []")&&str_contains($template,'data-admin-panel="roles"')&&str_contains($template,'role_state.can_define_roles'),'Admin Roles contract incomplete.');
$home=strpos($theme,"['key'=>'home'");$admin=strpos($theme,"['key'=>'admin'");$finance=strpos($theme,"['key'=>'finance'");$reports=strpos($theme,"['key'=>'reports'");
roles_shell_check($home!==false&&$admin>$home&&$finance>$admin&&$reports>$finance,'Bottom navigation order must be Dashboard, Admin, Financials, Timesheets.');
roles_shell_check(str_contains($theme,"['key'=>'home','label'=>'Dashboard'")&&str_contains($theme,"['key'=>'reports','label'=>'Timesheets'")&&!str_contains($theme,"'label'=>'Reports'"),'Dashboard/Timesheets primary labels regressed.');
roles_shell_check(!str_contains($theme,"['key'=>'operations'")&&!str_contains($theme,"['key'=>'dev'"),'Operations or DEV returned to bottom navigation.');
roles_shell_check(str_contains($theme,"$"."variables['merdpos_can_select_role']")&&str_contains($page,'data-merdpos-working-role'),'DEV-only Working Role selector missing.');
roles_shell_check(str_contains($shellJs,"roleKey !== 'DEV' && onDevSurface")&&str_contains($shellJs,"window.location.assign('/merdpos')")&&str_contains($devController,"$"."previewRole !== 'DEV'")&&str_contains($devController,"new RedirectResponse('/merdpos', 302)"),'Working Role must leave DEV-only surface when previewing a client role.');
roles_shell_check(str_contains($page,'<span>DEV</span>')&&str_contains($page,'m8 9-4 3 4 3'),'DEV account action must remain icon + DEV.');
roles_shell_check(str_contains($page,"item.key == 'home'")&&str_contains($page,'<rect x="3" y="3" width="7"')&&str_contains($page,"item.key == 'reports'")&&str_contains($page,'<circle cx="12" cy="12" r="9"'),'Dashboard/Timesheets navigation icons regressed.');
echo "MERDPOS Admin Roles + role-aware four-item shell contract validated.\n";
