<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->beginTransaction();
    $pdo->exec("ALTER TABLE employees MODIFY COLUMN employee_type VARCHAR(20) NULL");
    $stmt = $pdo->prepare(
        "UPDATE employees SET employee_type='DEV', role_name='Developer' "
        . "WHERE client_id=? AND full_name=? AND status='active'"
    );
    $stmt->execute([1, 'Imran']);
    $pdo->commit();
    echo "022 management roles applied; DEV rows updated: " . $stmt->rowCount() . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "022 management roles failed.\n");
    exit(1);
}
