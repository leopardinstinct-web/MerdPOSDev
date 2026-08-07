<?php
declare(strict_types=1);

require_once __DIR__ . '/durable_sale_model_migration_test.php';

try {
    m31_durable_sale_model_test();
    m31_partial_target_precondition_test();
    echo "M3.1 durable sale model tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'M3.1 durable sale model tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
