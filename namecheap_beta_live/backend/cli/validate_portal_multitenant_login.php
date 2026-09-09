<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$login = $root . '/timesheet_portal/api/login.php';
$runner = $root . '/browser_tests/live-dummy-attendance-e2e.js';
foreach ([$login, $runner] as $file) {
    if (!is_readable($file)) { fwrite(STDERR, "Missing login contract file: {$file}\n"); exit(1); }
}
$source = (string) file_get_contents($login);
$test = (string) file_get_contents($runner);
$checks = [
    [str_contains($source, 'merd_platform_identity_by_user_id($pdo, $userId)'), 'Platform identity namespace must be checked before client employees.'],
    [str_contains($source, "'identity_scope'=>'platform'"), 'Platform DEV login must establish a platform-scoped session.'],
    [str_contains($source, "FROM employees WHERE user_id=? AND status='active' AND UPPER(TRIM(employee_type))<>'DEV' ORDER BY client_id,id LIMIT 21"), 'Client login must resolve numeric User ID across active non-DEV tenant candidates.'],
    [!str_contains($source, "FROM employees WHERE client_id=? AND user_id=?"), 'Fixed PORTAL_CLIENT_ID login lookup must stay retired.'],
    [str_contains($source, '$authClientId = (int)$employee[\'client_id\'];'), 'Resolved employee tenant must become the authentication client.'],
    [str_contains($source, 'recordFailure(PORTAL_CLIENT_ID, null'), 'Pre-tenant failures must use one canonical lockout namespace.'],
    [!str_contains($source, 'foreach ($failureTargets'), 'Login failure handling must not lock every tenant sharing a User ID.'],
    [str_contains($source, 'recordSuccess(PORTAL_CLIENT_ID') && str_contains($source, 'recordSuccess($authClientId'), 'Successful login must reset global and resolved-tenant counters.'],
    [str_contains($test, 'DUMMY USER authoritative login preflight'), 'Attendance E2E must preflight DUMMY USER authentication before mutations.'],
    [str_contains($test, 'DUMMY SUPER authoritative login preflight'), 'Attendance E2E must preflight DUMMY SUPER authentication before mutations.'],
];
foreach ($checks as [$ok, $message]) {
    if (!$ok) { fwrite(STDERR, "Portal login tenant contract failed: {$message}\n"); exit(1); }
}
echo "MERDPOS portal multi-tenant login + DUMMY preflight contract validated.\n";
