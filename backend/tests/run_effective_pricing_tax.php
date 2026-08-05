<?php
declare(strict_types=1);

require_once __DIR__ . '/effective_pricing_tax_migration_test.php';

try {
    m22_run_tests();
    exit(0);
} catch (Throwable $exception) {
    echo 'M2.2 synthetic migration tests failed: '
        . $exception->getMessage() . "\n";
    exit(1);
}
