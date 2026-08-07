<?php
declare(strict_types=1);

require_once __DIR__ . '/split_sale_tender_migration_test.php';

try {
    m33_split_sale_tender_test();
    m33_partial_target_precondition_test();
    echo "M3.3 split sale tender tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'M3.3 split sale tender tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
