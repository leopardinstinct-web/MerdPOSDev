<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/030_dev_client_preferences.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('030 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='dev_client_preferences'");
    if ((int)$table->fetchColumn() !== 1) {
        throw new RuntimeException('dev_client_preferences table is missing after migration.');
    }

    $devCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE UPPER(TRIM(employee_type))='DEV'")->fetchColumn();
    $prefCount = (int)$pdo->query('SELECT COUNT(*) FROM dev_client_preferences')->fetchColumn();
    $invalid = (int)$pdo->query(
        'SELECT COUNT(*) FROM dev_client_preferences p '
        . 'LEFT JOIN employees e ON e.id=p.employee_id '
        . 'LEFT JOIN clients a ON a.id=p.auth_client_id '
        . 'LEFT JOIN clients s ON s.id=p.selected_client_id '
        . "WHERE e.id IS NULL OR a.id IS NULL OR s.id IS NULL OR UPPER(TRIM(e.employee_type))<>'DEV' OR e.client_id<>p.auth_client_id"
    )->fetchColumn();
    if ($invalid !== 0) {
        throw new RuntimeException('Invalid DEV client preference rows detected.');
    }

    echo "030 DEV client preferences applied; {$devCount} DEV accounts and {$prefCount} preferences ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '030 DEV client preferences failed: ' . $e->getMessage() . "\n");
    exit(1);
}
