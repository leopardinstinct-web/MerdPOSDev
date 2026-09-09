<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/api/includes/portal_permissions.php';

function merd_dashboard_roles(PDO $pdo, int $clientId, bool $activeOnly = true): array
{
    $sql = "SELECT id,client_id,role_key,role_label,base_role,authority_level,is_system,status FROM client_roles WHERE client_id=? AND UPPER(role_key)<>'DEV'";
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
    $actualDev = strtoupper(trim((string)($user['actual_role_key'] ?? $user['actual_employee_type'] ?? ''))) === 'DEV';
    $baseRole = strtoupper(trim((string)($user['role'] ?? $user['employee_type'] ?? 'USER')));
    $roleId = isset($user['client_role_id']) ? (int)$user['client_role_id'] : 0;

    // DEV is a platform identity. The legacy DEV client-role row is retained
    // only as an internal dashboard template and is never assignable/exposed.
    if ($actualDev && $baseRole === 'DEV') {
        $dev = merd_dashboard_system_role($pdo, $clientId, 'DEV');
        if ($dev) return $dev;
    }
    if ($roleId > 0) {
        $role = merd_dashboard_role_by_id($pdo, $clientId, $roleId);
        if ($role && strtoupper((string)$role['role_key']) !== 'DEV' && strtolower((string)$role['status']) === 'active') return $role;
    }
    $fallback = merd_dashboard_system_role($pdo, $clientId, $baseRole);
    if ($fallback && strtoupper((string)$fallback['role_key']) !== 'DEV') return $fallback;
    throw new RuntimeException('Dashboard role is not configured for this client.');
}

function merd_dashboard_permission_levels(PDO $pdo, int $clientId): array
{
    $catalog = merd_portal_permission_catalog();
    $levels = [];
    foreach ($catalog as $key => $rule) {
        $levels[$key] = !empty($rule['dev_only']) ? 1000 : max(1, min(1000, (int)$rule['min_loa']));
    }
    try {
        $stmt = $pdo->prepare('SELECT permission_key,min_authority_level FROM client_permission_levels WHERE client_id=?');
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['permission_key'];
            if (!isset($catalog[$key]) || !empty($catalog[$key]['dev_only'])) continue;
            $levels[$key] = max(1, min(1000, (int)$row['min_authority_level']));
        }
    } catch (Throwable) { }
    return $levels;
}

function merd_dashboard_role_has_permission(PDO $pdo, int $clientId, array $role, string $permission): bool
{
    $catalog = merd_portal_permission_catalog();
    if (!isset($catalog[$permission])) return false;
    $roleKey = strtoupper(trim((string)($role['role_key'] ?? $role['base_role'] ?? '')));
    $isDevRole = $roleKey === 'DEV';
    if (!empty($catalog[$permission]['dev_only'])) return $isDevRole;
    if (!merd_permission_role_key_allowed($catalog[$permission], $roleKey, $isDevRole)) return false;
    $levels = merd_dashboard_permission_levels($pdo, $clientId);
    if ((int)($role['authority_level'] ?? 0) < (int)($levels[$permission] ?? 1000)) return false;
    return merd_role_usability_enabled($pdo, $clientId, $roleKey, $permission);
}

function merd_dashboard_widget_catalog(PDO $pdo, int $clientId): array
{
    $rules = merd_portal_dashboard_widget_permissions();
    $catalog = [];
    foreach ($rules as $widget => $permissions) {
        $catalog[$widget] = ['visibility_permission'=>(string)$permissions[0],'data_permission'=>(string)$permissions[1]];
    }
    return $catalog;
}

function merd_dashboard_allowed_widgets(PDO $pdo, int $clientId, array $role): array
{
    $allowed = [];
    foreach (merd_dashboard_widget_catalog($pdo, $clientId) as $key => $rule) {
        if (!merd_dashboard_role_has_permission($pdo, $clientId, $role, (string)$rule['visibility_permission'])) continue;
        $allowed[] = $key;
    }
    return $allowed;
}

function merd_dashboard_widget_dependency_enabled(array $allowedWidgets, string $widgetKey, string $permission): bool
{
    if (!in_array($widgetKey, $allowedWidgets, true)) return false;
    $rules = merd_portal_dashboard_widget_permissions();
    return isset($rules[$widgetKey]) && (string)($rules[$widgetKey][1] ?? '') === $permission;
}

function merd_dashboard_dependency_enabled(array $allowedWidgets, string $permission): bool
{
    foreach ($allowedWidgets as $widgetKey) {
        if (merd_dashboard_widget_dependency_enabled($allowedWidgets, (string)$widgetKey, $permission)) return true;
    }
    return false;
}
