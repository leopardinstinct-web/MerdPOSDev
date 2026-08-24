<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $stores = $pdo->prepare("SELECT id,store_name FROM stores WHERE client_id=? AND status='active' ORDER BY store_name");
    $stores->execute([(int)$user['client_id']]);
    $shiftWhere = $user['is_super'] ? 's.client_id=?' : 's.client_id=? AND s.employee_id=?';
    $shiftArgs = $user['is_super'] ? [(int)$user['client_id']] : [(int)$user['client_id'], (int)$user['id']];
    $shifts = $pdo->prepare(
        'SELECT s.public_id AS shift_id,e.full_name,e.user_id,st.store_name,s.clock_in_at,s.clock_out_at,s.status '
        . 'FROM attendance_shifts s INNER JOIN employees e ON e.id=s.employee_id INNER JOIN stores st ON st.id=s.store_id '
        . 'WHERE ' . $shiftWhere . ' ORDER BY s.clock_in_at DESC LIMIT 100'
    );
    $shifts->execute($shiftArgs);
    json_response(['success' => true, 'csrf' => csrf_token(), 'is_super' => (bool)$user['is_super'],
        'current_user_id' => (string)$user['user_id'],
        'working' => merd_working_now($pdo, (int)$user['client_id']),
        'disputes' => merd_list_disputes($pdo, $user), 'attendance_flags' => merd_list_attendance_flags($pdo,$user), 'recent_shifts' => $shifts->fetchAll(PDO::FETCH_ASSOC),
        'stores' => $stores->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) { beta_api_error($e); }
