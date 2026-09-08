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

function defaults_v1_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$repo = dirname($root);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = (string) file_get_contents($module . '/src/Controller/AdministrationController.php');
$template = (string) file_get_contents($module . '/templates/merdpos-administration.html.twig');
$theme = (string) file_get_contents($root . '/web/themes/custom/merdpos_app/merdpos_app.theme');
$moduleFile = (string) file_get_contents($module . '/merdpos_core.module');
$css = (string) file_get_contents($module . '/css/administration-v1.css');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');
$betaApi = (string) file_get_contents($repo . '/namecheap_beta_live/timesheet_portal/api/defaults.php');
$betaJs = (string) file_get_contents($repo . '/namecheap_beta_live/timesheet_portal/assets/defaults.js');
$permissions = (string) file_get_contents($repo . '/namecheap_beta_live/backend/api/includes/portal_permissions.php');

defaults_v1_check(str_contains($permissions, "'defaults.manage'") && str_contains($permissions, "'dev_only'=>true"), 'Beta defaults.manage is not DEV-only.');
defaults_v1_check(str_contains($betaApi, 'beta_require_permission($user, \'defaults.manage\''), 'Beta defaults permission contract missing.');
defaults_v1_check(str_contains($betaApi, "save_client_defaults") && str_contains($betaApi, "save_store_defaults"), 'Beta defaults action contract missing.');
defaults_v1_check(str_contains($betaApi, "COALESCE(currency_code,?) AS effective_currency") && str_contains($betaApi, "COALESCE(timezone,?) AS effective_timezone"), 'Beta inheritance/effective-value contract missing.');
foreach (['Defaults','Set client defaults and optional per-store overrides for currency and timezone.','Client defaults','Stores inherit these values unless you set an override below.','Save client defaults','Store overrides','Use client default','Effective','Save'] as $marker) {
  defaults_v1_check(str_contains($template, $marker), "Drupal Defaults marker missing: {$marker}");
}
foreach (['Client defaults saved. Stores without overrides now inherit the new values.','Store defaults saved.'] as $marker) {
  defaults_v1_check(str_contains($controller, $marker), "Drupal Defaults success message missing: {$marker}");
}
defaults_v1_check(str_contains($theme, "['key'=>'defaults','label'=>'Defaults']"), 'Defaults Administration tab missing.');
defaults_v1_check(str_contains($theme, '!empty($perms[\'defaults.manage\'])'), 'Defaults tab is not permission-scoped.');
defaults_v1_check(strpos($theme, "['key'=>'stores','label'=>'Stores']") < strpos($theme, "['key'=>'defaults','label'=>'Defaults']"), 'Defaults must follow Stores.');
defaults_v1_check(strpos($theme, "['key'=>'defaults','label'=>'Defaults']") < strpos($theme, "['key'=>'workforce','label'=>'Workforce']"), 'Defaults must precede Workforce.');
defaults_v1_check(str_contains($moduleFile, "'can_manage_defaults' => false") && str_contains($moduleFile, "'defaults_state' => []"), 'Defaults theme variables missing.');
defaults_v1_check(str_contains($controller, "call('defaults', 'GET'"), 'Defaults signed GET missing.');
defaults_v1_check(substr_count($controller, "call('defaults', 'POST'") >= 2, 'Both Defaults signed POST actions are required.');
defaults_v1_check(str_contains($controller, "'action'=>'save_client_defaults'") && str_contains($controller, "'action'=>'save_store_defaults'"), 'Defaults controller action allowlist missing.');
defaults_v1_check(str_contains($controller, "['onboarding', 'clients', 'stores', 'defaults', 'workforce', 'roles']"), 'Defaults requested-tab allowlist missing.');
defaults_v1_check(str_contains($template, "value=\"\">Use client default ({{ defaults_client.default_currency }})"), 'Currency inheritance option missing.');
defaults_v1_check(str_contains($template, "value=\"\">Use client default ({{ defaults_client.default_timezone }})"), 'Timezone inheritance option missing.');
defaults_v1_check(str_contains($css, '.merdpos-defaults-store-row') && str_contains($css, '@media(max-width:35rem)'), 'Defaults responsive styling missing.');
foreach (['UPDATE clients SET default_currency','UPDATE stores SET currency_code','admin_audit_logs','portal_db(','PDO '] as $forbidden) {
  defaults_v1_check(!str_contains($controller, $forbidden), "Drupal must not own Defaults persistence: {$forbidden}");
}
foreach (['Client defaults','Store overrides','Save client defaults','Use client default'] as $marker) {
  defaults_v1_check(str_contains($betaJs, $marker), "Beta Defaults UI marker missing: {$marker}");
}
defaults_v1_check(str_contains($deploy, 'Defaults parity self-test failed.'), 'Defaults live deployment probe missing.');

$twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
$twig->addFunction(new \Twig\TwigFunction('path', static fn(string $route, array $parameters = [], array $options = []): string => '/merdpos'));
$twig->parse($twig->tokenize(new \Twig\Source($template, 'merdpos-administration.html.twig')));

putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('s', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');
$history = [];
$mock = new MockHandler([new Response(200, ['Content-Type'=>'application/json'], '{"success":true,"active_client_id":2,"client":{"default_currency":"AUD","default_timezone":"Australia/Sydney"},"stores":[],"currencies":["AUD"],"timezones":["Australia/Sydney"]}')]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$result = $client->call('defaults', 'POST', [], ['action'=>'save_store_defaults','store_id'=>7,'currency_code'=>'','timezone'=>''], 2);
defaults_v1_check(($result['status'] ?? '') === 'ok', 'Signed Defaults mock did not resolve OK.');
defaults_v1_check(count($history) === 1, 'Expected one signed Defaults request.');
$envelope = json_decode((string)$history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
defaults_v1_check(($envelope['route'] ?? '') === 'defaults', 'Signed Defaults route mismatch.');
defaults_v1_check(($envelope['method'] ?? '') === 'POST', 'Signed Defaults method mismatch.');
defaults_v1_check(($envelope['context_client_id'] ?? 0) === 2, 'Signed Defaults context client mismatch.');
defaults_v1_check(($envelope['body']['action'] ?? '') === 'save_store_defaults', 'Signed Defaults action mismatch.');
defaults_v1_check(array_key_exists('currency_code', $envelope['body']) && $envelope['body']['currency_code'] === '', 'Blank currency inheritance value was not preserved.');
defaults_v1_check(array_key_exists('timezone', $envelope['body']) && $envelope['body']['timezone'] === '', 'Blank timezone inheritance value was not preserved.');

foreach (['MERDPOS_DRUPAL_GATEWAY_URL','MERDPOS_DRUPAL_SERVICE_URL','MERDPOS_DRUPAL_SERVICE_SECRET','MERDPOS_DRUPAL_CLIENT_ID','MERDPOS_DRUPAL_ACTOR_USER_ID'] as $name) putenv($name);
echo "MERDPOS Drupal Defaults Parity v1 validated.\n";
