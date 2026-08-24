<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

function portal_employee_can_start_at_store(PDO $pdo, int $clientId, int $employeeId, int $storeId): bool
{
    $stmt = $pdo->prepare('SELECT access_mode FROM employee_store_access WHERE client_id=? AND employee_id=? LIMIT 1');
    $stmt->execute([$clientId, $employeeId]);
    $mode = strtolower((string)($stmt->fetchColumn() ?: 'all'));
    if ($mode !== 'selected') return true;

    $allowed = $pdo->prepare(
        'SELECT 1 FROM employee_store_assignments WHERE client_id=? AND employee_id=? AND store_id=? LIMIT 1'
    );
    $allowed->execute([$clientId, $employeeId, $storeId]);
    return (bool)$allowed->fetchColumn();
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success' => false, 'error' => 'POST required.'], 405);
    $user = beta_require_active_user();
    $input = request_input();
    require_csrf($input);
    $token = trim((string)($input['token'] ?? ''));
    if ($token === '') throw new MerdWorkforceException('missing_qr', 'Scan the current POS QR.');

    $pdo = portal_db();
    // A DEV client switch changes management data only. Personal attendance
    // always remains on the tenant that owns the authenticated employee account.
    $clientId = (int)($user['auth_client_id'] ?? $user['client_id']);
    $employeeId = (int)$user['id'];
    $qr = merd_verify_attendance_qr($pdo, $clientId, $token);

    $openStmt = $pdo->prepare(
        "SELECT store_id FROM attendance_shifts WHERE client_id=? AND employee_id=? AND status='open' LIMIT 1"
    );
    $openStmt->execute([$clientId, $employeeId]);
    $openStoreId = $openStmt->fetchColumn();
    if ($openStoreId === false && !portal_employee_can_start_at_store($pdo, $clientId, $employeeId, (int)$qr['store_id'])) {
        throw new MerdWorkforceException('store_not_allowed', 'You are not assigned to this store. Contact a manager if you need access.');
    }

    $attendanceUser = $user;
    $attendanceUser['client_id'] = $clientId;
    $result = merd_attendance_scan($pdo, $attendanceUser, $token);
    start_app_session();
    unset($_SESSION['pending_qr']);
    json_response(['success' => true, 'result' => $result]);
} catch (Throwable $e) { beta_api_error($e); }
