<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../../backend/api/includes/employee_auth.php';
require_once __DIR__ . '/../../backend/api/includes/auth_lockout.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'error' => 'Login endpoint is working. Use the MERDPOS login form.'
    ], 200);
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
        "SELECT id,client_id,store_id,full_name,user_id,login_password,pin_code,employee_type,role_name,status "
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
        // Compatibility shim: existing workforce approval code treats SUPER as the
        // management permission flag. ADMIN and DEV therefore enter that path too,
        // while role/actual_employee_type preserve the real role for the UI.
        'employee_type' => $isManagement ? 'SUPER' : $actualRole,
        'role_name' => (string)$employee['role_name'],
        'is_super' => $isManagement,
        'is_management' => $isManagement,
        'is_admin' => $actualRole === 'ADMIN',
        'is_dev' => $actualRole === 'DEV',
    ];

    login_user($user);

    // DEV working-client context is a preference, not the authentication tenant.
    // Restore the last valid selection after the authenticated session identity
    // has been established. Non-DEV roles always remain on their home client.
    if ($actualRole === 'DEV') {
        try {
            $pref = $pdo->prepare(
                'SELECT p.selected_client_id FROM dev_client_preferences p '
                . 'INNER JOIN clients c ON c.id=p.selected_client_id '
                . 'WHERE p.employee_id=? AND p.auth_client_id=? LIMIT 1'
            );
            $pref->execute([(int)$employee['id'], (int)$employee['client_id']]);
            $selectedClientId = (int)($pref->fetchColumn() ?: 0);
            if ($selectedClientId > 0) {
                set_dev_active_client_id($selectedClientId);
                $user['active_client_id'] = $selectedClientId;
            }
        } catch (Throwable $preferenceError) {
            // Login must remain available if a preference row is unavailable.
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
