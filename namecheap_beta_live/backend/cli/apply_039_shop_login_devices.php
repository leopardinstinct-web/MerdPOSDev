<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

try {
    $sqlPath = dirname(__DIR__) . '/sql/039_shop_login_devices.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('039 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $columns = $pdo->query("SHOW COLUMNS FROM devices LIKE 'device_code'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$columns) throw new RuntimeException('039 device_code column missing after migration.');
    $table = $pdo->query("SHOW TABLES LIKE 'shop_qr_uses'")->fetchColumn();
    if (!$table) throw new RuntimeException('039 shop_qr_uses table missing after migration.');

    echo "MERDPOS migration 039 applied: POS device codes + shop QR replay ledger.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'MERDPOS migration 039 FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
