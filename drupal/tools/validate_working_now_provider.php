<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProvider.php';

use Drupal\merdpos_core\Integration\WorkingNowProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

putenv('MERDPOS_DRUPAL_SERVICE_URL=https://example.invalid/integrations/working_now.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('t', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');

$history = [];
$mock = new MockHandler([
    new Response(200, ['Content-Type'=>'application/json'], json_encode([
        'success'=>true,
        'generated_at'=>'2026-09-04T00:00:00Z',
        'people'=>[[
            'shift_id'=>'shift-1','full_name'=>'Test Person','user_id'=>'1001',
            'store_id'=>1,'store_name'=>'MX','clock_in_at'=>'2026-09-04 00:00:00','working_minutes'=>75,
            'unexpected'=>'discard-me',
        ]],
    ], JSON_THROW_ON_ERROR)),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$provider = new WorkingNowProvider(new Client(['handler'=>$stack]));
$result = $provider->load();
check($result['status'] === 'ok', 'Expected OK provider state');
check($result['count'] === 1, 'Expected one Working Now row');
check($result['people'][0]['full_name'] === 'Test Person', 'Expected normalized employee name');
check(!array_key_exists('unexpected', $result['people'][0]), 'Unknown upstream field leaked through normalization');
check(count($history) === 1, 'Expected one signed request');

$request = $history[0]['request'];
$timestamp = (int)$request->getHeaderLine('X-MERDPOS-Timestamp');
$expected = hash_hmac('sha256', implode("\n", [
    'merdpos-service-v1','working_now','GET',(string)$timestamp,'1','1001',
]), str_repeat('t', 32));
check($request->getHeaderLine('X-MERDPOS-Service') === 'drupal-web', 'Service identity header mismatch');
check($request->getHeaderLine('X-MERDPOS-Client-Id') === '1', 'Client header mismatch');
check($request->getHeaderLine('X-MERDPOS-Actor-User-Id') === '1001', 'Actor header mismatch');
check(hash_equals($expected, $request->getHeaderLine('X-MERDPOS-Signature')), 'HMAC signature mismatch');

$forbidden = new WorkingNowProvider(new Client(['handler'=>new MockHandler([
    new Response(403, ['Content-Type'=>'application/json'], '{"success":false,"error_code":"service_forbidden"}'),
])]));
check($forbidden->load()['status'] === 'forbidden', '403 must map to forbidden');

$malformed = new WorkingNowProvider(new Client(['handler'=>new MockHandler([
    new Response(200, ['Content-Type'=>'application/json'], '{not-json'),
])]));
check($malformed->load()['status'] === 'unavailable', 'Malformed payload must fail closed');

putenv('MERDPOS_DRUPAL_SERVICE_URL');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET');
putenv('MERDPOS_DRUPAL_CLIENT_ID');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID');
$unconfigured = new WorkingNowProvider(new Client(['handler'=>new MockHandler([])]));
check($unconfigured->load()['status'] === 'unconfigured', 'Missing environment must be unconfigured');

echo "MERDPOS Drupal Working Now provider validated.\n";
