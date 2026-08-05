<?php
declare(strict_types=1);

require_once __DIR__ . '/catalogue_identity_migration_test.php';

function m22_apply_fixture(PDO $pdo): void
{
    m2_apply_sql_file($pdo, __DIR__ . '/fixtures/effective_pricing_tax_schema.sql');
}

function m22_apply_migration(PDO $pdo): void
{
    m2_apply_sql_file($pdo, dirname(__DIR__) . '/sql/017_effective_pricing_tax.sql');
}

function m22_reset_post_m21_fixture(): PDO
{
    $pdo = m2_reset_fixture();
    m2_apply_migration($pdo);
    m22_apply_fixture($pdo);
    return $pdo;
}

function m22_expect_precondition(string $expected, callable $mutate): void
{
    $pdo = m22_reset_post_m21_fixture();
    $mutate($pdo);
    try {
        m22_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(
            str_contains($exception->getMessage(), $expected),
            'M2.2 failed without its expected visible precondition message.'
        );
        return;
    }
    m2_fail('An incompatible synthetic M2.2 schema unexpectedly migrated.');
}

function m22_insert_price(
    PDO $pdo,
    int $clientId,
    int $productId,
    ?int $storeId,
    string $type,
    string $amount,
    string $from,
    ?string $to,
    string $status = 'published',
    ?string $promotionName = null
): int {
    $statement = $pdo->prepare(
        'INSERT INTO retail_price_versions '
        . '(client_id,product_id,store_id,price_type,unit_price,currency_code,'
        . 'effective_from_utc,effective_to_utc,status,promotion_name,created_by) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $statement->execute([
        $clientId,
        $productId,
        $storeId,
        $type,
        $amount,
        'AUD',
        $from,
        $to,
        $status,
        $promotionName,
        9001,
    ]);
    return (int)$pdo->lastInsertId();
}

function m22_price_and_currency_test(PDO $pdo): array
{
    m2_assert_same(
        '1:AUD,2:AUD',
        m2_scalar(
            $pdo,
            "SELECT GROUP_CONCAT(CONCAT(client_id,':',currency_code) ORDER BY client_id) FROM retail_catalogue_settings"
        ),
        'Initial AUD client catalogue settings are incorrect.'
    );
    m2_assert_same(
        '1001:each,1002:each,2001:each',
        m2_scalar(
            $pdo,
            "SELECT GROUP_CONCAT(CONCAT(id,':',unit_of_measure) ORDER BY id) FROM retail_products"
        ),
        'Existing products were not safely initialized to each.'
    );

    $clientRegular = m22_insert_price(
        $pdo,
        1,
        1001,
        null,
        'regular',
        '10.1234',
        '2026-01-01 00:00:00.000000',
        null
    );
    $storeRegular = m22_insert_price(
        $pdo,
        1,
        1001,
        11,
        'regular',
        '9.5000',
        '2026-01-01 00:00:00.000000',
        null
    );
    $clientPromotion = m22_insert_price(
        $pdo,
        1,
        1001,
        null,
        'promotion',
        '8.7500',
        '2026-06-01 00:00:00.000000',
        '2026-07-01 00:00:00.000000',
        'published',
        'Synthetic client promotion'
    );
    $storePromotion = m22_insert_price(
        $pdo,
        1,
        1001,
        11,
        'promotion',
        '8.2500',
        '2026-06-01 00:00:00.000000',
        '2026-07-01 00:00:00.000000',
        'published',
        'Synthetic store promotion'
    );

    m2_assert_same(
        '1,2,3,4',
        m2_scalar(
            $pdo,
            'SELECT GROUP_CONCAT(precedence_rank ORDER BY precedence_rank) FROM retail_price_versions'
        ),
        'Approved price precedence ranks are incorrect.'
    );
    m2_assert_same(
        '10.1234',
        m2_scalar($pdo, "SELECT CAST(unit_price AS CHAR) FROM retail_price_versions WHERE id={$clientRegular}"),
        'Catalogue price precision was not preserved.'
    );
    m2_assert_same(
        (string)$storePromotion,
        m2_scalar(
            $pdo,
            "SELECT id FROM retail_price_versions WHERE client_id=1 AND product_id=1001 "
            . "AND status='published' AND effective_from_utc <= '2026-06-01 00:00:00.000000' "
            . "AND (effective_to_utc IS NULL OR '2026-06-01 00:00:00.000000' < effective_to_utc) "
            . 'ORDER BY precedence_rank LIMIT 1'
        ),
        'Inclusive UTC start or price precedence is incorrect.'
    );
    m2_assert_same(
        (string)$storeRegular,
        m2_scalar(
            $pdo,
            "SELECT id FROM retail_price_versions WHERE client_id=1 AND product_id=1001 "
            . "AND status='published' AND effective_from_utc <= '2026-07-01 00:00:00.000000' "
            . "AND (effective_to_utc IS NULL OR '2026-07-01 00:00:00.000000' < effective_to_utc) "
            . 'ORDER BY precedence_rank LIMIT 1'
        ),
        'Exclusive UTC end is incorrect.'
    );

    m2_expect_database_failure(
        fn () => m22_insert_price(
            $pdo,
            1,
            1001,
            11,
            'promotion',
            '8.0000',
            '2026-06-15 00:00:00.000000',
            '2026-07-15 00:00:00.000000',
            'published',
            'Overlapping promotion'
        ),
        'An equal-scope overlapping price was accepted.'
    );
    $draftOverlap = m22_insert_price(
        $pdo,
        1,
        1001,
        11,
        'promotion',
        '7.9000',
        '2026-06-20 00:00:00.000000',
        '2026-06-25 00:00:00.000000',
        'draft',
        'Draft overlap'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_price_versions SET status='published' WHERE id={$draftOverlap}"),
        'A draft overlapping price was published.'
    );
    m22_insert_price(
        $pdo,
        1,
        1001,
        11,
        'promotion',
        '8.1000',
        '2026-07-01 00:00:00.000000',
        '2026-08-01 00:00:00.000000',
        'published',
        'Adjacent promotion'
    );
    m2_expect_database_failure(
        fn () => m22_insert_price(
            $pdo,
            1,
            1002,
            null,
            'promotion',
            '5.0000',
            '2026-01-01 00:00:00.000000',
            null
        ),
        'A promotion without a name was accepted.'
    );
    m2_expect_database_failure(
        fn () => m22_insert_price(
            $pdo,
            1,
            1002,
            null,
            'regular',
            '0.0000',
            '2026-01-01 00:00:00.000000',
            null
        ),
        'A zero authoritative price was accepted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_price_versions "
            . "(client_id,product_id,store_id,price_type,unit_price,currency_code,effective_from_utc,status,created_by) "
            . "VALUES (1,1002,21,'regular',5.0000,'AUD','2026-01-01','published',9001)"
        ),
        'A cross-tenant store price was accepted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_price_versions "
            . "(client_id,product_id,price_type,unit_price,currency_code,effective_from_utc,status,created_by) "
            . "VALUES (1,1002,'regular',5.0000,'USD','2026-01-01','published',9001)"
        ),
        'A currency outside client settings was accepted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_price_versions SET unit_price=7.0000 WHERE id={$storePromotion}"),
        'A published price was silently overwritten.'
    );
    $pdo->exec(
        "UPDATE retail_price_versions SET status='cancelled',cancelled_by=9002,"
        . "cancelled_at_utc='2026-06-15 00:00:00',cancellation_reason='Synthetic cancellation' "
        . "WHERE id={$clientPromotion}"
    );
    m2_assert_same(
        'cancelled:Synthetic cancellation',
        m2_scalar(
            $pdo,
            "SELECT CONCAT(status,':',cancellation_reason) FROM retail_price_versions WHERE id={$clientPromotion}"
        ),
        'Cancelled price history was not retained.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("DELETE FROM retail_price_versions WHERE id={$clientPromotion}"),
        'Cancelled price history was deleted.'
    );

    return [$clientRegular, $storeRegular, $storePromotion];
}

function m22_tax_test(PDO $pdo, int $priceVersionId): array
{
    m2_assert_same(
        '2',
        m2_scalar($pdo, "SELECT COUNT(*) FROM retail_tax_codes WHERE code='NO_TAX' AND status='active'"),
        'Each synthetic client did not receive protected NO_TAX.'
    );
    m2_assert_same(
        '0',
        m2_scalar(
            $pdo,
            "SELECT MAX(r.rate_basis_points) FROM retail_tax_rate_versions r "
            . "JOIN retail_tax_codes c ON c.id=r.tax_code_id WHERE c.code='NO_TAX'"
        ),
        'NO_TAX did not remain zero basis points.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("DELETE FROM retail_tax_codes WHERE client_id=1 AND code='NO_TAX'"),
        'NO_TAX was deleted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec(
            "UPDATE retail_tax_rate_versions r JOIN retail_tax_codes c ON c.id=r.tax_code_id "
            . "SET r.rate_basis_points=1 WHERE c.client_id=1 AND c.code='NO_TAX'"
        ),
        'NO_TAX was assigned a nonzero rate.'
    );

    $pdo->exec(
        "INSERT INTO retail_tax_codes (client_id,code,name,status,created_by) "
        . "VALUES (1,'GST','Goods and Services Tax','active',9001)"
    );
    $taxCodeId = (int)$pdo->lastInsertId();
    $statement = $pdo->prepare(
        'INSERT INTO retail_tax_rate_versions '
        . '(client_id,tax_code_id,rate_basis_points,effective_from_utc,effective_to_utc,status,created_by) '
        . 'VALUES (1,?,?,?,?,?,9001)'
    );
    $statement->execute([
        $taxCodeId,
        1000,
        '2026-01-01 00:00:00.000000',
        '2027-01-01 00:00:00.000000',
        'published',
    ]);
    $taxRateId = (int)$pdo->lastInsertId();
    m2_expect_database_failure(
        fn () => $statement->execute([
            $taxCodeId,
            900,
            '2026-06-01 00:00:00.000000',
            null,
            'published',
        ]),
        'An overlapping tax-rate version was accepted.'
    );
    $statement->execute([
        $taxCodeId,
        900,
        '2026-06-01 00:00:00.000000',
        '2026-07-01 00:00:00.000000',
        'draft',
    ]);
    $draftTaxRateId = (int)$pdo->lastInsertId();
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_tax_rate_versions SET status='published' WHERE id={$draftTaxRateId}"),
        'A draft overlapping tax rate was published.'
    );
    $statement->execute([
        $taxCodeId,
        1100,
        '2027-01-01 00:00:00.000000',
        null,
        'published',
    ]);

    $pdo->exec(
        "INSERT INTO retail_product_tax_assignments "
        . "(client_id,product_id,tax_code_id,effective_from_utc,status,created_by) "
        . "VALUES (1,1001,{$taxCodeId},'2026-01-01 00:00:00','published',9001)"
    );
    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_product_tax_assignments "
            . "(client_id,product_id,tax_code_id,effective_from_utc,status,created_by) "
            . "VALUES (1,1001,{$taxCodeId},'2026-06-01 00:00:00','published',9001)"
        ),
        'An overlapping product-tax assignment was accepted.'
    );
    $pdo->exec(
        "INSERT INTO retail_product_tax_assignments "
        . "(client_id,product_id,tax_code_id,effective_from_utc,status,created_by) "
        . "VALUES (1,1001,{$taxCodeId},'2026-06-01 00:00:00','draft',9001)"
    );
    $draftAssignmentId = (int)$pdo->lastInsertId();
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_product_tax_assignments SET status='published' WHERE id={$draftAssignmentId}"),
        'A draft overlapping product-tax assignment was published.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_product_tax_assignments "
            . "(client_id,product_id,tax_code_id,effective_from_utc,status,created_by) "
            . "VALUES (2,2001,{$taxCodeId},'2026-01-01 00:00:00','published',9001)"
        ),
        'A cross-tenant product-tax assignment was accepted.'
    );

    $pdo->exec(
        "UPDATE retail_sale_lines SET barcode_used='0012345',sku_snapshot='ABC-123',"
        . "unit_of_measure='each',catalogue_unit_price=10.1234,price_type='regular',"
        . "price_version_id={$priceVersionId},tax_code_id={$taxCodeId},tax_code_snapshot='GST',"
        . "tax_rate_version_id={$taxRateId},tax_rate_basis_points=1000,tax_inclusive=1,"
        . "net_amount=9.20,tax_amount=0.92,gross_line_total=10.12,currency_code='AUD',"
        . "authoritative_sold_at_utc='2026-06-15 00:00:00' WHERE id=501"
    );
    m2_assert_same(
        '10.1234:9.20:0.92:10.12:AUD',
        m2_scalar(
            $pdo,
            "SELECT CONCAT(CAST(catalogue_unit_price AS CHAR),':',CAST(net_amount AS CHAR),':',"
            . "CAST(tax_amount AS CHAR),':',CAST(gross_line_total AS CHAR),':',currency_code) "
            . 'FROM retail_sale_lines WHERE id=501'
        ),
        'Historical sale-line snapshot precision is incorrect.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("DELETE FROM retail_tax_rate_versions WHERE id={$taxRateId}"),
        'A referenced tax-rate version was deleted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("DELETE FROM retail_tax_codes WHERE id={$taxCodeId}"),
        'A referenced tax code was deleted.'
    );

    m2_assert_same(
        '1.00',
        m2_scalar($pdo, "SELECT CAST(ROUND(11.00-(11.00/(1+(1000/10000))),2) AS CHAR)"),
        'Tax-inclusive per-line calculation is incorrect.'
    );
    m2_assert_same(
        '1.01',
        m2_scalar($pdo, "SELECT CAST(ROUND(1.005,2) AS CHAR)"),
        'Half-up currency rounding is incorrect.'
    );

    return [$taxCodeId, $taxRateId];
}

function m22_units_and_preservation_test(PDO $pdo, array $before): void
{
    m2_assert_same(
        $before['products'],
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_products'),
        'M2.1 product IDs changed.'
    );
    m2_assert_same(
        $before['categories'],
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_categories'),
        'M2.1 category IDs changed.'
    );
    m2_assert_same(
        $before['barcodes'],
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(barcode ORDER BY id) FROM retail_product_barcodes'),
        'M2.1 barcode aliases changed.'
    );
    m2_assert_same(
        $before['legacy'],
        m2_scalar(
            $pdo,
            "SELECT GROUP_CONCAT(CONCAT(id,':',sell_price,':',cost_price,':',tax_rate) ORDER BY id) FROM retail_products"
        ),
        'Legacy product price, cost, or tax values changed.'
    );
    m2_assert_same(
        $before['store_prices'],
        m2_scalar(
            $pdo,
            "SELECT GROUP_CONCAT(CONCAT(id,':',COALESCE(store_price,'NULL')) ORDER BY id) FROM retail_store_inventory"
        ),
        'Legacy store prices changed.'
    );
    m2_assert_same(
        $before['sale'],
        m2_scalar(
            $pdo,
            "SELECT CONCAT(product_id,':',unit_price,':',unit_cost,':',line_total) FROM retail_sale_lines WHERE id=501"
        ),
        'Existing sale identity or legacy monetary snapshots changed.'
    );

    $pdo->exec("UPDATE retail_products SET unit_of_measure='kilogram' WHERE id=1002");
    $pdo->exec("UPDATE retail_products SET unit_of_measure='litre' WHERE id=2001");
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_products SET unit_of_measure='box' WHERE id=1001"),
        'An unsupported unit of measure was accepted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec("UPDATE retail_sale_lines SET quantity=1.500,unit_of_measure='each' WHERE id=501"),
        'A fractional each quantity snapshot was accepted.'
    );
    $pdo->exec("UPDATE retail_sale_lines SET quantity=1.250,unit_of_measure='kilogram' WHERE id=501");
    m2_assert_same(
        '1.250',
        m2_scalar($pdo, 'SELECT CAST(quantity AS CHAR) FROM retail_sale_lines WHERE id=501'),
        'A three-decimal measured quantity was not preserved.'
    );
}

function m22_positive_migration_test(): void
{
    $pdo = m22_reset_post_m21_fixture();
    $before = [
        'products' => m2_scalar($pdo, 'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_products'),
        'categories' => m2_scalar($pdo, 'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_categories'),
        'barcodes' => m2_scalar($pdo, 'SELECT GROUP_CONCAT(barcode ORDER BY id) FROM retail_product_barcodes'),
        'legacy' => m2_scalar(
            $pdo,
            "SELECT GROUP_CONCAT(CONCAT(id,':',sell_price,':',cost_price,':',tax_rate) ORDER BY id) FROM retail_products"
        ),
        'store_prices' => m2_scalar(
            $pdo,
            "SELECT GROUP_CONCAT(CONCAT(id,':',COALESCE(store_price,'NULL')) ORDER BY id) FROM retail_store_inventory"
        ),
        'sale' => m2_scalar(
            $pdo,
            "SELECT CONCAT(product_id,':',unit_price,':',unit_cost,':',line_total) FROM retail_sale_lines WHERE id=501"
        ),
    ];

    m22_apply_migration($pdo);
    m2_assert_same('0', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_price_versions'), 'Legacy prices were backfilled.');
    m2_assert_same('0', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_product_tax_assignments'), 'Legacy tax values were assigned.');
    [$clientRegular] = m22_price_and_currency_test($pdo);
    m22_tax_test($pdo, $clientRegular);
    m22_units_and_preservation_test($pdo, $before);

    try {
        m22_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(
            str_contains($exception->getMessage(), 'target tables already exist')
                || str_contains($exception->getMessage(), 'shape is incompatible'),
            'M2.2 migration rerun did not fail visibly.'
        );
        return;
    }
    m2_fail('Raw M2.2 migration rerun unexpectedly succeeded.');
}

function m22_run_tests(): void
{
    $tests = [
        'effective pricing, tax, UOM, and historical preservation' => fn () => m22_positive_migration_test(),
        'negative legacy price precondition' => fn () => m22_expect_precondition(
            'negative legacy product prices exist',
            fn (PDO $pdo) => $pdo->exec('UPDATE retail_products SET sell_price=-1 WHERE id=1001')
        ),
        'legacy tax range precondition' => fn () => m22_expect_precondition(
            'legacy tax rates are outside 0 to 100 percent',
            fn (PDO $pdo) => $pdo->exec('UPDATE retail_products SET tax_rate=101 WHERE id=1001')
        ),
        'tenant inventory precondition' => fn () => m22_expect_precondition(
            'invalid tenant-scoped inventory references exist',
            fn (PDO $pdo) => $pdo->exec('UPDATE retail_store_inventory SET store_id=21 WHERE id=301')
        ),
        'partial target schema precondition' => fn () => m22_expect_precondition(
            'target tables already exist',
            fn (PDO $pdo) => $pdo->exec('CREATE TABLE retail_price_versions (id INT PRIMARY KEY)')
        ),
    ];

    $passed = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
            echo "PASS: {$name}\n";
        } catch (Throwable $exception) {
            echo "FAIL: {$name}\n";
            throw $exception;
        }
    }
    echo 'M2.2 migration tests: ' . $passed . " passed.\n";
}
