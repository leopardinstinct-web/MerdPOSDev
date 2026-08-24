<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $isManagement = !empty($user['is_super']);
    $actualRole = strtoupper((string)($user['role'] ?? $user['actual_employee_type'] ?? $user['employee_type'] ?? 'USER'));

    $stores = $pdo->prepare("SELECT id,store_name FROM stores WHERE client_id=? AND status='active' ORDER BY id");
    $stores->execute([(int)$user['client_id']]);

    $shiftWhere = $isManagement ? 's.client_id=?' : 's.client_id=? AND s.employee_id=?';
    $shiftArgs = $isManagement ? [(int)$user['client_id']] : [(int)$user['client_id'], (int)$user['id']];
    $shifts = $pdo->prepare(
        'SELECT s.public_id AS shift_id,e.full_name,e.user_id,st.store_name,s.clock_in_at,s.clock_out_at,s.status '
        . 'FROM attendance_shifts s INNER JOIN employees e ON e.id=s.employee_id INNER JOIN stores st ON st.id=s.store_id '
        . 'WHERE ' . $shiftWhere . ' ORDER BY s.clock_in_at DESC LIMIT 100'
    );
    $shifts->execute($shiftArgs);

    $working = merd_working_now($pdo, (int)$user['client_id']);
    if (!$isManagement) {
        $working = array_values(array_filter($working, fn(array $row): bool => (string)$row['user_id'] === (string)$user['user_id']));
    }

    $management = null;
    if ($isManagement) {
        $businessDate = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d');

        $financial = $pdo->prepare(
            "SELECT st.id AS store_id,st.store_name,"
            . "COALESCE(SUM(CASE WHEN f.account='Register' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS register_balance,"
            . "COALESCE(SUM(CASE WHEN f.account='Petty Cash' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS petty_balance "
            . "FROM stores st LEFT JOIN financial_day_accounts f ON f.store_id=st.id AND f.client_id=st.client_id AND f.business_date=? "
            . "WHERE st.client_id=? AND st.status='active' GROUP BY st.id,st.store_name ORDER BY st.id"
        );
        $financial->execute([$businessDate, (int)$user['client_id']]);

        $sales = $pdo->prepare(
            "SELECT st.id AS store_id,st.store_name,COALESCE(SUM(rs.total),0) AS today_sales "
            . "FROM stores st LEFT JOIN retail_sales rs ON rs.store_id=st.id AND rs.client_id=st.client_id AND DATE(rs.sold_at)=? AND rs.status='completed' "
            . "WHERE st.client_id=? AND st.status='active' GROUP BY st.id,st.store_name ORDER BY st.id"
        );
        $sales->execute([$businessDate, (int)$user['client_id']]);

        $outbox = $pdo->prepare("SELECT COUNT(*) FROM google_sheet_outbox WHERE client_id=? AND status IN ('pending','processing','failed')");
        $outbox->execute([(int)$user['client_id']]);

        $employeeCount = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE client_id=? AND status='active'");
        $employeeCount->execute([(int)$user['client_id']]);

        $management = [
            'business_date' => $businessDate,
            'active_employees' => (int)$employeeCount->fetchColumn(),
            'financial_by_store' => $financial->fetchAll(PDO::FETCH_ASSOC),
            'sales_by_store' => $sales->fetchAll(PDO::FETCH_ASSOC),
            'sync_attention' => (int)$outbox->fetchColumn(),
        ];
    }

    json_response([
        'success' => true,
        'csrf' => csrf_token(),
        'is_super' => $isManagement,
        'is_management' => $isManagement,
        'role' => $actualRole,
        'is_dev' => $actualRole === 'DEV',
        'is_admin' => $actualRole === 'ADMIN',
        'current_user_id' => (string)$user['user_id'],
        'working' => $working,
        'disputes' => merd_list_disputes($pdo, $user),
        'attendance_flags' => merd_list_attendance_flags($pdo, $user),
        'recent_shifts' => $shifts->fetchAll(PDO::FETCH_ASSOC),
        'stores' => $stores->fetchAll(PDO::FETCH_ASSOC),
        'management' => $management,
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
