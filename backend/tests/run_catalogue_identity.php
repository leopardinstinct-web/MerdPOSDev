<?php
declare(strict_types=1);

require_once __DIR__ . '/catalogue_identity_migration_test.php';

try {
    m2_run_tests();
    exit(0);
} catch (Throwable $exception) {
    echo 'M2.1 synthetic migration tests failed: '
        . $exception->getMessage() . "\n";
    exit(1);
}
