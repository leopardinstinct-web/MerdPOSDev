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

function portal_shop_actor_key(array $user): string
{
    $scope = trim((string)($user['identity_scope'] ?? 'employee')) ?: 'employee';
    $id = trim((string)($user['user_id'] ?? $user['id'] ?? ''));
    return substr($scope . ':' . $id, 0, 191);
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success' => false, 'error' => 'POST required.'], 405);
    $user = beta_require_active_user();
    $input = request_input();
    require_csrf($input);
    $token = trim((string)($input['token'] ?? ''));
    if ($token === '') throw new MerdWorkforceException('missing_qr', 'Scan the current POS QR.');

    $pdo = portal_db();
    $roleKey = strtoupper((string)($user['role_key'] ?? $user['employee_type'] ?? $user['role'] ?? 'USER'));
    $attendanceRole = $roleKey === 'USER' && !beta_actual_user_is_dev($user);
    $clientId = $attendanceRole
        ? (int)($user['auth_client_id'] ?? $user['client_id'])
        : (int)$user['client_id'];
    $qr = merd_verify_attendance_qr($pdo, $clientId, $token);

    if ($attendanceRole) {
        beta_require_permission($user, 'attendance.scan', $pdo);
        $employeeId = (int)$user['id'];
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
        if (strtoupper((string)($result['action'] ?? '')) === 'IN') {
            $_SESSION['shop_context'] = [
                'client_id'=>$clientId,'store_id'=>(int)$qr['store_id'],'device_id'=>(int)$qr['device_id'],
                'store_name'=>(string)$qr['store_name'],'logged_in_at'=>(string)($result['occurred_at'] ?? gmdate('Y-m-d H:i:s')),
                'mode'=>'attendance',
            ];
            $shopActive = true;
        } else {
            unset($_SESSION['shop_context']);
            $shopActive = false;
        }
        unset($_SESSION['pending_qr']);
        $result['attendance'] = true;
        $result['shop_active'] = $shopActive;
        $result['device_code'] = (string)($qr['device_code'] ?? '');
        json_response(['success' => true, 'result' => $result]);
    }

    beta_require_permission($user, 'finance.view', $pdo);
    start_app_session();
    $actorKey = portal_shop_actor_key($user);
    $duplicate = $pdo->prepare(
        'SELECT action,used_at FROM shop_qr_uses WHERE token_hash=? AND actor_key=? LIMIT 1'
    );
    $duplicate->execute([$qr['token_hash'],$actorKey]);
    $prior = $duplicate->fetch(PDO::FETCH_ASSOC);
    if (is_array($prior)) {
        $context = beta_shop_context($pdo,$user);
        json_response(['success'=>true,'result'=>[
            'duplicate'=>true,'action'=>(string)$prior['action'],'attendance'=>false,
            'shop_active'=>is_array($context),'store_name'=>(string)$qr['store_name'],
            'client_id'=>$clientId,'store_id'=>(int)$qr['store_id'],'device_id'=>(int)$qr['device_id'],
            'occurred_at'=>(string)$prior['used_at'],'device_code'=>(string)($qr['device_code'] ?? ''),
        ]]);
    }
    $current = beta_shop_context($pdo,$user);
    if (is_array($current) && (int)($current['store_id'] ?? 0) !== (int)$qr['store_id']) {
        throw new MerdWorkforceException('shop_logout_required', 'Log out of the current shop before logging in to another store.');
    }
    $sameStore = is_array($current) && (int)($current['store_id'] ?? 0) === (int)$qr['store_id'];
    $action = $sameStore ? 'OUT' : 'IN';
    $stamp = gmdate('Y-m-d H:i:s');
    if ($action === 'OUT') {
        unset($_SESSION['shop_context']);
    } else {
        $_SESSION['shop_context'] = [
            'client_id'=>$clientId,'store_id'=>(int)$qr['store_id'],'device_id'=>(int)$qr['device_id'],
            'store_name'=>(string)$qr['store_name'],'logged_in_at'=>$stamp,'mode'=>'finance',
        ];
    }

    $use = $pdo->prepare(
        'INSERT INTO shop_qr_uses (token_hash,client_id,actor_key,device_id,store_id,action,used_at) VALUES (?,?,?,?,?,?,?)'
    );
    $use->execute([$qr['token_hash'],$clientId,$actorKey,(int)$qr['device_id'],(int)$qr['store_id'],$action,$stamp]);
    unset($_SESSION['pending_qr']);
    json_response(['success'=>true,'result'=>[
        'duplicate'=>false,'action'=>$action,'attendance'=>false,'shop_active'=>$action === 'IN',
        'store_name'=>(string)$qr['store_name'],'client_id'=>$clientId,
        'store_id'=>(int)$qr['store_id'],'device_id'=>(int)$qr['device_id'],'occurred_at'=>$stamp,
        'device_code'=>(string)($qr['device_code'] ?? ''),
    ]]);
} catch (Throwable $e) { beta_api_error($e); }
