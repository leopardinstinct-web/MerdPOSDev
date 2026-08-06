<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_ledger_balance_migration_test.php';

try {
    m23_run_tests();
    exit(0);
} catch (Throwable $exception) {
    echo 'M2.3 synthetic migration tests failed: '
        . $exception->getMessage() . "\n";
    exit(1);
}
