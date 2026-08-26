<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/034_legacy_migration_sync.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('034 migration SQL could not be read.');
    $pdo->exec($sql);

    $expected = [
        'client_legacy_sources','client_migration_state','legacy_migration_batches',
        'legacy_migration_stage_rows','legacy_migration_records','legacy_migration_conflicts',
    ];
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    foreach ($expected as $table) {
        $check->execute([$table]);
        if ((int)$check->fetchColumn() !== 1) throw new RuntimeException("Missing migration table: {$table}");
    }

    $pdo->exec('INSERT IGNORE INTO client_migration_state (client_id) SELECT id FROM clients');
    $clients = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $states = (int)$pdo->query('SELECT COUNT(*) FROM client_migration_state')->fetchColumn();
    echo "034 legacy migration sync applied; {$clients} clients, {$states} migration states, 6 migration tables ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '034 legacy migration migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
