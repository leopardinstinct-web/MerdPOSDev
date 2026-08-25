<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/027_store_profile_defaults.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('027 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $required = [
        'clients' => ['default_currency', 'default_timezone'],
        'stores' => ['address', 'google_maps_url', 'logo_path', 'currency_code', 'timezone'],
    ];
    foreach ($required as $table => $columns) {
        $stmt = $pdo->prepare(
            'SELECT column_name FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name=?'
        );
        $stmt->execute([$table]);
        $present = array_fill_keys(array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($columns as $column) {
            if (!isset($present[strtolower($column)])) {
                throw new RuntimeException("{$table}.{$column} is missing after migration.");
            }
        }
    }

    $clientCount = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $storeCount = (int)$pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
    echo "027 store profile/defaults applied; {$clientCount} clients and {$storeCount} stores ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "027 store profile/defaults failed: " . $e->getMessage() . "\n");
    exit(1);
}
