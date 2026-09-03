<?php
declare(strict_types=1);

function merd_service_permission_levels(PDO $pdo, int $clientId): array
{
    $catalog = merd_portal_permission_catalog();
    $levels = [];
    foreach ($catalog as $key => $rule) {
        $levels[$key] = !empty($rule['dev_only'])
            ? 1000
            : max(1, min(1000, (int)$rule['min_loa']));
    }

    $stmt = $pdo->prepare(
        'SELECT permission_key,min_authority_level FROM client_permission_levels WHERE client_id=?'
    );
    $stmt->execute([$clientId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)$row['permission_key'];
        if (!isset($catalog[$key]) || !empty($catalog[$key]['dev_only'])) continue;
        $levels[$key] = max(1, min(1000, (int)$row['min_authority_level']));
    }
    return $levels;
}

function merd_service_actor(PDO $pdo, int $clientId, string $actorUserId): array
{
    $stmt = $pdo->prepare(
        'SELECT e.id,e.client_id,e.full_name,e.user_id,e.employee_type,e.role_name,e.client_role_id,e.status,'
        . 'r.role_key,r.role_label,r.base_role,r.authority_level,r.status AS role_status '
        . 'FROM employees e LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id '
        . 'WHERE e.client_id=? AND e.user_id=? LIMIT 1'
    );
    $stmt->execute([$clientId, $actorUserId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($actor) || strtolower((string)($actor['status'] ?? '')) !== 'active') {
        throw new MerdRequestException('service_actor_unavailable', 403, 'Service actor is unavailable.');
    }

    $roleValid = !empty($actor['client_role_id'])
        && !empty($actor['role_key'])
        && strtolower((string)($actor['role_status'] ?? '')) === 'active';
    $baseRole = strtoupper(trim((string)($roleValid ? $actor['base_role'] : $actor['employee_type'])));
    if (!in_array($baseRole, ['USER','ADMIN','SUPER','DEV'], true)) $baseRole = 'USER';

    $role = $roleValid ? [
        'id' => (int)$actor['client_role_id'],
        'role_key' => (string)$actor['role_key'],
        'role_label' => (string)$actor['role_label'],
        'base_role' => $baseRole,
        'authority_level' => (int)$actor['authority_level'],
    ] : null;

    if ($baseRole === 'DEV' || $role === null) {
        $fallback = $pdo->prepare(
            "SELECT id,role_key,role_label,base_role,authority_level,status FROM client_roles "
            . "WHERE client_id=? AND role_key=? AND status='active' LIMIT 1"
        );
        $fallback->execute([$clientId, $baseRole]);
        $fallbackRole = $fallback->fetch(PDO::FETCH_ASSOC);
        if (is_array($fallbackRole)) {
            $role = [
                'id' => (int)$fallbackRole['id'],
                'role_key' => (string)$fallbackRole['role_key'],
                'role_label' => (string)$fallbackRole['role_label'],
                'base_role' => strtoupper((string)$fallbackRole['base_role']),
                'authority_level' => (int)$fallbackRole['authority_level'],
            ];
        }
    }

    if (!is_array($role)) {
        throw new MerdRequestException('service_actor_unavailable', 403, 'Service actor is unavailable.');
    }
    if ($baseRole === 'DEV') $role['authority_level'] = 1000;

    return ['employee' => $actor, 'role' => $role];
}

function merd_service_role_has_permission(
    PDO $pdo,
    int $clientId,
    array $role,
    string $permission
): bool {
    $catalog = merd_portal_permission_catalog();
    if (!isset($catalog[$permission])) return false;

    $roleKey = strtoupper(trim((string)($role['role_key'] ?? $role['base_role'] ?? '')));
    if (!empty($catalog[$permission]['dev_only'])) return $roleKey === 'DEV';

    $levels = merd_service_permission_levels($pdo, $clientId);
    return (int)($role['authority_level'] ?? 0) >= (int)($levels[$permission] ?? 1000);
}

function merd_service_require_permissions(PDO $pdo, int $clientId, array $role, array $permissions): void
{
    foreach ($permissions as $permission) {
        if (merd_service_role_has_permission($pdo, $clientId, $role, (string)$permission)) continue;
        throw new MerdRequestException('service_forbidden', 403, 'Service actor is not permitted for this data.');
    }
}
