<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../../backend/api/includes/employee_auth.php';
require_once __DIR__ . '/../../backend/api/includes/auth_lockout.php';

// Direct browser visit check. This stops the confusing "POST required" screen.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'error' => 'Login endpoint is working. Use the login form; do not open api/login.php directly.'
    ], 200);
}

// Accept normal FormData, JSON, and raw urlencoded payloads.
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
        // Return HTTP 200 for wrong password so browser console does not show a scary 401 resource error.
        json_response([
            'success' => false,
            'error' => 'Invalid User ID or Password.'
        ], 200);
    }
    if (merd_employee_needs_hash_upgrade($employee)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upgrade = $pdo->prepare('UPDATE employees SET login_password=?,pin_code=? WHERE id=?');
        $upgrade->execute([$hash, $hash, (int)$employee['id']]);
    }
    $lockout->recordSuccess(PORTAL_CLIENT_ID, (int)$employee['id'], $userId, $fingerprint, 'portal_login', $now);
    $isSuper = strtoupper((string)$employee['employee_type']) === 'SUPER';
    $user = [
        'id' => (int)$employee['id'], 'client_id' => (int)$employee['client_id'],
        'store_id' => $employee['store_id'] === null ? null : (int)$employee['store_id'],
        'name' => (string)$employee['full_name'], 'full_name' => (string)$employee['full_name'],
        'user_id' => (string)$employee['user_id'], 'role' => (string)$employee['employee_type'],
        'employee_type' => (string)$employee['employee_type'], 'role_name' => (string)$employee['role_name'],
        'is_super' => $isSuper,
    ];
    login_user($user);
    start_app_session();
    $next = isset($_SESSION['pending_qr']) ? 'scan.php' : 'dashboard.php';
    json_response(['success' => true, 'user' => $user, 'next' => $next]);
} catch (MerdAuthLocked $e) {
    json_response(['success' => false, 'error' => 'Too many attempts. Try again later.'], 429);
} catch (MerdSecurityControlUnavailable $e) {
    json_response(['success' => false, 'error' => 'Login security is temporarily unavailable.'], 503);
} catch (Throwable $e) {
    error_log('portal login failed: ' . get_class($e));
    json_response(['success' => false, 'error' => 'Login is temporarily unavailable.'], 500);
}
