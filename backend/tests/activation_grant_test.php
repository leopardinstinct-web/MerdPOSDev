<?php
declare(strict_types=1);

merd_test('activation grants are hashed, expire after ten minutes, and are single use', function (): void {
    $store = new MerdMemoryGrantStore();
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    $issued = merd_activation_grant_issue(
        $store,
        7,
        $now,
        static fn (int $length): string => str_repeat("\x01", $length)
    );
    $grant = $issued['grant'];
    $hash = hash('sha256', $grant);
    merd_assert(isset($store->rows[$hash]), 'Grant hash was not persisted.');
    merd_assert(!isset($store->rows[$grant]), 'Plaintext grant was persisted.');
    merd_assert_same($now->modify('+10 minutes')->getTimestamp(), $issued['expires_at']->getTimestamp());
    merd_assert(merd_activation_grant_consume($store, 7, $grant, $now->modify('+9 minutes')));
    merd_assert(!merd_activation_grant_consume($store, 7, $grant, $now->modify('+9 minutes')));
});

merd_test('expired activation grants fail', function (): void {
    $store = new MerdMemoryGrantStore();
    $now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    $issued = merd_activation_grant_issue($store, 7, $now, static fn (int $length): string => str_repeat("\x02", $length));
    merd_assert(!merd_activation_grant_consume($store, 7, $issued['grant'], $now->modify('+10 minutes')));
});
