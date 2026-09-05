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

function admin_write_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = file_get_contents($module . '/src/Controller/AdministrationController.php');
$template = file_get_contents($module . '/templates/merdpos-administration.html.twig');
$routing = file_get_contents($module . '/merdpos_core.routing.yml');
$libraries = file_get_contents($module . '/merdpos_core.libraries.yml');

foreach ([$controller,$template,$routing,$libraries] as $source) admin_write_check(is_string($source), 'Administration source is unreadable.');
admin_write_check(str_contains($routing, "path: '/merdpos/admin'"), 'Administration route missing.');
admin_write_check(str_contains($routing, "_permission: 'manage merdpos operations'"), 'Administration Drupal permission guard missing.');
admin_write_check(str_contains($controller, "csrf->validate"), 'Drupal CSRF validation missing.');
foreach (['save_client','save_store','save_employee'] as $action) {
  admin_write_check(str_contains($controller, "'{$action}'"), "Administration action missing: {$action}");
}
foreach (['clients','admin_directory'] as $route) {
  admin_write_check(str_contains($controller, "call('{$route}'"), "Signed gateway call missing: {$route}");
}
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE '] as $forbidden) {
  admin_write_check(!str_contains($controller, $forbidden), "Drupal administration must not contain operational SQL: {$forbidden}");
}
foreach (['data-admin-panel="clients"','data-admin-panel="stores"','data-admin-panel="workforce"','name="form_token"'] as $marker) {
  admin_write_check(str_contains($template, $marker), "Administration template marker missing: {$marker}");
}
admin_write_check(!str_contains($template, '|raw'), 'Administration template must not bypass Twig escaping.');
admin_write_check(str_contains($libraries, 'css/administration-v1.css'), 'Administration CSS library missing.');
admin_write_check(str_contains($libraries, 'js/administration-v1.js'), 'Administration JS library missing.');

putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('a', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');
$history = [];
$mock = new MockHandler([new Response(200, ['Content-Type'=>'application/json'], '{"success":true}')]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$result = $client->call('admin_directory', 'GET', [], [], 7);
admin_write_check($result['status'] === 'ok', 'Context-aware gateway call failed.');
admin_write_check(count($history) === 1, 'Expected one context-aware gateway request.');
$envelope = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
admin_write_check(($envelope['context_client_id'] ?? 0) === 7, 'Selected client context missing from signed gateway envelope.');
admin_write_check(($envelope['route'] ?? '') === 'admin_directory', 'Administration gateway route mismatch.');

foreach (['MERDPOS_DRUPAL_GATEWAY_URL','MERDPOS_DRUPAL_SERVICE_URL','MERDPOS_DRUPAL_SERVICE_SECRET','MERDPOS_DRUPAL_CLIENT_ID','MERDPOS_DRUPAL_ACTOR_USER_ID'] as $name) putenv($name);

echo "MERDPOS Drupal administration write v1 contract validated.\n";
