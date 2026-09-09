<?php
declare(strict_types=1);

require_once __DIR__ . '/platform_identity.php';

function merd_service_permission_levels(PDO $pdo, int $clientId): array
{
    $catalog = merd_portal_permission_catalog();
    $levels = [];
    foreach ($catalog as $key => $rule) {
        $levels[$key] = !empty($rule['dev_only']) ? 1000 : max(1, min(1000, (int)$rule['min_loa']));
    }
    $stmt = $pdo->prepare('SELECT permission_key,min_authority_level FROM client_permission_levels WHERE client_id=?');
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
    $platform = merd_platform_identity_by_user_id($pdo, $actorUserId);
    if (is_array($platform)) {
        if (!merd_platform_identity_is_active_dev($platform)) {
            throw new MerdRequestException('service_actor_unavailable', 403, 'Service actor is unavailable.');
        }
        $clientStmt = $pdo->prepare("SELECT id FROM clients WHERE id=? AND status='active' LIMIT 1");
        $clientStmt->execute([$clientId]);
        if (!$clientStmt->fetchColumn()) throw new MerdRequestException('client_not_found', 404, 'Active client not found.');
        return [
            'identity_scope'=>'platform',
            'platform_identity'=>$platform,
            'employee'=>null,
            'role'=>['id'=>0,'role_key'=>'DEV','role_label'=>'Developer','base_role'=>'DEV','authority_level'=>1000],
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT e.id,e.client_id,e.full_name,e.user_id,e.employee_type,e.role_name,e.client_role_id,e.status,'
        . 'r.role_key,r.role_label,r.base_role,r.authority_level,r.status AS role_status '
        . 'FROM employees e LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id '
        . "WHERE e.client_id=? AND e.user_id=? AND UPPER(TRIM(e.employee_type))<>'DEV' LIMIT 1"
    );
    $stmt->execute([$clientId, $actorUserId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($actor) || strtolower((string)($actor['status'] ?? '')) !== 'active') {
        throw new MerdRequestException('service_actor_unavailable', 403, 'Service actor is unavailable.');
    }

    $roleValid = !empty($actor['client_role_id']) && !empty($actor['role_key'])
        && strtolower((string)($actor['role_status'] ?? '')) === 'active';
    $baseRole = strtoupper(trim((string)($roleValid ? $actor['base_role'] : $actor['employee_type'])));
    if (!in_array($baseRole, ['USER','ADMIN','SUPER'], true)) $baseRole = 'USER';
    $role = $roleValid ? [
        'id'=>(int)$actor['client_role_id'],'role_key'=>(string)$actor['role_key'],
        'role_label'=>(string)$actor['role_label'],'base_role'=>$baseRole,
        'authority_level'=>(int)$actor['authority_level'],
    ] : null;
    if ($role === null) {
        $fallback = $pdo->prepare(
            "SELECT id,role_key,role_label,base_role,authority_level FROM client_roles "
            . "WHERE client_id=? AND role_key=? AND status='active' LIMIT 1"
        );
        $fallback->execute([$clientId, $baseRole]);
        $row = $fallback->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $role = [
                'id'=>(int)$row['id'],'role_key'=>(string)$row['role_key'],'role_label'=>(string)$row['role_label'],
                'base_role'=>strtoupper((string)$row['base_role']),'authority_level'=>(int)$row['authority_level'],
            ];
        }
    }
    if (!is_array($role)) throw new MerdRequestException('service_actor_unavailable', 403, 'Service actor is unavailable.');
    return ['identity_scope'=>'employee','platform_identity'=>null,'employee'=>$actor,'role'=>$role];
}

function merd_service_role_has_permission(PDO $pdo, int $clientId, array $role, string $permission): bool
{
    $catalog = merd_portal_permission_catalog();
    if (!isset($catalog[$permission])) return false;
    $roleKey = strtoupper(trim((string)($role['role_key'] ?? $role['base_role'] ?? '')));
    $isDev = $roleKey === 'DEV';
    if (!empty($catalog[$permission]['dev_only'])) return $isDev;
    if (!merd_permission_role_key_allowed($catalog[$permission], $roleKey, $isDev)) return false;
    $levels = merd_service_permission_levels($pdo, $clientId);
    if ((int)($role['authority_level'] ?? 0) < (int)($levels[$permission] ?? 1000)) return false;
    return merd_role_usability_enabled($pdo, $clientId, $roleKey, $permission);
}

function merd_service_require_permissions(PDO $pdo, int $clientId, array $role, array $permissions): void
{
    foreach ($permissions as $permission) {
        if (merd_service_role_has_permission($pdo, $clientId, $role, (string)$permission)) continue;
        throw new MerdRequestException('service_forbidden', 403, 'Service actor is not permitted for this data.');
    }
}
