<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/role_authority.php';
require_once __DIR__ . '/../includes/dashboard_access.php';

function role_actor(PDO $pdo, array $sessionUser): array
{
    $authClientId = (int)($sessionUser['auth_client_id'] ?? $sessionUser['client_id']);
    $stmt = $pdo->prepare('SELECT id,client_id,full_name,employee_type,status FROM employees WHERE id=? AND client_id=? LIMIT 1');
    $stmt->execute([(int)$sessionUser['id'], $authClientId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($actor) || strtolower((string)$actor['status']) !== 'active') {
        throw new MerdWorkforceException('account_inactive', 'Your account is inactive.');
    }
    if (strtoupper(trim((string)$actor['employee_type'])) !== 'DEV') {
        json_response(['success' => false, 'error' => 'DEV access required.'], 403);
    }
    $actor['auth_client_id'] = $authClientId;
    $actor['client_id'] = (int)$sessionUser['client_id'];
    return $actor;
}

function role_label(mixed $value): string
{
    $label = trim((string)$value);
    if ($label === '' || mb_strlen($label) > 80) {
        throw new MerdWorkforceException('invalid_role_name', 'Enter a role name up to 80 characters.');
    }
    return $label;
}

function role_level(mixed $value): int
{
    $level = filter_var($value, FILTER_VALIDATE_INT);
    if ($level === false || $level < 1 || $level > 99) {
        throw new MerdWorkforceException('invalid_authority', 'LOA must be a whole number from 1 to 99.');
    }
    return (int)$level;
}

function role_key_from_label(PDO $pdo, int $clientId, string $label): string
{
    $base = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', trim($label)));
    $base = trim($base, '_');
    if ($base === '') $base = 'ROLE';
    $base = substr($base, 0, 26);
    if (in_array($base, ['USER','ADMIN','SUPER','DEV'], true)) $base .= '_CUSTOM';
    $key = $base;
    $suffix = 2;
    $check = $pdo->prepare('SELECT COUNT(*) FROM client_roles WHERE client_id=? AND role_key=?');
    while (true) {
        $check->execute([$clientId, $key]);
        if ((int)$check->fetchColumn() === 0) return $key;
        $tail = '_' . $suffix++;
        $key = substr($base, 0, 32 - strlen($tail)) . $tail;
    }
}

function role_state(PDO $pdo, array $actor): array
{
    $clientId = (int)$actor['client_id'];
    $clientStmt = $pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1');
    $clientStmt->execute([$clientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) throw new MerdWorkforceException('client_not_found', 'Working client was not found.');

    $stmt = $pdo->prepare(
        'SELECT r.id,r.role_key,r.role_label,r.base_role,r.authority_level,r.is_system,r.status,'
        . '(SELECT COUNT(*) FROM employees e WHERE e.client_id=r.client_id AND e.client_role_id=r.id) AS employee_count,'
        . '(SELECT COUNT(*) FROM dashboard_role_layouts d WHERE d.role_id=r.id) AS dashboard_widget_count '
        . 'FROM client_roles r WHERE r.client_id=? ORDER BY r.authority_level ASC,r.id ASC'
    );
    $stmt->execute([$clientId]);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($roles as &$role) {
        $role['allowed_widgets'] = merd_dashboard_allowed_widgets($pdo, $clientId, $role);
        $role['deletable'] = !(bool)$role['is_system'] && (int)$role['employee_count'] === 0;
        $role['editable'] = strtoupper((string)$role['role_key']) !== 'DEV';
    }
    unset($role);

    return [
        'success' => true,
        'csrf' => csrf_token(),
        'client' => $client,
        'roles' => $roles,
        'dev_authority_level' => 1000,
    ];
}

function role_audit(PDO $pdo, array $actor, string $action, int $roleId, array $details): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$actor['client_id'], (int)$actor['id'], $action, 'client_role', (string)$roleId,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS role audit failed: ' . get_class($e));
    }
}

function sync_system_authority(PDO $pdo, int $clientId, string $roleKey, int $level, int $actorId): void
{
    if (!in_array($roleKey, ['USER','ADMIN','SUPER'], true)) return;
    $stmt = $pdo->prepare(
        'INSERT INTO client_role_authority (client_id,role_name,authority_level,updated_by_employee_id) VALUES (?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE authority_level=VALUES(authority_level),updated_by_employee_id=VALUES(updated_by_employee_id),updated_at=CURRENT_TIMESTAMP'
    );
    $stmt->execute([$clientId, $roleKey, $level, $actorId]);
}

function prune_role_dashboard(PDO $pdo, int $clientId, array $role): void
{
    $allowed = array_fill_keys(merd_dashboard_allowed_widgets($pdo, $clientId, $role), true);
    $stmt = $pdo->prepare('SELECT id,widget_key FROM dashboard_role_layouts WHERE client_id=? AND role_id=?');
    $stmt->execute([$clientId, (int)$role['id']]);
    $delete = $pdo->prepare('DELETE FROM dashboard_role_layouts WHERE id=?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($allowed[(string)$row['widget_key']])) $delete->execute([(int)$row['id']]);
    }
}

function clone_admin_dashboard(PDO $pdo, int $clientId, array $newRole): void
{
    $admin = merd_dashboard_system_role($pdo, $clientId, 'ADMIN');
    if (!$admin) return;
    $allowed = array_fill_keys(merd_dashboard_allowed_widgets($pdo, $clientId, $newRole), true);
    $source = $pdo->prepare('SELECT widget_key,grid_x,grid_y,grid_w,grid_h FROM dashboard_role_layouts WHERE client_id=? AND role_id=? ORDER BY grid_y,grid_x,id');
    $source->execute([$clientId, (int)$admin['id']]);
    $insert = $pdo->prepare(
        'INSERT INTO dashboard_role_layouts (client_id,role_id,widget_key,grid_x,grid_y,grid_w,grid_h) VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($source->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($allowed[(string)$row['widget_key']])) continue;
        $insert->execute([$clientId, (int)$newRole['id'], (string)$row['widget_key'], (int)$row['grid_x'], (int)$row['grid_y'], (int)$row['grid_w'], (int)$row['grid_h']]);
    }
}

try {
    $sessionUser = beta_require_active_user();
    $pdo = portal_db();
    $actor = role_actor($pdo, $sessionUser);
    $clientId = (int)$actor['client_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(role_state($pdo, $actor));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['success' => false, 'error' => 'GET or POST required.'], 405);
    }

    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? '');

    if ($action === 'create_role') {
        $label = role_label($input['role_label'] ?? '');
        $level = role_level($input['authority_level'] ?? null);
        $dup = $pdo->prepare('SELECT id FROM client_roles WHERE client_id=? AND LOWER(TRIM(role_label))=LOWER(TRIM(?)) LIMIT 1');
        $dup->execute([$clientId, $label]);
        if ($dup->fetchColumn()) throw new MerdWorkforceException('duplicate_role', 'A role with that name already exists.');
        $key = role_key_from_label($pdo, $clientId, $label);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO client_roles (client_id,role_key,role_label,base_role,authority_level,is_system,status) VALUES (?,?,?,'ADMIN',?,0,'active')");
            $stmt->execute([$clientId, $key, $label, $level]);
            $roleId = (int)$pdo->lastInsertId();
            $newRole = merd_dashboard_role_by_id($pdo, $clientId, $roleId);
            if (!$newRole) throw new RuntimeException('New role could not be reloaded.');
            clone_admin_dashboard($pdo, $clientId, $newRole);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        role_audit($pdo, $actor, 'role.create', $roleId, ['role_key'=>$key,'role_label'=>$label,'base_role'=>'ADMIN','authority_level'=>$level,'inherits_dashboard_from'=>'ADMIN']);
        json_response(role_state($pdo, $actor));
    }

    if ($action === 'save_role') {
        $roleId = filter_var($input['role_id'] ?? null, FILTER_VALIDATE_INT);
        if ($roleId === false || $roleId <= 0) throw new MerdWorkforceException('invalid_role', 'Choose a valid role.');
        $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
        if (!$role) throw new MerdWorkforceException('role_not_found', 'Role not found.');
        $key = strtoupper((string)$role['role_key']);
        if ($key === 'DEV') throw new MerdWorkforceException('role_fixed', 'DEV authority is fixed at 1000.');
        $level = role_level($input['authority_level'] ?? null);
        $label = !empty($role['is_system']) ? (string)$role['role_label'] : role_label($input['role_label'] ?? $role['role_label']);

        if (empty($role['is_system'])) {
            $dup = $pdo->prepare('SELECT id FROM client_roles WHERE client_id=? AND LOWER(TRIM(role_label))=LOWER(TRIM(?)) AND id<>? LIMIT 1');
            $dup->execute([$clientId, $label, (int)$roleId]);
            if ($dup->fetchColumn()) throw new MerdWorkforceException('duplicate_role', 'A role with that name already exists.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE client_roles SET role_label=?,authority_level=? WHERE client_id=? AND id=?');
            $stmt->execute([$label, $level, $clientId, (int)$roleId]);
            sync_system_authority($pdo, $clientId, $key, $level, (int)$actor['id']);
            $updated = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
            if ($updated) prune_role_dashboard($pdo, $clientId, $updated);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        role_audit($pdo, $actor, 'role.update', (int)$roleId, ['role_label'=>$label,'authority_level'=>$level]);
        json_response(role_state($pdo, $actor));
    }

    if ($action === 'delete_role') {
        $roleId = filter_var($input['role_id'] ?? null, FILTER_VALIDATE_INT);
        if ($roleId === false || $roleId <= 0) throw new MerdWorkforceException('invalid_role', 'Choose a valid role.');
        $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
        if (!$role) throw new MerdWorkforceException('role_not_found', 'Role not found.');
        if (!empty($role['is_system'])) throw new MerdWorkforceException('role_fixed', 'System roles cannot be deleted.');
        $count = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=? AND client_role_id=?');
        $count->execute([$clientId, (int)$roleId]);
        if ((int)$count->fetchColumn() > 0) throw new MerdWorkforceException('role_in_use', 'Reassign employees before deleting this role.');
        $pdo->prepare('DELETE FROM client_roles WHERE client_id=? AND id=?')->execute([$clientId, (int)$roleId]);
        role_audit($pdo, $actor, 'role.delete', (int)$roleId, ['role_key'=>$role['role_key'],'role_label'=>$role['role_label'],'dashboard'=>'cascade_deleted']);
        json_response(role_state($pdo, $actor));
    }

    // Backward-compatible save for the original three authority inputs.
    if ($action === 'save_authority') {
        $levels = $input['levels'] ?? null;
        if (!is_array($levels)) throw new MerdWorkforceException('invalid_authority', 'Provide authority levels.');
        $pdo->beginTransaction();
        try {
            foreach (['USER','ADMIN','SUPER'] as $key) {
                $level = role_level($levels[$key] ?? $levels[strtolower($key)] ?? null);
                $role = merd_dashboard_system_role($pdo, $clientId, $key);
                if (!$role) throw new RuntimeException("{$key} role is missing.");
                $pdo->prepare('UPDATE client_roles SET authority_level=? WHERE id=?')->execute([$level, (int)$role['id']]);
                sync_system_authority($pdo, $clientId, $key, $level, (int)$actor['id']);
                $role['authority_level'] = $level;
                prune_role_dashboard($pdo, $clientId, $role);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        json_response(role_state($pdo, $actor));
    }

    json_response(['success' => false, 'error' => 'Unsupported role action.'], 400);
} catch (Throwable $e) {
    beta_api_error($e);
}
