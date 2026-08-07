<?php
declare(strict_types=1);

require_once __DIR__ . '/catalogue_snapshot_integration_test.php';
require_once __DIR__ . '/../api/includes/catalogue_incremental.php';

function m26_reset_fixture(): PDO
{
    $pdo = m24_reset_fixture();
    $migration = file_get_contents(__DIR__ . '/../sql/019_incremental_catalogue_sync.sql');
    m2_assert(is_string($migration), 'M2.6 migration could not be read.');
    $pdo->exec($migration);
    return $pdo;
}

function m26_auth(PDO $pdo): array
{
    $request = m24_request();
    return merd_device_authenticate_request($pdo, $request['server'], $request['body']);
}

function m26_cursor_and_pagination_test(): void
{
    $pdo = m26_reset_fixture();
    $auth = m26_auth($pdo);
    $now = new DateTimeImmutable('2026-08-06 12:00:00.000000', new DateTimeZone('UTC'));
    $full = merd_catalogue_register_snapshot(
        $pdo,
        merd_catalogue_full_snapshot($pdo, $auth, $now),
        1,
        11,
        $now
    );
    m2_assert((bool)preg_match('/^m2c1_[A-Za-z0-9_-]{43}$/', $full['cursor_seed']), 'Server cursor is not opaque.');

    $pdo->exec("UPDATE retail_products SET name='Incremental update' WHERE id=1001");
    $target = merd_catalogue_full_snapshot($pdo, $auth, $now->modify('+1 minute'));
    $target['snapshot']['products'] = array_values(array_filter(
        $target['snapshot']['products'],
        static fn (array $product): bool => $product['id'] !== 1005
    ));
    $target['snapshot_revision'] = 'sha256:' . str_repeat('c', 64);
    $source = merd_catalogue_cursor_row($pdo, 1, 11, $full['cursor_seed'], $now);
    $batch = merd_catalogue_create_batch($pdo, 1, 11, $source, $target, 1, $now);
    m2_assert(count($batch['events']) >= 2, 'Incremental changes were not detected.');
    $productUpdates = array_values(array_filter(
        $batch['events'],
        static fn (array $event): bool => $event['entity_type'] === 'product'
    ));
    m2_assert_same('upsert', $productUpdates[0]['operation'], 'Product update did not become an upsert.');
    m2_assert_same('tombstone', $productUpdates[count($productUpdates) - 1]['operation'], 'Missing product did not become a tombstone.');

    $first = merd_catalogue_incremental_response($batch, 0, $auth, $now);
    $replay = merd_catalogue_incremental_response($batch, 0, $auth, $now);
    m2_assert_same($first, $replay, 'Incremental page replay changed content.');
    m2_assert_same(true, $first['has_more'], 'One-event page did not paginate.');
    m2_assert_same(null, $first['next_cursor'], 'Cursor advanced before the final page.');
    $lastIndex = $first['page_count'] - 1;
    $last = merd_catalogue_incremental_response($batch, $lastIndex, $auth, $now);
    m2_assert_same(false, $last['has_more'], 'Final page incorrectly has a continuation.');
    m2_assert_same($batch['target_cursor_token'], $last['next_cursor'], 'Final page did not advance the target cursor.');
}

function m26_scope_expiry_and_no_change_test(): void
{
    $pdo = m26_reset_fixture();
    $auth = m26_auth($pdo);
    $now = new DateTimeImmutable('2026-08-06 12:00:00.000000', new DateTimeZone('UTC'));
    $full = merd_catalogue_register_snapshot($pdo, merd_catalogue_full_snapshot($pdo, $auth, $now), 1, 11, $now);
    $source = merd_catalogue_cursor_row($pdo, 1, 11, $full['cursor_seed'], $now);
    $target = merd_catalogue_full_snapshot($pdo, $auth, $now->modify('+1 second'));
    $batch = merd_catalogue_create_batch($pdo, 1, 11, $source, $target, 100, $now);
    m2_assert_same([], $batch['events'], 'Unchanged catalogue produced incremental events.');
    $page = merd_catalogue_incremental_response($batch, 0, $auth, $now);
    m2_assert_same(1, $page['page_count'], 'Empty incremental result did not produce one final page.');
    m2_assert_same($full['cursor_seed'], $page['next_cursor'], 'Unchanged catalogue unexpectedly rotated cursor.');

    foreach ([[2, 21], [1, 12]] as [$clientId, $storeId]) {
        try {
            merd_catalogue_cursor_row($pdo, $clientId, $storeId, $full['cursor_seed'], $now);
            m2_fail('Wrong-scope cursor was accepted.');
        } catch (MerdRequestException $exception) {
            m2_assert_same('catalogue_cursor_expired', $exception->errorCode, 'Wrong-scope cursor leaked tenant state.');
        }
    }
    $pdo->prepare('UPDATE retail_catalogue_cursor_snapshots SET expires_at_utc=? WHERE cursor_token=?')
        ->execute(['2026-08-06 11:59:59.000000', $full['cursor_seed']]);
    try {
        merd_catalogue_cursor_row($pdo, 1, 11, $full['cursor_seed'], $now);
        m2_fail('Expired cursor was accepted.');
    } catch (MerdRequestException $exception) {
        m2_assert_same('catalogue_cursor_expired', $exception->errorCode, 'Expired cursor did not request full resync.');
    }
}

function m26_migration_safety_test(): void
{
    $pdo = m24_reset_fixture();
    $stockMovementCount = m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements');
    $migration = file_get_contents(__DIR__ . '/../sql/019_incremental_catalogue_sync.sql');
    $pdo->exec((string)$migration);
    m2_assert_same('1', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_sales WHERE id=401'), 'M2.6 migration changed sale history.');
    m2_assert_same($stockMovementCount, m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements'), 'M2.6 migration changed stock history.');
    m2_assert_same('0', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_catalogue_cursor_snapshots'), 'M2.6 migration seeded runtime cursors.');
    $pdo->exec((string)$migration);
    m2_assert_same('2', m2_scalar($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('retail_catalogue_cursor_snapshots','retail_catalogue_sync_batches')"), 'M2.6 migration is not replay-safe.');
}

function m26_run_tests(): void
{
    m26_migration_safety_test();
    m26_cursor_and_pagination_test();
    m26_scope_expiry_and_no_change_test();
    echo "M2.6 incremental catalogue synthetic integration tests passed.\n";
}
