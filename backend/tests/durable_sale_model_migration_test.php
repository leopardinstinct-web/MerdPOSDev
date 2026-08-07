<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_ledger_balance_migration_test.php';

function m31_apply_migration(PDO $pdo): void
{
    m2_apply_sql_file($pdo, dirname(__DIR__) . '/sql/020_durable_sale_model.sql');
}

function m31_reset_fixture(): PDO
{
    $pdo = m23_reset_fixture();
    m31_apply_migration($pdo);
    return $pdo;
}

function m31_durable_sale_model_test(): void
{
    $pdo = m23_reset_fixture();
    $salesBefore = m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',sale_number,':',total) ORDER BY id) FROM retail_sales");
    $linesBefore = m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',product_id,':',line_total) ORDER BY id) FROM retail_sale_lines");
    $stockBefore = m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements');

    m31_apply_migration($pdo);

    m2_assert_same($salesBefore, m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',sale_number,':',total) ORDER BY id) FROM retail_sales"), 'M3.1 changed completed sales.');
    m2_assert_same($linesBefore, m2_scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',product_id,':',line_total) ORDER BY id) FROM retail_sale_lines"), 'M3.1 changed completed sale lines.');
    m2_assert_same($stockBefore, m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements'), 'M3.1 changed stock history.');
    m2_assert_same('0', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_sale_tenders'), 'M3.1 synthesized tenders for historical sales.');
    m2_assert_same('0', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_sales WHERE sale_uid IS NOT NULL'), 'M3.1 rewrote historical sale identity.');

    $saleUid = '018f3f10-7b18-7e9a-9f42-123456789abc';
    $pdo->prepare(
        'INSERT INTO retail_sales '
        . '(client_id,store_id,local_sale_id,sale_number,cashier_id,cashier_name,subtotal,discount,tax,total,payment_method,status,device_uuid,sold_at,'
        . 'sale_uid,occurred_at_utc,currency_code,subtotal_exact,manual_discount_exact,tax_exact,total_exact,receipt_contract_version) '
        . "VALUES (1,11,77,'M3-TEST',9001,'Synthetic Cashier',10,0,0.91,10,'cash','completed','device-a','2026-08-07 10:00:00',?,"
        . "'2026-08-07 10:00:00.123456','AUD',10,0,0.91,10,'m3.receipt.v1')"
    )->execute([$saleUid]);
    $saleId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO retail_sale_tenders '
        . '(tender_uid,retail_sale_id,client_id,store_id,tender_type,currency_code,amount_due,amount_tendered,change_due,recorded_at_utc) '
        . "VALUES ('018f3f10-7b18-7e9a-9f42-abcdef123456',?,1,11,'cash','AUD',10,20,10,'2026-08-07 10:00:00.123456')"
    )->execute([$saleId]);
    m2_assert_same('10.00:20.00:10.00', m2_scalar($pdo, "SELECT CONCAT(amount_due,':',amount_tendered,':',change_due) FROM retail_sale_tenders WHERE retail_sale_id={$saleId}"), 'Exact tender amounts were not preserved.');

    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_sales (client_id,store_id,local_sale_id,sale_number,cashier_id,cashier_name,subtotal,discount,tax,total,payment_method,status,device_uuid,sold_at,sale_uid) "
            . "VALUES (1,11,78,'M3-DUP',9001,'Synthetic',1,0,0,1,'cash','completed','device-b','2026-08-07 10:01:00','{$saleUid}')"
        ),
        'Duplicate durable sale identity was accepted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_sale_tenders SET tender_type='card_recorded' WHERE retail_sale_id={$saleId}"),
        'Card-recorded tender accepted cash-change semantics.'
    );
}

function m31_partial_target_precondition_test(): void
{
    $pdo = m23_reset_fixture();
    $pdo->exec('ALTER TABLE retail_sales ADD COLUMN sale_uid CHAR(36) NULL');
    try {
        m31_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(str_contains($exception->getMessage(), 'partial durable sale target'), 'M3.1 partial-target failure was not explicit.');
        return;
    }
    m2_fail('M3.1 migrated a partial target schema.');
}
