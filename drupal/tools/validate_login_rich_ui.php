<?php
declare(strict_types=1);
$root=dirname(__DIR__);function rich_check(bool $ok,string $m): void {if(!$ok)throw new RuntimeException($m);}function rich_read(string $p): string {$s=file_get_contents($p);if(!is_string($s))throw new RuntimeException('Unreadable '.$p);return $s;}
$twig=rich_read($root.'/web/themes/custom/merdpos_app/templates/page.html.twig');$theme=rich_read($root.'/web/themes/custom/merdpos_app/merdpos_app.theme');$css=rich_read($root.'/web/themes/custom/merdpos_app/css/app-shell.css');
$services=rich_read($root.'/web/modules/custom/merdpos_core/merdpos_core.services.yml');$route=rich_read($root.'/web/modules/custom/merdpos_core/src/Routing/MerdposRouteSubscriber.php');$denied=rich_read($root.'/web/modules/custom/merdpos_core/src/Routing/MerdposAccessDeniedSubscriber.php');
$runtime=rich_read($root.'/tools/namecheap_resolve_runtime.php');$deploy=rich_read($root.'/tools/namecheap_deploy.sh');$composer=json_decode(rich_read($root.'/composer.json'),true,32,JSON_THROW_ON_ERROR);
foreach(['merdpos-logo-approved.png','merdpos-mark.png','merdpos-wordmark.png','M-dark-theme.png','MERDPOS-dark-theme.png'] as $asset) rich_check(is_file($root.'/web/themes/custom/merdpos_app/assets/'.$asset),'Shell/login asset missing: '.$asset);
rich_check(str_contains($twig,'Welcome back.')&&str_contains($twig,'merdpos-logo-approved.png'),'MERDPOS login brand regressed.');
rich_check(str_contains($twig,'data-light-src=')&&str_contains($twig,'M-dark-theme.png')&&str_contains($twig,'MERDPOS-dark-theme.png'),'Dark/light shell brand source switching missing.');
rich_check(!str_contains($twig,'merdpos-shell-tagline')&&!str_contains($twig,'<small>Drupal Beta</small>'),'Signed-in tagline/Drupal Beta label must remain removed.');
rich_check(str_contains($css,':root[data-theme="dark"] .merdpos-shell-brand')&&str_contains($css,'background:transparent')&&str_contains($css,'box-shadow:none'),'Dark shell brand must remain transparent without white glass.');
rich_check(!str_contains($css,'filter: brightness(0) invert(1)'),'Dark mode still destroys approved brand colors.');
rich_check(str_contains($twig,'Administration')&&str_contains($twig,'Timesheets')&&!str_contains($twig,'<strong>Reports</strong>')&&str_contains($twig,'Financials')&&str_contains($twig,'DEV'),'Login capability graphics missing.');
rich_check(str_contains($theme,'merdpos_core.identity_manager')&&str_contains($route,'MerdposLoginForm')&&str_contains($denied,'merdpos_core.'),'MERDPOS shell/auth integration regressed.');
rich_check(str_contains($services,'merdpos_core.authenticator')&&str_contains($services,'merdpos_core.identity_manager'),'MERDPOS auth services missing.');
rich_check(str_contains($runtime,'MERDPOS_DRUPAL_LOGIN_URL=')&&str_contains($runtime,'FROM platform_identities p'),'Platform DEV runtime identity resolution regressed.');
rich_check(str_contains($deploy,'config:set system.site page.front /merdpos -y'),'Drupal front page is not MERDPOS.');
foreach(['drupal/dashboard','drupal/charts','drupal/ui_patterns','drupal/ui_icons','drupal/gin','drupal/gin_toolbar','drupal/better_exposed_filters'] as $package) rich_check(isset(($composer['require']??[])[$package]),'Free UI package missing: '.$package);
echo "MERDPOS Drupal login + light/dark shell brand contract validated.\n";
