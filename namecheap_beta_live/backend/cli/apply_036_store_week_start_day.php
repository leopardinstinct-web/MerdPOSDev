<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

try {
    $sqlPath = dirname(__DIR__) . '/sql/036_store_week_start_day.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('036 migration SQL could not be read.');
    $pdo->exec($sql);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute(['stores','week_start_day']);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('stores.week_start_day is missing after migration.');

    $invalid = (int)$pdo->query('SELECT COUNT(*) FROM stores WHERE week_start_day<1 OR week_start_day>7')->fetchColumn();
    if ($invalid !== 0) throw new RuntimeException('Invalid store week_start_day values remain after migration.');
    $count = (int)$pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
    echo "036 store week start day applied; {$count} stores ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '036 store week start day migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
