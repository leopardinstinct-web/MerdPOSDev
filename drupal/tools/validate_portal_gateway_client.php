<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Drupal\merdpos_core\Integration\PortalGatewayClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function gateway_client_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('t', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');

$history = [];
$mock = new MockHandler([
  new Response(200, ['Content-Type'=>'application/json'], '{"success":true,"role":"DEV"}'),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$result = $client->call('beta_state', 'GET');
gateway_client_check($result['status'] === 'ok', 'Expected gateway client OK state.');
gateway_client_check(($result['payload']['success'] ?? false) === true, 'Canonical payload missing.');
gateway_client_check(count($history) === 1, 'Expected one signed gateway request.');

$request = $history[0]['request'];
$raw = (string) $request->getBody();
$timestamp = (int) $request->getHeaderLine('X-MERDPOS-Timestamp');
$context = 'sha256:' . hash('sha256', $raw);
$expected = hash_hmac('sha256', implode("\n", [
  'merdpos-service-v1','portal_gateway','POST',(string)$timestamp,'1','1001',$context,
]), str_repeat('t', 32));
gateway_client_check($request->getMethod() === 'POST', 'Gateway transport must be POST.');
gateway_client_check($request->getHeaderLine('Content-Type') === 'application/json', 'Gateway JSON content type missing.');
gateway_client_check(hash_equals($expected, $request->getHeaderLine('X-MERDPOS-Signature')), 'Body-bound gateway HMAC mismatch.');
$envelope = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
gateway_client_check(($envelope['route'] ?? '') === 'beta_state', 'Gateway route envelope mismatch.');
gateway_client_check(($envelope['method'] ?? '') === 'GET', 'Gateway method envelope mismatch.');
$objectEnvelope = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
gateway_client_check($objectEnvelope->query instanceof stdClass, 'Gateway query must be a JSON object.');
gateway_client_check($objectEnvelope->body instanceof stdClass, 'Gateway body must be a JSON object.');
$forbidden = new PortalGatewayClient(new Client(['handler'=>new MockHandler([
  new Response(403, ['Content-Type'=>'application/json'], '{"success":false,"error_code":"service_forbidden"}'),
])]));
gateway_client_check($forbidden->call('dev_status')['status'] === 'forbidden', '403 must map to forbidden.');

$malformed = new PortalGatewayClient(new Client(['handler'=>new MockHandler([
  new Response(200, ['Content-Type'=>'application/json'], '{not-json'),
])]));
gateway_client_check($malformed->call('beta_state')['status'] === 'unavailable', 'Malformed payload must fail closed.');

gateway_client_check($client->call('../ui_studio_history')['status'] === 'invalid', 'Invalid route syntax must fail locally.');

putenv('MERDPOS_DRUPAL_GATEWAY_URL');
putenv('MERDPOS_DRUPAL_SERVICE_URL');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET');
putenv('MERDPOS_DRUPAL_CLIENT_ID');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID');
$unconfigured = new PortalGatewayClient(new Client(['handler'=>new MockHandler([])]));
gateway_client_check($unconfigured->call('beta_state')['status'] === 'unconfigured', 'Missing environment must be unconfigured.');

echo "MERDPOS Drupal generalized portal gateway client validated.\n";
