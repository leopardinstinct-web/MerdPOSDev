<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

try {
    $sqlPath = dirname(__DIR__) . '/sql/035_ui_studio_global_history.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('035 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $tables = ['ui_studio_state', 'ui_studio_history'];
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
    );
    foreach ($tables as $table) {
        $check->execute([$table]);
        if ((int)$check->fetchColumn() !== 1) {
            throw new RuntimeException("Missing UI Studio table: {$table}");
        }
    }
    $stateRows = (int)$pdo->query('SELECT COUNT(*) FROM ui_studio_state')->fetchColumn();
    $clientRows = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    if ($stateRows < $clientRows) {
        $pdo->exec("INSERT IGNORE INTO ui_studio_state (client_id,revision,patches_json) SELECT id,0,'[]' FROM clients");
        $stateRows = (int)$pdo->query('SELECT COUNT(*) FROM ui_studio_state')->fetchColumn();
    }
    if ($stateRows < $clientRows) {
        throw new RuntimeException('UI Studio state rows are incomplete after migration.');
    }

    echo "035 UI Studio global history applied; {$clientRows} clients, {$stateRows} state rows ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '035 UI Studio global history migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
