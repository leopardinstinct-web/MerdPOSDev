<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function directory_role_rank(string $role): int
{
    return match (strtoupper($role)) {
        'DEV' => 30,
        'SUPER' => 20,
        'ADMIN' => 10,
        default => 0,
    };
}

function directory_role_name(string $role): string
{
    return match (strtoupper($role)) {
        'DEV' => 'Developer',
        'SUPER' => 'Super',
        'ADMIN' => 'Administrator',
        default => 'Staff',
    };
}

function directory_actor(PDO $pdo, array $sessionUser): array
{
    $stmt = $pdo->prepare('SELECT id,client_id,full_name,employee_type,status FROM employees WHERE id=? AND client_id=? LIMIT 1');
    $stmt->execute([(int)$sessionUser['id'], (int)$sessionUser['client_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || strtolower((string)$row['status']) !== 'active') {
        throw new MerdWorkforceException('account_inactive', 'Your account is inactive.');
    }
    $row['employee_type'] = strtoupper(trim((string)$row['employee_type']));
    if (!in_array($row['employee_type'], ['ADMIN', 'SUPER', 'DEV'], true)) {
        json_response(['success' => false, 'error' => 'ADMIN, SUPER or DEV access required.'], 403);
    }
    return $row;
}

function directory_allowed_roles(string $actorRole): array
{
    return match (strtoupper($actorRole)) {
        'DEV' => ['USER', 'ADMIN', 'SUPER', 'DEV'],
        'SUPER' => ['USER', 'ADMIN', 'SUPER'],
        'ADMIN' => ['USER', 'ADMIN'],
        default => ['USER'],
    };
}

function directory_assert_target_role(string $actorRole, string $targetRole): void
{
    if (!in_array(strtoupper($targetRole), directory_allowed_roles($actorRole), true)) {
        throw new MerdWorkforceException('role_forbidden', 'You cannot assign that access level.');
    }
}

function directory_audit(PDO $pdo, array $actor, string $action, string $entityType, ?string $entityId, array $details): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$actor['client_id'],
            (int)$actor['id'],
            $action,
            $entityType,
            $entityId,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS directory audit write failed: ' . get_class($e));
    }
}

function directory_store_columns(PDO $pdo): array
{
    $rows = $pdo->query('SHOW COLUMNS FROM stores')->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($rows as $row) $columns[(string)$row['Field']] = $row;
    return $columns;
}

function directory_generated_code(string $name): string
{
    $base = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)));
    $base = trim($base, '-');
    if ($base === '') $base = 'STORE';
    return substr($base, 0, 24) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function directory_ensure_shift_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_shift_start_times ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . 'client_id INT NOT NULL,store_id INT NOT NULL,store_name VARCHAR(150) NOT NULL,'
        . 'shift_start_time TIME NOT NULL,updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . 'UNIQUE KEY uq_store_shift_start (client_id,store_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function directory_load_state(PDO $pdo, array $actor): array
{
    $clientId = (int)$actor['client_id'];

    $storesStmt = $pdo->prepare(
        "SELECT s.id,s.store_name,s.status,COALESCE(t.shift_start_time,'') AS shift_start_time "
        . "FROM stores s LEFT JOIN store_shift_start_times t ON t.client_id=s.client_id AND t.store_id=s.id "
        . "WHERE s.client_id=? ORDER BY CASE WHEN s.status='active' THEN 0 ELSE 1 END,s.store_name"
    );
    $storesStmt->execute([$clientId]);
    $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);

    $employeesStmt = $pdo->prepare(
        "SELECT e.id,e.full_name,e.user_id,e.store_id,COALESCE(s.store_name,'') AS store_name,"
        . "UPPER(COALESCE(e.employee_type,'USER')) AS employee_type,e.role_name,e.hourly_rate,e.status "
        . "FROM employees e LEFT JOIN stores s ON s.id=e.store_id AND s.client_id=e.client_id "
        . "WHERE e.client_id=? ORDER BY CASE WHEN e.status='active' THEN 0 ELSE 1 END,e.full_name"
    );
    $employeesStmt->execute([$clientId]);
    $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

    $actorRank = directory_role_rank((string)$actor['employee_type']);
    foreach ($employees as &$employee) {
        $targetRank = directory_role_rank((string)$employee['employee_type']);
        $employee['editable'] = $targetRank <= $actorRank;
        $employee['self'] = (int)$employee['id'] === (int)$actor['id'];
    }
    unset($employee);

    return [
        'success' => true,
        'csrf' => csrf_token(),
        'actor' => [
            'id' => (int)$actor['id'],
            'role' => (string)$actor['employee_type'],
            'allowed_roles' => directory_allowed_roles((string)$actor['employee_type']),
        ],
        'employees' => $employees,
        'stores' => $stores,
    ];
}

try {
    $sessionUser = beta_require_active_user();
    $pdo = portal_db();
    $actor = directory_actor($pdo, $sessionUser);
    directory_ensure_shift_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(directory_load_state($pdo, $actor));
    }

    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? '');

    if ($action === 'save_employee') {
        $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
        $name = trim((string)($input['full_name'] ?? ''));
        $userId = preg_replace('/\D+/', '', (string)($input['user_id'] ?? ''));
        $storeId = (int)($input['store_id'] ?? 0);
        $role = strtoupper(trim((string)($input['employee_type'] ?? 'USER'));
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $rateText = trim((string)($input['hourly_rate'] ?? '0'));
        $newPassword = preg_replace('/\D+/', '', (string)($input['new_password'] ?? ''));

        if ($name === '' || mb_strlen($name) > 190) throw new MerdWorkforceException('invalid_name', 'Enter an employee name.');
        if ($userId === '' || strlen($userId) > 32) throw new MerdWorkforceException('invalid_user_id', 'Enter a numeric User ID.');
        if (!in_array($status, ['active', 'inactive'], true)) throw new MerdWorkforceException('invalid_status', 'Choose active or inactive.');
        if (!is_numeric($rateText) || (float)$rateText < 0 || (float)$rateText > 9999) throw new MerdWorkforceException('invalid_rate', 'Enter a valid hourly rate.');
        directory_assert_target_role((string)$actor['employee_type'], $role);

        $storeCheck = $pdo->prepare('SELECT id FROM stores WHERE id=? AND client_id=? LIMIT 1');
        $storeCheck->execute([$storeId, (int)$actor['client_id']]);
        if (!$storeCheck->fetchColumn()) throw new MerdWorkforceException('invalid_store', 'Choose a valid store.');

        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT id,employee_type,status FROM employees WHERE id=? AND client_id=? LIMIT 1');
            $stmt->execute([$id, (int)$actor['client_id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) throw new MerdWorkforceException('employee_not_found', 'Employee not found.');
            if (directory_role_rank((string)$existing['employee_type']) > directory_role_rank((string)$actor['employee_type'])) {
                throw new MerdWorkforceException('role_forbidden', 'You cannot edit that employee.');
            }
            if ($id === (int)$actor['id'] && ($role !== (string)$actor['employee_type'] || $status !== 'active')) {
                throw new MerdWorkforceException('self_protection', 'You cannot change your own access level or deactivate your own account here.');
            }
        } elseif ($newPassword === '') {
            throw new MerdWorkforceException('password_required', 'Set a numeric password for the new employee.');
        }

        if ($newPassword !== '' && (strlen($newPassword) < 4 || strlen($newPassword) > 20)) {
            throw new MerdWorkforceException('invalid_password', 'Password must be 4–20 digits.');
        }

        $dupUser = $pdo->prepare('SELECT id FROM employees WHERE client_id=? AND user_id=? AND (? IS NULL OR id<>?) LIMIT 1');
        $dupUser->execute([(int)$actor['client_id'], $userId, $id, $id]);
        if ($dupUser->fetchColumn()) throw new MerdWorkforceException('duplicate_user_id', 'That User ID is already in use.');

        $dupName = $pdo->prepare('SELECT id FROM employees WHERE client_id=? AND LOWER(TRIM(full_name))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1');
        $dupName->execute([(int)$actor['client_id'], $name, $id, $id]);
        if ($dupName->fetchColumn()) throw new MerdWorkforceException('duplicate_name', 'An employee with that name already exists.');

        $pdo->beginTransaction();
        try {
            $roleName = directory_role_name($role);
            $rate = number_format((float)$rateText, 2, '.', '');
            if ($id === null) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'INSERT INTO employees (client_id,store_id,full_name,user_id,login_password,employee_type,pin_code,role_name,hourly_rate,status) VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([(int)$actor['client_id'], $storeId, $name, $userId, $hash, $role, $hash, $roleName, $rate, $status]);
                $id = (int)$pdo->lastInsertId();
                $auditAction = 'employee.create';
            } else {
                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare(
                        'UPDATE employees SET store_id=?,full_name=?,user_id=?,employee_type=?,role_name=?,hourly_rate=?,status=?,login_password=?,pin_code=? WHERE id=? AND client_id=?'
                    );
                    $stmt->execute([$storeId, $name, $userId, $role, $roleName, $rate, $status, $hash, $hash, $id, (int)$actor['client_id']]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE employees SET store_id=?,full_name=?,user_id=?,employee_type=?,role_name=?,hourly_rate=?,status=? WHERE id=? AND client_id=?'
                    );
                    $stmt->execute([$storeId, $name, $userId, $role, $roleName, $rate, $status, $id, (int)$actor['client_id']]);
                }
                $auditAction = 'employee.update';
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        directory_audit($pdo, $actor, $auditAction, 'employee', (string)$id, [
            'full_name' => $name,
            'user_id' => $userId,
            'store_id' => $storeId,
            'employee_type' => $role,
            'hourly_rate' => $rate,
            'status' => $status,
            'password_reset' => $newPassword !== '',
        ]);
        json_response(directory_load_state($pdo, $actor));
    }

    if ($action === 'save_store') {
        $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
        $name = trim((string)($input['store_name'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $shiftStart = trim((string)($input['shift_start_time'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) throw new MerdWorkforceException('invalid_store_name', 'Enter a store name.');
        if (!in_array($status, ['active', 'inactive'], true)) throw new MerdWorkforceException('invalid_status', 'Choose active or inactive.');
        if ($shiftStart !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $shiftStart)) {
            throw new MerdWorkforceException('invalid_shift_start', 'Enter a valid shift start time.');
        }
        if (strlen($shiftStart) === 5) $shiftStart .= ':00';

        $dup = $pdo->prepare('SELECT id FROM stores WHERE client_id=? AND LOWER(TRIM(store_name))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1');
        $dup->execute([(int)$actor['client_id'], $name, $id, $id]);
        if ($dup->fetchColumn()) throw new MerdWorkforceException('duplicate_store', 'A store with that name already exists.');

        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $columns = directory_store_columns($pdo);
                $values = [
                    'client_id' => (int)$actor['client_id'],
                    'store_name' => $name,
                    'status' => $status,
                ];
                if (isset($columns['name'])) $values['name'] = $name;
                if (isset($columns['timezone'])) $values['timezone'] = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Australia/Sydney';
                if (isset($columns['store_code'])) $values['store_code'] = directory_generated_code($name);
                if (isset($columns['code'])) $values['code'] = directory_generated_code($name);
                if (isset($columns['slug'])) $values['slug'] = strtolower(directory_generated_code($name));

                foreach ($columns as $field => $meta) {
                    if (array_key_exists($field, $values) || str_contains(strtolower((string)$meta['Extra']), 'auto_increment')) continue;
                    if ($meta['Default'] !== null || strtoupper((string)$meta['Null']) === 'YES') continue;
                    if (in_array($field, ['created_at', 'updated_at'], true)) continue;
                    throw new MerdWorkforceException('store_schema_unsupported', 'The store schema requires an additional field: ' . $field . '.');
                }

                $fieldSql = implode(',', array_map(fn(string $f): string => '`' . $f . '`', array_keys($values)));
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $stmt = $pdo->prepare('INSERT INTO stores (' . $fieldSql . ') VALUES (' . $placeholders . ')');
                $stmt->execute(array_values($values));
                $id = (int)$pdo->lastInsertId();
                $auditAction = 'store.create';
            } else {
                $check = $pdo->prepare('SELECT id FROM stores WHERE id=? AND client_id=? LIMIT 1');
                $check->execute([$id, (int)$actor['client_id']]);
                if (!$check->fetchColumn()) throw new MerdWorkforceException('store_not_found', 'Store not found.');
                $columns = directory_store_columns($pdo);
                $assign = ['store_name=?', 'status=?'];
                $args = [$name, $status];
                if (isset($columns['name'])) { $assign[] = '`name`=?'; $args[] = $name; }
                $args[] = $id;
                $args[] = (int)$actor['client_id'];
                $stmt = $pdo->prepare('UPDATE stores SET ' . implode(',', $assign) . ' WHERE id=? AND client_id=?');
                $stmt->execute($args);
                $auditAction = 'store.update';
            }

            if ($shiftStart !== '') {
                $stmt = $pdo->prepare(
                    'INSERT INTO store_shift_start_times (client_id,store_id,store_name,shift_start_time) VALUES (?,?,?,?) '
                    . 'ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),shift_start_time=VALUES(shift_start_time),updated_at=CURRENT_TIMESTAMP'
                );
                $stmt->execute([(int)$actor['client_id'], $id, $name, $shiftStart]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM store_shift_start_times WHERE client_id=? AND store_id=?');
                $stmt->execute([(int)$actor['client_id'], $id]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        directory_audit($pdo, $actor, $auditAction, 'store', (string)$id, [
            'store_name' => $name,
            'status' => $status,
            'shift_start_time' => $shiftStart,
        ]);
        json_response(directory_load_state($pdo, $actor));
    }

    json_response(['success' => false, 'error' => 'Unsupported directory action.'], 400);
} catch (Throwable $e) {
    beta_api_error($e);
}
