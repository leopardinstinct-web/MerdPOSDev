<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Auth/MerdposAuthenticatorInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Auth/MerdposAuthenticator.php';

use Drupal\merdpos_core\Auth\MerdposAuthenticator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function auth_check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

putenv('MERDPOS_DRUPAL_LOGIN_URL=https://example.invalid/beta/timesheet_portal/api/login.php');
$health = new MerdposAuthenticator(new Client(['handler'=>new MockHandler([
  new Response(200, ['Content-Type'=>'application/json'], '{"success":false,"error":"Login endpoint is working. Use the MERDPOS login form."}'),
])]));
auth_check($health->health()['status'] === 'ok', 'Login health response must validate.');

$history = [];
$stack = HandlerStack::create(new MockHandler([
  new Response(200, ['Content-Type'=>'application/json'], '{"success":true,"user":{"id":7,"client_id":1,"store_id":2,"full_name":"Test DEV","user_id":"1001","role":"DEV","role_key":"DEV","role_label":"Developer","authority_level":1000}}'),
]));
$stack->push(Middleware::history($history));
$auth = new MerdposAuthenticator(new Client(['handler'=>$stack]));$result = $auth->authenticate('1001', '123456');
auth_check($result['status'] === 'ok', 'Valid MERDPOS identity must authenticate.');
auth_check(($result['identity']['role'] ?? '') === 'DEV', 'DEV role must be preserved.');
auth_check(count($history) === 1, 'Expected one MERDPOS login request.');
$request = $history[0]['request'];
auth_check($request->getMethod() === 'POST', 'MERDPOS login transport must be POST.');
auth_check($request->getUri()->getScheme() === 'https', 'MERDPOS login transport must use HTTPS.');
auth_check(str_contains((string)$request->getBody(), 'user_id=1001'), 'User ID missing from login POST.');

$invalid = new MerdposAuthenticator(new Client(['handler'=>new MockHandler([
  new Response(200, ['Content-Type'=>'application/json'], '{"success":false,"error":"Invalid User ID or Password."}'),
])]));
auth_check($invalid->authenticate('1001', '111111')['status'] === 'invalid', 'Invalid credentials must fail closed.');

$locked = new MerdposAuthenticator(new Client(['handler'=>new MockHandler([
  new Response(429, ['Content-Type'=>'application/json'], '{"success":false,"error":"Too many attempts."}'),
])]));
auth_check($locked->authenticate('1001', '111111')['status'] === 'locked', 'Lockout response must be preserved.');

putenv('MERDPOS_DRUPAL_LOGIN_URL');
$unconfigured = new MerdposAuthenticator(new Client(['handler'=>new MockHandler([])]));
auth_check($unconfigured->health()['status'] === 'unconfigured', 'Missing login URL must fail closed.');

echo "MERDPOS Drupal authoritative login client validated.\n";