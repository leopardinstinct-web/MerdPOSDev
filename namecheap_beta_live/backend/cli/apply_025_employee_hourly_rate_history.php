<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/025_employee_hourly_rate_history.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('025 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $seed = $pdo->prepare(
        'INSERT IGNORE INTO employee_hourly_rate_history '
        . '(client_id,employee_id,hourly_rate,effective_from,changed_by_employee_id) '
        . 'SELECT client_id,id,COALESCE(hourly_rate,0),?,NULL FROM employees'
    );
    $seed->execute(['1970-01-01']);

    $employeeCount = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
    $historyCount = (int)$pdo->query('SELECT COUNT(*) FROM employee_hourly_rate_history')->fetchColumn();
    echo "025 employee hourly rate history applied; {$employeeCount} employees present, {$historyCount} rate history rows.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "025 employee hourly rate history failed: " . $e->getMessage() . "\n");
    exit(1);
}
