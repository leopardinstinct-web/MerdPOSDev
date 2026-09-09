<?php
declare(strict_types=1);

function merd_platform_identity_by_user_id(PDO $pdo, string $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id,user_id,full_name,login_password,pin_code,role_key,status,legacy_employee_id "
        . "FROM platform_identities WHERE user_id=? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function merd_platform_identity_by_id(PDO $pdo, int $identityId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id,user_id,full_name,login_password,pin_code,role_key,status,legacy_employee_id "
        . "FROM platform_identities WHERE id=? LIMIT 1"
    );
    $stmt->execute([$identityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function merd_platform_identity_selected_client(PDO $pdo, int $identityId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT p.selected_client_id FROM platform_identity_preferences p "
        . "JOIN clients c ON c.id=p.selected_client_id AND c.status='active' "
        . "WHERE p.platform_identity_id=? LIMIT 1"
    );
    $stmt->execute([$identityId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int)$value;
}

function merd_platform_identity_set_selected_client(PDO $pdo, int $identityId, int $clientId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO platform_identity_preferences (platform_identity_id,selected_client_id) VALUES (?,?) '
        . 'ON DUPLICATE KEY UPDATE selected_client_id=VALUES(selected_client_id),updated_at=CURRENT_TIMESTAMP'
    );
    $stmt->execute([$identityId, $clientId]);
}

function merd_platform_identity_is_active_dev(array $identity): bool
{
    return strtolower((string)($identity['status'] ?? '')) === 'active'
        && strtoupper(trim((string)($identity['role_key'] ?? ''))) === 'DEV';
}
