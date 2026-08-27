<?php
declare(strict_types=1);

require_once __DIR__ . '/durable_sale_model_migration_test.php';

function m33_apply_migration(PDO $pdo): void
{
    m2_apply_sql_file($pdo, dirname(__DIR__) . '/sql/021_split_sale_tenders.sql');
}

function m33_split_sale_tender_test(): void
{
    $pdo = m31_reset_fixture();
    $pdo->exec(
        "INSERT INTO retail_sales "
        . "(client_id,store_id,local_sale_id,sale_number,cashier_id,cashier_name,subtotal,discount,tax,total,payment_method,status,device_uuid,sold_at,sale_uid) "
        . "VALUES (1,11,3301,'M33-SPLIT',9001,'Synthetic',100,0,9.09,100,'split','completed','device-m33','2026-08-07 12:00:00','11111111-2222-4333-8444-555555555555')"
    );
    $saleId = (int)$pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO retail_sale_tenders "
        . "(tender_uid,retail_sale_id,client_id,store_id,tender_type,currency_code,amount_due,amount_tendered,change_due,recorded_at_utc) "
        . "VALUES ('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',{$saleId},1,11,'cash','AUD',29,29,0,'2026-08-07 12:00:00')"
    );

    m33_apply_migration($pdo);

    m2_assert_same('1', m2_scalar($pdo, "SELECT sequence_number FROM retail_sale_tenders WHERE retail_sale_id={$saleId}"), 'M3.3 did not preserve the M3.1 tender as sequence 1.');
    $pdo->exec(
        "INSERT INTO retail_sale_tenders "
        . "(tender_uid,retail_sale_id,client_id,store_id,sequence_number,tender_type,currency_code,amount_due,amount_tendered,change_due,recorded_at_utc,actor_id,device_uuid) "
        . "VALUES ('ffffffff-1111-4222-8333-444444444444',{$saleId},1,11,2,'card_recorded','AUD',71,71,0,'2026-08-07 12:00:01','9001','device-m33')"
    );
    m2_assert_same('29.00:71.00', m2_scalar($pdo, "SELECT GROUP_CONCAT(amount_tendered ORDER BY sequence_number SEPARATOR ':') FROM retail_sale_tenders WHERE retail_sale_id={$saleId}"), 'M3.3 did not preserve real split tender components.');

    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_sale_tenders "
            . "(tender_uid,retail_sale_id,client_id,store_id,sequence_number,tender_type,currency_code,amount_due,amount_tendered,change_due,recorded_at_utc) "
            . "VALUES ('99999999-1111-4222-8333-444444444444',{$saleId},1,11,3,'card_recorded','AUD',1,2,1,'2026-08-07 12:00:02')"
        ),
        'M3.3 accepted card change semantics.'
    );
}

function m33_partial_target_precondition_test(): void
{
    $pdo = m31_reset_fixture();
    $pdo->exec('ALTER TABLE retail_sale_tenders ADD COLUMN external_reference VARCHAR(191) NULL');
    try {
        m33_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(str_contains($exception->getMessage(), 'already or partially exists'), 'M3.3 partial-target failure was not explicit.');
        return;
    }
    m2_fail('M3.3 migrated a partial target schema.');
}
