<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../../backend/api/includes/portal_permissions.php';
require_once __DIR__ . '/../../backend/api/includes/workforce_beta.php';

function beta_api_error(Throwable $error): never
{
    if ($error instanceof MerdWorkforceException) {
        json_response(['success' => false, 'error_code' => $error->errorCode, 'error' => $error->getMessage()], 200);
    }
    error_log('timesheet beta API failure: ' . get_class($error));
    json_response(['success' => false, 'error_code' => 'internal_error', 'error' => 'The request could not be completed.'], 500);
}

/**
 * Effective permission thresholds for one client. Database values override the
 * catalogue defaults for delegable permissions. DEV-only permissions are
 * always forced to 1000 in code, even if the database is manually altered.
 */
function beta_permission_levels(PDO $pdo, int $clientId): array
{
    static $cache = [];
    if (isset($cache[$clientId])) return $cache[$clientId];

    $catalog = merd_portal_permission_catalog();
    $levels = [];
    foreach ($catalog as $key => $rule) {
        $levels[$key] = !empty($rule['dev_only']) ? 1000 : max(1, min(1000, (int)$rule['min_loa']));
    }

    try {
        $stmt = $pdo->prepare('SELECT permission_key,min_authority_level FROM client_permission_levels WHERE client_id=?');
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['permission_key'];
            if (!isset($catalog[$key]) || !empty($catalog[$key]['dev_only'])) continue;
            $levels[$key] = max(1, min(1000, (int)$row['min_authority_level']));
        }
    } catch (Throwable $e) {
        // Migration-safe fallback. Deployment runs schema migrations before the
        // portal is published, but a catalogue default remains fail-closed for
        // unknown/new permissions if schema reconciliation is incomplete.
        error_log('MERDPOS permission level fallback: ' . get_class($e));
    }

    return $cache[$clientId] = $levels;
}

function beta_user_is_dev(array $user): bool
{
    return strtoupper(trim((string)($user['actual_employee_type'] ?? $user['role'] ?? ''))) === 'DEV';
}

function beta_has_permission(array $user, string $permission, ?PDO $pdo = null): bool
{
    $catalog = merd_portal_permission_catalog();
    if (!isset($catalog[$permission])) return false; // unknown permissions fail closed

    if (isset($user['permissions']) && is_array($user['permissions']) && array_key_exists($permission, $user['permissions'])) {
        return (bool)$user['permissions'][$permission];
    }

    if (!empty($catalog[$permission]['dev_only'])) return beta_user_is_dev($user);

    $authority = max(0, (int)($user['authority_level'] ?? 0));
    $required = (int)$catalog[$permission]['min_loa'];
    if ($pdo instanceof PDO && !empty($user['client_id'])) {
        $levels = beta_permission_levels($pdo, (int)$user['client_id']);
        $required = (int)($levels[$permission] ?? 1000);
    }
    return $authority >= $required;
}

function beta_require_permission(array $user, string $permission, ?PDO $pdo = null): void
{
    if (beta_has_permission($user, $permission, $pdo)) return;
    $catalog = merd_portal_permission_catalog();
    $label = (string)($catalog[$permission]['label'] ?? $permission);
    throw new MerdWorkforceException('forbidden', 'Your access level does not permit: ' . $label . '.');
}

function beta_require_any_permission(array $user, array $permissions, ?PDO $pdo = null): void
{
    foreach ($permissions as $permission) {
        if (beta_has_permission($user, (string)$permission, $pdo)) return;
    }
    throw new MerdWorkforceException('forbidden', 'Your access level does not permit this action.');
}

function beta_permission_snapshot(PDO $pdo, array $user): array
{
    $levels = beta_permission_levels($pdo, (int)$user['client_id']);
    $catalog = merd_portal_permission_catalog();
    $authority = max(0, (int)($user['authority_level'] ?? 0));
    $isDev = beta_user_is_dev($user);
    $permissions = [];
    foreach ($catalog as $key => $rule) {
        $permissions[$key] = !empty($rule['dev_only'])
            ? $isDev
            : $authority >= (int)($levels[$key] ?? 1000);
    }
    return [$permissions, $levels];
}

function beta_require_active_user(): array
{
    $user = require_login();
    $pdo = portal_db();
    $authClientId = (int)($user['auth_client_id'] ?? $user['client_id']);

    // Authentication identity always belongs to the original tenant. A DEV
    // working-client context never changes which employee row is authenticated.
    $stmt = $pdo->prepare(
        'SELECT e.status,e.employee_type,e.role_name,e.client_role_id,'
        . 'r.role_key,r.role_label,r.base_role,r.authority_level,r.status AS role_status '
        . 'FROM employees e LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id '
        . 'WHERE e.id=? AND e.client_id=? LIMIT 1'
    );
    $stmt->execute([(int)$user['id'], $authClientId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($actor) || strtolower((string)$actor['status']) !== 'active') {
        throw new MerdWorkforceException('account_inactive','Your account is inactive. Contact an authorised manager.');
    }

    $roleRowValid = !empty($actor['client_role_id'])
        && !empty($actor['role_key'])
        && strtolower((string)($actor['role_status'] ?? 'active')) === 'active';
    $baseRole = strtoupper(trim((string)($roleRowValid ? $actor['base_role'] : $actor['employee_type'])));
    if (!in_array($baseRole, ['USER','ADMIN','SUPER','DEV'], true)) $baseRole = 'USER';

    $authority = 0;
    $roleKey = $baseRole;
    $roleLabel = (string)($actor['role_name'] ?: $baseRole);
    $clientRoleId = null;
    if ($roleRowValid) {
        $authority = (int)$actor['authority_level'];
        $roleKey = (string)$actor['role_key'];
        $roleLabel = (string)$actor['role_label'];
        $clientRoleId = (int)$actor['client_role_id'];
    } else {
        // Defensive compatibility for an incompletely mapped legacy account.
        try {
            $fallback = $pdo->prepare("SELECT id,role_key,role_label,authority_level FROM client_roles WHERE client_id=? AND role_key=? AND status='active' LIMIT 1");
            $fallback->execute([$authClientId, $baseRole]);
            $role = $fallback->fetch(PDO::FETCH_ASSOC);
            if (is_array($role)) {
                $authority = (int)$role['authority_level'];
                $roleKey = (string)$role['role_key'];
                $roleLabel = (string)$role['role_label'];
                $clientRoleId = (int)$role['id'];
            }
        } catch (Throwable $e) {
            $authority = match ($baseRole) {'DEV'=>1000,'SUPER'=>90,'ADMIN'=>50,default=>10};
        }
    }
    if ($baseRole === 'DEV') $authority = 1000;
    $authority = max(1, min(1000, $authority));

    $user['role'] = $baseRole;
    $user['actual_employee_type'] = $baseRole;
    $user['employee_type'] = $baseRole;
    $user['role_name'] = $roleLabel;
    $user['client_role_id'] = $clientRoleId;
    $user['role_key'] = $roleKey;
    $user['role_label'] = $roleLabel;
    $user['authority_level'] = $authority;
    $user['is_dev'] = $baseRole === 'DEV';

    if ($baseRole === 'DEV') {
        $contextId = (int)$user['client_id'];
        $clientStmt = $pdo->prepare("SELECT id FROM clients WHERE id=? AND status='active' LIMIT 1");
        $clientStmt->execute([$contextId]);
        if (!$clientStmt->fetchColumn()) {
            clear_dev_active_client_id();
            $user['client_id'] = $authClientId;
            $user['active_client_id'] = $authClientId;
            $user['is_cross_client_context'] = false;
        }
    } else {
        // A user who is no longer DEV cannot retain a DEV-selected tenant.
        clear_dev_active_client_id();
        $user['client_id'] = $authClientId;
        $user['active_client_id'] = $authClientId;
        $user['is_cross_client_context'] = false;
    }

    $user['auth_client_id'] = $authClientId;
    $user['home_client_id'] = $authClientId;
    [$permissions, $levels] = beta_permission_snapshot($pdo, $user);
    $user['permissions'] = $permissions;
    $user['permission_levels'] = $levels;
    $user['is_management'] = !empty($permissions['workforce.view'])
        || !empty($permissions['timesheets.view_all'])
        || !empty($permissions['disputes.review'])
        || !empty($permissions['finance.cross_store']);
    // Compatibility flag only. Do not use as an authorization decision in new code.
    $user['is_super'] = !empty($permissions['timesheets.view_all']);
    $user['is_admin'] = $baseRole === 'ADMIN';

    // Keep identity metadata in sync for display only. Permission checks always
    // refresh through this function and never trust a stale session flag.
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach (['role','actual_employee_type','employee_type','role_name','client_role_id','role_key','role_label','authority_level','is_super','is_management','is_admin','is_dev'] as $field) {
            $_SESSION['user'][$field] = $user[$field];
        }
    }

    return $user;
}

function parse_utc_datetime(mixed $value, bool $optional = true): ?string
{
    if (($value === null || $value === '') && $optional) return null;
    $text = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new DateTimeZone(APP_TIMEZONE));
    if (!$date) throw new MerdWorkforceException('invalid_datetime', 'Enter a valid date and time.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
