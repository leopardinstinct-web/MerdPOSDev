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

function finance_offline_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = (string) file_get_contents($module . '/src/Controller/FinanceController.php');
$provider = (string) file_get_contents($module . '/src/Integration/ParityDataProvider.php');
$template = (string) file_get_contents($module . '/templates/merdpos-finance.html.twig');
$js = (string) file_get_contents($module . '/js/finance-v3.js');
$css = (string) file_get_contents($module . '/css/finance-v2.css');
$routing = (string) file_get_contents($module . '/merdpos_core.routing.yml');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');

finance_offline_check(str_contains($routing, "merdpos_core.finance_submit:"), 'Finance JSON submit route missing.');
finance_offline_check(str_contains($routing, "path: '/merdpos/finance/submit'"), 'Finance JSON submit path missing.');
finance_offline_check(str_contains($routing, 'FinanceController::submitJson'), 'Finance JSON controller route missing.');
finance_offline_check(substr_count($routing, 'methods: [POST]') >= 5, 'Finance JSON route must be POST only.');
finance_offline_check(str_contains($controller, "headers->get('X-MERDPOS-CSRF'"), 'Finance queue CSRF header validation missing.');
finance_offline_check(str_contains($controller, 'normalizeQueuedSubmission'), 'Queued Finance submission normalization missing.');
finance_offline_check(str_contains($controller, "call('financials', 'POST'"), 'Queued Finance signed write missing.');
finance_offline_check(str_contains($controller, "'retryable'=>\$retryable"), 'Retryable gateway-unavailable signal missing.');
finance_offline_check(str_contains($controller, "if (\$gatewayStatus === 'ok') \$http = 422;"), 'Authoritative Finance rejection must not be mislabeled as service unavailable.');
finance_offline_check(str_contains($controller, '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'), 'Browser UUID v4 validation missing.');
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE '] as $forbidden) {
  finance_offline_check(!str_contains($controller, $forbidden), "Drupal Finance offline controller must not own operational SQL: {$forbidden}");
}
finance_offline_check(str_contains($provider, "'available_raw'=>(float)"), 'Confirmed raw account balance missing from Finance provider.');
finance_offline_check(str_contains($template, 'data-finance-offline-root'), 'Finance offline root metadata missing.');
finance_offline_check(str_contains($template, 'data-finance-queue-badge'), 'Finance queue badge missing.');
finance_offline_check(str_contains($template, 'data-finance-effective-available'), 'Queued effective available display missing.');
finance_offline_check(substr_count($template, 'data-finance-queue-form') >= 3, 'Open/Cash/Closing forms are not all queue-enabled.');
finance_offline_check(!str_contains($template, '|raw'), 'Finance offline template must not bypass Twig escaping.');

foreach ([
  "merdpos_financial_queue_v1",
  "crypto.randomUUID",
  "localStorage.getItem(queueKey)",
  "localStorage.setItem(queueKey",
  "navigator.onLine",
  "window.addEventListener('online', flushQueue)",
  "Saved on this phone. Sending",
  "Saved successfully.",
  "This day already has a closing waiting to sync.",
  "This day already has an opening waiting to sync.",
  "effectiveAvailable",
  "remaining.push(item)",
  "data?.retryable === true",
  "window.location.reload()",
] as $marker) finance_offline_check(str_contains($js, $marker), "Finance offline JS marker missing: {$marker}");
finance_offline_check(str_contains($js, "Offline — showing the last confirmed balance plus this device’s pending entries."), 'Beta-equivalent offline status missing.');
finance_offline_check(str_contains($js, "'✓ Up to date'"), 'Beta-equivalent clear queue badge missing.');
finance_offline_check(str_contains($css, '.merdpos-finance-queue-pill'), 'Finance queue styling missing.');
finance_offline_check(str_contains($css, '.merdpos-finance-effective'), 'Effective-balance styling missing.');
finance_offline_check(str_contains($deploy, 'Finance offline queue parity self-test failed.'), 'Finance offline live deployment probe missing.');

$betaJs = dirname($root) . '/namecheap_beta_live/timesheet_portal/assets/beta.js';
finance_offline_check(is_file($betaJs), 'Canonical Beta finance browser source missing.');
$beta = (string) file_get_contents($betaJs);
foreach (['merdpos_financial_queue_v1','function queueFinancial','function effectiveAvailable','window.addEventListener(\'online\', flushQueue)'] as $marker) {
  finance_offline_check(str_contains($beta, $marker), "Canonical Beta offline marker missing: {$marker}");
}

$twig = new Twig\Environment(new Twig\Loader\ArrayLoader());
$twig->parse($twig->tokenize(new Twig\Source($template, 'merdpos-finance.html.twig')));

putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('q', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');
$history = [];
$mock = new MockHandler([new Response(200, ['Content-Type'=>'application/json'], '{"success":true,"result":{"status":"sheet_pending","duplicate":false}}')]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$submissionId = '123e4567-e89b-42d3-a456-426614174000';
$body = [
  'submission_id'=>$submissionId,
  'store_id'=>7,
  'business_date'=>'2026-09-08',
  'submission_type'=>'cash_out',
  'payload'=>['transactions'=>[['account'=>'Register','head'=>'Offline till movement','amount'=>12.5]]],
];
$result = $client->call('financials', 'POST', [], $body);
finance_offline_check(($result['status'] ?? '') === 'ok', 'Offline Finance signed mock did not resolve OK.');
finance_offline_check(count($history) === 1, 'Expected one offline Finance signed request.');
$envelope = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
finance_offline_check(($envelope['body']['submission_id'] ?? '') === $submissionId, 'Browser-generated Finance UUID was not preserved through signed gateway.');
finance_offline_check(($envelope['body']['submission_type'] ?? '') === 'cash_out', 'Queued Finance type was not preserved.');
finance_offline_check(($envelope['body']['payload']['transactions'][0]['amount'] ?? 0) === 12.5, 'Queued Finance payload was not preserved.');

foreach (['MERDPOS_DRUPAL_GATEWAY_URL','MERDPOS_DRUPAL_SERVICE_URL','MERDPOS_DRUPAL_SERVICE_SECRET','MERDPOS_DRUPAL_CLIENT_ID','MERDPOS_DRUPAL_ACTOR_USER_ID'] as $name) putenv($name);
echo "MERDPOS Drupal Finance Offline Queue Parity v1 validated.\n";
