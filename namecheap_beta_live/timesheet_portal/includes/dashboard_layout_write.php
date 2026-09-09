<?php
declare(strict_types=1);

/** Persist a validated layout and its audit event in the same transaction. */
function dashboard_write_layout(PDO $pdo, array $actor, array $role, array $clean, bool $reset = false): void
{
    $clientId = (int)$actor['client_id'];
    $roleId = (int)$role['id'];
    if ($reset) $clean = [];
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM dashboard_role_layouts WHERE client_id=? AND role_id=?');
        $delete->execute([$clientId, $roleId]);
        if ($clean) {
            $insert = $pdo->prepare('INSERT INTO dashboard_role_layouts (client_id,role_id,widget_key,grid_x,grid_y,grid_w,grid_h) VALUES (?,?,?,?,?,?,?)');
            foreach ($clean as [$key,$x,$y,$w,$h]) $insert->execute([$clientId,$roleId,$key,$x,$y,$w,$h]);
        }
        beta_admin_audit($pdo, $actor, $reset ? 'dashboard.layout.reset' : 'dashboard.layout.save', 'dashboard_role_layout', (string)$roleId, [
            'role_id' => $roleId, 'role_key' => (string)($role['role_key'] ?? ''),
            'widget_count' => count($clean), 'widget_keys' => array_column($clean, 0),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
