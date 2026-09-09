<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // MySQL DDL such as ALTER TABLE performs an implicit commit, so it must not
    // be wrapped in the transaction used for the data update below.
    $pdo->exec("ALTER TABLE employees MODIFY COLUMN employee_type VARCHAR(20) NULL");

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "UPDATE employees SET employee_type='DEV', role_name='Developer' "
        . "WHERE client_id=? AND full_name=? AND status='active'"
    );
    $stmt->execute([1, 'Imran']);

    $verify = $pdo->prepare(
        "SELECT employee_type, role_name FROM employees "
        . "WHERE client_id=? AND full_name=? AND status='active' LIMIT 1"
    );
    $verify->execute([1, 'Imran']);
    $role = $verify->fetch(PDO::FETCH_ASSOC);

    if (is_array($role)) {
        if (strtoupper((string)$role['employee_type']) !== 'DEV') {
            throw new RuntimeException('DEV role verification failed.');
        }
        $pdo->commit();
        echo "022 management roles applied; legacy DEV employee verified.\n";
        exit(0);
    }

    $platformTable = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='platform_identities'"
    );
    $platformTable->execute();
    $platformCount = 0;
    if ((int)$platformTable->fetchColumn() === 1) {
        $platformCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM platform_identities WHERE role_key='DEV' AND status='active'"
        )->fetchColumn();
    }
    if ($platformCount < 1) {
        throw new RuntimeException('Neither the legacy DEV employee nor an active platform DEV identity was found.');
    }

    $pdo->commit();
    echo "022 management roles already superseded; active platform DEV identity verified.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "022 management roles failed: " . $e->getMessage() . "\n");
    exit(1);
}
