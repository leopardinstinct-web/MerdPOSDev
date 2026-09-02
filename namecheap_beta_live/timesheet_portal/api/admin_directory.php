<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/dashboard_access.php';

function directory_audit(PDO $pdo, array $actor, string $action, string $entityType, ?string $entityId, array $details): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            (int)$actor['client_id'], (int)$actor['id'], $action, $entityType, $entityId,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS directory audit write failed: ' . get_class($e));
    }
}

function directory_actor(array $sessionUser): array
{
    $sessionUser['full_name'] = (string)($sessionUser['full_name'] ?? $sessionUser['name'] ?? '');
    $sessionUser['auth_client_id'] = (int)($sessionUser['auth_client_id'] ?? $sessionUser['client_id']);
    $sessionUser['authority_level'] = max(1, (int)($sessionUser['authority_level'] ?? 1));
    return $sessionUser;
}

function directory_role_rows(PDO $pdo, array $actor): array
{
    if (!beta_has_permission($actor, 'workforce.manage', $pdo)) return [];
    $stmt = $pdo->prepare(
        "SELECT id,role_key,role_label,base_role,authority_level,is_system,status FROM client_roles "
        . "WHERE client_id=? AND status='active' AND authority_level<=? ORDER BY authority_level ASC,id ASC"
    );
    $stmt->execute([(int)$actor['client_id'], (int)$actor['authority_level']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function directory_role_for_save(PDO $pdo, array $actor, mixed $roleId, mixed $legacyBase = null): array
{
    beta_require_permission($actor, 'workforce.manage', $pdo);
    $clientId = (int)$actor['client_id'];
    $id = filter_var($roleId, FILTER_VALIDATE_INT);
    $role = null;
    if ($id !== false && $id > 0) $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$id);
    if (!$role && $legacyBase !== null) $role = merd_dashboard_system_role($pdo, $clientId, strtoupper(trim((string)$legacyBase)));
    if (!$role || strtolower((string)$role['status']) !== 'active') throw new MerdWorkforceException('role_not_found', 'Choose a valid active role.');
    if ((int)$role['authority_level'] > (int)$actor['authority_level']) throw new MerdWorkforceException('role_forbidden', 'You cannot assign a role above your Level of Authority.');
    return $role;
}

function directory_store_columns(PDO $pdo): array
{
    $rows = $pdo->query('SHOW COLUMNS FROM stores')->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($rows as $row) $columns[(string)$row['Field']] = $row;
    return $columns;
}

function directory_store_edit_fields(PDO $pdo): array
{
    $columns = directory_store_columns($pdo);
    $groups = [
        [['store_code','code'], 'Code', 'text', false],
        [['address','address_line1'], 'Address', 'text', true],
        [['address_line2'], 'Address line 2', 'text', true],
        [['suburb'], 'Suburb', 'text', false],
        [['city'], 'City', 'text', false],
        [['state','region'], 'State / region', 'text', false],
        [['postcode','postal_code'], 'Postcode', 'text', false],
        [['country'], 'Country', 'text', false],
        [['phone','phone_number'], 'Phone', 'tel', false],
        [['email','store_email'], 'Email', 'email', false],
        [['timezone'], 'Timezone', 'text', false],
        [['currency_code'], 'Currency', 'text', false],
        [['tax_number'], 'Tax number', 'text', false],
        [['abn'], 'ABN', 'text', false],
    ];
    $fields = [];
    foreach ($groups as [$names,$label,$type,$wide]) {
        foreach ($names as $name) {
            if (!isset($columns[$name])) continue;
            $column = $columns[$name];
            $max = 255;
            if (preg_match('/varchar\((\d+)\)/i', (string)($column['Type'] ?? ''), $match)) $max = (int)$match[1];
            $fields[] = [
                'name'=>$name,'label'=>$label,'type'=>$type,'wide'=>$wide,'max_length'=>$max,
                'nullable'=>strtoupper((string)($column['Null'] ?? 'NO')) === 'YES',
                'has_default'=>($column['Default'] ?? null) !== null,
            ];
            break;
        }
    }
    return $fields;
}

function directory_store_profile_input(array $input, array $fields, bool $isNew): array
{
    $values = [];
    foreach ($fields as $field) {
        $name = (string)$field['name'];
        $value = trim((string)($input[$name] ?? ''));
        $max = max(1, (int)($field['max_length'] ?? 255));
        if (mb_strlen($value) > $max) throw new MerdWorkforceException('invalid_store_field', (string)$field['label'] . ' is too long.');
        if (($field['type'] ?? '') === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new MerdWorkforceException('invalid_store_email', 'Enter a valid store email address.');
        }
        if ($value === '' && empty($field['nullable']) && empty($field['has_default'])) {
            if ($isNew && in_array($name, ['store_code','code'], true)) $value = directory_generated_code((string)($input['store_name'] ?? 'Store'));
            else throw new MerdWorkforceException('required_store_field', (string)$field['label'] . ' is required.');
        }
        $values[$name] = ($value === '' && !empty($field['nullable'])) ? null : $value;
    }
    return $values;
}

function directory_normalize_store_time(mixed $value): ?string
{
    $text = trim((string)$value);
    if ($text === '') return null;
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $text)) throw new MerdWorkforceException('invalid_time', 'Use a valid 24-hour time.');
    return strlen($text) === 5 ? $text . ':00' : $text;
}

function directory_normalize_store_schedule(array $rawDays): array
{
    $days = [];
    foreach ($rawDays as $rawDay) {
        if (!is_array($rawDay)) continue;
        $day = filter_var($rawDay['day_of_week'] ?? null, FILTER_VALIDATE_INT);
        if ($day === false || $day < 1 || $day > 7 || isset($days[$day])) throw new MerdWorkforceException('invalid_schedule', 'Each weekday must appear once.');
        $closed = !empty($rawDay['is_closed']);
        $start = $closed ? null : directory_normalize_store_time($rawDay['start_time'] ?? null);
        $end = $closed ? null : directory_normalize_store_time($rawDay['end_time'] ?? null);
        if (!$closed && ($start === null || $end === null)) throw new MerdWorkforceException('incomplete_schedule', 'Every open day needs both a start time and an end time.');
        $days[(int)$day] = ['day_of_week'=>(int)$day,'start_time'=>$start,'end_time'=>$end,'is_closed'=>$closed?1:0];
    }
    ksort($days);
    if (count($days) !== 7) throw new MerdWorkforceException('invalid_schedule', 'All seven weekdays are required.');
    return $days;
}

function directory_save_store_schedule(PDO $pdo, int $clientId, int $storeId, string $storeName, int $weekStartDay, array $days, int $actorId): void
{
    $upsert = $pdo->prepare('INSERT INTO store_weekly_hours (client_id,store_id,day_of_week,start_time,end_time,is_closed,updated_by_employee_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time),end_time=VALUES(end_time),is_closed=VALUES(is_closed),updated_by_employee_id=VALUES(updated_by_employee_id),updated_at=CURRENT_TIMESTAMP');
    foreach ($days as $day) $upsert->execute([$clientId,$storeId,$day['day_of_week'],$day['start_time'],$day['end_time'],$day['is_closed'],$actorId]);
    $legacyStart = null;
    if (isset($days[1]) && !$days[1]['is_closed']) $legacyStart = $days[1]['start_time'];
    if ($legacyStart === null) foreach ($days as $day) if (!$day['is_closed'] && $day['start_time'] !== null) { $legacyStart = $day['start_time']; break; }
    if ($legacyStart !== null) {
        $legacy = $pdo->prepare('INSERT INTO store_shift_start_times (client_id,store_id,store_name,shift_start_time) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),shift_start_time=VALUES(shift_start_time),updated_at=CURRENT_TIMESTAMP');
        $legacy->execute([$clientId,$storeId,$storeName,$legacyStart]);
    } else {
        $pdo->prepare('DELETE FROM store_shift_start_times WHERE client_id=? AND store_id=?')->execute([$clientId,$storeId]);
    }
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

function directory_normalize_store_ids(mixed $value): array
{
    if (!is_array($value)) return [];
    $ids = [];
    foreach ($value as $raw) {
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) $ids[(int)$id] = (int)$id;
    }
    ksort($ids);
    return array_values($ids);
}

function directory_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function directory_permissions(PDO $pdo, array $actor): array
{
    $keys = [
        'stores.view','stores.manage','stores.timings.manage','stores.profile.manage','stores.logo.manage',
        'workforce.view','workforce.manage','workforce.payrates.manage','workforce.credentials.reset',
    ];
    $out = [];
    foreach ($keys as $key) $out[$key] = beta_has_permission($actor, $key, $pdo);
    return $out;
}

function directory_load_state(PDO $pdo, array $actor): array
{
    $clientId = (int)$actor['client_id'];
    $permissions = directory_permissions($pdo, $actor);
    $canStores = $permissions['stores.view'] || $permissions['workforce.view'];
    $canWorkforce = $permissions['workforce.view'];
    $canPay = $permissions['workforce.payrates.manage'];

    $stores = [];
    $storeEditFields = $canStores ? directory_store_edit_fields($pdo) : [];
    if ($canStores) {
        $storeSelect = "SELECT s.id,s.store_name,s.status,COALESCE(s.week_start_day,1) AS week_start_day,COALESCE(t.shift_start_time,'') AS shift_start_time";
        foreach ($storeEditFields as $field) $storeSelect .= ',s.`' . $field['name'] . '`';
        $storesStmt = $pdo->prepare($storeSelect . " FROM stores s LEFT JOIN store_shift_start_times t ON t.client_id=s.client_id AND t.store_id=s.id WHERE s.client_id=? ORDER BY s.id ASC");
        $storesStmt->execute([$clientId]);
        $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $employees = [];
    if ($canWorkforce) {
        $employeesStmt = $pdo->prepare(
            "SELECT e.id,e.full_name,e.user_id,e.store_id,COALESCE(s.store_name,'') AS store_name,"
            . "UPPER(COALESCE(e.employee_type,'USER')) AS employee_type,e.role_name,e.client_role_id,e.hourly_rate,e.status,"
            . "COALESCE(r.role_key,UPPER(COALESCE(e.employee_type,'USER'))) AS role_key,"
            . "COALESCE(r.role_label,e.role_name,UPPER(COALESCE(e.employee_type,'USER'))) AS role_label,"
            . "COALESCE(r.authority_level,0) AS role_authority,COALESCE(r.base_role,UPPER(COALESCE(e.employee_type,'USER'))) AS role_base,"
            . "COALESCE(a.access_mode,'all') AS store_access_mode "
            . "FROM employees e "
            . "LEFT JOIN stores s ON s.id=e.store_id AND s.client_id=e.client_id "
            . "LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id "
            . "LEFT JOIN employee_store_access a ON a.employee_id=e.id AND a.client_id=e.client_id "
            . "WHERE e.client_id=? ORDER BY e.id ASC"
        );
        $employeesStmt->execute([$clientId]);
        $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

        $assignmentStmt = $pdo->prepare('SELECT employee_id,store_id FROM employee_store_assignments WHERE client_id=? ORDER BY employee_id,store_id');
        $assignmentStmt->execute([$clientId]);
        $assignmentMap = [];
        foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $assignmentMap[(int)$row['employee_id']][] = (int)$row['store_id'];

        $rateMap = [];
        if ($canPay) {
            $rateStmt = $pdo->prepare('SELECT employee_id,hourly_rate,effective_from FROM employee_hourly_rate_history WHERE client_id=? ORDER BY employee_id,effective_from,id');
            $rateStmt->execute([$clientId]);
            foreach ($rateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rateMap[(int)$row['employee_id']][] = ['hourly_rate'=>(float)$row['hourly_rate'],'effective_from'=>(string)$row['effective_from']];
            }
        }

        $today = date('Y-m-d');
        foreach ($employees as &$employee) {
            $employeeId = (int)$employee['id'];
            $mode = strtolower((string)($employee['store_access_mode'] ?? 'all'));
            if (!in_array($mode, ['all','selected'], true)) $mode = 'all';
            $employee['store_access_mode'] = $mode;
            $employee['assigned_store_ids'] = $mode === 'selected' ? ($assignmentMap[$employeeId] ?? []) : [];
            $targetAuthority = strtoupper((string)$employee['role_key']) === 'DEV' ? 1000 : (int)$employee['role_authority'];
            $employee['editable'] = $permissions['workforce.manage'] && $targetAuthority <= (int)$actor['authority_level'];
            $employee['self'] = $clientId === (int)$actor['auth_client_id'] && $employeeId === (int)$actor['id'];
            $employee['rate_history'] = $canPay ? ($rateMap[$employeeId] ?? []) : [];
            $employee['next_rate'] = null;
            if ($canPay) {
                foreach ($employee['rate_history'] as $rateRow) {
                    if ($rateRow['effective_from'] > $today) { $employee['next_rate'] = $rateRow; break; }
                }
            } else {
                $employee['hourly_rate'] = null;
            }
        }
        unset($employee);
    } else {
        $today = date('Y-m-d');
    }

    $roles = directory_role_rows($pdo, $actor);
    return [
        'success'=>true,
        'csrf'=>csrf_token(),
        'today'=>$today,
        'active_client_id'=>$clientId,
        'permissions'=>$permissions,
        'actor'=>[
            'id'=>(int)$actor['id'],
            'role_key'=>(string)($actor['role_key'] ?? $actor['role'] ?? 'USER'),
            'role_label'=>(string)($actor['role_label'] ?? $actor['role_name'] ?? 'User'),
            'authority_level'=>(int)$actor['authority_level'],
            'roles'=>$roles,
            'allowed_roles'=>array_values(array_map(fn(array $r): string => (string)$r['role_key'], $roles)),
        ],
        'employees'=>$employees,
        'stores'=>$stores,
        'store_edit_fields'=>$storeEditFields,
    ];
}

try {
    $sessionUser = beta_require_active_user();
    $pdo = portal_db();
    $actor = directory_actor($sessionUser);
    directory_ensure_shift_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(directory_load_state($pdo, $actor));
    }

    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? '');

    if ($action === 'save_employee') {
        beta_require_permission($actor, 'workforce.manage', $pdo);
        $canPay = beta_has_permission($actor, 'workforce.payrates.manage', $pdo);
        $canResetCredentials = beta_has_permission($actor, 'workforce.credentials.reset', $pdo);

        $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
        $name = trim((string)($input['full_name'] ?? ''));
        $userId = preg_replace('/\D+/', '', (string)($input['user_id'] ?? ''));
        $roleRow = directory_role_for_save($pdo, $actor, $input['client_role_id'] ?? null, $input['employee_type'] ?? 'USER');
        $role = strtoupper((string)$roleRow['base_role']);
        $roleName = (string)$roleRow['role_label'];
        $clientRoleId = (int)$roleRow['id'];
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $rateText = trim((string)($input['hourly_rate'] ?? ''));
        $rateEffective = trim((string)($input['rate_effective_date'] ?? ''));
        $newPassword = preg_replace('/\D+/', '', (string)($input['new_password'] ?? ''));
        $storeAccessMode = strtolower(trim((string)($input['store_access_mode'] ?? 'all')));
        $selectedStoreIds = directory_normalize_store_ids($input['store_ids'] ?? []);

        if ($name === '' || mb_strlen($name) > 190) throw new MerdWorkforceException('invalid_name', 'Enter an employee name.');
        if ($userId === '' || strlen($userId) > 32) throw new MerdWorkforceException('invalid_user_id', 'Enter a numeric User ID.');
        if (!in_array($status, ['active','inactive'], true)) throw new MerdWorkforceException('invalid_status', 'Choose active or inactive.');
        if (!in_array($storeAccessMode, ['all','selected'], true)) throw new MerdWorkforceException('invalid_store_access', 'Choose all stores or selected stores.');
        if ($canPay) {
            if (!is_numeric($rateText) || (float)$rateText < 0 || (float)$rateText > 9999) throw new MerdWorkforceException('invalid_rate', 'Enter a valid hourly rate.');
            if ($rateEffective === '' || !directory_valid_date($rateEffective)) throw new MerdWorkforceException('invalid_rate_date', 'Choose a valid effective date for the hourly rate.');
        }

        $storeRowsStmt = $pdo->prepare('SELECT id,status FROM stores WHERE client_id=? ORDER BY id');
        $storeRowsStmt->execute([(int)$actor['client_id']]);
        $validStores = []; $activeStores = [];
        foreach ($storeRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $storeRow) {
            $sid = (int)$storeRow['id']; $validStores[$sid] = true;
            if (strtolower((string)$storeRow['status']) === 'active') $activeStores[$sid] = true;
        }
        if (!$activeStores) throw new MerdWorkforceException('no_active_stores', 'At least one active store is required.');

        $existing = null;
        if ($id !== null) {
            $stmt = $pdo->prepare(
                'SELECT e.id,e.employee_type,e.client_role_id,e.status,e.store_id,e.hourly_rate,COALESCE(r.authority_level,0) role_authority,COALESCE(r.role_key,e.employee_type) role_key '
                . 'FROM employees e LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id WHERE e.id=? AND e.client_id=? LIMIT 1'
            );
            $stmt->execute([$id, (int)$actor['client_id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) throw new MerdWorkforceException('employee_not_found', 'Employee not found.');
            $existingAuthority = strtoupper((string)$existing['role_key']) === 'DEV' ? 1000 : (int)$existing['role_authority'];
            if ($existingAuthority > (int)$actor['authority_level']) throw new MerdWorkforceException('role_forbidden', 'You cannot edit that employee.');
            if ((int)$actor['client_id'] === (int)$actor['auth_client_id'] && $id === (int)$actor['id'] && ($clientRoleId !== (int)($existing['client_role_id'] ?? 0) || $status !== 'active')) {
                throw new MerdWorkforceException('self_protection', 'You cannot change your own role or deactivate your own account here.');
            }
            if ($newPassword !== '' && !$canResetCredentials) {
                throw new MerdWorkforceException('forbidden', 'Your access level does not permit password resets.');
            }
        } elseif ($newPassword === '') {
            throw new MerdWorkforceException('password_required', 'Set a numeric password for the new employee.');
        }

        if ($storeAccessMode === 'selected') {
            if (!$selectedStoreIds) throw new MerdWorkforceException('store_access_empty', 'Select at least one allowed store.');
            foreach ($selectedStoreIds as $selectedStoreId) if (!isset($activeStores[$selectedStoreId])) throw new MerdWorkforceException('invalid_store_access', 'Selected store access can only include active stores.');
            $storeId = (int)$selectedStoreIds[0];
        } else {
            $existingStoreId = is_array($existing) ? (int)($existing['store_id'] ?? 0) : 0;
            $storeId = isset($validStores[$existingStoreId]) ? $existingStoreId : (int)array_key_first($activeStores);
            $selectedStoreIds = [];
        }

        if ($newPassword !== '' && (strlen($newPassword) < 4 || strlen($newPassword) > 20)) throw new MerdWorkforceException('invalid_password', 'Password must be 4–20 digits.');

        $dupUser = $pdo->prepare('SELECT id FROM employees WHERE client_id=? AND user_id=? AND (? IS NULL OR id<>?) LIMIT 1');
        $dupUser->execute([(int)$actor['client_id'], $userId, $id, $id]);
        if ($dupUser->fetchColumn()) throw new MerdWorkforceException('duplicate_user_id', 'That User ID is already in use.');
        $dupName = $pdo->prepare('SELECT id FROM employees WHERE client_id=? AND LOWER(TRIM(full_name))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1');
        $dupName->execute([(int)$actor['client_id'], $name, $id, $id]);
        if ($dupName->fetchColumn()) throw new MerdWorkforceException('duplicate_name', 'An employee with that name already exists.');

        $requestedRate = $canPay ? number_format((float)$rateText, 2, '.', '') : (is_array($existing) ? number_format((float)$existing['hourly_rate'], 2, '.', '') : '0.00');

        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'INSERT INTO employees (client_id,store_id,full_name,user_id,login_password,employee_type,pin_code,role_name,client_role_id,hourly_rate,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([(int)$actor['client_id'],$storeId,$name,$userId,$hash,$role,$hash,$roleName,$clientRoleId,$requestedRate,$status]);
                $id = (int)$pdo->lastInsertId(); $auditAction = 'employee.create';
            } else {
                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE employees SET store_id=?,full_name=?,user_id=?,employee_type=?,role_name=?,client_role_id=?,status=?,login_password=?,pin_code=? WHERE id=? AND client_id=?');
                    $stmt->execute([$storeId,$name,$userId,$role,$roleName,$clientRoleId,$status,$hash,$hash,$id,(int)$actor['client_id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE employees SET store_id=?,full_name=?,user_id=?,employee_type=?,role_name=?,client_role_id=?,status=? WHERE id=? AND client_id=?');
                    $stmt->execute([$storeId,$name,$userId,$role,$roleName,$clientRoleId,$status,$id,(int)$actor['client_id']]);
                }
                $auditAction = 'employee.update';
            }

            $accessStmt = $pdo->prepare(
                'INSERT INTO employee_store_access (client_id,employee_id,access_mode,updated_by_employee_id) VALUES (?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE access_mode=VALUES(access_mode),updated_by_employee_id=VALUES(updated_by_employee_id),updated_at=CURRENT_TIMESTAMP'
            );
            $accessStmt->execute([(int)$actor['client_id'],$id,$storeAccessMode,(int)$actor['id']]);

            $pdo->prepare('DELETE FROM employee_store_assignments WHERE client_id=? AND employee_id=?')->execute([(int)$actor['client_id'],$id]);
            if ($storeAccessMode === 'selected') {
                $insertAssignment = $pdo->prepare('INSERT INTO employee_store_assignments (client_id,employee_id,store_id) VALUES (?,?,?)');
                foreach ($selectedStoreIds as $selectedStoreId) $insertAssignment->execute([(int)$actor['client_id'],$id,$selectedStoreId]);
            }

            if ($canPay) {
                $rateStmt = $pdo->prepare(
                    'INSERT INTO employee_hourly_rate_history (client_id,employee_id,hourly_rate,effective_from,changed_by_employee_id) VALUES (?,?,?,?,?) '
                    . 'ON DUPLICATE KEY UPDATE hourly_rate=VALUES(hourly_rate),changed_by_employee_id=VALUES(changed_by_employee_id),updated_at=CURRENT_TIMESTAMP'
                );
                $rateStmt->execute([(int)$actor['client_id'],$id,$requestedRate,$rateEffective,(int)$actor['id']]);

                $currentRateStmt = $pdo->prepare('SELECT hourly_rate FROM employee_hourly_rate_history WHERE client_id=? AND employee_id=? AND effective_from<=CURDATE() ORDER BY effective_from DESC,id DESC LIMIT 1');
                $currentRateStmt->execute([(int)$actor['client_id'],$id]);
                $currentRate = $currentRateStmt->fetchColumn(); if ($currentRate === false) $currentRate = $requestedRate;
                $pdo->prepare('UPDATE employees SET hourly_rate=? WHERE id=? AND client_id=?')->execute([number_format((float)$currentRate,2,'.',''),$id,(int)$actor['client_id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        directory_audit($pdo,$actor,$auditAction,'employee',(string)$id,[
            'full_name'=>$name,'user_id'=>$userId,'internal_store_id'=>$storeId,'store_access_mode'=>$storeAccessMode,'store_ids'=>$selectedStoreIds,
            'employee_type'=>$role,'client_role_id'=>$clientRoleId,'role_key'=>$roleRow['role_key'],'role_label'=>$roleName,'authority_level'=>(int)$roleRow['authority_level'],
            'hourly_rate_changed'=>$canPay,'rate_effective_date'=>$canPay ? $rateEffective : null,'status'=>$status,'password_reset'=>$newPassword !== '',
        ]);
        json_response(directory_load_state($pdo,$actor));
    }

    if ($action === 'save_store') {
        beta_require_permission($actor, 'stores.manage', $pdo);
        $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
        $name = trim((string)($input['store_name'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $weekStartDay = (int)($input['week_start_day'] ?? 1);
        if ($name === '' || mb_strlen($name) > 150) throw new MerdWorkforceException('invalid_store_name', 'Enter a store name.');
        if (!in_array($status, ['active','inactive'], true)) throw new MerdWorkforceException('invalid_status', 'Choose active or inactive.');
        if ($weekStartDay < 1 || $weekStartDay > 7) throw new MerdWorkforceException('invalid_week_start', 'Choose a valid week start day.');

        $dup = $pdo->prepare('SELECT id FROM stores WHERE client_id=? AND LOWER(TRIM(store_name))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1');
        $dup->execute([(int)$actor['client_id'],$name,$id,$id]);
        if ($dup->fetchColumn()) throw new MerdWorkforceException('duplicate_store', 'A store with that name already exists.');

        $columns = directory_store_columns($pdo);
        $storeEditFields = directory_store_edit_fields($pdo);
        $profileValues = directory_store_profile_input($input, $storeEditFields, $id === null);
        $scheduleDays = null;
        if (array_key_exists('days', $input)) {
            beta_require_permission($actor, 'stores.timings.manage', $pdo);
            if (!is_array($input['days'])) throw new MerdWorkforceException('invalid_schedule', 'A seven-day schedule is required.');
            $scheduleDays = directory_normalize_store_schedule($input['days']);
        }

        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $values = ['client_id'=>(int)$actor['client_id'],'store_name'=>$name,'status'=>$status,'week_start_day'=>$weekStartDay] + $profileValues;
                if (isset($columns['name'])) $values['name'] = $name;
                if (isset($columns['store_code']) && !array_key_exists('store_code',$values)) $values['store_code'] = directory_generated_code($name);
                if (isset($columns['code']) && !array_key_exists('code',$values)) $values['code'] = directory_generated_code($name);
                if (isset($columns['slug'])) $values['slug'] = strtolower(directory_generated_code($name));
                foreach ($columns as $field=>$meta) {
                    if (array_key_exists($field,$values) || str_contains(strtolower((string)$meta['Extra']),'auto_increment')) continue;
                    if ($meta['Default'] !== null || strtoupper((string)$meta['Null']) === 'YES') continue;
                    if (in_array($field,['created_at','updated_at'],true)) continue;
                    throw new MerdWorkforceException('store_schema_unsupported','The store schema requires an additional field: '.$field.'.');
                }
                $fieldSql = implode(',',array_map(fn(string $f):string=>'`'.$f.'`',array_keys($values)));
                $placeholders = implode(',',array_fill(0,count($values),'?'));
                $stmt = $pdo->prepare('INSERT INTO stores ('.$fieldSql.') VALUES ('.$placeholders.')');
                $stmt->execute(array_values($values));
                $id = (int)$pdo->lastInsertId(); $auditAction = 'store.create';
            } else {
                $check = $pdo->prepare('SELECT id FROM stores WHERE id=? AND client_id=? LIMIT 1');
                $check->execute([$id,(int)$actor['client_id']]);
                if (!$check->fetchColumn()) throw new MerdWorkforceException('store_not_found','Store not found.');
                $assign = ['store_name=?','status=?','week_start_day=?']; $args = [$name,$status,$weekStartDay];
                if (isset($columns['name'])) { $assign[]='`name`=?'; $args[]=$name; }
                foreach ($profileValues as $field=>$value) { $assign[]='`'.$field.'`=?'; $args[]=$value; }
                $args[]=$id; $args[]=(int)$actor['client_id'];
                $pdo->prepare('UPDATE stores SET '.implode(',',$assign).' WHERE id=? AND client_id=?')->execute($args);
                $pdo->prepare('UPDATE store_shift_start_times SET store_name=? WHERE client_id=? AND store_id=?')->execute([$name,(int)$actor['client_id'],$id]);
                $auditAction = 'store.update';
            }
            if ($scheduleDays !== null) directory_save_store_schedule($pdo,(int)$actor['client_id'],(int)$id,$name,$weekStartDay,$scheduleDays,(int)$actor['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        directory_audit($pdo,$actor,$auditAction,'store',(string)$id,['store_name'=>$name,'status'=>$status,'week_start_day'=>$weekStartDay,'profile_fields'=>array_keys($profileValues),'schedule_updated'=>$scheduleDays!==null]);
        json_response(directory_load_state($pdo,$actor));
    }

    json_response(['success'=>false,'error'=>'Unsupported directory action.'],400);
} catch (Throwable $e) {
    beta_api_error($e);
}
