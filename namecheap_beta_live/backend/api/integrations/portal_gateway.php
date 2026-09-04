<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/request.php';
require_once __DIR__ . '/../includes/portal_permissions.php';
require_once __DIR__ . '/../includes/service_auth.php';
require_once __DIR__ . '/../includes/service_actor.php';

$requestId = merd_request_id();
$portalRoot = dirname(__DIR__, 3) . '/timesheet_portal';

function merd_drupal_gateway_routes(): array
{
    return [
        'beta_state' => ['GET'],
        'dashboard_data' => ['GET'],
        'dashboard_layout' => ['GET', 'POST'],
        'admin_directory' => ['GET', 'POST'],
        'weeks' => ['GET'],
        'timesheet' => ['GET'],
        'disputes' => ['GET', 'POST'],
        'financials' => ['GET', 'POST'],
        'dev_status' => ['GET'],
        'clients' => ['GET', 'POST'],
        'legacy_migration' => ['GET', 'POST'],
        'defaults' => ['GET', 'POST'],
        'store_identity' => ['GET', 'POST'],
        'store_timings' => ['GET', 'POST'],
        'role_authority' => ['GET', 'POST'],
        'client_context' => ['GET'],
        'check_sheet' => ['GET'],
        'timesheet_google_refresh' => ['POST'],
        'change_password' => ['POST'],
        'attendance_scan' => ['POST'],
    ];
}

function merd_drupal_gateway_reject_studio(string $route, array $query, array $body): void
{
    if (in_array($route, ['ui_studio_history', 'ui_studio_asset'], true)) {
        throw new MerdRequestException('route_not_allowed', 403, 'This route is not available to Drupal.');
    }
    if ($route === 'dashboard_layout' && (!empty($query['dev_studio']) || !empty($body['dev_studio']))) {
        throw new MerdRequestException('route_not_allowed', 403, 'DevStudio is not available to Drupal.');
    }
}

function merd_drupal_gateway_scalar_map(mixed $value, string $field): array
{
    if ($value === null || $value === []) return [];
    if (!is_array($value) || array_is_list($value) || count($value) > 100) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid ' . $field . '.');
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key)) {
            throw new MerdRequestException('invalid_request', 400, 'Invalid ' . $field . '.');
        }
        if (is_array($item)) {
            if (count($item) > 100) throw new MerdRequestException('invalid_request', 400, 'Invalid ' . $field . '.');
            $result[$key] = $item;
            continue;
        }
        if (!is_scalar($item) && $item !== null) {
            throw new MerdRequestException('invalid_request', 400, 'Invalid ' . $field . '.');
        }
        $result[$key] = $item;
    }
    return $result;
}

try {
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '' || strlen($raw) > 1024 * 1024) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    $context = 'sha256:' . hash('sha256', $raw);
    $service = merd_service_authenticate($_SERVER, 'portal_gateway', null, null, $context);
    $request = merd_request_json($raw);

    $route = strtolower(trim((string)($request['route'] ?? '')));
    $method = strtoupper(trim((string)($request['method'] ?? 'GET')));
    $query = merd_drupal_gateway_scalar_map($request['query'] ?? [], 'query');
    $body = merd_drupal_gateway_scalar_map($request['body'] ?? [], 'body');

    $routes = merd_drupal_gateway_routes();
    if (!isset($routes[$route]) || !in_array($method, $routes[$route], true)) {
        throw new MerdRequestException('route_not_allowed', 403, 'This route is not available to Drupal.');
    }
    merd_drupal_gateway_reject_studio($route, $query, $body);

    $actor = merd_service_actor($pdo, (int)$service['client_id'], (string)$service['actor_user_id']);
    $employee = $actor['employee'];
    $role = $actor['role'];

    $storeStmt = $pdo->prepare('SELECT store_id FROM employees WHERE id=? AND client_id=? LIMIT 1');
    $storeStmt->execute([(int)$employee['id'], (int)$service['client_id']]);
    $storeId = $storeStmt->fetchColumn();

    require_once $portalRoot . '/includes/beta_api.php';
    start_app_session();
    $_SESSION['user'] = [
        'id' => (int)$employee['id'],
        'client_id' => (int)$service['client_id'],
        'store_id' => $storeId === false || $storeId === null ? null : (int)$storeId,
        'name' => (string)$employee['full_name'],
        'full_name' => (string)$employee['full_name'],
        'user_id' => (string)$employee['user_id'],
        'role' => strtoupper((string)$role['base_role']),
        'actual_employee_type' => strtoupper((string)$role['base_role']),
        'employee_type' => strtoupper((string)$role['base_role']),
        'role_name' => (string)$role['role_label'],
        'client_role_id' => (int)$role['id'],
        'role_key' => (string)$role['role_key'],
        'role_label' => (string)$role['role_label'],
        'authority_level' => (int)$role['authority_level'],
        'is_dev' => strtoupper((string)$role['base_role']) === 'DEV',
    ];
    $_SESSION['login_at_utc'] = gmdate(DateTimeInterface::ATOM);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    unset($_SESSION['dev_active_client_id']);
    $_COOKIE['merdpos_dev_view_role'] = 'DEV';

    if ($method === 'POST') $body['csrf'] = csrf_token();
    $_GET = $query;
    $_POST = $method === 'POST' ? $body : [];
    $_REQUEST = array_merge($_GET, $_POST);
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['SCRIPT_NAME'] = '/beta/timesheet_portal/api/' . $route . '.php';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['CONTENT_TYPE'] = 'application/json';

    $target = $portalRoot . '/api/' . $route . '.php';
    if (!is_file($target)) {
        throw new MerdRequestException('route_not_allowed', 403, 'This route is not available to Drupal.');
    }
    require $target;
    throw new RuntimeException('Gateway target returned without a response.');
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (Throwable $e) {
    error_log('Drupal portal gateway failed: ' . get_class($e));
    merd_api_fail('internal_error', 'Could not complete the MERDPOS request.', 500, $requestId);
}
