<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../../backend/api/includes/employee_auth.php';
require_once __DIR__ . '/../../backend/api/includes/auth_lockout.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Login endpoint is working. Use the MERDPOS login form.'], 200);
}

$input = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input');
if (empty($input) && stripos($contentType, 'application/json') !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}
if (empty($input) && $raw !== '') {
    parse_str($raw, $parsed);
    if (is_array($parsed)) $input = $parsed;
}

$userId = preg_replace('/\D+/', '', (string)($input['user_id'] ?? ''));
$password = preg_replace('/\D+/', '', (string)($input['password'] ?? ''));
if ($userId === '' || $password === '') {
    json_response(['success' => false, 'error' => 'Enter numeric User ID and Password.'], 200);
}

try {
    $pdo = portal_db();
    $fingerprint = 'portal-' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $lockout = new MerdAuthLockoutService(new MerdPdoAuthLockoutStore($pdo));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lockout->assertNotLocked(PORTAL_CLIENT_ID, $userId, $fingerprint, 'portal_login', $now);

    $stmt = $pdo->prepare(
        "SELECT id,client_id,store_id,full_name,user_id,login_password,pin_code,employee_type,role_name,client_role_id,status "
        . "FROM employees WHERE client_id=? AND user_id=? AND status='active' LIMIT 1"
    );
    $stmt->execute([PORTAL_CLIENT_ID, $userId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($employee) || !merd_employee_authenticates($employee, $password)) {
        $lockout->recordFailure(PORTAL_CLIENT_ID, is_array($employee) ? (int)$employee['id'] : null, $userId, $fingerprint, 'portal_login', $now);
        json_response(['success' => false, 'error' => 'Invalid User ID or Password.'], 200);
    }

    if (merd_employee_needs_hash_upgrade($employee)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upgrade = $pdo->prepare('UPDATE employees SET login_password=?,pin_code=? WHERE id=?');
        $upgrade->execute([$hash, $hash, (int)$employee['id']]);
    }
    $lockout->recordSuccess(PORTAL_CLIENT_ID, (int)$employee['id'], $userId, $fingerprint, 'portal_login', $now);

    $actualRole = strtoupper(trim((string)$employee['employee_type']));
    if ($actualRole === '') $actualRole = 'USER';

    $roleRow = null;
    if (!empty($employee['client_role_id'])) {
        $roleStmt = $pdo->prepare(
            "SELECT id,role_key,role_label,base_role,authority_level,status FROM client_roles WHERE client_id=? AND id=? LIMIT 1"
        );
        $roleStmt->execute([(int)$employee['client_id'], (int)$employee['client_role_id']]);
        $candidate = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($candidate) && strtolower((string)$candidate['status']) === 'active') $roleRow = $candidate;
    }
    if (!is_array($roleRow)) {
        $roleStmt = $pdo->prepare(
            "SELECT id,role_key,role_label,base_role,authority_level,status FROM client_roles WHERE client_id=? AND role_key=? AND status='active' LIMIT 1"
        );
        $roleStmt->execute([(int)$employee['client_id'], $actualRole]);
        $candidate = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($candidate)) $roleRow = $candidate;
    }

    if (is_array($roleRow)) {
        $actualRole = strtoupper((string)$roleRow['base_role']);
        if ((int)($employee['client_role_id'] ?? 0) !== (int)$roleRow['id'] || (string)$employee['role_name'] !== (string)$roleRow['role_label'] || strtoupper((string)$employee['employee_type']) !== $actualRole) {
            $syncRole = $pdo->prepare('UPDATE employees SET client_role_id=?,role_name=?,employee_type=? WHERE id=?');
            $syncRole->execute([(int)$roleRow['id'], (string)$roleRow['role_label'], $actualRole, (int)$employee['id']]);
        }
    }

    $managementRoles = ['ADMIN', 'SUPER', 'DEV'];
    $isManagement = in_array($actualRole, $managementRoles, true);
    $user = [
        'id' => (int)$employee['id'],
        'client_id' => (int)$employee['client_id'],
        'store_id' => $employee['store_id'] === null ? null : (int)$employee['store_id'],
        'name' => (string)$employee['full_name'],
        'full_name' => (string)$employee['full_name'],
        'user_id' => (string)$employee['user_id'],
        'role' => $actualRole,
        'actual_employee_type' => $actualRole,
        'employee_type' => $isManagement ? 'SUPER' : $actualRole,
        'role_name' => is_array($roleRow) ? (string)$roleRow['role_label'] : (string)$employee['role_name'],
        'client_role_id' => is_array($roleRow) ? (int)$roleRow['id'] : null,
        'role_key' => is_array($roleRow) ? (string)$roleRow['role_key'] : $actualRole,
        'role_label' => is_array($roleRow) ? (string)$roleRow['role_label'] : (string)$employee['role_name'],
        'authority_level' => is_array($roleRow) ? (int)$roleRow['authority_level'] : ($actualRole === 'DEV' ? 1000 : 0),
        'is_super' => $isManagement,
        'is_management' => $isManagement,
        'is_admin' => $actualRole === 'ADMIN',
        'is_dev' => $actualRole === 'DEV',
    ];

    login_user($user);

    if ($actualRole === 'DEV') {
        try {
            $pref = $pdo->prepare(
                'SELECT p.selected_client_id FROM dev_client_preferences p INNER JOIN clients c ON c.id=p.selected_client_id '
                . 'WHERE p.employee_id=? AND p.auth_client_id=? LIMIT 1'
            );
            $pref->execute([(int)$employee['id'], (int)$employee['client_id']]);
            $selectedClientId = (int)($pref->fetchColumn() ?: 0);
            if ($selectedClientId > 0) {
                set_dev_active_client_id($selectedClientId);
                $user['active_client_id'] = $selectedClientId;
            }
        } catch (Throwable $preferenceError) {
            error_log('MERDPOS DEV client preference restore failed: ' . get_class($preferenceError));
        }
    }

    start_app_session();
    $next = isset($_SESSION['pending_qr']) ? 'scan.php' : 'dashboard.php';
    json_response(['success' => true, 'user' => $user, 'next' => $next]);
} catch (MerdAuthLocked $e) {
    json_response(['success' => false, 'error' => 'Too many attempts. Try again later.'], 429);
} catch (MerdSecurityControlUnavailable $e) {
    json_response(['success' => false, 'error' => 'Login security is temporarily unavailable.'], 503);
} catch (Throwable $e) {
    error_log('MERDPOS portal login failed: ' . get_class($e));
    json_response(['success' => false, 'error' => 'Login is temporarily unavailable.'], 500);
}
