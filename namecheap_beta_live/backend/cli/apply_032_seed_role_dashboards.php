<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const MERD_DASHBOARD_SEED_KEY = '032_role_dashboards_full_widgets_v1';

function dashboard_seed_allowed(array $role, int $adminLoa): array
{
    $allowed = ['my_shift', 'my_disputes', 'recent_attendance'];
    $base = strtoupper(trim((string)($role['base_role'] ?? 'USER')));
    $loa = (int)($role['authority_level'] ?? 0);
    if (in_array($base, ['ADMIN','SUPER','DEV'], true) && $loa >= $adminLoa) {
        $allowed = array_merge($allowed, [
            'working_now_count',
            'pending_disputes',
            'active_employees',
            'sync_attention',
            'working_now',
            'workforce_by_store',
            'store_cash_position',
            'cash_mix',
            'today_sales_by_store',
        ]);
    }
    return $allowed;
}

function dashboard_seed_layout(string $key, bool $management): array
{
    $managementLayout = [
        'working_now_count'   => [0, 0, 3, 2],
        'pending_disputes'    => [3, 0, 3, 2],
        'active_employees'    => [6, 0, 3, 2],
        'sync_attention'      => [9, 0, 3, 2],
        'my_shift'            => [0, 2, 4, 2],
        'my_disputes'         => [4, 2, 4, 3],
        'working_now'         => [0, 5, 6, 4],
        'workforce_by_store'  => [6, 5, 6, 4],
        'store_cash_position' => [0, 9, 6, 4],
        'cash_mix'            => [6, 9, 6, 4],
        'today_sales_by_store'=> [0, 13, 6, 4],
        'recent_attendance'   => [0, 17, 12, 5],
    ];
    $basicLayout = [
        'my_shift'          => [0, 0, 4, 2],
        'my_disputes'       => [4, 0, 4, 3],
        'recent_attendance' => [0, 3, 12, 5],
    ];
    $map = $management ? $managementLayout : $basicLayout;
    return $map[$key] ?? [0, 0, 4, 3];
}

try {
    $sqlPath = dirname(__DIR__) . '/sql/032_seed_role_dashboards.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('032 migration SQL could not be read.');
    $pdo->exec($sql);

    $done = $pdo->prepare('SELECT COUNT(*) FROM app_migrations WHERE migration_key=?');
    $done->execute([MERD_DASHBOARD_SEED_KEY]);
    if ((int)$done->fetchColumn() > 0) {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM dashboard_role_layouts')->fetchColumn();
        echo "032 role dashboards already seeded; {$count} widget rows present.\n";
        exit(0);
    }

    $roles = $pdo->query(
        'SELECT id,client_id,role_key,role_label,base_role,authority_level,status FROM client_roles ORDER BY client_id,id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $adminLevelStmt = $pdo->prepare("SELECT authority_level FROM client_roles WHERE client_id=? AND role_key='ADMIN' LIMIT 1");
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO dashboard_role_layouts (client_id,role_id,widget_key,grid_x,grid_y,grid_w,grid_h) VALUES (?,?,?,?,?,?,?)'
    );

    $inserted = 0;
    $roleCount = 0;
    $pdo->beginTransaction();
    try {
        foreach ($roles as $role) {
            $clientId = (int)$role['client_id'];
            $adminLevelStmt->execute([$clientId]);
            $adminLoa = max(1, (int)($adminLevelStmt->fetchColumn() ?: 50));
            $allowed = dashboard_seed_allowed($role, $adminLoa);
            $management = count($allowed) > 3;
            foreach ($allowed as $widgetKey) {
                [$x,$y,$w,$h] = dashboard_seed_layout($widgetKey, $management);
                $insert->execute([$clientId, (int)$role['id'], $widgetKey, $x, $y, $w, $h]);
                $inserted += $insert->rowCount();
            }
            $roleCount++;
        }
        $mark = $pdo->prepare('INSERT INTO app_migrations (migration_key) VALUES (?)');
        $mark->execute([MERD_DASHBOARD_SEED_KEY]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $total = (int)$pdo->query('SELECT COUNT(*) FROM dashboard_role_layouts')->fetchColumn();
    echo "032 role dashboards seeded; {$roleCount} roles checked, {$inserted} widgets added, {$total} total widget rows.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '032 role dashboard seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
