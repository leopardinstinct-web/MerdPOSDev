<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/dashboard_access.php';

function dashboard_data_is_dev(array $user): bool
{
    return beta_user_is_dev($user);
}

function dashboard_data_role(PDO $pdo, array $user): array
{
    $clientId = (int)$user['client_id'];
    if (dashboard_data_is_dev($user) && isset($_GET['role_id']) && $_GET['role_id'] !== '') {
        beta_require_permission($user, 'dashboard.configure', $pdo);
        $roleId = filter_var($_GET['role_id'], FILTER_VALIDATE_INT);
        if ($roleId === false || $roleId <= 0) throw new MerdWorkforceException('invalid_role', 'Choose a valid dashboard role.');
        $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
        if (!$role || strtolower((string)$role['status']) !== 'active') throw new MerdWorkforceException('role_not_found', 'Dashboard role not found.');
        return $role;
    }
    return merd_dashboard_user_role($pdo, $user);
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $clientId = (int)$user['client_id'];
    $role = dashboard_data_role($pdo, $user);
    $allowed = merd_dashboard_allowed_widgets($pdo, $clientId, $role);
    $allowedMap = array_fill_keys($allowed, true);

    // Data permissions are evaluated for the selected dashboard role itself.
    // DEV preview therefore receives only the data that the previewed role is
    // entitled to, not the broader data available to the DEV actor.
    $roleCanWorkforce = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'workforce.view');
    $roleCanReviewDisputes = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'disputes.review');
    $roleCanViewAllTimesheets = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'timesheets.view_all');
    $roleCanFinanceSummary = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'finance.management_summary');
    $roleCanSync = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'system.sync_status');

    $clientDefaults = $pdo->prepare('SELECT default_currency,default_timezone FROM clients WHERE id=? LIMIT 1');
    $clientDefaults->execute([$clientId]);
    $defaults = $clientDefaults->fetch(PDO::FETCH_ASSOC) ?: ['default_currency'=>'AUD','default_timezone'=>'Australia/Sydney'];
    $currency = strtoupper((string)($defaults['default_currency'] ?: 'AUD'));
    $timezone = (string)($defaults['default_timezone'] ?: 'Australia/Sydney');
    try { new DateTimeZone($timezone); } catch (Throwable) { $timezone = 'Australia/Sydney'; }

    $working = merd_working_now($pdo, $clientId);
    if (!$roleCanWorkforce) {
        $working = array_values(array_filter($working, fn(array $row): bool => (string)($row['user_id'] ?? '') === (string)$user['user_id']));
    }

    $dataUser = $user;
    $dataUser['employee_type'] = $roleCanReviewDisputes ? 'SUPER' : 'USER';
    $dataUser['role_name'] = $roleCanReviewDisputes ? 'SUPER' : 'USER';
    $disputes = merd_list_disputes($pdo, $dataUser);

    $allRecent = $roleCanViewAllTimesheets || $roleCanWorkforce;
    $shiftWhere = $allRecent ? 's.client_id=?' : 's.client_id=? AND s.employee_id=?';
    $shiftArgs = $allRecent ? [$clientId] : [$clientId, (int)$user['id']];
    $shifts = $pdo->prepare(
        'SELECT s.public_id AS shift_id,e.full_name,e.user_id,st.store_name,s.clock_in_at,s.clock_out_at,s.status,'
        . 'COALESCE(st.timezone,?) AS timezone '
        . 'FROM attendance_shifts s INNER JOIN employees e ON e.id=s.employee_id INNER JOIN stores st ON st.id=s.store_id '
        . 'WHERE ' . $shiftWhere . ' ORDER BY s.clock_in_at DESC LIMIT 100'
    );
    $shifts->execute(array_merge([$timezone], $shiftArgs));

    $stores = [];
    $management = null;
    if ($roleCanWorkforce || $roleCanFinanceSummary || $roleCanSync) {
        $storeStmt = $pdo->prepare(
            "SELECT id,store_name,COALESCE(currency_code,?) AS currency_code,COALESCE(timezone,?) AS timezone "
            . "FROM stores WHERE client_id=? AND status='active' ORDER BY id ASC"
        );
        $storeStmt->execute([$currency,$timezone,$clientId]);
        $stores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);

        $businessDate = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
        $financialRows = [];
        if ($roleCanFinanceSummary && (isset($allowedMap['store_cash_position']) || isset($allowedMap['cash_mix']))) {
            $financial = $pdo->prepare(
                "SELECT st.id AS store_id,st.store_name,COALESCE(st.currency_code,?) AS currency_code,COALESCE(st.timezone,?) AS timezone,"
                . "COALESCE(SUM(CASE WHEN f.account='Register' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS register_balance,"
                . "COALESCE(SUM(CASE WHEN f.account='Petty Cash' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS petty_balance "
                . "FROM stores st LEFT JOIN financial_day_accounts f ON f.store_id=st.id AND f.client_id=st.client_id AND f.business_date=? "
                . "WHERE st.client_id=? AND st.status='active' GROUP BY st.id,st.store_name,st.currency_code,st.timezone ORDER BY st.id"
            );
            $financial->execute([$currency,$timezone,$businessDate,$clientId]);
            $financialRows = $financial->fetchAll(PDO::FETCH_ASSOC);
        }

        $salesRows = [];
        if ($roleCanFinanceSummary && isset($allowedMap['today_sales_by_store'])) {
            $sales = $pdo->prepare(
                "SELECT st.id AS store_id,st.store_name,COALESCE(st.currency_code,?) AS currency_code,COALESCE(SUM(rs.total),0) AS today_sales "
                . "FROM stores st LEFT JOIN retail_sales rs ON rs.store_id=st.id AND rs.client_id=st.client_id AND DATE(rs.sold_at)=? AND rs.status='completed' "
                . "WHERE st.client_id=? AND st.status='active' GROUP BY st.id,st.store_name,st.currency_code ORDER BY st.id"
            );
            $sales->execute([$currency,$businessDate,$clientId]);
            $salesRows = $sales->fetchAll(PDO::FETCH_ASSOC);
        }

        $activeEmployees = null;
        if ($roleCanWorkforce && isset($allowedMap['active_employees'])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE client_id=? AND status='active'");
            $stmt->execute([$clientId]);
            $activeEmployees = (int)$stmt->fetchColumn();
        }

        $syncAttention = null;
        if ($roleCanSync && isset($allowedMap['sync_attention'])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM google_sheet_outbox WHERE client_id=? AND status IN ('pending','processing','failed')");
            $stmt->execute([$clientId]);
            $syncAttention = (int)$stmt->fetchColumn();
        }

        $management = [
            'business_date'=>$businessDate,
            'currency_code'=>$currency,
            'timezone'=>$timezone,
            'active_employees'=>$activeEmployees,
            'sync_attention'=>$syncAttention,
            'financial_by_store'=>$financialRows,
            'sales_by_store'=>$salesRows,
        ];
    }

    json_response([
        'success'=>true,
        'role'=>[
            'id'=>(int)$role['id'],
            'role_key'=>(string)$role['role_key'],
            'role_label'=>(string)$role['role_label'],
            'base_role'=>(string)$role['base_role'],
            'authority_level'=>(int)$role['authority_level'],
        ],
        'allowed_widgets'=>$allowed,
        'client_defaults'=>['currency_code'=>$currency,'timezone'=>$timezone],
        'working'=>$working,
        'disputes'=>$disputes,
        'recent_shifts'=>$shifts->fetchAll(PDO::FETCH_ASSOC),
        'stores'=>$stores,
        'management'=>$management,
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
