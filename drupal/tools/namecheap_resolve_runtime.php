<?php

declare(strict_types=1);

$betaConfig = '/home/dridsheikh/merdpos.com/app/beta/backend/api/config.php';
$dbBootstrap = '/home/dridsheikh/.merdpos_drupal_db.php';
$runtimeFile = '/home/dridsheikh/.merdpos_drupal_runtime.php';
$serviceUrl = 'https://app.merdpos.com/beta/backend/api/integrations/working_now.php';
$gatewayUrl = 'https://app.merdpos.com/beta/backend/api/integrations/portal_gateway.php';

if (!is_readable($betaConfig) || !is_readable($dbBootstrap)) {
  fwrite(STDERR, "Required private deployment configuration is missing.\n");
  exit(1);
}

$_SERVER['REQUEST_METHOD'] ??= 'GET';
require $betaConfig;
if (!isset($pdo) || !$pdo instanceof PDO) {
  fwrite(STDERR, "Authoritative Beta database connection is unavailable.\n");
  exit(1);
}
$secret = defined('MERDPOS_DRUPAL_SERVICE_SECRET') ? (string) MERDPOS_DRUPAL_SERVICE_SECRET : '';
if (strlen($secret) < 32) {
  fwrite(STDERR, "Authoritative Beta service secret is unavailable.\n");
  exit(1);
}
$db = require $dbBootstrap;
if (!is_array($db) || !isset($db['database'], $db['hash_salt'])) {
  fwrite(STDERR, "Drupal private database bootstrap is invalid.\n");
  exit(1);
}$sql = <<<'SQL'
SELECT e.client_id,e.user_id,e.id,
  UPPER(COALESCE(r.base_role,e.employee_type,e.role_name,'')) AS base_role,
  COALESCE(r.authority_level,
    CASE UPPER(COALESCE(e.employee_type,e.role_name,''))
      WHEN 'DEV' THEN 1000 WHEN 'SUPER' THEN 800 WHEN 'ADMIN' THEN 500 ELSE 100 END
  ) AS authority_level
FROM employees e
LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id
WHERE e.status='active' AND e.user_id IS NOT NULL AND TRIM(e.user_id)<>''
  AND UPPER(COALESCE(r.base_role,e.employee_type,e.role_name,''))='DEV'
  AND (r.id IS NULL OR r.status='active')
ORDER BY CASE UPPER(COALESCE(r.base_role,e.employee_type,e.role_name,''))
  WHEN 'DEV' THEN 0 WHEN 'SUPER' THEN 1 WHEN 'ADMIN' THEN 2 ELSE 3 END,
  authority_level DESC,e.id ASC
LIMIT 50
SQL;
$candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$chosen = null;
foreach ($candidates as $candidate) {
  $clientId = (int) ($candidate['client_id'] ?? 0);
  $actorId = trim((string) ($candidate['user_id'] ?? ''));
  if ($clientId < 1 || !preg_match('/^\d{1,20}$/', $actorId)) continue;
  if (bridge_allows($serviceUrl, $secret, $clientId, $actorId)) {
    $chosen = ['client_id' => $clientId, 'actor_user_id' => $actorId];
    break;
  }
}
if ($chosen === null) {
  fwrite(STDERR, "No active MERDPOS DEV service actor passed the live Working Now permission contract.\n");
  exit(1);
}$database = $db['database'];
foreach (['database','username','password','host','port'] as $key) {
  if (!isset($database[$key]) || (string) $database[$key] === '') {
    fwrite(STDERR, "Drupal private database bootstrap is incomplete.\n");
    exit(1);
  }
}
$runtime = "<?php\n\ndeclare(strict_types=1);\n\n";
$runtime .= '$databases[\'default\'][\'default\'] = ' . var_export([
  'database' => (string) $database['database'],
  'username' => (string) $database['username'],
  'password' => (string) $database['password'],
  'prefix' => '', 'host' => (string) $database['host'], 'port' => (string) $database['port'],
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql', 'driver' => 'mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
], true) . ";\n";
$runtime .= '$settings[\'hash_salt\'] = ' . var_export((string) $db['hash_salt'], true) . ";\n";
$runtime .= 'putenv(' . var_export('MERDPOS_DRUPAL_SERVICE_URL=' . $serviceUrl, true) . ");\n";
$runtime .= 'putenv(' . var_export('MERDPOS_DRUPAL_GATEWAY_URL=' . $gatewayUrl, true) . ");\n";
$runtime .= 'putenv(' . var_export('MERDPOS_DRUPAL_SERVICE_SECRET=' . $secret, true) . ");\n";
$runtime .= 'putenv(' . var_export('MERDPOS_DRUPAL_CLIENT_ID=' . $chosen['client_id'], true) . ");\n";
$runtime .= 'putenv(' . var_export('MERDPOS_DRUPAL_ACTOR_USER_ID=' . $chosen['actor_user_id'], true) . ");\n";
if (file_put_contents($runtimeFile, $runtime, LOCK_EX) === false || !chmod($runtimeFile, 0600)) {
  fwrite(STDERR, "Could not write private Drupal runtime configuration.\n"); exit(1);
}
echo "MERDPOS Drupal private runtime resolved.\n";
function bridge_allows(string $url, string $secret, int $clientId, string $actorId): bool {
  $timestamp = time();
  $payload = implode("\n", ['merdpos-service-v1','working_now','GET',(string) $timestamp,(string) $clientId,$actorId]);
  $signature = hash_hmac('sha256', $payload, $secret);
  $headers = [
    'Accept: application/json', 'X-MERDPOS-Service: drupal-web',
    'X-MERDPOS-Timestamp: ' . $timestamp, 'X-MERDPOS-Client-Id: ' . $clientId,
    'X-MERDPOS-Actor-User-Id: ' . $actorId, 'X-MERDPOS-Signature: ' . $signature,
  ];
  $context = stream_context_create(['http' => [
    'method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 4,
    'ignore_errors' => true, 'follow_location' => 0,
  ]]);
  $body = @file_get_contents($url, false, $context);
  if (!is_string($body)) return false;
  $statusLine = $http_response_header[0] ?? '';
  if (!preg_match('/\s(\d{3})\s/', $statusLine, $match) || (int) $match[1] !== 200) return false;
  $decoded = json_decode($body, true);
  return is_array($decoded) && ($decoded['success'] ?? false) === true;
}
