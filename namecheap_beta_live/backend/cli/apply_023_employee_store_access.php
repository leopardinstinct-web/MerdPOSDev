<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sql = file_get_contents(dirname(__DIR__) . '/sql/023_employee_store_access.sql');
    if ($sql === false) {
        throw new RuntimeException('023 SQL file could not be read.');
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $pdo->exec($statement);
    }

    $employeeCount = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
    $accessCount = (int)$pdo->query('SELECT COUNT(*) FROM employee_store_access')->fetchColumn();
    $allCount = (int)$pdo->query("SELECT COUNT(*) FROM employee_store_access WHERE access_mode='all'")->fetchColumn();

    if ($accessCount < $employeeCount) {
        throw new RuntimeException('Not every employee has a store-access row.');
    }

    echo "023 employee store access applied; {$accessCount} employees configured, {$allCount} with all-store access.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "023 employee store access failed: " . $e->getMessage() . "\n");
    exit(1);
}
