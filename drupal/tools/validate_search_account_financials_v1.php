<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function saf_read(string $p): string { $s=file_get_contents($p); if(!is_string($s)) throw new RuntimeException('Unreadable '.$p); return $s; }
function saf_check(bool $ok,string $m): void { if(!$ok) throw new RuntimeException($m); }
$admin=saf_read($root.'/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig');
$adminJs=saf_read($root.'/web/modules/custom/merdpos_core/js/administration-v1.js');
$reports=saf_read($root.'/web/modules/custom/merdpos_core/templates/merdpos-reports.html.twig');
$reportsJs=saf_read($root.'/web/modules/custom/merdpos_core/js/reports-v2.js');
$page=saf_read($root.'/web/themes/custom/merdpos_app/templates/page.html.twig');
$theme=saf_read($root.'/web/themes/custom/merdpos_app/merdpos_app.theme');
$routing=saf_read($root.'/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
$provider=saf_read($root.'/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
$css=saf_read($root.'/web/themes/custom/merdpos_app/css/app-shell.css');
saf_check(substr_count($admin,'data-admin-search=')===3,'Expected exactly Clients/Stores/Workforce Admin search boxes.');
saf_check(substr_count($admin,'data-search-text=')>=3,'Admin record-specific search indexes missing.');
saf_check(str_contains($admin,'{{ employee.role_label }}') && str_contains($admin,'{{ employee.user_id }}'),'Workforce search index does not include role and user ID.');
saf_check(str_contains($adminJs,"item.dataset.searchText") && !str_contains($adminJs,"item.textContent.toLowerCase().includes(query)"),'Admin search still scans hidden editor controls.');
saf_check(str_contains($reports,'data-timesheet-action') && str_contains($reports,'data-timesheet-dialog'),'Timesheet row actions are not integrated into Reports.');
saf_check(str_contains($reportsJs,'openDialog') && !str_contains($reportsJs,'fetch('),'Timesheet actions must remain form-driven.');
saf_check(str_contains($page,'class="merdpos-account-theme"') && str_contains($page,'<option value="dark">Dark</option>'),'Theme selector is not inside authenticated account menu.');
saf_check(str_contains($page,'merdpos-theme-control--login') && !str_contains($page,'<label class="merdpos-theme-control">'),'Authenticated header theme selector was not removed while login theme control is preserved.');
saf_check(!str_contains($page,'MERDPOS ID') && !str_contains($page,'merdpos-account-id'),'Irrelevant MERDPOS ID remains in account menu.');
saf_check(str_contains($theme,"['key' => 'finance', 'label' => 'Financials'") && str_contains($page,'<strong>Financials</strong><small>Cash · Ledger</small>'),'Financials label is not consistent in shell/login UI.');
saf_check(str_contains($routing,"_title: 'Financials'") && str_contains($provider,"'finance','Financials','Financial command centre'"),'Financials route/surface label not renamed.');
saf_check(str_contains($css,'.merdpos-account-theme select'),'Account-menu theme control styling missing.');
echo "MERDPOS application search + account menu + Financials v1 contract validated.\n";
