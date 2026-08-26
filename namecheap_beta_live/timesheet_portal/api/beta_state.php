<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $actualRole = strtoupper((string)($user['role'] ?? $user['actual_employee_type'] ?? 'USER'));
    $canDashboard = beta_has_permission($user, 'dashboard.view', $pdo);
    $canViewWorkforce = beta_has_permission($user, 'workforce.view', $pdo);
    $canViewStores = beta_has_permission($user, 'stores.view', $pdo);
    $canViewOwnTimesheets = beta_has_permission($user, 'timesheets.view_own', $pdo);
    $canViewAllTimesheets = beta_has_permission($user, 'timesheets.view_all', $pdo);
    $canViewOwnDisputes = beta_has_permission($user, 'disputes.view_own', $pdo);
    $canSubmitDisputes = beta_has_permission($user, 'disputes.submit_own', $pdo);
    $canReviewDisputes = beta_has_permission($user, 'disputes.review', $pdo);
    $canResolveFlags = beta_has_permission($user, 'attendance_flags.resolve', $pdo);
    $canFinanceView = beta_has_permission($user, 'finance.view', $pdo);
    $canFinanceCrossStore = beta_has_permission($user, 'finance.cross_store', $pdo);
    $canFinanceSummary = beta_has_permission($user, 'finance.management_summary', $pdo);
    $canSyncStatus = beta_has_permission($user, 'system.sync_status', $pdo);
    $isManagement = !empty($user['is_management']);

    $clientDefaults = $pdo->prepare('SELECT id,name,client_code,default_currency,default_timezone FROM clients WHERE id=? LIMIT 1');
    $clientDefaults->execute([(int)$user['client_id']]);
    $clientDefaultsRow = $clientDefaults->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => (int)$user['client_id'],
        'name' => 'MERDPOS',
        'client_code' => '',
        'default_currency' => 'AUD',
        'default_timezone' => 'Australia/Sydney',
    ];
    $clientCurrency = strtoupper((string)($clientDefaultsRow['default_currency'] ?: 'AUD'));
    $clientTimezone = (string)($clientDefaultsRow['default_timezone'] ?: 'Australia/Sydney');
    try { new DateTimeZone($clientTimezone); } catch (Throwable) { $clientTimezone = 'Australia/Sydney'; }

    // Canonical store identity is kept separate from active operational store
    // scope so an inactive store still retains the same name/code/logo wherever
    // historical or administrative UI displays it. Response scope is filtered
    // below so a shared state request does not bypass Stores/Finance permissions.
    $storeIdentityStmt = $pdo->prepare(
        'SELECT id,store_name,store_code,logo_path,status FROM stores WHERE client_id=? ORDER BY id'
    );
    $storeIdentityStmt->execute([(int)$user['client_id']]);
    $storeIdentityRows = $storeIdentityStmt->fetchAll(PDO::FETCH_ASSOC);

    $stores = $pdo->prepare(
        "SELECT id,store_name,store_code,logo_path,COALESCE(currency_code,?) AS currency_code,COALESCE(timezone,?) AS timezone "
        . "FROM stores WHERE client_id=? AND status='active' ORDER BY id"
    );
    $stores->execute([$clientCurrency, $clientTimezone, (int)$user['client_id']]);
    $storeRows = $stores->fetchAll(PDO::FETCH_ASSOC);

    $recentShifts = [];
    $canReadRecentShifts = $canViewOwnTimesheets || $canViewAllTimesheets || $canViewOwnDisputes;
    if ($canReadRecentShifts) {
        $allRecentShifts = $canViewWorkforce || $canViewAllTimesheets;
        $shiftWhere = $allRecentShifts ? 's.client_id=?' : 's.client_id=? AND s.employee_id=?';
        $shiftArgs = $allRecentShifts ? [(int)$user['client_id']] : [(int)$user['client_id'], (int)$user['id']];
        $shifts = $pdo->prepare(
            'SELECT s.public_id AS shift_id,e.full_name,e.user_id,st.store_name,s.clock_in_at,s.clock_out_at,s.status,'
            . 'COALESCE(st.timezone,?) AS timezone '
            . 'FROM attendance_shifts s INNER JOIN employees e ON e.id=s.employee_id INNER JOIN stores st ON st.id=s.store_id '
            . 'WHERE ' . $shiftWhere . ' ORDER BY s.clock_in_at DESC LIMIT 100'
        );
        $shifts->execute(array_merge([$clientTimezone], $shiftArgs));
        $recentShifts = $shifts->fetchAll(PDO::FETCH_ASSOC);
    }

    $working = [];
    if ($canViewWorkforce || $canViewOwnTimesheets || $canFinanceView) {
        $working = merd_working_now($pdo, (int)$user['client_id']);
        if (!$canViewWorkforce) {
            $working = array_values(array_filter($working, fn(array $row): bool => (string)$row['user_id'] === (string)$user['user_id']));
        }
    }

    // Store lists are feature-scoped. Full store enumeration is allowed only
    // where the user can already view workforce/store/cross-store finance data.
    // Finance-only users receive only the store(s) where they are actively
    // clocked in; dispute submitters retain active store names for new-shift
    // correction requests.
    $canEnumerateStores = $canViewStores || $canViewWorkforce || $canFinanceCrossStore || $canFinanceSummary;
    if (!$canEnumerateStores) {
        if ($canSubmitDisputes) {
            // New-shift disputes intentionally allow selection from active stores.
        } elseif ($canFinanceView) {
            $workingStoreNames = array_fill_keys(array_map(static fn(array $row): string => (string)$row['store_name'], $working), true);
            $storeRows = array_values(array_filter($storeRows, static fn(array $row): bool => isset($workingStoreNames[(string)$row['store_name']])));
            $storeIdentityRows = array_values(array_filter($storeIdentityRows, static fn(array $row): bool => isset($workingStoreNames[(string)$row['store_name']])));
        } else {
            $storeRows = [];
            $storeIdentityRows = [];
        }
    }

    $management = null;
    if ($canViewWorkforce || $canFinanceSummary || $canSyncStatus) {
        $businessDate = (new DateTimeImmutable('now', new DateTimeZone($clientTimezone)))->format('Y-m-d');
        $management = [
            'business_date' => $businessDate,
            'currency_code' => $clientCurrency,
            'timezone' => $clientTimezone,
            'active_employees' => null,
            'financial_by_store' => [],
            'sales_by_store' => [],
            'sync_attention' => null,
        ];

        if ($canViewWorkforce) {
            $employeeCount = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE client_id=? AND status='active'");
            $employeeCount->execute([(int)$user['client_id']]);
            $management['active_employees'] = (int)$employeeCount->fetchColumn();
        }

        if ($canFinanceSummary) {
            $financial = $pdo->prepare(
                "SELECT st.id AS store_id,st.store_name,COALESCE(st.currency_code,?) AS currency_code,COALESCE(st.timezone,?) AS timezone,"
                . "COALESCE(SUM(CASE WHEN f.account='Register' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS register_balance,"
                . "COALESCE(SUM(CASE WHEN f.account='Petty Cash' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS petty_balance "
                . "FROM stores st LEFT JOIN financial_day_accounts f ON f.store_id=st.id AND f.client_id=st.client_id AND f.business_date=? "
                . "WHERE st.client_id=? AND st.status='active' GROUP BY st.id,st.store_name,st.currency_code,st.timezone ORDER BY st.id"
            );
            $financial->execute([$clientCurrency, $clientTimezone, $businessDate, (int)$user['client_id']]);
            $management['financial_by_store'] = $financial->fetchAll(PDO::FETCH_ASSOC);

            $sales = $pdo->prepare(
                "SELECT st.id AS store_id,st.store_name,COALESCE(st.currency_code,?) AS currency_code,COALESCE(SUM(rs.total),0) AS today_sales "
                . "FROM stores st LEFT JOIN retail_sales rs ON rs.store_id=st.id AND rs.client_id=st.client_id AND DATE(rs.sold_at)=? AND rs.status='completed' "
                . "WHERE st.client_id=? AND st.status='active' GROUP BY st.id,st.store_name,st.currency_code ORDER BY st.id"
            );
            $sales->execute([$clientCurrency, $businessDate, (int)$user['client_id']]);
            $management['sales_by_store'] = $sales->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($canSyncStatus) {
            $outbox = $pdo->prepare("SELECT COUNT(*) FROM google_sheet_outbox WHERE client_id=? AND status IN ('pending','processing','failed')");
            $outbox->execute([(int)$user['client_id']]);
            $management['sync_attention'] = (int)$outbox->fetchColumn();
        }
    }

    $disputes = [];
    if ($canViewOwnDisputes || $canReviewDisputes) {
        $disputeUser = $user;
        $disputeUser['employee_type'] = $canReviewDisputes ? 'SUPER' : 'USER';
        $disputeUser['role_name'] = $canReviewDisputes ? 'SUPER' : 'USER';
        $disputes = merd_list_disputes($pdo, $disputeUser);
    }

    $attendanceFlags = [];
    if ($canResolveFlags) {
        $flagUser = $user;
        $flagUser['employee_type'] = 'SUPER';
        $flagUser['role_name'] = 'SUPER';
        $attendanceFlags = merd_list_attendance_flags($pdo, $flagUser);
    }

    $generatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    json_response([
        'success' => true,
        'csrf' => csrf_token(),
        'generated_at' => $generatedAt->format(DateTimeInterface::ATOM),
        'is_super' => $canViewAllTimesheets,
        'is_management' => $isManagement,
        'role' => $actualRole,
        'role_key' => (string)($user['role_key'] ?? $actualRole),
        'role_label' => (string)($user['role_label'] ?? $actualRole),
        'authority_level' => (int)($user['authority_level'] ?? 0),
        'permissions' => $user['permissions'] ?? [],
        'is_dev' => beta_user_is_dev($user),
        'is_admin' => $actualRole === 'ADMIN',
        'current_user_id' => (string)$user['user_id'],
        'client' => [
            'id' => (int)$clientDefaultsRow['id'],
            'name' => (string)$clientDefaultsRow['name'],
            'client_code' => (string)$clientDefaultsRow['client_code'],
        ],
        'client_defaults' => ['currency_code' => $clientCurrency, 'timezone' => $clientTimezone],
        'working' => $working,
        'disputes' => $disputes,
        'attendance_flags' => $attendanceFlags,
        'recent_shifts' => $recentShifts,
        'store_identity' => $storeIdentityRows,
        'stores' => $storeRows,
        'management' => $management,
        'dashboard_allowed' => $canDashboard,
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
