<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/includes/request.php';
require_once __DIR__ . '/../api/includes/portal_permissions.php';
require_once __DIR__ . '/../api/includes/service_auth.php';
require_once __DIR__ . '/../api/includes/service_actor.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expect_error(callable $fn, string $code): void
{
    try {
        $fn();
    } catch (MerdRequestException $e) {
        check($e->errorCode === $code, "Expected {$code}, got {$e->errorCode}");
        return;
    }
    throw new RuntimeException("Expected {$code} exception");
}

$secret = str_repeat('s', 32);
$now = 1788472000;
$server = [
    'REQUEST_METHOD' => 'GET',
    'HTTP_X_MERDPOS_SERVICE' => 'drupal-web',
    'HTTP_X_MERDPOS_TIMESTAMP' => (string)$now,
    'HTTP_X_MERDPOS_CLIENT_ID' => '1',
    'HTTP_X_MERDPOS_ACTOR_USER_ID' => '1001',
];
$server['HTTP_X_MERDPOS_SIGNATURE'] = merd_service_sign('working_now', 'GET', $now, 1, '1001', $secret);
$auth = merd_service_authenticate($server, 'working_now', $now, $secret);
check($auth['client_id'] === 1 && $auth['actor_user_id'] === '1001', 'Valid service auth failed');

$bad = $server;
$bad['HTTP_X_MERDPOS_SIGNATURE'] = str_repeat('0', 64);
expect_error(fn() => merd_service_authenticate($bad, 'working_now', $now, $secret), 'service_unauthorized');

$stale = $server;
$stale['HTTP_X_MERDPOS_TIMESTAMP'] = (string)($now - 1000);
$stale['HTTP_X_MERDPOS_SIGNATURE'] = merd_service_sign('working_now', 'GET', $now - 1000, 1, '1001', $secret);
expect_error(fn() => merd_service_authenticate($stale, 'working_now', $now, $secret), 'service_unauthorized');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE client_roles (id INTEGER PRIMARY KEY, client_id INTEGER, role_key TEXT, role_label TEXT, base_role TEXT, authority_level INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, client_id INTEGER, full_name TEXT, user_id TEXT, employee_type TEXT, role_name TEXT, client_role_id INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE client_permission_levels (client_id INTEGER, permission_key TEXT, min_authority_level INTEGER)');
$pdo->exec("INSERT INTO client_roles VALUES (1,1,'ADMIN','Administrator','ADMIN',50,'active'),(2,1,'USER','User','USER',10,'active')");
$pdo->exec("INSERT INTO employees VALUES (1,1,'Admin User','1001','ADMIN','Administrator',1,'active'),(2,1,'Staff User','1002','USER','User',2,'active')");
$admin = merd_service_actor($pdo, 1, '1001');
merd_service_require_permissions($pdo, 1, $admin['role'], [
    'dashboard.view',
    'dashboard.widget.working_now',
]);

$user = merd_service_actor($pdo, 1, '1002');
expect_error(fn() => merd_service_require_permissions($pdo, 1, $user['role'], [
    'dashboard.widget.working_now',
]), 'service_forbidden');

// Dashboard-scoped dependency parity: raising general workforce.view must not
// revoke an independently permitted Working Now dashboard widget.
$pdo->exec("INSERT INTO client_permission_levels VALUES (1,'workforce.view',60)");
merd_service_require_permissions($pdo, 1, $admin['role'], ['dashboard.widget.working_now']);

// Raising the widget's own threshold must revoke the bridge immediately.
$pdo->exec("INSERT INTO client_permission_levels VALUES (1,'dashboard.widget.working_now',60)");
expect_error(fn() => merd_service_require_permissions($pdo, 1, $admin['role'], [
    'dashboard.widget.working_now',
]), 'service_forbidden');

echo "MERDPOS Drupal Working Now service contract validated.\n";
