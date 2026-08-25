<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/role_authority.php';

function role_authority_actor(PDO $pdo, array $sessionUser): array
{
    $authClientId = (int)($sessionUser['auth_client_id'] ?? $sessionUser['client_id']);
    $stmt = $pdo->prepare('SELECT id,client_id,full_name,employee_type,status FROM employees WHERE id=? AND client_id=? LIMIT 1');
    $stmt->execute([(int)$sessionUser['id'], $authClientId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($actor) || strtolower((string)$actor['status']) !== 'active') {
        throw new MerdWorkforceException('account_inactive', 'Your account is inactive.');
    }
    $role = strtoupper(trim((string)$actor['employee_type']));
    if ($role !== 'DEV') json_response(['success' => false, 'error' => 'DEV access required.'], 403);
    $actor['employee_type'] = 'DEV';
    $actor['auth_client_id'] = $authClientId;
    $actor['client_id'] = (int)$sessionUser['client_id'];
    return $actor;
}

function role_authority_state(PDO $pdo, array $actor): array
{
    $clientId = (int)$actor['client_id'];
    $clientStmt = $pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1');
    $clientStmt->execute([$clientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) throw new MerdWorkforceException('client_not_found', 'Working client was not found.');

    $map = merd_role_authority_map($pdo, $clientId);
    return [
        'success' => true,
        'csrf' => csrf_token(),
        'client' => $client,
        'roles' => [
            ['role_name' => 'USER', 'label' => 'User', 'authority_level' => $map['USER']],
            ['role_name' => 'ADMIN', 'label' => 'Admin', 'authority_level' => $map['ADMIN']],
            ['role_name' => 'SUPER', 'label' => 'Super', 'authority_level' => $map['SUPER']],
        ],
        'dev_authority_level' => 1000,
    ];
}

function role_authority_audit(PDO $pdo, array $actor, array $before, array $after): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$actor['client_id'],
            (int)$actor['id'],
            'role_authority.update',
            'client_role_authority',
            (string)$actor['client_id'],
            json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS role authority audit write failed: ' . get_class($e));
    }
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $actor = role_authority_actor($pdo, $user);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(role_authority_state($pdo, $actor));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['success' => false, 'error' => 'GET or POST required.'], 405);
    }

    $input = request_input();
    require_csrf($input);
    if ((string)($input['action'] ?? '') !== 'save_authority') {
        json_response(['success' => false, 'error' => 'Unsupported role action.'], 400);
    }

    $levels = $input['levels'] ?? null;
    if (!is_array($levels)) throw new MerdWorkforceException('invalid_authority', 'Provide authority levels for User, Admin and Super.');

    $normalized = [];
    foreach (['USER','ADMIN','SUPER'] as $role) {
        $raw = $levels[$role] ?? $levels[strtolower($role)] ?? null;
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1 || $value > 99) {
            throw new MerdWorkforceException('invalid_authority', 'Authority levels must be whole numbers from 1 to 99.');
        }
        $normalized[$role] = (int)$value;
    }
    if (count(array_unique(array_values($normalized))) !== 3) {
        throw new MerdWorkforceException('duplicate_authority', 'User, Admin and Super must each have a different authority level.');
    }

    $before = merd_role_authority_map($pdo, (int)$actor['client_id']);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO client_role_authority (client_id,role_name,authority_level,updated_by_employee_id) VALUES (?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE authority_level=VALUES(authority_level),updated_by_employee_id=VALUES(updated_by_employee_id),updated_at=CURRENT_TIMESTAMP'
        );
        foreach ($normalized as $role => $level) {
            $stmt->execute([(int)$actor['client_id'], $role, $level, (int)$actor['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    role_authority_audit($pdo, $actor, $before, $normalized + ['DEV' => 1000]);
    json_response(role_authority_state($pdo, $actor));
} catch (Throwable $e) {
    beta_api_error($e);
}
