<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_convergence_test.php';

try {
    m27_stock_convergence_test();
    echo "M2.7 stock convergence tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'M2.7 stock convergence tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
