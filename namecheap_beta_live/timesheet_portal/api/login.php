<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../../backend/api/includes/employee_auth.php';
require_once __DIR__ . '/../../backend/api/includes/auth_lockout.php';
require_once __DIR__ . '/../../backend/api/includes/platform_identity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success'=>false,'error'=>'Login endpoint is working. Use the MERDPOS login form.'], 200);
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
    json_response(['success'=>false,'error'=>'Enter numeric User ID and Password.'], 200);
}

try {
    $pdo = portal_db();
    $fingerprint = 'portal-' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $lockout = new MerdAuthLockoutService(new MerdPdoAuthLockoutStore($pdo));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lockout->assertNotLocked(PORTAL_CLIENT_ID, $userId, $fingerprint, 'portal_login', $now);

    // Platform identities have namespace priority. A DEV User ID can never
    // fall through and authenticate as a client employee with the same ID.
    $platform = merd_platform_identity_by_user_id($pdo, $userId);
    if (is_array($platform)) {
        if (!merd_platform_identity_is_active_dev($platform) || !merd_employee_authenticates($platform, $password)) {
            $lockout->recordFailure(PORTAL_CLIENT_ID, null, $userId, $fingerprint, 'portal_login', $now);
            json_response(['success'=>false,'error'=>'Invalid User ID or Password.'], 200);
        }
        if (merd_employee_needs_hash_upgrade($platform)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE platform_identities SET login_password=?,pin_code=? WHERE id=?')->execute([$hash,$hash,(int)$platform['id']]);
        }
        $lockout->recordSuccess(PORTAL_CLIENT_ID, null, $userId, $fingerprint, 'portal_login', $now);
        $selectedClientId = merd_platform_identity_selected_client($pdo, (int)$platform['id']);
        if ($selectedClientId === null || $selectedClientId <= 0) {
            throw new RuntimeException('Platform DEV identity has no active Working Client.');
        }
        $user = [
            'id'=>(int)$platform['id'],
            'platform_identity_id'=>(int)$platform['id'],
            'identity_scope'=>'platform',
            'client_id'=>$selectedClientId,
            'active_client_id'=>$selectedClientId,
            'auth_client_id'=>0,
            'home_client_id'=>0,
            'store_id'=>null,
            'name'=>(string)$platform['full_name'],
            'full_name'=>(string)$platform['full_name'],
            'user_id'=>(string)$platform['user_id'],
            'role'=>'DEV',
            'actual_employee_type'=>'DEV',
            'employee_type'=>'DEV',
            'role_name'=>'Developer',
            'client_role_id'=>null,
            'role_key'=>'DEV',
            'role_label'=>'Developer',
            'authority_level'=>1000,
            'is_super'=>true,
            'is_management'=>true,
            'is_admin'=>false,
            'is_dev'=>true,
        ];
        login_user($user);
        set_dev_active_client_id($selectedClientId);
        start_app_session();
        $next = isset($_SESSION['pending_qr']) ? 'scan.php' : 'dashboard.php';
        json_response(['success'=>true,'user'=>$user,'next'=>$next]);
    }

    $stmt = $pdo->prepare(
        "SELECT id,client_id,store_id,full_name,user_id,login_password,pin_code,employee_type,role_name,client_role_id,status "
        . "FROM employees WHERE user_id=? AND status='active' AND UPPER(TRIM(employee_type))<>'DEV' ORDER BY client_id,id LIMIT 21"
    );
    $stmt->execute([$userId]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($candidates) > 20) throw new RuntimeException('Ambiguous MERDPOS login identity.');

    $matched = [];
    foreach ($candidates as $candidate) {
        if (is_array($candidate) && merd_employee_authenticates($candidate, $password)) $matched[] = $candidate;
    }
    if (count($matched) !== 1) {
        $lockout->recordFailure(PORTAL_CLIENT_ID, null, $userId, $fingerprint, 'portal_login', $now);
        json_response(['success'=>false,'error'=>'Invalid User ID or Password.'], 200);
    }
    $employee = $matched[0];
    $authClientId = (int)$employee['client_id'];
    if ($authClientId !== PORTAL_CLIENT_ID) $lockout->assertNotLocked($authClientId, $userId, $fingerprint, 'portal_login', $now);

    if (merd_employee_needs_hash_upgrade($employee)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE employees SET login_password=?,pin_code=? WHERE id=? AND client_id=?')
            ->execute([$hash,$hash,(int)$employee['id'],$authClientId]);
    }
    $lockout->recordSuccess(PORTAL_CLIENT_ID, $authClientId === PORTAL_CLIENT_ID ? (int)$employee['id'] : null, $userId, $fingerprint, 'portal_login', $now);
    if ($authClientId !== PORTAL_CLIENT_ID) $lockout->recordSuccess($authClientId, (int)$employee['id'], $userId, $fingerprint, 'portal_login', $now);

    $actualRole = strtoupper(trim((string)$employee['employee_type']));
    if (!in_array($actualRole, ['USER','ADMIN','SUPER'], true)) $actualRole = 'USER';
    $roleRow = null;
    if (!empty($employee['client_role_id'])) {
        $roleStmt = $pdo->prepare("SELECT id,role_key,role_label,base_role,authority_level,status FROM client_roles WHERE client_id=? AND id=? LIMIT 1");
        $roleStmt->execute([$authClientId,(int)$employee['client_role_id']]);
        $candidate = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($candidate) && strtolower((string)$candidate['status']) === 'active' && strtoupper((string)$candidate['role_key']) !== 'DEV') $roleRow = $candidate;
    }
    if (!is_array($roleRow)) {
        $roleStmt = $pdo->prepare("SELECT id,role_key,role_label,base_role,authority_level,status FROM client_roles WHERE client_id=? AND role_key=? AND status='active' LIMIT 1");
        $roleStmt->execute([$authClientId,$actualRole]);
        $candidate = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($candidate)) $roleRow = $candidate;
    }
    if (is_array($roleRow)) {
        $actualRole = strtoupper((string)$roleRow['base_role']);
        if (!in_array($actualRole, ['USER','ADMIN','SUPER'], true)) $actualRole = 'USER';
        if ((int)($employee['client_role_id'] ?? 0) !== (int)$roleRow['id'] || (string)$employee['role_name'] !== (string)$roleRow['role_label'] || strtoupper((string)$employee['employee_type']) !== $actualRole) {
            $pdo->prepare('UPDATE employees SET client_role_id=?,role_name=?,employee_type=? WHERE id=?')
                ->execute([(int)$roleRow['id'],(string)$roleRow['role_label'],$actualRole,(int)$employee['id']]);
        }
    }

    $isManagement = in_array($actualRole, ['ADMIN','SUPER'], true);
    $user = [
        'id'=>(int)$employee['id'],'identity_scope'=>'employee','client_id'=>$authClientId,
        'store_id'=>$employee['store_id'] === null ? null : (int)$employee['store_id'],
        'name'=>(string)$employee['full_name'],'full_name'=>(string)$employee['full_name'],'user_id'=>(string)$employee['user_id'],
        'role'=>$actualRole,'actual_employee_type'=>$actualRole,'employee_type'=>$actualRole,
        'role_name'=>is_array($roleRow) ? (string)$roleRow['role_label'] : (string)$employee['role_name'],
        'client_role_id'=>is_array($roleRow) ? (int)$roleRow['id'] : null,
        'role_key'=>is_array($roleRow) ? (string)$roleRow['role_key'] : $actualRole,
        'role_label'=>is_array($roleRow) ? (string)$roleRow['role_label'] : (string)$employee['role_name'],
        'authority_level'=>is_array($roleRow) ? (int)$roleRow['authority_level'] : ($actualRole === 'SUPER' ? 90 : ($actualRole === 'ADMIN' ? 50 : 10)),
        'is_super'=>$actualRole === 'SUPER','is_management'=>$isManagement,'is_admin'=>$actualRole === 'ADMIN','is_dev'=>false,
    ];
    login_user($user);
    start_app_session();
    $next = isset($_SESSION['pending_qr']) ? 'scan.php' : 'dashboard.php';
    json_response(['success'=>true,'user'=>$user,'next'=>$next]);
} catch (MerdAuthLocked) {
    json_response(['success'=>false,'error'=>'Too many attempts. Try again later.'], 429);
} catch (MerdSecurityControlUnavailable) {
    json_response(['success'=>false,'error'=>'Login security is temporarily unavailable.'], 503);
} catch (Throwable $e) {
    error_log('MERDPOS portal login failed: ' . get_class($e));
    json_response(['success'=>false,'error'=>'Login is temporarily unavailable.'], 500);
}
