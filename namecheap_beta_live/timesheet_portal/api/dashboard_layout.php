<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/dashboard_access.php';

function dashboard_can_configure(array $user, ?PDO $pdo = null): bool
{
    return beta_has_permission($user, 'dashboard.configure', $pdo);
}

function dashboard_selected_role(PDO $pdo, array $user, mixed $requestedRoleId = null): array
{
    $clientId = (int)$user['client_id'];
    if (dashboard_can_configure($user, $pdo) && $requestedRoleId !== null && $requestedRoleId !== '') {
        $roleId = filter_var($requestedRoleId, FILTER_VALIDATE_INT);
        if ($roleId === false || $roleId <= 0) throw new MerdWorkforceException('invalid_role', 'Choose a valid dashboard role.');
        $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
        if (!$role || strtolower((string)$role['status']) !== 'active') throw new MerdWorkforceException('role_not_found', 'Dashboard role not found.');
        return $role;
    }
    return merd_dashboard_user_role($pdo, $user);
}

function dashboard_state(PDO $pdo, array $user, array $role): array
{
    $clientId = (int)$user['client_id'];
    $allowed = merd_dashboard_allowed_widgets($pdo, $clientId, $role);
    $allowedMap = array_fill_keys($allowed, true);
    $stmt = $pdo->prepare(
        'SELECT widget_key,grid_x,grid_y,grid_w,grid_h FROM dashboard_role_layouts '
        . 'WHERE client_id=? AND role_id=? ORDER BY grid_y ASC,grid_x ASC,id ASC'
    );
    $stmt->execute([$clientId, (int)$role['id']]);
    $layout = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn(array $row): bool => isset($allowedMap[(string)$row['widget_key']])));

    $canConfigure = dashboard_can_configure($user, $pdo);
    $roles = $canConfigure ? merd_dashboard_roles($pdo, $clientId, true) : [$role];
    foreach ($roles as &$candidate) {
        $candidate['allowed_widget_count'] = count(merd_dashboard_allowed_widgets($pdo, $clientId, $candidate));
    }
    unset($candidate);

    return [
        'success' => true,
        'csrf' => csrf_token(),
        'context_client_id' => $clientId,
        'can_edit' => $canConfigure,
        'can_select_role' => $canConfigure,
        'selected_role' => $role,
        'roles' => $roles,
        'allowed_widgets' => $allowed,
        'layout' => $layout,
        'grid' => ['columns' => 12, 'max_rows' => 1000],
    ];
}

function dashboard_int(mixed $value, string $field, int $min, int $max): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false || $parsed < $min || $parsed > $max) {
        throw new MerdWorkforceException('invalid_dashboard_layout', "Invalid dashboard {$field}.");
    }
    return (int)$parsed;
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $role = dashboard_selected_role($pdo, $user, $_GET['role_id'] ?? null);
        json_response(dashboard_state($pdo, $user, $role));
    }

    beta_require_permission($user, 'dashboard.configure', $pdo);
    $input = request_input();
    require_csrf($input);
    $role = dashboard_selected_role($pdo, $user, $input['role_id'] ?? null);
    $clientId = (int)$user['client_id'];
    $roleId = (int)$role['id'];
    $action = (string)($input['action'] ?? 'save_layout');

    if ($action === 'reset_layout') {
        $stmt = $pdo->prepare('DELETE FROM dashboard_role_layouts WHERE client_id=? AND role_id=?');
        $stmt->execute([$clientId, $roleId]);
        json_response(dashboard_state($pdo, $user, $role));
    }

    if ($action !== 'save_layout') {
        json_response(['success' => false, 'error' => 'Unsupported dashboard action.'], 400);
    }

    $items = $input['layout'] ?? null;
    if (!is_array($items) || count($items) > 30) {
        throw new MerdWorkforceException('invalid_dashboard_layout', 'Dashboard layout must contain 0–30 widgets.');
    }

    $allowed = array_fill_keys(merd_dashboard_allowed_widgets($pdo, $clientId, $role), true);
    $clean = [];
    $seen = [];
    foreach ($items as $row) {
        if (!is_array($row)) throw new MerdWorkforceException('invalid_dashboard_layout', 'Invalid dashboard widget row.');
        $key = strtolower(trim((string)($row['widget_key'] ?? '')));
        if (!isset($allowed[$key])) throw new MerdWorkforceException('dashboard_widget_forbidden', 'That widget is outside the selected role LOA.');
        if (isset($seen[$key])) throw new MerdWorkforceException('duplicate_dashboard_widget', 'Each dashboard widget can be added once.');
        $seen[$key] = true;
        $x = dashboard_int($row['grid_x'] ?? null, 'grid_x', 0, 11);
        $y = dashboard_int($row['grid_y'] ?? null, 'grid_y', 0, 999);
        $w = dashboard_int($row['grid_w'] ?? null, 'grid_w', 1, 12);
        $h = dashboard_int($row['grid_h'] ?? null, 'grid_h', 1, 20);
        if ($x + $w > 12) throw new MerdWorkforceException('invalid_dashboard_layout', 'A dashboard widget extends beyond the grid.');
        $clean[] = [$key, $x, $y, $w, $h];
    }

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM dashboard_role_layouts WHERE client_id=? AND role_id=?');
        $delete->execute([$clientId, $roleId]);
        if ($clean) {
            $insert = $pdo->prepare(
                'INSERT INTO dashboard_role_layouts (client_id,role_id,widget_key,grid_x,grid_y,grid_w,grid_h) VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($clean as [$key,$x,$y,$w,$h]) $insert->execute([$clientId,$roleId,$key,$x,$y,$w,$h]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_response(dashboard_state($pdo, $user, $role));
} catch (Throwable $e) {
    beta_api_error($e);
}
