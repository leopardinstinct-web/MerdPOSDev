<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    beta_require_permission($user, 'dev.status', $pdo);

    $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $serverVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    $tables = [
        'app_migrations', 'clients', 'client_role_authority', 'client_roles', 'client_permission_levels', 'dev_client_preferences',
        'dashboard_layouts', 'dashboard_role_layouts',
        'employees', 'stores', 'employee_store_access', 'employee_store_assignments',
        'employee_hourly_rate_history', 'store_weekly_hours', 'store_shift_start_times',
        'employee_logs', 'attendance_shifts', 'attendance_disputes',
        'attendance_account_flags', 'financial_day_accounts', 'financial_ledger_entries',
        'financial_submissions', 'google_sheet_outbox', 'retail_sales',
        'retail_sale_lines', 'retail_store_inventory'
    ];

    $counts = [];
    foreach ($tables as $table) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $exists->execute([$table]);
        if (!(int)$exists->fetchColumn()) {
            $counts[$table] = null;
            continue;
        }
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    json_response([
        'success' => true,
        'database' => $databaseName,
        'server_version' => $serverVersion,
        'php_version' => PHP_VERSION,
        'app' => 'MERDPOS beta',
        'branch' => 'namecheap-beta-live',
        'authorization_model' => 'central_permission_loa_v1',
        'actor_loa' => (int)($user['authority_level'] ?? 0),
        'tables' => $counts,
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
