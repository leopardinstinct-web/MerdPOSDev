<?php
declare(strict_types=1);

function merd_dashboard_roles(PDO $pdo, int $clientId, bool $activeOnly = true): array
{
    $sql = 'SELECT id,client_id,role_key,role_label,base_role,authority_level,is_system,status FROM client_roles WHERE client_id=?';
    if ($activeOnly) $sql .= " AND status='active'";
    $sql .= ' ORDER BY authority_level ASC,id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function merd_dashboard_role_by_id(PDO $pdo, int $clientId, int $roleId): ?array
{
    $stmt = $pdo->prepare('SELECT id,client_id,role_key,role_label,base_role,authority_level,is_system,status FROM client_roles WHERE client_id=? AND id=? LIMIT 1');
    $stmt->execute([$clientId, $roleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function merd_dashboard_system_role(PDO $pdo, int $clientId, string $key): ?array
{
    $stmt = $pdo->prepare('SELECT id,client_id,role_key,role_label,base_role,authority_level,is_system,status FROM client_roles WHERE client_id=? AND role_key=? LIMIT 1');
    $stmt->execute([$clientId, strtoupper(trim($key))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function merd_dashboard_user_role(PDO $pdo, array $user): array
{
    $clientId = (int)$user['client_id'];
    $baseRole = strtoupper(trim((string)($user['role'] ?? $user['actual_employee_type'] ?? $user['employee_type'] ?? 'USER')));
    $roleId = isset($user['client_role_id']) ? (int)$user['client_role_id'] : 0;

    if ($baseRole === 'DEV') {
        $dev = merd_dashboard_system_role($pdo, $clientId, 'DEV');
        if ($dev) return $dev;
    }

    if ($roleId > 0) {
        $role = merd_dashboard_role_by_id($pdo, $clientId, $roleId);
        if ($role && strtolower((string)$role['status']) === 'active') return $role;
    }

    $fallback = merd_dashboard_system_role($pdo, $clientId, $baseRole);
    if ($fallback) return $fallback;

    throw new RuntimeException('Dashboard role is not configured for this client.');
}

function merd_dashboard_widget_catalog(PDO $pdo, int $clientId): array
{
    $admin = merd_dashboard_system_role($pdo, $clientId, 'ADMIN');
    $adminLoa = max(1, (int)($admin['authority_level'] ?? 50));

    return [
        'my_shift' => ['min_loa' => 1, 'management' => false],
        'my_disputes' => ['min_loa' => 1, 'management' => false],
        'recent_attendance' => ['min_loa' => 1, 'management' => false],
        'working_now_count' => ['min_loa' => $adminLoa, 'management' => true],
        'pending_disputes' => ['min_loa' => $adminLoa, 'management' => true],
        'active_employees' => ['min_loa' => $adminLoa, 'management' => true],
        'sync_attention' => ['min_loa' => $adminLoa, 'management' => true],
        'working_now' => ['min_loa' => $adminLoa, 'management' => true],
        'workforce_by_store' => ['min_loa' => $adminLoa, 'management' => true],
        'store_cash_position' => ['min_loa' => $adminLoa, 'management' => true],
        'cash_mix' => ['min_loa' => $adminLoa, 'management' => true],
        'today_sales_by_store' => ['min_loa' => $adminLoa, 'management' => true],
    ];
}

function merd_dashboard_allowed_widgets(PDO $pdo, int $clientId, array $role): array
{
    $loa = (int)$role['authority_level'];
    $base = strtoupper(trim((string)$role['base_role']));
    $managementBase = in_array($base, ['ADMIN','SUPER','DEV'], true);
    $allowed = [];
    foreach (merd_dashboard_widget_catalog($pdo, $clientId) as $key => $rule) {
        if ($loa < (int)$rule['min_loa']) continue;
        if (!empty($rule['management']) && !$managementBase) continue;
        $allowed[] = $key;
    }
    return $allowed;
}
