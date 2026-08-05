<?php
declare(strict_types=1);

const M2_TEST_DATABASE = 'merd_m2_1_synthetic';

function m2_fail(string $message): never
{
    throw new RuntimeException($message);
}

function m2_assert(bool $condition, string $message): void
{
    if (!$condition) {
        m2_fail($message);
    }
}

function m2_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        m2_fail($message);
    }
}

function m2_connect(bool $withDatabase = false): PDO
{
    $host = getenv('M2_DB_HOST') ?: '127.0.0.1';
    $port = getenv('M2_DB_PORT') ?: '3306';
    $user = getenv('M2_DB_USER') ?: 'root';
    $password = getenv('M2_DB_PASSWORD');
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        m2_fail('M2 schema tests refuse non-local database hosts.');
    }
    if (!preg_match('/^\d{1,5}$/', $port)) {
        m2_fail('Invalid synthetic database port.');
    }
    $database = $withDatabase ? ';dbname=' . M2_TEST_DATABASE : '';
    return new PDO(
        "mysql:host={$host};port={$port}{$database};charset=utf8mb4",
        $user,
        $password === false ? '' : $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

function m2_apply_sql_file(PDO $pdo, string $path): void
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        m2_fail('Could not read synthetic SQL input.');
    }
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (trim($buffer) === '' && preg_match('/^\s*--/', $line)) {
            continue;
        }
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches)) {
            if (trim($buffer) !== '') {
                m2_fail('Unexpected SQL before delimiter change.');
            }
            $delimiter = $matches[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if ($trimmed === '' || !str_ends_with($trimmed, $delimiter)) {
            continue;
        }
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        $buffer = '';
        if ($statement !== '') {
            $result = $pdo->query($statement);
            if ($result !== false) {
                do {
                    $result->fetchAll();
                } while ($result->nextRowset());
                $result->closeCursor();
            }
        }
    }
    if (trim($buffer) !== '') {
        m2_fail('Unterminated SQL statement in synthetic input.');
    }
}

function m2_reset_fixture(): PDO
{
    $server = m2_connect();
    $server->exec('DROP DATABASE IF EXISTS `' . M2_TEST_DATABASE . '`');
    $server->exec(
        'CREATE DATABASE `' . M2_TEST_DATABASE
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $pdo = m2_connect(true);
    m2_apply_sql_file($pdo, __DIR__ . '/fixtures/catalogue_identity_schema.sql');
    return $pdo;
}

function m2_apply_migration(PDO $pdo): void
{
    m2_apply_sql_file($pdo, dirname(__DIR__) . '/sql/016_catalogue_identity_lifecycle.sql');
}

function m2_scalar(PDO $pdo, string $sql): string
{
    $value = $pdo->query($sql)->fetchColumn();
    return $value === false ? '' : (string)$value;
}

function m2_expect_database_failure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (PDOException) {
        return;
    }
    m2_fail($message);
}

function m2_expect_precondition(string $expected, callable $mutate): void
{
    $pdo = m2_reset_fixture();
    $mutate($pdo);
    try {
        m2_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(
            str_contains($exception->getMessage(), $expected),
            'Migration failed without the expected visible precondition message.'
        );
        return;
    }
    m2_fail('An incompatible synthetic schema unexpectedly migrated.');
}

function m2_positive_migration_test(): void
{
    $pdo = m2_reset_fixture();
    $productIdsBefore = m2_scalar(
        $pdo,
        'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_products'
    );
    $categoryIdsBefore = m2_scalar(
        $pdo,
        'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_categories'
    );
    $saleReferenceBefore = m2_scalar(
        $pdo,
        'SELECT GROUP_CONCAT(product_id ORDER BY id) FROM retail_sale_lines'
    );
    $movementReferenceBefore = m2_scalar(
        $pdo,
        'SELECT GROUP_CONCAT(product_id ORDER BY id) FROM retail_stock_movements'
    );
    $purchaseReferenceBefore = m2_scalar(
        $pdo,
        'SELECT GROUP_CONCAT(product_id ORDER BY id) FROM retail_purchase_order_lines'
    );

    m2_apply_migration($pdo);

    m2_assert_same(
        $productIdsBefore,
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_products'),
        'Existing product IDs changed.'
    );
    m2_assert_same(
        $categoryIdsBefore,
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(id ORDER BY id) FROM retail_categories'),
        'Existing category IDs changed.'
    );
    m2_assert_same(
        $saleReferenceBefore,
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(product_id ORDER BY id) FROM retail_sale_lines'),
        'Historical sale product references changed.'
    );
    m2_assert_same(
        $movementReferenceBefore,
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(product_id ORDER BY id) FROM retail_stock_movements'),
        'Historical movement product references changed.'
    );
    m2_assert_same(
        $purchaseReferenceBefore,
        m2_scalar($pdo, 'SELECT GROUP_CONCAT(product_id ORDER BY id) FROM retail_purchase_order_lines'),
        'Purchase product references changed.'
    );
    m2_assert_same(
        '0012345,12345',
        m2_scalar(
            $pdo,
            "SELECT GROUP_CONCAT(barcode ORDER BY product_id) FROM retail_product_barcodes WHERE client_id=1"
        ),
        'Leading-zero barcode migration was not exact.'
    );
    m2_assert_same(
        'ABC-123',
        trim(m2_scalar($pdo, 'SELECT sku FROM retail_products WHERE id=1001')),
        'SKU display value was not preserved.'
    );
    m2_assert_same(
        'abc-123',
        m2_scalar($pdo, 'SELECT sku_normalized FROM retail_products WHERE id=1001'),
        'SKU normalization is incorrect.'
    );

    $pdo->exec(
        "INSERT INTO retail_products (id,client_id,category_id,sku,barcode,name,status) "
        . "VALUES (1003,1,101,NULL,NULL,'Barcode-free product','active')"
    );
    $pdo->exec(
        "INSERT INTO retail_product_barcodes (client_id,product_id,barcode,is_primary) VALUES "
        . "(1,1001,'CASE-CODE',0),(1,1001,'case-code',0),(1,1001,'ALT-001',0)"
    );
    m2_assert_same(
        '5',
        m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_product_barcodes WHERE client_id=1'),
        'Zero-to-many or exact barcode alias behavior is incorrect.'
    );
    $pdo->exec(
        "INSERT INTO retail_product_barcodes (client_id,product_id,barcode,is_primary) "
        . "VALUES (2,2001,'ALT-001',0)"
    );
    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_product_barcodes (client_id,product_id,barcode,is_primary) "
            . "VALUES (1,1002,'ALT-001',0)"
        ),
        'Duplicate client barcode alias was accepted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec(
            "INSERT INTO retail_products (client_id,category_id,sku,barcode,name,status) "
            . "VALUES (1,101,'abc-123',NULL,'Duplicate SKU','active')"
        ),
        'Case-insensitive client SKU duplicate was accepted.'
    );
    $pdo->exec(
        "INSERT INTO retail_products (client_id,category_id,sku,barcode,name,status) "
        . "VALUES (2,201,'  second-sku  ',NULL,'Second client SKU','active')"
    );

    $productStatusBefore = m2_scalar($pdo, 'SELECT status FROM retail_products WHERE id=1001');
    $pdo->exec("UPDATE retail_categories SET status='disabled' WHERE id=101");
    m2_assert_same(
        $productStatusBefore,
        m2_scalar($pdo, 'SELECT status FROM retail_products WHERE id=1001'),
        'Disabling a category changed product lifecycle.'
    );
    $pdo->exec(
        "UPDATE retail_products SET status='archived',archived_at='2026-08-05 01:00:00' WHERE id=1002"
    );
    $pdo->exec(
        "UPDATE retail_products SET status='tombstoned',tombstoned_at='2026-08-05 02:00:00' WHERE id=1003"
    );
    m2_assert_same(
        'archived:1,tombstoned:1',
        m2_scalar(
            $pdo,
            "SELECT CONCAT(" .
            "'archived:',SUM(status='archived' AND archived_at IS NOT NULL)," .
            "',tombstoned:',SUM(status='tombstoned' AND tombstoned_at IS NOT NULL)) " .
            'FROM retail_products WHERE client_id=1'
        ),
        'Lifecycle metadata is not distinguishable.'
    );

    m2_expect_database_failure(
        fn () => $pdo->exec('DELETE FROM retail_categories WHERE id=101'),
        'Referenced category deletion was accepted.'
    );
    m2_expect_database_failure(
        fn () => $pdo->exec('DELETE FROM retail_products WHERE id=1001'),
        'Historically referenced product deletion was accepted.'
    );
    try {
        m2_apply_migration($pdo);
    } catch (PDOException $exception) {
        m2_assert(
            str_contains($exception->getMessage(), 'retail_products shape is incompatible')
                || str_contains($exception->getMessage(), 'already exists'),
            'Migration rerun did not fail visibly.'
        );
        return;
    }
    m2_fail('Raw migration rerun unexpectedly succeeded.');
}

function m2_run_tests(): void
{
    $tests = [
        'positive migration and identity preservation' => fn () => m2_positive_migration_test(),
        'duplicate normalized SKU precondition' => fn () => m2_expect_precondition(
            'duplicate normalized SKUs exist',
            fn (PDO $pdo) => $pdo->exec(
                "INSERT INTO retail_products (id,client_id,category_id,sku,barcode,name,status) "
                . "VALUES (1003,1,101,'abc-123','SKU-DUP','Duplicate SKU','active')"
            )
        ),
        'trimmed barcode collision precondition' => fn () => m2_expect_precondition(
            'trimmed barcode aliases collide',
            fn (PDO $pdo) => $pdo->exec(
                "INSERT INTO retail_products (id,client_id,category_id,sku,barcode,name,status) "
                . "VALUES (1003,1,101,NULL,' 0012345','Barcode collision','active')"
            )
        ),
        'cross-client category precondition' => fn () => m2_expect_precondition(
            'cross-client product category references exist',
            fn (PDO $pdo) => $pdo->exec('UPDATE retail_products SET category_id=201 WHERE id=1002')
        ),
        'orphaned historical reference precondition' => fn () => m2_expect_precondition(
            'invalid historical sale product references exist',
            fn (PDO $pdo) => $pdo->exec('UPDATE retail_sale_lines SET product_id=999999 WHERE id=501')
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
    echo 'M2.1 migration tests: ' . $passed . " passed.\n";
}
