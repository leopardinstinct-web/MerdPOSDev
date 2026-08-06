<?php
declare(strict_types=1);

require_once __DIR__ . '/effective_pricing_tax_migration_test.php';

function m23_apply_migration(PDO $pdo): void
{
    m2_apply_sql_file($pdo, dirname(__DIR__) . '/sql/018_stock_ledger_balance.sql');
}

function m23_reset_post_m22_fixture(): PDO
{
    $pdo = m22_reset_post_m21_fixture();
    m22_apply_migration($pdo);
    return $pdo;
}

function m23_reset_fixture(): PDO
{
    $pdo = m23_reset_post_m22_fixture();
    m23_apply_migration($pdo);
    return $pdo;
}

function m23_expect_precondition(string $expected, callable $mutate): void
{
    $pdo = m23_reset_post_m22_fixture();
    $mutate($pdo);
    try {
        m23_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(
            str_contains($exception->getMessage(), $expected),
            'M2.3 failed without its expected visible precondition message.'
        );
        return;
    }
    m2_fail('An incompatible synthetic M2.3 schema unexpectedly migrated.');
}

function m23_insert_movement(PDO $pdo, array $overrides = []): int
{
    static $sequence = 0;
    $sequence++;
    $values = array_merge([
        'client_id' => 1,
        'store_id' => 11,
        'product_id' => 1001,
        'movement_type' => 'sale',
        'signed_quantity' => '-1.000',
        'balance_before' => '0.000',
        'balance_after' => '0.000',
        'balance_revision' => 0,
        'source_type' => 'synthetic_sale',
        'source_record_key' => 'sale-' . $sequence,
        'idempotency_key' => 'idem-' . $sequence,
        'device_uuid' => 'synthetic-device',
        'retail_sale_id' => null,
        'purchase_order_id' => null,
        'purchase_order_line_id' => null,
        'legacy_stock_movement_id' => null,
        'legacy_inventory_id' => null,
        'actor_type' => 'employee',
        'actor_id' => '9001',
        'reason_code' => null,
        'note' => null,
        'occurred_at_utc' => '2026-08-01 10:00:00.000000',
        'reversal_of_movement_id' => null,
        'transfer_id' => null,
        'transfer_leg' => null,
        'metadata_json' => '{"synthetic":true}',
    ], $overrides);

    $columns = array_keys($values);
    $statement = $pdo->prepare(
        'INSERT INTO retail_stock_ledger_movements (' . implode(',', $columns) . ') '
        . 'VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
    );
    $statement->execute(array_values($values));
    return (int)$pdo->lastInsertId();
}

function m23_preservation_and_opening_test(): void
{
    $pdo = m23_reset_post_m22_fixture();
    $inventory = m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',quantity) ORDER BY id) FROM retail_store_inventory");
    $sales = m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',total) ORDER BY id) FROM retail_sales");
    $purchases = m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',quantity_received) ORDER BY id) FROM retail_purchase_order_lines");
    $legacyMovements = m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_movements');
    $priceStructures = m2_scalar($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('retail_price_versions','retail_tax_codes','retail_tax_rate_versions','retail_product_tax_assignments')");

    m23_apply_migration($pdo);

    m2_assert_same($inventory, m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',quantity) ORDER BY id) FROM retail_store_inventory"), 'Legacy inventory changed.');
    m2_assert_same($sales, m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',total) ORDER BY id) FROM retail_sales"), 'Existing sales changed.');
    m2_assert_same($purchases, m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',quantity_received) ORDER BY id) FROM retail_purchase_order_lines"), 'Purchase-order lines changed.');
    m2_assert_same($legacyMovements, m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_movements'), 'Legacy movements changed.');
    m2_assert_same($priceStructures, '4', 'M2.2 structures were not preserved.');
    m2_assert_same('0', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_balances'), 'Migration silently created balances.');
    m2_assert_same('0', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_reconciliation_candidates'), 'Migration silently created reconciliation candidates.');

    m23_insert_movement($pdo, [
        'movement_type' => 'opening_balance',
        'signed_quantity' => '12.500',
        'source_type' => 'reviewed_opening',
        'source_record_key' => 'opening-11-1001',
        'idempotency_key' => 'opening-11-1001',
        'device_uuid' => null,
        'reason_code' => 'reviewed_legacy_opening',
        'legacy_inventory_id' => 301,
    ]);
    m2_assert_same('12.500', m2_scalar($pdo, 'SELECT CAST(quantity AS CHAR) FROM retail_stock_balances WHERE client_id=1 AND store_id=11 AND product_id=1001'), 'Opening balance did not update maintained stock.');
    m2_assert_same('1', m2_scalar($pdo, 'SELECT revision FROM retail_stock_balances WHERE client_id=1 AND store_id=11 AND product_id=1001'), 'Opening balance revision is wrong.');
    m2_expect_database_failure(
        fn () => m23_insert_movement($pdo, [
            'movement_type' => 'opening_balance',
            'signed_quantity' => '1.000',
            'source_type' => 'reviewed_opening',
            'source_record_key' => 'second-opening',
            'idempotency_key' => 'second-opening',
            'device_uuid' => null,
            'reason_code' => 'attempted_second_opening',
        ]),
        'A second opening balance was accepted.'
    );
}

function m23_idempotency_and_isolation_test(): void
{
    $pdo = m23_reset_fixture();
    $sale = m23_insert_movement($pdo, [
        'source_record_key' => 'sale-repeat',
        'idempotency_key' => 'stable-sale-key',
        'retail_sale_id' => 401,
    ]);
    m2_expect_database_failure(
        fn () => m23_insert_movement($pdo, [
            'source_record_key' => 'sale-repeat-transport-retry',
            'idempotency_key' => 'stable-sale-key',
        ]),
        'A repeated sale idempotency key created another effect.'
    );
    m2_assert_same('1', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements'), 'Repeated sale changed movement count.');
    m2_assert_same((string)$sale, m2_scalar($pdo, "SELECT id FROM retail_stock_ledger_movements WHERE idempotency_key='stable-sale-key'"), 'Original idempotent result is not resolvable.');

    m23_insert_movement($pdo, [
        'store_id' => 12,
        'movement_type' => 'purchase_receiving',
        'signed_quantity' => '4.000',
        'source_type' => 'purchase_receipt',
        'source_record_key' => 'receipt-1',
        'idempotency_key' => 'stable-sale-key',
    ]);
    m23_insert_movement($pdo, [
        'client_id' => 2,
        'store_id' => 21,
        'product_id' => 2001,
        'movement_type' => 'purchase_receiving',
        'signed_quantity' => '3.000',
        'source_type' => 'purchase_receipt',
        'source_record_key' => 'receipt-1',
        'idempotency_key' => 'stable-sale-key',
    ]);
    m2_assert_same('3', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements'), 'Client/store-scoped idempotency rejected valid independent movements.');
    m2_expect_database_failure(
        fn () => m23_insert_movement($pdo, [
            'client_id' => 2,
            'store_id' => 11,
            'product_id' => 2001,
            'idempotency_key' => 'wrong-tenant',
            'source_record_key' => 'wrong-tenant',
        ]),
        'A cross-tenant store movement was accepted.'
    );
}

function m23_offline_negative_and_reversal_test(): void
{
    $pdo = m23_reset_fixture();
    $first = m23_insert_movement($pdo, [
        'signed_quantity' => '-2.000',
        'source_record_key' => 'offline-sale-newer',
        'idempotency_key' => 'offline-sale-newer',
        'occurred_at_utc' => '2026-08-02 12:00:00.000000',
    ]);
    m23_insert_movement($pdo, [
        'signed_quantity' => '-3.000',
        'source_record_key' => 'offline-sale-late',
        'idempotency_key' => 'offline-sale-late',
        'occurred_at_utc' => '2026-07-31 12:00:00.000000',
    ]);
    m2_assert_same('-5.000', m2_scalar($pdo, 'SELECT CAST(quantity AS CHAR) FROM retail_stock_balances WHERE client_id=1 AND store_id=11 AND product_id=1001'), 'Late offline movement was not applied in accepted server order.');
    m2_assert_same('2', m2_scalar($pdo, 'SELECT revision FROM retail_stock_balances WHERE client_id=1 AND store_id=11 AND product_id=1001'), 'Server revision did not order accepted movements.');
    m2_assert_same('-5.000', m2_scalar($pdo, 'SELECT CAST(lowest_observed_balance AS CHAR) FROM retail_negative_stock_exceptions'), 'Lowest negative balance was not retained.');
    m2_assert_same('open', m2_scalar($pdo, 'SELECT status FROM retail_negative_stock_exceptions'), 'Negative stock was not flagged open.');

    m23_insert_movement($pdo, [
        'movement_type' => 'reversal',
        'signed_quantity' => '2.000',
        'source_type' => 'stock_reversal',
        'source_record_key' => 'reverse-' . $first,
        'idempotency_key' => 'reverse-' . $first,
        'reason_code' => 'duplicate_offline_sale',
        'reversal_of_movement_id' => $first,
    ]);
    m2_expect_database_failure(
        fn () => m23_insert_movement($pdo, [
            'movement_type' => 'reversal',
            'signed_quantity' => '2.000',
            'source_type' => 'stock_reversal',
            'source_record_key' => 'reverse-again-' . $first,
            'idempotency_key' => 'reverse-again-' . $first,
            'reason_code' => 'repeat_attempt',
            'reversal_of_movement_id' => $first,
        ]),
        'A movement was reversed twice.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_stock_ledger_movements SET note='edited' WHERE id={$first}"),
        'A posted movement was edited.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("DELETE FROM retail_stock_ledger_movements WHERE id={$first}"),
        'A posted movement was deleted.'
    );
}

function m23_adjustment_receiving_transfer_and_consistency_test(): void
{
    $pdo = m23_reset_fixture();
    m2_expect_database_failure(
        fn () => m23_insert_movement($pdo, [
            'movement_type' => 'adjustment_increase',
            'signed_quantity' => '1.000',
            'source_type' => 'manual_adjustment',
            'source_record_key' => 'adjust-no-reason',
            'idempotency_key' => 'adjust-no-reason',
        ]),
        'A manual adjustment without a reason was accepted.'
    );
    m23_insert_movement($pdo, [
        'movement_type' => 'adjustment_increase',
        'signed_quantity' => '2.000',
        'source_type' => 'manual_adjustment',
        'source_record_key' => 'adjust-reviewed',
        'idempotency_key' => 'adjust-reviewed',
        'reason_code' => 'cycle_count_gain',
        'note' => 'Synthetic reviewed adjustment',
    ]);
    m23_insert_movement($pdo, [
        'movement_type' => 'purchase_receiving',
        'signed_quantity' => '5.000',
        'source_type' => 'purchase_receipt',
        'source_record_key' => 'receipt-repeat',
        'idempotency_key' => 'receipt-repeat',
        'purchase_order_id' => 701,
        'purchase_order_line_id' => 801,
    ]);
    m2_expect_database_failure(
        fn () => m23_insert_movement($pdo, [
            'movement_type' => 'purchase_receiving',
            'signed_quantity' => '5.000',
            'source_type' => 'purchase_receipt',
            'source_record_key' => 'receipt-repeat-transport',
            'idempotency_key' => 'receipt-repeat',
        ]),
        'Repeated receiving created a second stock effect.'
    );

    $pdo->exec(
        "INSERT INTO retail_stock_transfers "
        . "(client_id,transfer_key,source_store_id,destination_store_id,product_id,quantity,status,created_by_actor_type,created_by_actor_id) "
        . "VALUES (1,'transfer-1',11,12,1001,2.000,'draft','employee','9001')"
    );
    $transferId = (int)$pdo->lastInsertId();
    m23_insert_movement($pdo, [
        'movement_type' => 'transfer_out',
        'signed_quantity' => '-2.000',
        'source_type' => 'stock_transfer',
        'source_record_key' => 'transfer-1-out',
        'idempotency_key' => 'transfer-1-out',
        'transfer_id' => $transferId,
        'transfer_leg' => 'out',
    ]);
    m23_insert_movement($pdo, [
        'store_id' => 12,
        'movement_type' => 'transfer_in',
        'signed_quantity' => '2.000',
        'source_type' => 'stock_transfer',
        'source_record_key' => 'transfer-1-in',
        'idempotency_key' => 'transfer-1-in',
        'transfer_id' => $transferId,
        'transfer_leg' => 'in',
    ]);
    m2_assert_same('in,out', m2_scalar($pdo, "SELECT GROUP_CONCAT(transfer_leg ORDER BY transfer_leg) FROM retail_stock_ledger_movements WHERE transfer_id={$transferId}"), 'Transfer legs were not linked.');
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_stock_transfers SET quantity=3 WHERE id={$transferId}"),
        'Posted transfer quantity was mutable.'
    );

    $mismatchCount = m2_scalar(
        $pdo,
        'SELECT COUNT(*) FROM retail_stock_balances b WHERE b.quantity <> '
        . '(SELECT COALESCE(SUM(m.signed_quantity),0) FROM retail_stock_ledger_movements m '
        . 'WHERE m.client_id=b.client_id AND m.store_id=b.store_id AND m.product_id=b.product_id)'
    );
    m2_assert_same('0', $mismatchCount, 'Maintained balances do not equal ledger totals.');
    m2_expect_database_failure(
        fn () => m23_insert_movement($pdo, [
            'product_id' => 999999,
            'source_record_key' => 'missing-product',
            'idempotency_key' => 'missing-product',
        ]),
        'A movement referencing a missing product was accepted.'
    );
}

function m23_precondition_and_rerun_test(): void
{
    m23_expect_precondition(
        'required M2.2 tables are missing',
        fn (PDO $pdo) => $pdo->exec('DROP TABLE retail_product_tax_assignments')
    );
    m23_expect_precondition(
        'legacy inventory shape is incompatible',
        fn (PDO $pdo) => $pdo->exec('ALTER TABLE retail_store_inventory MODIFY quantity DECIMAL(11,2) NOT NULL DEFAULT 0')
    );

    $pdo = m23_reset_fixture();
    try {
        m23_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(str_contains($exception->getMessage(), 'target tables already exist'), 'Raw rerun did not fail visibly.');
        return;
    }
    m2_fail('Raw M2.3 migration rerun unexpectedly succeeded.');
}

function m23_run_tests(): void
{
    m23_preservation_and_opening_test();
    m23_idempotency_and_isolation_test();
    m23_offline_negative_and_reversal_test();
    m23_adjustment_receiving_transfer_and_consistency_test();
    m23_precondition_and_rerun_test();
    echo "M2.3 stock ledger/balance synthetic migration tests passed.\n";
}
