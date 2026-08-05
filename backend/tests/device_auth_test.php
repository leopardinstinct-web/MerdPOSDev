<?php
declare(strict_types=1);

merd_test('device token extraction prefers matching bearer and supports legacy only in helper', function (): void {
    $bearer = merd_device_auth_extract_token(['HTTP_AUTHORIZATION' => 'Bearer secret-value'], ['activation_token' => 'secret-value']);
    merd_assert_same('bearer', $bearer['transport']);
    $legacy = merd_device_auth_extract_token([], [], ['activation_token' => 'legacy-value']);
    merd_assert_same('legacy', $legacy['transport']);
    merd_assert_throws(MerdRequestException::class, static function (): void {
        merd_device_auth_extract_token(['HTTP_AUTHORIZATION' => 'Bearer one'], ['activation_token' => 'two']);
    });
    merd_assert_throws(MerdRequestException::class, static function (): void {
        merd_device_auth_extract_token([], ['activation_token' => ['not', 'scalar']]);
    });
});

merd_test('device authorization binds token hash, UUID, client, store, status, and expiry', function (): void {
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    $token = 'test-token-not-logged';
    $store = new MerdMemoryDeviceStore([
        'id' => 9,
        'client_id' => 3,
        'store_id' => 4,
        'device_uuid' => 'device-a',
        'status' => 'active',
        'revoked_at' => null,
        'token_hash' => merd_device_token_hash($token),
        'token_expires_at' => '2026-08-06 12:00:00',
        'previous_token_hash' => null,
        'previous_token_valid_until' => null,
    ]);
    merd_assert(merd_device_authorize($store, 3, 4, 'device-a', $token, $now) !== null);
    merd_assert(merd_device_authorize($store, 3, 4, 'device-b', $token, $now) === null);
    merd_assert(merd_device_authorize($store, 3, 5, 'device-a', $token, $now) === null);
    merd_assert(merd_device_authorize($store, 8, 4, 'device-a', $token, $now) === null);
    merd_assert(merd_device_authorize($store, 3, 4, 'device-a', 'wrong', $now) === null);
});

merd_test('previous device token is accepted only inside overlap window', function (): void {
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    $token = 'previous-value';
    $store = new MerdMemoryDeviceStore([
        'id' => 9, 'client_id' => 3, 'store_id' => 4, 'device_uuid' => 'device-a',
        'status' => 'active', 'revoked_at' => null,
        'token_hash' => merd_device_token_hash('current-value'),
        'token_expires_at' => '2027-01-01 00:00:00',
        'previous_token_hash' => merd_device_token_hash($token),
        'previous_token_valid_until' => '2026-08-06 12:00:00',
    ]);
    merd_assert(merd_device_authorize($store, 3, 4, 'device-a', $token, $now) !== null);
    merd_assert(merd_device_authorize($store, 3, 4, 'device-a', $token, $now->modify('+2 days')) === null);
});
