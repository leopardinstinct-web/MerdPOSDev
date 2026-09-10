<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClient.php';

use Drupal\merdpos_core\Integration\PortalGatewayClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function account_password_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = (string) file_get_contents($module . '/src/Controller/AccountController.php');
$routing = (string) file_get_contents($module . '/merdpos_core.routing.yml');
$services = (string) file_get_contents($module . '/merdpos_core.services.yml');
$theme = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$template = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/templates/page.html.twig');
$js = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/js/app-shell.js');
$css = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/css/app-shell.css');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');

account_password_check(str_contains($routing, "path: '/merdpos/account/change-password'"), 'Change-password route missing.');
account_password_check(str_contains($routing, 'AccountController::changePassword'), 'Change-password controller route missing.');
account_password_check(str_contains($routing, 'methods: [POST]'), 'Change-password route must be POST only.');
account_password_check(str_contains($services, 'merdpos_core.account_controller'), 'Account controller service missing.');
account_password_check(str_contains($controller, 'csrf->validate'), 'Drupal CSRF validation missing from password change.');
account_password_check(str_contains($controller, "call('beta_state', 'GET'"), 'Password permission preflight missing.');
account_password_check(str_contains($controller, "'password.change_own'"), 'Authoritative password.change_own permission missing.');
account_password_check(str_contains($controller, "call('change_password', 'POST'"), 'Signed change_password gateway call missing.');
account_password_check(str_contains($controller, "'/^\\d{6,20}$/'"), '6–20 digit new-password contract missing.');
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE ','password_hash'] as $forbidden) account_password_check(!str_contains($controller, $forbidden), "Drupal account controller must not own password storage logic: {$forbidden}");
foreach (['Change password','Current password','New password','Confirm new password','data-merdpos-password-open','data-merdpos-password-dialog','name="form_token"'] as $marker) account_password_check(str_contains($template, $marker), "Password dialog marker missing: {$marker}");
account_password_check(!str_contains($template, '|raw'), 'Account template must not bypass Twig escaping.');
account_password_check(str_contains($theme, "password.change_own"), 'Account menu is not permission-scoped.');
account_password_check(str_contains($js, 'showModal'), 'Password dialog open behavior missing.');
account_password_check(str_contains($js, 'merdpos-password-close'), 'Password dialog close behavior missing.');
account_password_check(str_contains($css, '.merdpos-password-dialog'), 'Password dialog styling missing.');
account_password_check(str_contains($css, '@media(max-width:35rem)'), 'Password dialog mobile adaptation missing.');
account_password_check(!str_contains($template, 'merdpos-shell-tagline'), 'Authenticated shell tagline must remain removed.');
account_password_check(str_contains($template, '/assets/merdpos-logo-approved.png'), 'Approved MERDPOS login lockup asset is missing.');
account_password_check(!str_contains($template, 'merdpos-account-brand-glass') && !str_contains($css, 'merdpos-account-brand-glass'), 'Account-menu brand glass returned.');
account_password_check(str_contains($css, ':root[data-theme="dark"] .merdpos-login-brand'), 'Dark-theme login lockup contrast treatment missing.');
account_password_check(is_file($root . '/web/themes/custom/merdpos_app/assets/merdpos-logo-approved.png'), 'Approved MERDPOS logo asset missing.');
account_password_check(is_file($root . '/web/themes/custom/merdpos_app/assets/merdpos-tagline.png'), 'Approved MERDPOS tagline asset missing.');
account_password_check(str_contains($deploy, 'Account password parity self-test failed.'), 'Password live deployment probe missing.');

putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('a', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');
$history = [];
$mock = new MockHandler([new Response(200, ['Content-Type'=>'application/json'], '{"success":true,"message":"Password changed."}')]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$result = $client->call('change_password', 'POST', [], ['current_password'=>'111111','new_password'=>'222222','confirm_password'=>'222222']);
account_password_check($result['status'] === 'ok', 'Signed password gateway POST did not resolve OK.');
account_password_check(count($history) === 1, 'Expected one signed password request.');
$envelope = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
account_password_check(($envelope['route'] ?? '') === 'change_password', 'Password gateway route mismatch.');
account_password_check(($envelope['method'] ?? '') === 'POST', 'Password gateway method mismatch.');
account_password_check(($envelope['body']['current_password'] ?? '') === '111111', 'Current password was not preserved in signed body.');
account_password_check(($envelope['body']['new_password'] ?? '') === '222222', 'New password was not preserved in signed body.');

foreach (['MERDPOS_DRUPAL_GATEWAY_URL','MERDPOS_DRUPAL_SERVICE_URL','MERDPOS_DRUPAL_SERVICE_SECRET','MERDPOS_DRUPAL_CLIENT_ID','MERDPOS_DRUPAL_ACTOR_USER_ID'] as $name) putenv($name);
echo "MERDPOS Drupal Account Password Parity v1 contract validated.\n";