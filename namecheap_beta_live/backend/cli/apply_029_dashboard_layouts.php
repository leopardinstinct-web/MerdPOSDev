<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/029_dashboard_layouts.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('029 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='dashboard_layouts'");
    if ((int)$table->fetchColumn() !== 1) throw new RuntimeException('dashboard_layouts table is missing after migration.');

    $columns = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='dashboard_layouts'")->fetchColumn();
    if ((int)$columns < 10) throw new RuntimeException('dashboard_layouts schema is incomplete.');

    $bad = (int)$pdo->query('SELECT COUNT(*) FROM dashboard_layouts WHERE grid_x>11 OR grid_w<1 OR grid_w>12 OR grid_h<1 OR grid_h>20')->fetchColumn();
    if ($bad !== 0) throw new RuntimeException('Invalid dashboard layout rows detected.');

    $rows = (int)$pdo->query('SELECT COUNT(*) FROM dashboard_layouts')->fetchColumn();
    echo "029 dashboard layouts applied; {$rows} saved widget rows ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "029 dashboard layouts failed: " . $e->getMessage() . "\n");
    exit(1);
}
