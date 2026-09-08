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

function timesheet_sync_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = (string) file_get_contents($module . '/src/Controller/AccountController.php');
$gateway = (string) file_get_contents($module . '/src/Integration/PortalGatewayClient.php');
$admin = (string) file_get_contents($module . '/src/Controller/AdministrationController.php');
$routing = (string) file_get_contents($module . '/merdpos_core.routing.yml');
$services = (string) file_get_contents($module . '/merdpos_core.services.yml');
$theme = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$template = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/templates/page.html.twig');
$js = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/js/app-shell.js');
$css = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/css/app-shell.css');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');

timesheet_sync_check(str_contains($routing, "path: '/merdpos/account/working-client'"), 'Working client route missing.');
timesheet_sync_check(str_contains($routing, "path: '/merdpos/account/timesheet-google-sync'"), 'Time Sheet sync route missing.');
timesheet_sync_check(substr_count($routing, 'methods: [POST]') >= 5, 'Account context routes must be POST-only.');
timesheet_sync_check(str_contains($controller, "WORKING_CLIENT_TOKEN_ID = 'merdpos_working_client_v1'"), 'Working client CSRF boundary missing.');
timesheet_sync_check(str_contains($controller, "TIMESHEET_SYNC_TOKEN_ID = 'merdpos_timesheet_google_sync_v1'"), 'Time Sheet sync CSRF boundary missing.');
timesheet_sync_check(str_contains($controller, "call('client_context', 'POST'"), 'Working client selection does not use canonical client_context POST.');
timesheet_sync_check(str_contains($controller, "call('timesheet_google_refresh', 'POST'"), 'Time Sheet sync does not use canonical signed route.');
timesheet_sync_check(str_contains($controller, "'action'=>'refresh_timesheet'"), 'Canonical refresh_timesheet action missing.');
timesheet_sync_check(str_contains($controller, 'activeClientId !== (int) $clientId'), 'Stale Working client guard missing.');
timesheet_sync_check(str_contains($controller, "getSession()->set('merdpos_context_client_id'"), 'Validated Working client is not persisted in Drupal session.');
foreach (['DELETE FROM employee_logs','INSERT INTO employee_logs','admin_audit_logs','legacy_google_fetch_tab'] as $forbidden) {
  timesheet_sync_check(!str_contains($controller, $forbidden), "Drupal must not own Time Sheet replacement logic: {$forbidden}");
}

timesheet_sync_check(str_contains($gateway, 'sessionContextClientId'), 'Gateway does not carry Drupal Working client context.');
timesheet_sync_check(str_contains($gateway, "'merdpos_context_client_id'"), 'Gateway session context key missing.');
timesheet_sync_check(str_contains($gateway, "'timesheet_google_refresh'") && str_contains($gateway, '190.0'), 'Long-running Time Sheet sync timeout missing.');
timesheet_sync_check(str_contains($services, "'@user.data', '@request_stack'"), 'Gateway request-stack wiring missing.');
timesheet_sync_check(str_contains($admin, '$activeClientId') && str_contains($admin, "getSession()->set('merdpos_context_client_id'"), 'Administration is not aligned to global Working client context.');

foreach (['Working client','Select working client','Sync','Sync Time Sheet from Google','Replace SQL Time Sheet from Google'] as $marker) {
  timesheet_sync_check(str_contains($template, $marker), "Account Working client marker missing: {$marker}");
}
timesheet_sync_check(str_contains($template, '/assets/restart_alt_48px.svg'), 'Canonical restart icon is not used.');
timesheet_sync_check(str_contains($theme, "call('client_context', 'GET'"), 'Shell does not read canonical Working client state.');
timesheet_sync_check(str_contains($theme, "merdpos_can_timesheet_sync"), 'Actual-DEV sync visibility state missing.');
timesheet_sync_check(str_contains($js, 'Replace all ${clientName} SQL Time Sheet data with the latest Google'), 'Beta-equivalent destructive confirmation missing.');
timesheet_sync_check(str_contains($js, "if (!approved) return;"), 'Sync must stop before any request when confirmation is cancelled.');
timesheet_sync_check(str_contains($js, 'Time Sheet synced - ${imported.toLocaleString()} rows imported from Google.'), 'Beta success notice missing.');
timesheet_sync_check(str_contains($controller, "'message'=>'Working client changed to '"), 'Working client success notice missing.');
timesheet_sync_check(str_contains($css, '.merdpos-account-timesheet-sync'), 'Time Sheet sync styling missing.');
timesheet_sync_check(str_contains($css, 'merd-timesheet-sync-spin'), 'Time Sheet sync busy-state animation missing.');
timesheet_sync_check(str_contains($deploy, 'Time Sheet sync parity self-test failed.'), 'Time Sheet live deployment probe missing.');

$icon = $root . '/web/themes/custom/merdpos_app/assets/restart_alt_48px.svg';
$canonicalIcon = dirname($root) . '/namecheap_beta_live/timesheet_portal/assets/vendor/google-material-symbols/restart_alt_48px.svg';
timesheet_sync_check(is_file($icon) && is_file($canonicalIcon), 'Restart icon source or Drupal copy missing.');
timesheet_sync_check(hash_file('sha256', $icon) === hash_file('sha256', $canonicalIcon), 'Drupal restart icon differs from canonical Beta asset.');

$twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
$twig->addFunction(new \Twig\TwigFunction('path', static fn(string $route, array $parameters = [], array $options = []): string => '/merdpos'));
$twig->parse($twig->tokenize(new \Twig\Source($template, 'page.html.twig')));

putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('s', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');
$history = [];
$mock = new MockHandler([new Response(200, ['Content-Type'=>'application/json'], '{"success":true,"inserted_rows":42,"message":"Time Sheet synced from Google."}')]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$result = $client->call('timesheet_google_refresh', 'POST', [], ['action'=>'refresh_timesheet','client_id'=>2], 2);
timesheet_sync_check(($result['status'] ?? '') === 'ok', 'Signed Time Sheet sync mock did not resolve OK.');
timesheet_sync_check(count($history) === 1, 'Expected one signed Time Sheet sync request.');
$envelope = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
timesheet_sync_check(($envelope['route'] ?? '') === 'timesheet_google_refresh', 'Signed Time Sheet sync route mismatch.');
timesheet_sync_check(($envelope['method'] ?? '') === 'POST', 'Signed Time Sheet sync method mismatch.');
timesheet_sync_check(($envelope['context_client_id'] ?? 0) === 2, 'Signed Time Sheet sync context client mismatch.');
timesheet_sync_check(($envelope['body']['action'] ?? '') === 'refresh_timesheet', 'Signed Time Sheet action mismatch.');
timesheet_sync_check(($envelope['body']['client_id'] ?? 0) === 2, 'Signed Time Sheet body client mismatch.');

foreach (['MERDPOS_DRUPAL_GATEWAY_URL','MERDPOS_DRUPAL_SERVICE_URL','MERDPOS_DRUPAL_SERVICE_SECRET','MERDPOS_DRUPAL_CLIENT_ID','MERDPOS_DRUPAL_ACTOR_USER_ID'] as $name) putenv($name);
echo "MERDPOS Drupal Working Client + Google Time Sheet Sync v1 validated.\n";
