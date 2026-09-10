<?php
declare(strict_types=1);
$root=dirname(__DIR__);function admin_context_check(bool $ok,string $m): void {if(!$ok)throw new RuntimeException($m);}
$controller=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Controller/AdministrationController.php');$template=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig');
$module=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/merdpos_core.module');$css=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/css/administration-v1.css');$page=(string)file_get_contents($root.'/web/themes/custom/merdpos_app/templates/page.html.twig');
admin_context_check(str_contains($controller,'private function requestedTab(Request $request): ?string'),'Administration current-tab resolver missing.');
admin_context_check(str_contains($controller,'redirect_tab')&&str_contains($controller,': $requestedTab;'),'Administration POST redirect does not preserve active child tab.');
admin_context_check(str_contains($template,"'tab': current_tab"),'Administration form actions do not preserve active child tab.');
admin_context_check(!str_contains($template,'merdpos-admin-client-switch--hero')&&!str_contains($template,'Working client'),'Working Client must not return to the Administration hero.');
admin_context_check(!str_contains($css,'.merdpos-admin-client-switch--hero'),'Retired Administration hero client-switch styling remains.');
admin_context_check(str_contains($page,'<details class="merdpos-account-context')&&str_contains($page,'data-merdpos-working-client'),'Working Client must live in the collapsed account context.');
admin_context_check(str_contains($page,'data-merdpos-context-section')&&str_contains($page,'data-merdpos-timesheet-sync'),'Working Client context/sync integration missing.');
admin_context_check(str_contains($module,"'current_tab' => 'stores'")&&str_contains($module,"'timezone_options' => []")&&str_contains($module,"'currency_options' => []"),'Administration Twig contract regressed.');
echo "MERDPOS Administration account-level client-context navigation validated.\n";
