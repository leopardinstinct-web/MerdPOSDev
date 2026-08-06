<?php
declare(strict_types=1);

merd_test('sync request helpers reject malformed lists, counters, and timestamps', function (): void {
    merd_assert_same([], merd_request_list([]));
    merd_assert_same(0, merd_request_nonnegative_int(0));
    merd_assert_same('2026-08-05 12:30:00', merd_request_datetime('2026-08-05 12:30:00'));
    merd_assert_throws(MerdRequestException::class, static fn (): array => merd_request_list(['key' => 'value']));
    merd_assert_throws(MerdRequestException::class, static fn (): int => merd_request_nonnegative_int(-1));
    merd_assert_throws(MerdRequestException::class, static fn (): ?string => merd_request_datetime('not-a-date'));
});

merd_test('normal endpoints centralize device authorization and never parse legacy tokens', function (): void {
    foreach (['get_employees.php', 'get_working_now.php', 'sync_employee_logs.php', 'sync_shifts.php', 'sync_retail.php', 'sync_catalogue.php'] as $endpoint) {
        $source = file_get_contents(__DIR__ . '/../api/' . $endpoint);
        merd_assert(is_string($source));
        merd_assert(strpos($source, 'merd_device_authenticate_request') !== false, $endpoint . ' missed shared authorization.');
        merd_assert(strpos($source, "['activation_token']") === false, $endpoint . ' parsed a legacy token directly.');
        merd_assert(strpos($source, 'Access-Control-Allow-Origin: *') === false, $endpoint . ' retained wildcard CORS.');
    }
});

merd_test('device last-sync updates bind device and tenant identifiers', function (): void {
    $source = file_get_contents(__DIR__ . '/../api/includes/device_auth.php');
    merd_assert(is_string($source));
    merd_assert(strpos($source, 'WHERE id = ? AND client_id = ? AND store_id = ? AND device_uuid = ?') !== false);
});

merd_test('maintenance and debug utilities deny before loading configuration', function (): void {
    foreach (['import_actual_employees.php', 'import_timesheet_logs.php', 'init_employee_logs.php', 'init_db.php', 'cors_test.php', 'test_activate.php'] as $endpoint) {
        $source = file_get_contents(__DIR__ . '/../api/' . $endpoint);
        merd_assert(is_string($source));
        $guard = strpos($source, 'merd_maintenance_guard();');
        $config = strpos($source, 'config.php');
        merd_assert($guard !== false && $config !== false && $guard < $config, $endpoint . ' is not deny-first.');
    }
});
