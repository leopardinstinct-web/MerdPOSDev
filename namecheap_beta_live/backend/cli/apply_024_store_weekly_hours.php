<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/024_store_weekly_hours.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('024 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $stores = $pdo->query(
        "SELECT s.client_id,s.id AS store_id,t.shift_start_time "
        . "FROM stores s LEFT JOIN store_shift_start_times t "
        . "ON t.client_id=s.client_id AND t.store_id=s.id "
        . "ORDER BY s.client_id,s.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO store_weekly_hours '
        . '(client_id,store_id,day_of_week,start_time,end_time,is_closed,updated_by_employee_id) '
        . 'VALUES (?,?,?,?,NULL,0,NULL)'
    );

    $seeded = 0;
    $pdo->beginTransaction();
    foreach ($stores as $store) {
        $start = trim((string)($store['shift_start_time'] ?? ''));
        $start = $start !== '' ? $start : null;
        for ($day = 1; $day <= 7; $day++) {
            $insert->execute([(int)$store['client_id'], (int)$store['store_id'], $day, $start]);
            $seeded += $insert->rowCount();
        }
    }
    $pdo->commit();

    $storeCount = count($stores);
    $rowCount = (int)$pdo->query('SELECT COUNT(*) FROM store_weekly_hours')->fetchColumn();
    echo "024 store weekly hours applied; {$storeCount} stores present, {$seeded} schedule rows seeded, {$rowCount} total schedule rows.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "024 store weekly hours failed: " . $e->getMessage() . "\n");
    exit(1);
}
