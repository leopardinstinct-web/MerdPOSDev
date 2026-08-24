<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function store_identity_actor(PDO $pdo, array $sessionUser): array
{
    $authClientId = (int)($sessionUser['auth_client_id'] ?? $sessionUser['client_id']);
    $stmt = $pdo->prepare('SELECT id,client_id,full_name,employee_type,status FROM employees WHERE id=? AND client_id=? LIMIT 1');
    $stmt->execute([(int)$sessionUser['id'], $authClientId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($actor) || strtolower((string)$actor['status']) !== 'active') {
        throw new MerdWorkforceException('account_inactive', 'Your account is inactive.');
    }
    $role = strtoupper(trim((string)$actor['employee_type']));
    if ($role !== 'DEV') {
        json_response(['success' => false, 'error' => 'DEV access required for store identifiers.'], 403);
    }
    $actor['employee_type'] = $role;
    $actor['auth_client_id'] = $authClientId;
    $actor['client_id'] = (int)$sessionUser['client_id'];
    return $actor;
}

function store_identity_columns(PDO $pdo): array
{
    $rows = $pdo->query('SHOW COLUMNS FROM stores')->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($rows as $row) $columns[(string)$row['Field']] = $row;
    return $columns;
}

function store_identity_code(string $value): string
{
    $code = strtoupper(trim($value));
    if (strlen($code) < 2 || strlen($code) > 50) {
        throw new MerdWorkforceException('invalid_store_code', 'Store Code must be 2–50 characters.');
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,49}$/', $code)) {
        throw new MerdWorkforceException('invalid_store_code', 'Store Code must start with a letter or number and use only A–Z, 0–9, hyphen or underscore.');
    }
    $reserved = ['ALL','NONE','NULL','SYSTEM','ADMIN','SUPER','DEV','STORE'];
    if (in_array($code, $reserved, true)) {
        throw new MerdWorkforceException('reserved_store_code', 'That Store Code is reserved. Choose a more specific identifier.');
    }
    return $code;
}

function store_identity_load(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare(
        "SELECT id,store_name,store_code,status FROM stores WHERE client_id=? ORDER BY id ASC"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function store_identity_audit(PDO $pdo, array $actor, string $action, int $storeId, array $details): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$actor['client_id'],
            (int)$actor['id'],
            $action,
            'store',
            (string)$storeId,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS store identity audit write failed: ' . get_class($e));
    }
}

try {
    $sessionUser = beta_require_active_user();
    $pdo = portal_db();
    $actor = store_identity_actor($pdo, $sessionUser);
    $clientId = (int)$actor['client_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response([
            'success' => true,
            'csrf' => csrf_token(),
            'actor_role' => 'DEV',
            'active_client_id' => $clientId,
            'stores' => store_identity_load($pdo, $clientId),
            'rules' => [
                'store_code_min_length' => 2,
                'store_code_max_length' => 50,
                'store_code_pattern' => '^[A-Z0-9][A-Z0-9_-]{1,49}$',
                'store_code_unique_scope' => 'client',
            ],
        ]);
    }

    $input = request_input();
    require_csrf($input);
    if ((string)($input['action'] ?? '') !== 'save_store') {
        json_response(['success' => false, 'error' => 'Unsupported store identity action.'], 400);
    }

    $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
    $name = trim((string)($input['store_name'] ?? ''));
    $status = strtolower(trim((string)($input['status'] ?? 'active')));
    $storeCode = store_identity_code((string)($input['store_code'] ?? ''));

    if ($name === '' || mb_strlen($name) > 150) {
        throw new MerdWorkforceException('invalid_store_name', 'Enter a store name up to 150 characters.');
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        throw new MerdWorkforceException('invalid_status', 'Choose active or inactive.');
    }

    $dupName = $pdo->prepare(
        'SELECT id FROM stores WHERE client_id=? AND LOWER(TRIM(store_name))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1'
    );
    $dupName->execute([$clientId, $name, $id, $id]);
    if ($dupName->fetchColumn()) {
        throw new MerdWorkforceException('duplicate_store', 'A store with that name already exists.');
    }

    $dupCode = $pdo->prepare(
        'SELECT id FROM stores WHERE client_id=? AND LOWER(TRIM(store_code))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1'
    );
    $dupCode->execute([$clientId, $storeCode, $id, $id]);
    if ($dupCode->fetchColumn()) {
        throw new MerdWorkforceException('duplicate_store_code', 'That Store Code is already assigned to another store.');
    }

    $columns = store_identity_columns($pdo);
    if (!isset($columns['store_code'])) {
        throw new MerdWorkforceException('store_schema_unsupported', 'The live stores table does not contain store_code.');
    }

    $oldCode = null;
    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $values = [
                'client_id' => $clientId,
                'store_name' => $name,
                'store_code' => $storeCode,
                'status' => $status,
            ];
            if (isset($columns['name'])) $values['name'] = $name;
            if (isset($columns['timezone'])) $values['timezone'] = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Australia/Sydney';
            if (isset($columns['code'])) $values['code'] = $storeCode;
            if (isset($columns['slug'])) $values['slug'] = strtolower($storeCode);

            foreach ($columns as $field => $meta) {
                if (array_key_exists($field, $values) || str_contains(strtolower((string)$meta['Extra']), 'auto_increment')) continue;
                if ($meta['Default'] !== null || strtoupper((string)$meta['Null']) === 'YES') continue;
                if (in_array($field, ['created_at', 'updated_at'], true)) continue;
                throw new MerdWorkforceException('store_schema_unsupported', 'The store schema requires an additional field: ' . $field . '.');
            }

            $fieldSql = implode(',', array_map(fn(string $field): string => '`' . $field . '`', array_keys($values)));
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $stmt = $pdo->prepare('INSERT INTO stores (' . $fieldSql . ') VALUES (' . $placeholders . ')');
            $stmt->execute(array_values($values));
            $id = (int)$pdo->lastInsertId();
            $auditAction = 'store.create';
        } else {
            $check = $pdo->prepare('SELECT id,store_code FROM stores WHERE id=? AND client_id=? LIMIT 1 FOR UPDATE');
            $check->execute([$id, $clientId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                throw new MerdWorkforceException('store_not_found', 'Store not found.');
            }
            $oldCode = (string)$existing['store_code'];

            $assign = ['store_name=?', 'store_code=?', 'status=?'];
            $args = [$name, $storeCode, $status];
            if (isset($columns['name'])) { $assign[] = '`name`=?'; $args[] = $name; }
            if (isset($columns['code'])) { $assign[] = '`code`=?'; $args[] = $storeCode; }
            if (isset($columns['slug'])) { $assign[] = '`slug`=?'; $args[] = strtolower($storeCode); }
            $args[] = $id;
            $args[] = $clientId;
            $stmt = $pdo->prepare('UPDATE stores SET ' . implode(',', $assign) . ' WHERE id=? AND client_id=?');
            $stmt->execute($args);

            $legacyName = $pdo->prepare('UPDATE store_shift_start_times SET store_name=? WHERE client_id=? AND store_id=?');
            $legacyName->execute([$name, $clientId, $id]);
            $auditAction = 'store.update';
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)$e->getCode() === '23000') {
            throw new MerdWorkforceException('duplicate_store_code', 'Store name or Store Code conflicts with an existing store.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    store_identity_audit($pdo, $actor, $auditAction, (int)$id, [
        'store_name' => $name,
        'store_code' => $storeCode,
        'previous_store_code' => $oldCode,
        'status' => $status,
    ]);

    json_response([
        'success' => true,
        'message' => $auditAction === 'store.create' ? 'Store created.' : 'Store saved.',
        'csrf' => csrf_token(),
        'store_id' => (int)$id,
        'active_client_id' => $clientId,
        'stores' => store_identity_load($pdo, $clientId),
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
