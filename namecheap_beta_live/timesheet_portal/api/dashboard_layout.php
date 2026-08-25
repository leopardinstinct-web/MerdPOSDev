<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function dashboard_allowed_widgets(array $user): array
{
    $management = !empty($user['is_super']);
    if ($management) {
        return [
            'working_now_count',
            'pending_disputes',
            'active_employees',
            'sync_attention',
            'working_now',
            'workforce_by_store',
            'store_cash_position',
            'cash_mix',
            'today_sales_by_store',
            'recent_attendance',
        ];
    }
    return ['my_shift', 'my_disputes', 'recent_attendance'];
}

function dashboard_state(PDO $pdo, array $user): array
{
    $employeeId = (int)$user['id'];
    $clientId = (int)$user['client_id'];
    $stmt = $pdo->prepare(
        'SELECT widget_key,grid_x,grid_y,grid_w,grid_h '
        . 'FROM dashboard_layouts WHERE employee_id=? AND context_client_id=? '
        . 'ORDER BY grid_y ASC,grid_x ASC,id ASC'
    );
    $stmt->execute([$employeeId, $clientId]);

    return [
        'success' => true,
        'csrf' => csrf_token(),
        'employee_id' => $employeeId,
        'context_client_id' => $clientId,
        'allowed_widgets' => dashboard_allowed_widgets($user),
        'layout' => $stmt->fetchAll(PDO::FETCH_ASSOC),
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
        json_response(dashboard_state($pdo, $user));
    }

    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? 'save_layout');
    $employeeId = (int)$user['id'];
    $clientId = (int)$user['client_id'];

    if ($action === 'reset_layout') {
        $stmt = $pdo->prepare('DELETE FROM dashboard_layouts WHERE employee_id=? AND context_client_id=?');
        $stmt->execute([$employeeId, $clientId]);
        json_response(dashboard_state($pdo, $user));
    }

    if ($action !== 'save_layout') {
        json_response(['success' => false, 'error' => 'Unsupported dashboard action.'], 400);
    }

    $items = $input['layout'] ?? null;
    if (!is_array($items) || count($items) > 30) {
        throw new MerdWorkforceException('invalid_dashboard_layout', 'Dashboard layout must contain 0–30 widgets.');
    }

    $allowed = array_fill_keys(dashboard_allowed_widgets($user), true);
    $clean = [];
    $seen = [];
    foreach ($items as $row) {
        if (!is_array($row)) throw new MerdWorkforceException('invalid_dashboard_layout', 'Invalid dashboard widget row.');
        $key = strtolower(trim((string)($row['widget_key'] ?? '')));
        if (!isset($allowed[$key])) throw new MerdWorkforceException('invalid_dashboard_widget', 'That dashboard widget is not available for your role.');
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
        $delete = $pdo->prepare('DELETE FROM dashboard_layouts WHERE employee_id=? AND context_client_id=?');
        $delete->execute([$employeeId, $clientId]);
        if ($clean) {
            $insert = $pdo->prepare(
                'INSERT INTO dashboard_layouts (employee_id,context_client_id,widget_key,grid_x,grid_y,grid_w,grid_h) '
                . 'VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($clean as [$key, $x, $y, $w, $h]) {
                $insert->execute([$employeeId, $clientId, $key, $x, $y, $w, $h]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_response(dashboard_state($pdo, $user));
} catch (Throwable $e) {
    beta_api_error($e);
}
