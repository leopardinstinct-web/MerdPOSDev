<?php
declare(strict_types=1);

merd_test('per-device lockout begins on the fifth failure and actions stay separate', function (): void {
    $store = new MerdMemoryLockoutStore();
    $service = new MerdAuthLockoutService($store);
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $service->recordFailure(1, 10, '1007', 'device-a', 'login', $now);
    }
    merd_assert_throws(MerdAuthLocked::class, static function () use ($service, $now): void {
        $service->assertNotLocked(1, '1007', 'device-a', 'login', $now);
    });
    $service->assertNotLocked(1, '1007', 'device-a', 'change_password', $now);
});

merd_test('employee-wide lockout counts fifteen failures across devices in thirty minutes', function (): void {
    $store = new MerdMemoryLockoutStore();
    $service = new MerdAuthLockoutService($store);
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    for ($attempt = 1; $attempt <= 15; $attempt++) {
        $service->recordFailure(1, 10, '1007', 'device-' . $attempt, 'login', $now->modify('+' . $attempt . ' seconds'));
    }
    merd_assert_throws(MerdAuthLocked::class, static function () use ($service, $now): void {
        $service->assertNotLocked(1, '1007', 'new-device', 'login', $now->modify('+1 minute'));
    });
});

merd_test('missing security tables fail closed', function (): void {
    $store = new MerdMemoryLockoutStore();
    $store->available = false;
    $service = new MerdAuthLockoutService($store);
    merd_assert_throws(MerdSecurityControlUnavailable::class, static function () use ($service): void {
        $service->assertNotLocked(
            1,
            '1007',
            'device-a',
            'login',
            new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'))
        );
    });
});
