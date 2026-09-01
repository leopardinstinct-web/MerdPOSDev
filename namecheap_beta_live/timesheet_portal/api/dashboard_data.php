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
    $effectiveRole = merd_dashboard_user_role($pdo, $user);
    if (!isset($_GET['role_id']) || $_GET['role_id'] === '') return $effectiveRole;
    $roleId = filter_var($_GET['role_id'], FILTER_VALIDATE_INT);
    if ($roleId === false || $roleId <= 0) throw new MerdWorkforceException('invalid_role', 'Choose a valid dashboard role.');
    if ((int)$effectiveRole['id'] === (int)$roleId) return $effectiveRole;
    beta_require_permission($user, 'dashboard.configure', $pdo);
    $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
    if (!$role || strtolower((string)$role['status']) !== 'active') throw new MerdWorkforceException('role_not_found', 'Dashboard role not found.');
    return $role;
}

function dashboard_data_period_dates(string $businessDate, DateTimeZone $timezone, int $days): array
{
    $days = in_array($days, [7,14,30], true) ? $days : 7;
    $end = new DateTimeImmutable($businessDate, $timezone);
    $dates = [];
    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $dates[] = $end->modify("-{$offset} days")->format('Y-m-d');
    }
    return $dates;
}

function dashboard_data_current_week_dates(string $businessDate, DateTimeZone $timezone, int $weekStartDay): array
{
    $weekStartDay = max(1, min(7, $weekStartDay));
    $end = new DateTimeImmutable($businessDate, $timezone);
    $offset = ((int)$end->format('N') - $weekStartDay + 7) % 7;
    $start = $end->modify("-{$offset} days");
    $dates = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        $dates[] = $cursor->format('Y-m-d');
    }
    return $dates;
}

function dashboard_data_fill_series(array $dates, array $rows, bool $integer = false): array
{
    $values = [];
    foreach ($rows as $row) {
        $date = (string)($row['business_date'] ?? '');
        if ($date === '') continue;
        $values[$date] = $integer ? (int)($row['value'] ?? 0) : (float)($row['value'] ?? 0);
    }
    return array_map(
        static fn(string $date): array => ['date'=>$date, 'value'=>$values[$date] ?? ($integer ? 0 : 0.0)],
        $dates
    );
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $clientId = (int)$user['client_id'];
    $role = dashboard_data_role($pdo, $user);
    $allowed = merd_dashboard_allowed_widgets($pdo, $clientId, $role);
    $allowedMap = array_fill_keys($allowed, true);

    // Whole-application permissions remain role/LOA based. Widget data dependencies
    // are resolved only inside this endpoint, so a dashboard widget never grants
    // its role the corresponding navigation area or general API permission.
    $roleCanWorkforce = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'workforce.view');
    $roleCanReviewDisputes = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'disputes.review');
    $roleCanViewAllTimesheets = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'timesheets.view_all');
    $roleCanFinanceSummary = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'finance.management_summary');
    $roleCanSync = merd_dashboard_role_has_permission($pdo, $clientId, $role, 'system.sync_status');
    $dashboardCanWorkforce = $roleCanWorkforce || merd_dashboard_dependency_enabled($allowed, 'workforce.view');
    $dashboardCanReviewDisputes = $roleCanReviewDisputes || merd_dashboard_dependency_enabled($allowed, 'disputes.review');
    $dashboardCanFinanceSummary = $roleCanFinanceSummary || merd_dashboard_dependency_enabled($allowed, 'finance.management_summary');
    $dashboardCanSync = $roleCanSync || merd_dashboard_dependency_enabled($allowed, 'system.sync_status');

    $clientDefaults = $pdo->prepare('SELECT default_currency,default_timezone FROM clients WHERE id=? LIMIT 1');
    $clientDefaults->execute([$clientId]);
    $defaults = $clientDefaults->fetch(PDO::FETCH_ASSOC) ?: ['default_currency'=>'AUD','default_timezone'=>'Australia/Sydney'];
    $currency = strtoupper((string)($defaults['default_currency'] ?: 'AUD'));
    $timezone = (string)($defaults['default_timezone'] ?: 'Australia/Sydney');
    try { $timezoneObject = new DateTimeZone($timezone); } catch (Throwable) { $timezone = 'Australia/Sydney'; $timezoneObject = new DateTimeZone($timezone); }

    $period = strtolower(trim((string)($_GET['period'] ?? '')));
    $isCurrentWeek = $period === 'current_week';
    $days = filter_var($_GET['days'] ?? 7, FILTER_VALIDATE_INT);
    if (!in_array($days, [7,14,30], true)) $days = 7;
    $storeId = filter_var($_GET['store_id'] ?? 0, FILTER_VALIDATE_INT);
    $storeId = ($storeId !== false && $storeId > 0) ? (int)$storeId : 0;
    $selectedStore = null;
    if ($storeId > 0) {
        $storeCheck = $pdo->prepare("SELECT id,store_name,week_start_day,timezone FROM stores WHERE id=? AND client_id=? AND status='active' LIMIT 1");
        $storeCheck->execute([$storeId,$clientId]);
        $selectedStore = $storeCheck->fetch(PDO::FETCH_ASSOC);
        if (!is_array($selectedStore)) throw new MerdWorkforceException('store_not_found', 'Dashboard store filter is unavailable.');
    }

    $needsWorkingRoster = isset($allowedMap['working_now']) || isset($allowedMap['workforce_by_store']);
    $needsWorkingCount = isset($allowedMap['working_now_count']) || $needsWorkingRoster;
    $needsMyShift = isset($allowedMap['my_shift']);
    $allWorking = ($needsMyShift || ($dashboardCanWorkforce && $needsWorkingCount)) ? merd_working_now($pdo, $clientId) : [];
    $myWorking = $needsMyShift ? array_values(array_filter($allWorking, fn(array $row): bool => (string)($row['user_id'] ?? '') === (string)$user['user_id'])) : [];
    $filteredWorking = $storeId > 0 ? array_values(array_filter($allWorking, fn(array $row): bool => (int)($row['store_id'] ?? 0) === $storeId)) : $allWorking;
    $workingCount = ($dashboardCanWorkforce && $needsWorkingCount) ? count($filteredWorking) : 0;
    $working = ($dashboardCanWorkforce && $needsWorkingRoster) ? $filteredWorking : [];

    $disputes = [];
    if (isset($allowedMap['my_disputes'])) {
        $ownUser = $user;
        $ownUser['employee_type'] = 'USER';
        $ownUser['role_name'] = 'USER';
        $disputes = merd_list_disputes($pdo, $ownUser);
    }
    $pendingDisputesCount = 0;
    if ($dashboardCanReviewDisputes && isset($allowedMap['pending_disputes'])) {
        $reviewUser = $user;
        $reviewUser['employee_type'] = 'SUPER';
        $reviewUser['role_name'] = 'SUPER';
        $pendingDisputesCount = count(array_filter(merd_list_disputes($pdo, $reviewUser), fn(array $row): bool => (string)($row['status'] ?? '') === 'pending'));
    }

    // A workforce-scoped widget must not broaden Recent attendance. That widget's
    // own dependency is timesheets.view_own; broader history still requires the
    // role's real timesheets.view_all permission.
    $allRecent = $roleCanViewAllTimesheets;
    $recentShifts = [];
    if (isset($allowedMap['recent_attendance'])) {
        $shiftWhere = $allRecent ? 's.client_id=?' : 's.client_id=? AND s.employee_id=?';
        $shiftArgs = $allRecent ? [$clientId] : [$clientId, (int)$user['id']];
        if ($storeId > 0) { $shiftWhere .= ' AND s.store_id=?'; $shiftArgs[] = $storeId; }
        $shifts = $pdo->prepare(
            'SELECT s.public_id AS shift_id,e.full_name,e.user_id,st.id AS store_id,st.store_name,s.clock_in_at,s.clock_out_at,s.status,'
            . 'COALESCE(st.timezone,?) AS timezone '
            . 'FROM attendance_shifts s INNER JOIN employees e ON e.id=s.employee_id INNER JOIN stores st ON st.id=s.store_id '
            . 'WHERE ' . $shiftWhere . ' ORDER BY s.clock_in_at DESC LIMIT 100'
        );
        $shifts->execute(array_merge([$timezone], $shiftArgs));
        $recentShifts = $shifts->fetchAll(PDO::FETCH_ASSOC);
    }

    $filterStores = [];
    $storeFilterWidgets = ['working_now_count','working_now','workforce_by_store','store_cash_position','cash_mix','today_sales_by_store','recent_attendance','sales_change','attendance_change','sales_trend_7d','attendance_trend_7d','top_stores_sales'];
    $needsStoreFilter = count(array_intersect($allowed, $storeFilterWidgets)) > 0;
    if ($needsStoreFilter && ($dashboardCanWorkforce || $dashboardCanFinanceSummary)) {
        $filterStoreStmt = $pdo->prepare("SELECT id,store_name,week_start_day,timezone FROM stores WHERE client_id=? AND status='active' ORDER BY id ASC");
        $filterStoreStmt->execute([$clientId]);
        $filterStores = $filterStoreStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($needsStoreFilter && isset($allowedMap['recent_attendance'])) {
        $where = $allRecent ? 's.client_id=?' : 's.client_id=? AND s.employee_id=?';
        $args = $allRecent ? [$clientId] : [$clientId, (int)$user['id']];
        $filterStoreStmt = $pdo->prepare(
            'SELECT DISTINCT st.id,st.store_name,st.week_start_day,st.timezone FROM attendance_shifts s INNER JOIN stores st ON st.id=s.store_id WHERE ' . $where . ' ORDER BY st.id ASC'
        );
        $filterStoreStmt->execute($args);
        $filterStores = $filterStoreStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($storeId > 0 && $needsStoreFilter) {
        $allowedStoreIds = array_map(static fn(array $row): int => (int)$row['id'], $filterStores);
        if (!in_array($storeId, $allowedStoreIds, true)) throw new MerdWorkforceException('store_not_found', 'Dashboard store filter is unavailable.');
    }

    $stores = [];
    $management = null;
    $needsManagement = $dashboardCanWorkforce || $dashboardCanFinanceSummary || $dashboardCanSync;
    if ($needsManagement) {
        $needsStoreRows = isset($allowedMap['workforce_by_store']) || isset($allowedMap['store_cash_position']) || isset($allowedMap['cash_mix']) || isset($allowedMap['today_sales_by_store']) || isset($allowedMap['top_stores_sales']);
        if ($needsStoreRows) {
            $storeSql = "SELECT id,store_name,COALESCE(currency_code,?) AS currency_code,COALESCE(timezone,?) AS timezone FROM stores WHERE client_id=? AND status='active'";
            $storeArgs = [$currency,$timezone,$clientId];
            if ($storeId > 0) { $storeSql .= ' AND id=?'; $storeArgs[] = $storeId; }
            $storeSql .= ' ORDER BY id ASC';
            $storeStmt = $pdo->prepare($storeSql);
            $storeStmt->execute($storeArgs);
            $stores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $periodTimezoneObject = $timezoneObject;
        $weekStartDay = 1;
        if ($isCurrentWeek) {
            if (is_array($selectedStore)) {
                $weekStartDay = max(1, min(7, (int)($selectedStore['week_start_day'] ?? 1)));
                $storeTimezone = trim((string)($selectedStore['timezone'] ?? ''));
                if ($storeTimezone !== '') { try { $periodTimezoneObject = new DateTimeZone($storeTimezone); } catch (Throwable) {} }
            } else {
                $weekStarts = array_values(array_unique(array_map(static fn(array $row): int => max(1, min(7, (int)($row['week_start_day'] ?? 1))), $filterStores)));
                if (count($weekStarts) > 1) throw new MerdWorkforceException('store_required_for_current_week', 'Choose a store to use its configured week start.');
                if ($weekStarts) $weekStartDay = $weekStarts[0];
            }
        }
        $businessDate = (new DateTimeImmutable('now', $periodTimezoneObject))->format('Y-m-d');
        $trendDates = $isCurrentWeek
            ? dashboard_data_current_week_dates($businessDate, $periodTimezoneObject, $weekStartDay)
            : dashboard_data_period_dates($businessDate, $periodTimezoneObject, (int)$days);
        $days = count($trendDates);
        $trendStart = $trendDates[0];

        $financialRows = [];
        if ($dashboardCanFinanceSummary && (isset($allowedMap['store_cash_position']) || isset($allowedMap['cash_mix']))) {
            $financial = $pdo->prepare(
                "SELECT st.id AS store_id,st.store_name,COALESCE(st.currency_code,?) AS currency_code,COALESCE(st.timezone,?) AS timezone,"
                . "COALESCE(SUM(CASE WHEN f.account='Register' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS register_balance,"
                . "COALESCE(SUM(CASE WHEN f.account='Petty Cash' THEN COALESCE(f.closing_amount,f.opening_amount+f.in_total-f.out_total) ELSE 0 END),0) AS petty_balance "
                . "FROM stores st LEFT JOIN financial_day_accounts f ON f.store_id=st.id AND f.client_id=st.client_id AND f.business_date=? "
                . "WHERE st.client_id=? AND st.status='active' AND (?=0 OR st.id=?) GROUP BY st.id,st.store_name,st.currency_code,st.timezone ORDER BY st.id"
            );
            $financial->execute([$currency,$timezone,$businessDate,$clientId,$storeId,$storeId]);
            $financialRows = $financial->fetchAll(PDO::FETCH_ASSOC);
        }

        $salesRows = [];
        if ($dashboardCanFinanceSummary && (isset($allowedMap['today_sales_by_store']) || isset($allowedMap['top_stores_sales']))) {
            $sales = $pdo->prepare(
                "SELECT st.id AS store_id,st.store_name,COALESCE(st.currency_code,?) AS currency_code,COALESCE(SUM(rs.total),0) AS today_sales "
                . "FROM stores st LEFT JOIN retail_sales rs ON rs.store_id=st.id AND rs.client_id=st.client_id AND DATE(rs.sold_at)=? AND rs.status='completed' "
                . "WHERE st.client_id=? AND st.status='active' AND (?=0 OR st.id=?) GROUP BY st.id,st.store_name,st.currency_code ORDER BY st.id"
            );
            $sales->execute([$currency,$businessDate,$clientId,$storeId,$storeId]);
            $salesRows = $sales->fetchAll(PDO::FETCH_ASSOC);
        }

        $salesTrend = [];
        if ($dashboardCanFinanceSummary && (isset($allowedMap['sales_change']) || isset($allowedMap['sales_trend_7d']))) {
            $stmt = $pdo->prepare(
                "SELECT DATE(rs.sold_at) AS business_date,COALESCE(SUM(rs.total),0) AS value "
                . "FROM retail_sales rs WHERE rs.client_id=? AND rs.status='completed' AND DATE(rs.sold_at) BETWEEN ? AND ? AND (?=0 OR rs.store_id=?) "
                . "GROUP BY DATE(rs.sold_at) ORDER BY business_date ASC"
            );
            $stmt->execute([$clientId,$trendStart,$businessDate,$storeId,$storeId]);
            $salesTrend = dashboard_data_fill_series($trendDates, $stmt->fetchAll(PDO::FETCH_ASSOC), false);
        }

        $attendanceTrend = [];
        if ($dashboardCanWorkforce && (isset($allowedMap['attendance_change']) || isset($allowedMap['attendance_trend_7d']))) {
            $stmt = $pdo->prepare(
                "SELECT DATE(s.clock_in_at) AS business_date,COUNT(*) AS value "
                . "FROM attendance_shifts s WHERE s.client_id=? AND DATE(s.clock_in_at) BETWEEN ? AND ? AND (?=0 OR s.store_id=?) "
                . "GROUP BY DATE(s.clock_in_at) ORDER BY business_date ASC"
            );
            $stmt->execute([$clientId,$trendStart,$businessDate,$storeId,$storeId]);
            $attendanceTrend = dashboard_data_fill_series($trendDates, $stmt->fetchAll(PDO::FETCH_ASSOC), true);
        }

        $activeEmployees = null;
        if ($dashboardCanWorkforce && isset($allowedMap['active_employees'])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE client_id=? AND status='active'");
            $stmt->execute([$clientId]);
            $activeEmployees = (int)$stmt->fetchColumn();
        }

        $syncAttention = null;
        if ($dashboardCanSync && isset($allowedMap['sync_attention'])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM google_sheet_outbox WHERE client_id=? AND status IN ('pending','processing','failed')");
            $stmt->execute([$clientId]);
            $syncAttention = (int)$stmt->fetchColumn();
        }

        $syncStatuses = [];
        if ($dashboardCanSync && isset($allowedMap['sync_status_table'])) {
            $stmt = $pdo->prepare(
                "SELECT status,COUNT(*) AS item_count FROM google_sheet_outbox "
                . "WHERE client_id=? AND status IN ('pending','processing','failed') GROUP BY status"
            );
            $stmt->execute([$clientId]);
            $counts = ['failed'=>0,'processing'=>0,'pending'=>0];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = strtolower((string)($row['status'] ?? ''));
                if (array_key_exists($status, $counts)) $counts[$status] = (int)($row['item_count'] ?? 0);
            }
            foreach (['failed','processing','pending'] as $status) {
                $syncStatuses[] = ['status'=>$status,'count'=>$counts[$status]];
            }
        }

        $management = [
            'business_date'=>$businessDate,
            'currency_code'=>$currency,
            'timezone'=>$timezone,
            'period_days'=>(int)$days,
            'period'=>$isCurrentWeek ? 'current_week' : (string)$days,
            'week_start_day'=>$isCurrentWeek ? $weekStartDay : null,
            'active_employees'=>$activeEmployees,
            'sync_attention'=>$syncAttention,
            'financial_by_store'=>$financialRows,
            'sales_by_store'=>$salesRows,
            'analytics'=>[
                'sales_period'=>$salesTrend,
                'attendance_period'=>$attendanceTrend,
                'sync_statuses'=>$syncStatuses,
            ],
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
        'filters'=>['store_id'=>$storeId,'days'=>(int)$days,'period'=>$isCurrentWeek ? 'current_week' : (string)$days,'period_label'=>$isCurrentWeek ? 'Current week' : ((int)$days . ' days'),'week_start_day'=>$isCurrentWeek ? ($weekStartDay ?? 1) : null],
        'filter_options'=>['stores'=>$filterStores,'periods'=>['current_week',7,14,30]],
        'working_count'=>$workingCount,
        'working'=>$working,
        'my_working'=>$myWorking,
        'disputes'=>$disputes,
        'pending_disputes_count'=>$pendingDisputesCount,
        'recent_shifts'=>$recentShifts,
        'stores'=>$stores,
        'management'=>$management,
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
