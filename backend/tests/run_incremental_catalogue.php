<?php
declare(strict_types=1);

require_once __DIR__ . '/incremental_catalogue_sync_test.php';

try {
    m26_run_tests();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
