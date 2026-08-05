<?php
declare(strict_types=1);

merd_test('JSON endpoints enforce content type and bounded numeric credentials', function (): void {
    merd_request_require_json_content_type(['CONTENT_TYPE' => 'application/json; charset=utf-8']);
    merd_assert_same('0012', merd_request_numeric_string('0012', 4));
    merd_assert_throws(MerdRequestException::class, static function (): void {
        merd_request_require_json_content_type(['CONTENT_TYPE' => 'text/plain']);
    });
    merd_assert_throws(MerdRequestException::class, static function (): void {
        merd_request_numeric_string('12ab', 4);
    });
});

merd_test('setup-key validation is exact and timing-safe compatible', function (): void {
    merd_assert(merd_setup_key_matches('setup-value', 'setup-value'));
    merd_assert(!merd_setup_key_matches('setup-value', 'setup-value '));
    merd_assert(!merd_setup_key_matches(null, 'setup-value'));
});

merd_test('device activation consumes a client-bound grant and persists only the token hash', function (): void {
    $grants = new MerdMemoryGrantStore();
    $devices = new MerdMemoryDeviceActivationStore();
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    $issued = merd_activation_grant_issue(
        $grants,
        4,
        $now,
        static fn (int $length): string => str_repeat("\x01", $length)
    );
    $activated = merd_activate_device(
        $grants,
        $devices,
        4,
        8,
        $issued['grant'],
        'device-a',
        'Counter A',
        $now,
        static fn (int $length): string => str_repeat("\x02", $length)
    );
    $row = $devices->devices['device-a'];
    merd_assert_same(merd_device_token_hash($activated['token']), $row['token_hash']);
    merd_assert(!in_array($activated['token'], $row, true), 'Plaintext token was persisted.');
    merd_assert_same($now->modify('+180 days')->getTimestamp(), $activated['expires_at']->getTimestamp());
    merd_assert_throws(MerdActivationDenied::class, static function () use (
        $grants,
        $devices,
        $issued,
        $now
    ): void {
        merd_activate_device($grants, $devices, 4, 8, $issued['grant'], 'device-b', 'Counter B', $now);
    });
});

merd_test('device activation rejects stores outside the validated client', function (): void {
    $grants = new MerdMemoryGrantStore();
    $devices = new MerdMemoryDeviceActivationStore();
    $devices->eligible = false;
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    $issued = merd_activation_grant_issue($grants, 4, $now);
    merd_assert_throws(MerdActivationDenied::class, static function () use (
        $grants,
        $devices,
        $issued,
        $now
    ): void {
        merd_activate_device($grants, $devices, 4, 99, $issued['grant'], 'device-a', 'Counter A', $now);
    });
});

merd_test('device token rotation preserves only the previous hash for seven days', function (): void {
    $grants = new MerdMemoryGrantStore();
    $devices = new MerdMemoryDeviceActivationStore();
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    $firstGrant = merd_activation_grant_issue($grants, 4, $now);
    $first = merd_activate_device($grants, $devices, 4, 8, $firstGrant['grant'], 'device-a', 'Counter A', $now);
    $secondGrant = merd_activation_grant_issue($grants, 4, $now->modify('+1 hour'));
    $second = merd_activate_device(
        $grants,
        $devices,
        4,
        8,
        $secondGrant['grant'],
        'device-a',
        'Counter A',
        $now->modify('+1 hour')
    );
    $row = $devices->devices['device-a'];
    merd_assert_same(merd_device_token_hash($first['token']), $row['previous_token_hash']);
    merd_assert_same(merd_device_token_hash($second['token']), $row['token_hash']);
    merd_assert_same(
        $now->modify('+7 days +1 hour')->getTimestamp(),
        $row['previous_token_valid_until']->getTimestamp()
    );
});

merd_test('employee authentication preserves numeric legacy migration and hashed verification', function (): void {
    $legacy = ['login_password' => '0012', 'pin_code' => '0012'];
    merd_assert(merd_employee_authenticates($legacy, '0012'));
    merd_assert(merd_employee_needs_hash_upgrade($legacy));
    $hash = password_hash('0012', PASSWORD_DEFAULT);
    $hashed = ['login_password' => $hash, 'pin_code' => $hash];
    merd_assert(merd_employee_authenticates($hashed, '0012'));
    merd_assert(!merd_employee_needs_hash_upgrade($hashed));
    merd_assert(!merd_employee_authenticates($hashed, '9999'));
});

merd_test('legacy activation token parsing remains centralized in device-auth helper', function (): void {
    foreach (['login.php', 'change_password.php'] as $endpoint) {
        $source = file_get_contents(__DIR__ . '/../api/' . $endpoint);
        merd_assert(is_string($source));
        merd_assert(strpos($source, "['activation_token']") === false, $endpoint . ' parsed a legacy token directly.');
        merd_assert(strpos($source, 'merd_device_auth_extract_token') !== false);
    }
});
