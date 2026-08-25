<?php
declare(strict_types=1);

function merd_role_authority_defaults(): array
{
    return ['USER' => 10, 'ADMIN' => 50, 'SUPER' => 90, 'DEV' => 1000];
}

function merd_role_authority_map(PDO $pdo, int $clientId): array
{
    $map = merd_role_authority_defaults();
    try {
        $stmt = $pdo->prepare("SELECT role_name,authority_level FROM client_role_authority WHERE client_id=? AND role_name IN ('USER','ADMIN','SUPER')");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $role = strtoupper(trim((string)$row['role_name']));
            $level = (int)$row['authority_level'];
            if (isset($map[$role]) && $role !== 'DEV' && $level >= 1 && $level <= 99) $map[$role] = $level;
        }
    } catch (Throwable $e) {
        // Migration-controlled fallback keeps the portal usable during a deploy.
        error_log('MERDPOS role authority fallback: ' . get_class($e));
    }
    $map['DEV'] = 1000;
    return $map;
}

function merd_role_authority_level(array $map, string $role): int
{
    $role = strtoupper(trim($role));
    return (int)($map[$role] ?? 0);
}

function merd_role_authority_assignable(array $map, string $actorRole): array
{
    $actorRole = strtoupper(trim($actorRole));
    if ($actorRole === 'DEV') return ['USER', 'ADMIN', 'SUPER', 'DEV'];
    $actorLevel = merd_role_authority_level($map, $actorRole);
    $roles = ['USER', 'ADMIN', 'SUPER'];
    return array_values(array_filter($roles, fn(string $role): bool => merd_role_authority_level($map, $role) <= $actorLevel));
}
