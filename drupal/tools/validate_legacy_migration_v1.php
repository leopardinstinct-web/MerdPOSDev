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

function legacy_v1_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$controller = (string) file_get_contents($module . '/src/Controller/AdministrationController.php');
$gateway = (string) file_get_contents($module . '/src/Integration/PortalGatewayClient.php');
$routing = (string) file_get_contents($module . '/merdpos_core.routing.yml');
$libraries = (string) file_get_contents($module . '/merdpos_core.libraries.yml');
$modulePhp = (string) file_get_contents($module . '/merdpos_core.module');
$template = (string) file_get_contents($module . '/templates/merdpos-administration.html.twig');
$js = (string) file_get_contents($module . '/js/legacy-migration-v1.js');
$css = (string) file_get_contents($module . '/css/legacy-migration-v1.css');
$deploy = (string) file_get_contents($root . '/tools/namecheap_deploy.sh');
legacy_v1_check(str_contains($routing, "merdpos_core.legacy_migration:"), 'Legacy Migration Drupal route missing.');
legacy_v1_check(str_contains($routing, "path: '/merdpos/admin/legacy-migration'"), 'Legacy Migration Drupal path missing.');
legacy_v1_check(str_contains($routing, 'AdministrationController::legacyMigration'), 'Legacy Migration controller route missing.');
legacy_v1_check(str_contains($routing, 'methods: [GET, POST]'), 'Legacy Migration route must allow GET and POST only.');
legacy_v1_check(str_contains($controller, "LEGACY_TOKEN_ID = 'merdpos-legacy-migration-v1'"), 'Legacy Migration Drupal CSRF boundary missing.');
legacy_v1_check(str_contains($controller, "headers->get('X-MERDPOS-CSRF'"), 'Legacy Migration CSRF header validation missing.');
legacy_v1_check(str_contains($controller, "call('legacy_migration', 'GET'"), 'Signed Legacy Migration GET missing.');
legacy_v1_check(str_contains($controller, "call('legacy_migration', 'POST'"), 'Signed Legacy Migration POST missing.');
legacy_v1_check(str_contains($controller, "['save_sources','preview','sync','final']"), 'Legacy Migration action whitelist missing.');
legacy_v1_check(str_contains($controller, 'legacyAttendanceSheets'), 'Attendance source mapping normalization missing.');
legacy_v1_check(str_contains($controller, 'legacyStringList'), 'Financial tab allowlist normalization missing.');
legacy_v1_check(str_contains($controller, 'hash_equals($expected, $confirmation)'), 'Final cutover authoritative Client Code recheck missing.');
legacy_v1_check(str_contains($controller, 'sanitizeLegacyPayload'), 'Legacy Migration browser response sanitizer missing.');
legacy_v1_check(!str_contains($controller, "'csrf'=>"), 'Beta CSRF must not be exposed through Drupal Legacy Migration response.');
foreach (['PDO','SELECT ','INSERT ','UPDATE ','DELETE ','legacy_run_batch_safe','legacy_google_fetch_tab','client_migration_state'] as $forbidden) {
  legacy_v1_check(!str_contains($controller, $forbidden), "Drupal must not own Legacy Migration backend logic: {$forbidden}");
}
legacy_v1_check(str_contains($gateway, "['timesheet_google_refresh','legacy_migration']"), 'Long-running Legacy Migration gateway timeout missing.');
legacy_v1_check(str_contains($libraries, 'css/legacy-migration-v1.css') && str_contains($libraries, 'js/legacy-migration-v1.js'), 'Legacy Migration library assets missing.');
legacy_v1_check(str_contains($modulePhp, "'can_manage_legacy' => false") && str_contains($modulePhp, "'legacy_token' => ''"), 'Legacy Migration theme variables missing.');
foreach (['Legacy Sync','Legacy migration','Controlled Google','Legacy Google sources','Save sources','Migration control','Preview changes','Sync legacy data','Final Sync &amp; switch to SQL','Open conflicts','Migration history'] as $marker) {
  legacy_v1_check(str_contains($template . $js, $marker), "Legacy Migration UI marker missing: {$marker}");
}
legacy_v1_check(str_contains($template, 'data-legacy-open') && str_contains($template, 'data-legacy-dialog'), 'Legacy Sync Client-row action/dialog missing.');
legacy_v1_check(str_contains($template, 'M20 7h-6V1') && str_contains($template, 'M4 17h6v6'), 'Canonical Beta Legacy Sync icon geometry missing.');
legacy_v1_check(str_contains($js, 'Sync validated legacy rows into MERDPOS SQL? Existing native/manual changes will not be silently overwritten.'), 'Beta Sync confirmation missing.');
legacy_v1_check(str_contains($js, 'Final cutover makes MERDPOS SQL authoritative and prevents future Google overwrites.'), 'Beta Final cutover prompt missing.');
legacy_v1_check(str_contains($js, "if (confirmationClientCode !== expected)"), 'Final cutover browser Client Code guard missing.');
legacy_v1_check(str_contains($js, "action: 'save_sources'") && str_contains($js, "['preview', 'sync', 'final']"), 'Legacy Migration browser action contract incomplete.');
legacy_v1_check(!str_contains($js, 'dev_studio') && !str_contains($controller, 'dev_studio'), 'DevStudio must remain excluded from Legacy Migration parity.');
legacy_v1_check(str_contains($css, '.merdpos-legacy-dialog') && str_contains($css, '.merdpos-legacy-table-wrap'), 'Legacy Migration dialog/table styling missing.');
legacy_v1_check(str_contains($css, '@media(max-width:51.25rem)') && str_contains($css, 'overflow-x:auto'), 'Legacy Migration responsive/internal-scroll contract missing.');
legacy_v1_check(str_contains($deploy, 'validate_legacy_migration_v1.php'), 'Legacy Migration validator is not wired into deployment.');
legacy_v1_check(str_contains($deploy, 'Legacy Migration parity self-test failed.'), 'Legacy Migration live GET probe missing.');

$twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
$twig->addFunction(new \Twig\TwigFunction('path', static fn(string $route, array $parameters = [], array $options = []): string => '/merdpos'));
$twig->parse($twig->tokenize(new \Twig\Source($template, 'merdpos-administration.html.twig')));
putenv('MERDPOS_DRUPAL_GATEWAY_URL=https://example.invalid/integrations/portal_gateway.php');
putenv('MERDPOS_DRUPAL_SERVICE_SECRET=' . str_repeat('s', 32));
putenv('MERDPOS_DRUPAL_CLIENT_ID=1');
putenv('MERDPOS_DRUPAL_ACTOR_USER_ID=1001');
$history = [];
$getPayload = json_encode(['success'=>true,'csrf'=>'beta-secret','client'=>['id'=>2,'name'=>'DUMMY','client_code'=>'DUMMY','status'=>'active'],'sources'=>[],'migration_state'=>['attendance_authority'=>'google_legacy','financial_authority'=>'google_legacy'],'recent_batches'=>[],'open_conflicts'=>[],'record_counts'=>[],'suggestions'=>[],'rules'=>['provider'=>'google_public_csv']], JSON_THROW_ON_ERROR);
$postPayload = json_encode(['success'=>true,'client'=>['id'=>2,'name'=>'DUMMY','client_code'=>'DUMMY','status'=>'active'],'batch_result'=>['batch_id'=>'MIG-TEST','inserted'=>0,'updated'=>0,'unchanged'=>0,'conflicts'=>0,'rejected'=>0]], JSON_THROW_ON_ERROR);
$mock = new MockHandler([
  new Response(200, ['Content-Type'=>'application/json'], $getPayload),
  new Response(200, ['Content-Type'=>'application/json'], $postPayload),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new PortalGatewayClient(new Client(['handler'=>$stack]));
$getResult = $client->call('legacy_migration', 'GET', ['client_id'=>2], [], 2);
$postResult = $client->call('legacy_migration', 'POST', [], ['action'=>'preview','client_id'=>2], 2);
legacy_v1_check(($getResult['status'] ?? '') === 'ok' && ($postResult['status'] ?? '') === 'ok', 'Signed Legacy Migration mock did not resolve OK.');
legacy_v1_check(count($history) === 2, 'Expected signed Legacy Migration GET and POST envelopes.');
$getEnvelope = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
$postEnvelope = json_decode((string) $history[1]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
legacy_v1_check(($getEnvelope['route'] ?? '') === 'legacy_migration' && ($getEnvelope['method'] ?? '') === 'GET', 'Signed Legacy Migration GET envelope mismatch.');
legacy_v1_check(($getEnvelope['query']['client_id'] ?? 0) === 2 && ($getEnvelope['context_client_id'] ?? 0) === 2, 'Signed Legacy Migration GET client context mismatch.');
legacy_v1_check(($postEnvelope['route'] ?? '') === 'legacy_migration' && ($postEnvelope['method'] ?? '') === 'POST', 'Signed Legacy Migration POST envelope mismatch.');
legacy_v1_check(($postEnvelope['body']['action'] ?? '') === 'preview' && ($postEnvelope['body']['client_id'] ?? 0) === 2, 'Signed Legacy Migration POST body mismatch.');
legacy_v1_check(($postEnvelope['context_client_id'] ?? 0) === 2, 'Signed Legacy Migration POST context mismatch.');

foreach (['MERDPOS_DRUPAL_GATEWAY_URL','MERDPOS_DRUPAL_SERVICE_URL','MERDPOS_DRUPAL_SERVICE_SECRET','MERDPOS_DRUPAL_CLIENT_ID','MERDPOS_DRUPAL_ACTOR_USER_ID'] as $name) putenv($name);
echo "MERDPOS Drupal Legacy Migration Parity v1 validated.\n";
