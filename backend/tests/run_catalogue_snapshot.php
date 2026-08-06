<?php
declare(strict_types=1);

require_once __DIR__ . '/catalogue_snapshot_integration_test.php';

try {
    m24_run_tests();
    exit(0);
} catch (Throwable $exception) {
    echo 'M2.4 catalogue snapshot tests failed: ' . $exception->getMessage() . "\n";
    exit(1);
}
