<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/activation_grant_test.php';
require_once __DIR__ . '/device_auth_test.php';
require_once __DIR__ . '/auth_lockout_test.php';
require_once __DIR__ . '/security_foundation_test.php';
require_once __DIR__ . '/endpoint_integration_test.php';
require_once __DIR__ . '/endpoint_hardening_test.php';
require_once __DIR__ . '/workforce_beta_test.php';

$passed = 0;
$failed = 0;
foreach ($GLOBALS['merd_tests'] as [$name, $test]) {
    try {
        $test();
        $passed++;
        echo "PASS: {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL: {$name}\n";
    }
}
echo "Tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
