<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/includes/request.php';
require_once __DIR__ . '/../api/includes/service_auth.php';

function gateway_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function gateway_expect_error(callable $fn, string $code): void
{
    try {
        $fn();
    } catch (MerdRequestException $e) {
        gateway_check($e->errorCode === $code, "Expected {$code}, got {$e->errorCode}");
        return;
    }
    throw new RuntimeException("Expected {$code} exception");
}

$secret = str_repeat('g', 32);
$now = 1788493000;
$rawA = '{"route":"beta_state","method":"GET","query":{},"body":{}}';
$rawB = '{"route":"dev_status","method":"GET","query":{},"body":{}}';
$contextA = 'sha256:' . hash('sha256', $rawA);
$contextB = 'sha256:' . hash('sha256', $rawB);
$server = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_X_MERDPOS_SERVICE' => 'drupal-web',
    'HTTP_X_MERDPOS_TIMESTAMP' => (string)$now,
    'HTTP_X_MERDPOS_CLIENT_ID' => '1',
    'HTTP_X_MERDPOS_ACTOR_USER_ID' => '1001',
];
$server['HTTP_X_MERDPOS_SIGNATURE'] = merd_service_sign(
    'portal_gateway', 'POST', $now, 1, '1001', $secret, $contextA
);
$auth = merd_service_authenticate($server, 'portal_gateway', $now, $secret, $contextA);
gateway_check($auth['client_id'] === 1, 'Valid gateway service auth failed.');

gateway_expect_error(
    fn() => merd_service_authenticate($server, 'portal_gateway', $now, $secret, $contextB),
    'service_unauthorized'
);

$stale = $server;
$stale['HTTP_X_MERDPOS_TIMESTAMP'] = (string)($now - 1000);
$stale['HTTP_X_MERDPOS_SIGNATURE'] = merd_service_sign(
    'portal_gateway', 'POST', $now - 1000, 1, '1001', $secret, $contextA
);
gateway_expect_error(
    fn() => merd_service_authenticate($stale, 'portal_gateway', $now, $secret, $contextA),
    'service_unauthorized'
);
$gatewaySource = file_get_contents(__DIR__ . '/../api/integrations/portal_gateway.php');
gateway_check(is_string($gatewaySource), 'Gateway source is unreadable.');

foreach ([
    'beta_state','dashboard_data','dashboard_layout','admin_directory','weeks','timesheet',
    'disputes','financials','dev_status','clients','legacy_migration','defaults',
    'store_identity','store_timings','role_authority','client_context','check_sheet',
    'timesheet_google_refresh','change_password','attendance_scan',
] as $route) {
    gateway_check(
        str_contains($gatewaySource, "'{$route}' =>"),
        "Gateway route missing: {$route}"
    );
}

foreach (['ui_studio_history','ui_studio_asset','login','logout','store_logo'] as $route) {
    gateway_check(
        !str_contains($gatewaySource, "'{$route}' =>"),
        "Forbidden/non-JSON gateway route exposed: {$route}"
    );
}

gateway_check(str_contains($gatewaySource, "route === 'dashboard_layout'"), 'Dashboard layout DevStudio guard missing.');
gateway_check(str_contains($gatewaySource, "'dev_studio'"), 'DevStudio request guard missing.');
gateway_check(str_contains($gatewaySource, "'/includes/beta_api.php'"), 'Canonical Beta permission/runtime reuse missing.');
gateway_check(str_contains($gatewaySource, '$value === null || $value === []'), 'Empty gateway map compatibility guard missing.');

echo "MERDPOS Drupal generalized portal gateway contract validated.\n";
