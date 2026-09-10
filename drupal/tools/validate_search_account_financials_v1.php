<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function saf_read(string $p): string {$s=file_get_contents($p);if(!is_string($s))throw new RuntimeException('Unreadable '.$p);return $s;}
function saf_check(bool $ok,string $m): void {if(!$ok)throw new RuntimeException($m);}
$admin=saf_read($root.'/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig');$adminJs=saf_read($root.'/web/modules/custom/merdpos_core/js/administration-v1.js');
$reports=saf_read($root.'/web/modules/custom/merdpos_core/templates/merdpos-reports.html.twig');$reportsJs=saf_read($root.'/web/modules/custom/merdpos_core/js/reports-v2.js');
$page=saf_read($root.'/web/themes/custom/merdpos_app/templates/page.html.twig');$theme=saf_read($root.'/web/themes/custom/merdpos_app/merdpos_app.theme');
$routing=saf_read($root.'/web/modules/custom/merdpos_core/merdpos_core.routing.yml');$provider=saf_read($root.'/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');$css=saf_read($root.'/web/themes/custom/merdpos_app/css/app-shell.css');
saf_check(substr_count($admin,'data-admin-search=')===3 && substr_count($admin,'data-search-text=')>=3,'Administration search contract regressed.');
saf_check(str_contains($adminJs,'item.dataset.searchText')&&!str_contains($adminJs,'item.textContent.toLowerCase().includes(query)'),'Admin search scans hidden controls.');
saf_check(str_contains($reports,'data-timesheet-action')&&str_contains($reports,'data-timesheet-dialog')&&str_contains($reportsJs,'openDialog')&&!str_contains($reportsJs,'fetch('),'Timesheet row actions regressed.');
saf_check(str_contains($page,'data-merdpos-theme-toggle')&&str_contains($page,'data-merdpos-theme-label')&&!str_contains($page,'class="merdpos-account-theme"'),'Authenticated theme must be the compact icon action.');
saf_check(str_contains($page,'merdpos-theme-control--login'),'Login theme control must remain available.');
saf_check(!str_contains($page,'merdpos-account-brand-glass'),'Account-menu brand glass must not return.');
saf_check(str_contains($page,'merdpos-account-pills')&&str_contains($page,'data-merdpos-client-code')&&str_contains($page,'data-merdpos-role-pill'),'Working client/role pills missing beside account trigger.');
saf_check(substr_count($page,'<details class="merdpos-account-context')>=2&&str_contains($page,'data-merdpos-working-role'),'Collapsed Working Client/Working Role contexts missing.');
saf_check(str_contains($page,'M-dark-theme.png')&&str_contains($page,'MERDPOS-dark-theme.png'),'Uploaded dark-theme shell assets are not wired.');
saf_check(str_contains($theme,"['key'=>'finance','label'=>'Financials'")&&str_contains($provider,"'finance','Financials','Cashflow Transactions'")&&str_contains($routing,"_title: 'Financials'"),'Financials naming contract regressed.');
echo "MERDPOS application search + compact account context + Financials contract validated.\n";
