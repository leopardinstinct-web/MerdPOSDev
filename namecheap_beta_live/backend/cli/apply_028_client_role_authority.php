<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/028_client_role_authority.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('028 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='client_role_authority'");
    if ((int)$table->fetchColumn() !== 1) throw new RuntimeException('client_role_authority table is missing after migration.');

    $clientCount = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $rowCount = (int)$pdo->query('SELECT COUNT(*) FROM client_role_authority')->fetchColumn();
    $bad = (int)$pdo->query("SELECT COUNT(*) FROM client_role_authority WHERE role_name NOT IN ('USER','ADMIN','SUPER') OR authority_level<1 OR authority_level>99")->fetchColumn();
    if ($bad !== 0) throw new RuntimeException('Invalid role authority rows detected.');

    echo "028 client role authority applied; {$clientCount} clients and {$rowCount} role rows ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "028 client role authority failed: " . $e->getMessage() . "\n");
    exit(1);
}
