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

function finance_write_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = (string) file_get_contents($module . '/src/Controller/FinanceController.php');
$template = (string) file_get_contents($module . '/templates/merdpos-finance.html.twig');
$routing = (string) file_get_contents($module . '/merdpos_core.routing.yml');
$libraries = (string) file_get_contents($module . '/merdpos_core.libraries.yml');
$theme = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$provider = (string) file_get_contents($module . '/src/Integration/ParityDataProvider.php');

finance_write_check(str_contains($routing, "path: '/merdpos/finance'"), 'Finance route missing.');
finance_write_check(str_contains($routing, "_permission: 'access merdpos portal'"), 'Finance route must allow MERDPOS USER role through to authoritative permission enforcement.');
finance_write_check(str_contains($controller, 'csrf->validate'), 'Drupal Finance CSRF validation missing.');
finance_write_check(str_contains($controller, "call('beta_state', 'GET'"), 'Finance named-permission preflight missing.');
finance_write_check(str_contains($controller, "call('financials', 'POST'"), 'Finance signed financials write call missing.');
foreach (['open_day','cash_movement','cash_in','cash_out','z_report'] as $action) finance_write_check(str_contains($controller, "'{$action}'"), "Finance action missing: {$action}");
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE '] as $forbidden) finance_write_check(!str_contains($controller, $forbidden), "Drupal Finance must not contain operational SQL: {$forbidden}");
foreach (['name="form_token"','name="finance_action" value="open_day"','name="finance_action" value="cash_movement"','name="finance_action" value="z_report"','data-finance-close-form','Signed MERDPOS write'] as $marker) finance_write_check(str_contains($template, $marker), "Finance template marker missing: {$marker}");
finance_write_check(!str_contains($template, '|raw'), 'Finance template must not bypass Twig escaping.');
finance_write_check(str_contains($libraries, 'js/finance-v3.js'), 'Finance write JS library missing.');
finance_write_check(str_contains($theme, "'backend_any'=>['finance.view']"), 'Finance shell navigation is not tied to finance.view.');
finance_write_check(str_contains($provider, "['read_only'] = false"), 'Finance provider is still marked read-only.');

putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('a', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');
$history = [];
$mock = new MockHandler([new Response(200, ['Content-Type'=>'application/json'], '{"success":true,"result":{"status":"sheet_pending","duplicate":false}}')]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$body = [
  'submission_id'=>'123e4567-e89b-42d3-a456-426614174000',
  'store_id'=>7,
  'business_date'=>'2026-09-08',
  'submission_type'=>'cash_in',
  'payload'=>['transactions'=>[['account'=>'Register','head'=>'Till movement','amount'=>20.0]]],
];
$result = $client->call('financials', 'POST', [], $body);
finance_write_check($result['status'] === 'ok', 'Signed Finance gateway POST did not resolve OK.');
finance_write_check(count($history) === 1, 'Expected one signed Finance gateway request.');
$envelope = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
finance_write_check(($envelope['route'] ?? '') === 'financials', 'Finance gateway route mismatch.');
finance_write_check(($envelope['method'] ?? '') === 'POST', 'Finance gateway method mismatch.');
finance_write_check(($envelope['body']['submission_type'] ?? '') === 'cash_in', 'Finance submission type was not preserved.');
finance_write_check(($envelope['body']['payload']['transactions'][0]['account'] ?? '') === 'Register', 'Finance payload was not preserved.');

foreach (['MERDPOS_DRUPAL_GATEWAY_URL','MERDPOS_DRUPAL_SERVICE_URL','MERDPOS_DRUPAL_SERVICE_SECRET','MERDPOS_DRUPAL_CLIENT_ID','MERDPOS_DRUPAL_ACTOR_USER_ID'] as $name) putenv($name);
echo "MERDPOS Drupal Finance Write Parity v1 contract validated.\n";