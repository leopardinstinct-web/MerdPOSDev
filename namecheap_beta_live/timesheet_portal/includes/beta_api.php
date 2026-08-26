<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../../backend/api/includes/workforce_beta.php';

function beta_api_error(Throwable $error): never
{
    if ($error instanceof MerdWorkforceException) {
        json_response(['success' => false, 'error_code' => $error->errorCode, 'error' => $error->getMessage()], 200);
    }
    error_log('timesheet beta API failure: ' . get_class($error));
    json_response(['success' => false, 'error_code' => 'internal_error', 'error' => 'The request could not be completed.'], 500);
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
        throw new MerdWorkforceException('account_inactive','Your account is inactive. Contact a SUPER user.');
    }

    $roleRowValid = !empty($actor['client_role_id'])
        && !empty($actor['role_key'])
        && strtolower((string)($actor['role_status'] ?? 'active')) === 'active';
    $baseRole = strtoupper(trim((string)($roleRowValid ? $actor['base_role'] : $actor['employee_type'])));
    if ($baseRole === '') $baseRole = 'USER';
    $isManagement = in_array($baseRole, ['ADMIN','SUPER','DEV'], true);

    $user['role'] = $baseRole;
    $user['actual_employee_type'] = $baseRole;
    $user['employee_type'] = $isManagement ? 'SUPER' : $baseRole;
    $user['role_name'] = $roleRowValid ? (string)$actor['role_label'] : (string)($actor['role_name'] ?: $baseRole);
    $user['client_role_id'] = $roleRowValid ? (int)$actor['client_role_id'] : null;
    $user['role_key'] = $roleRowValid ? (string)$actor['role_key'] : $baseRole;
    $user['role_label'] = $roleRowValid ? (string)$actor['role_label'] : $user['role_name'];
    $user['authority_level'] = $roleRowValid ? (int)$actor['authority_level'] : ($baseRole === 'DEV' ? 1000 : 0);
    $user['is_super'] = $isManagement;
    $user['is_management'] = $isManagement;
    $user['is_admin'] = $baseRole === 'ADMIN';
    $user['is_dev'] = $baseRole === 'DEV';

    // Keep the immutable home-session identity in sync so server-rendered pages
    // use the new role after the next navigation/reload without a fresh login.
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach (['role','actual_employee_type','employee_type','role_name','client_role_id','role_key','role_label','authority_level','is_super','is_management','is_admin','is_dev'] as $field) {
            $_SESSION['user'][$field] = $user[$field];
        }
    }

    if ($baseRole === 'DEV') {
        $contextId = (int)$user['client_id'];
        $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE id=? LIMIT 1');
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
