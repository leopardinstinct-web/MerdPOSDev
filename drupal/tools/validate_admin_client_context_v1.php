<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$controller=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Controller/AdministrationController.php');
$template=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig');
$module=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/merdpos_core.module');
$css=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/css/administration-v1.css');
function admin_context_check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
admin_context_check(str_contains($controller,'private function requestedTab(Request $request): ?string'),'Administration current-tab resolver missing.');
admin_context_check(str_contains($controller,'redirect_tab') && str_contains($controller,': $requestedTab;'),'Administration POST redirect does not preserve the active child tab.');
admin_context_check(str_contains($controller,'#current_tab') && str_contains($controller,'$currentTab'),'Administration render does not expose canonical current_tab.');
admin_context_check(str_contains($template,"'tab': current_tab"),'Administration form actions do not preserve the active child tab.');
admin_context_check(str_contains($template,"current_tab in ['stores', 'defaults', 'workforce', 'roles']"),'Client switch is not scoped to Stores/Defaults/Workforce/Roles.');
admin_context_check(str_contains($template,'<input type="hidden" name="tab" value="{{ current_tab }}">'),'Client switch does not preserve the active child tab.');
admin_context_check(str_contains($template,'merdpos-admin-client-switch--hero'),'Client switch is not placed in the hero context position.');
admin_context_check(str_contains($template,'<label><span>Working client</span>'),'Client switch still uses ambiguous Manage client labeling.');
admin_context_check(str_contains($module,"'current_tab' => 'stores'"),'Administration Twig contract is missing current_tab.');
admin_context_check(str_contains($module,"'timezone_options' => []") && str_contains($module,"'currency_options' => []"),'Administration Twig contract drops store timezone/currency options.');
admin_context_check(str_contains($css,'.merdpos-admin-client-switch--hero'),'Hero client-switch styling missing.');
echo "MERDPOS Administration client-context navigation v1 validated.\n";
