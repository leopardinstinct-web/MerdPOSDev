<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $columns = $pdo->query("SHOW COLUMNS FROM stores LIKE 'store_code'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$columns) {
        throw new RuntimeException('stores.store_code does not exist on the live schema.');
    }

    $blankCount = (int)$pdo->query("SELECT COUNT(*) FROM stores WHERE store_code IS NULL OR TRIM(store_code)='' ")->fetchColumn();
    if ($blankCount > 0) {
        throw new RuntimeException("{$blankCount} store row(s) have a blank store_code; refusing to create a uniqueness constraint.");
    }

    $duplicateStmt = $pdo->query(
        "SELECT client_id,LOWER(TRIM(store_code)) AS normalized_code,COUNT(*) AS c "
        . "FROM stores GROUP BY client_id,LOWER(TRIM(store_code)) HAVING COUNT(*)>1 LIMIT 1"
    );
    $duplicate = $duplicateStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($duplicate)) {
        throw new RuntimeException(
            'Duplicate store_code values exist for client ' . (string)$duplicate['client_id']
            . '; refusing to create the uniqueness constraint.'
        );
    }

    $indexStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema=DATABASE() AND table_name='stores' AND index_name='uq_stores_client_store_code'"
    );
    $indexStmt->execute();
    $exists = (int)$indexStmt->fetchColumn() > 0;

    if (!$exists) {
        $sqlPath = dirname(__DIR__) . '/sql/026_store_code_uniqueness.sql';
        $sql = file_get_contents($sqlPath);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('026 migration SQL could not be read.');
        }
        $pdo->exec($sql);
    }

    $storeCount = (int)$pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
    echo "026 store code uniqueness applied; {$storeCount} stores validated, unique client/store_code index present.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "026 store code uniqueness failed: " . $e->getMessage() . "\n");
    exit(1);
}
