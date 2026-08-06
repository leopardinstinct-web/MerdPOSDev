<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_ledger_balance_migration_test.php';
require_once __DIR__ . '/../api/includes/request.php';
require_once __DIR__ . '/../api/includes/device_auth.php';
require_once __DIR__ . '/../api/includes/catalogue_snapshot.php';

function m24_reset_fixture(): PDO
{
    $pdo = m23_reset_fixture();
    $pdo->exec(
        'CREATE TABLE devices ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,client_id INT NOT NULL,store_id INT NOT NULL,'
        . 'device_uuid VARCHAR(150) NOT NULL,status VARCHAR(20) NOT NULL,'
        . 'revoked_at DATETIME NULL,last_sync DATETIME NULL,token_hash CHAR(64) NOT NULL,'
        . 'token_expires_at DATETIME NOT NULL,previous_token_hash CHAR(64) NULL,'
        . 'previous_token_valid_until DATETIME NULL,'
        . 'UNIQUE KEY uq_m24_device_uuid (device_uuid)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $tokenHash = merd_device_token_hash('synthetic-catalogue-token');
    $statement = $pdo->prepare(
        'INSERT INTO devices '
        . '(id,client_id,store_id,device_uuid,status,token_hash,token_expires_at) '
        . "VALUES (1,1,11,'catalogue-device','active',?,'2027-01-01 00:00:00')"
    );
    $statement->execute([$tokenHash]);
    $statement = $pdo->prepare(
        'INSERT INTO devices '
        . '(id,client_id,store_id,device_uuid,status,token_hash,token_expires_at) '
        . "VALUES (2,1,11,'catalogue-device-2','active',?,'2027-01-01 00:00:00')"
    );
    $statement->execute([$tokenHash]);

    $pdo->exec(
        "INSERT INTO retail_products "
        . "(id,client_id,category_id,sku,barcode,name,description,status,archived_at,tombstoned_at,unit_of_measure) VALUES "
        . "(1003,1,101,NULL,NULL,'Barcode-free product','Synthetic no configuration','active',NULL,NULL,'each'),"
        . "(1004,1,101,'ARCH-1',NULL,'Archived product',NULL,'active','2026-07-01 00:00:00',NULL,'each'),"
        . "(1005,1,101,'TOMB-1',NULL,'Tombstoned product',NULL,'active',NULL,'2026-07-02 00:00:00','each')"
    );
    $pdo->exec("UPDATE retail_products SET unit_of_measure='kilogram' WHERE id=1002");
    $pdo->exec(
        "INSERT INTO retail_product_barcodes (client_id,product_id,barcode,is_primary) VALUES "
        . "(1,1001,'CASE-001',0),(1,1001,'ALT-001',0)"
    );
    $pdo->exec(
        'INSERT INTO retail_store_inventory '
        . '(id,client_id,store_id,product_id,quantity,reorder_level,store_price) VALUES '
        . '(304,1,11,1003,0,1,NULL),(305,1,11,1004,0,1,NULL),(306,1,11,1005,0,1,NULL)'
    );

    m22_insert_price($pdo, 1, 1001, null, 'regular', '10.0000', '2026-01-01 00:00:00.000000', null);
    m22_insert_price($pdo, 1, 1001, 11, 'regular', '9.0000', '2026-01-01 00:00:00.000000', null);
    m22_insert_price($pdo, 1, 1001, null, 'promotion', '8.0000', '2026-06-01 00:00:00.000000', '2026-09-01 00:00:00.000000', 'published', 'Client promotion');
    m22_insert_price($pdo, 1, 1001, 11, 'promotion', '7.0000', '2026-08-06 12:00:00.000000', '2026-09-01 00:00:00.000000', 'published', 'Store promotion');
    m22_insert_price($pdo, 1, 1001, 11, 'promotion', '6.5000', '2026-01-01 00:00:00.000000', '2026-02-01 00:00:00.000000', 'published', 'Expired promotion');
    m22_insert_price($pdo, 1, 1001, 12, 'regular', '1.0000', '2026-01-01 00:00:00.000000', null);
    m22_insert_price($pdo, 1, 1001, 11, 'promotion', '6.0000', '2026-08-01 00:00:00.000000', '2026-08-15 00:00:00.000000', 'draft', 'Draft promotion');
    $pdo->exec(
        "INSERT INTO retail_price_versions "
        . "(client_id,product_id,store_id,price_type,unit_price,currency_code,effective_from_utc,effective_to_utc,status,promotion_name,created_by,cancelled_by,cancelled_at_utc,cancellation_reason) VALUES "
        . "(1,1001,11,'promotion',5.0000,'AUD','2026-07-01 00:00:00.000000','2026-07-15 00:00:00.000000','cancelled','Cancelled promotion',9001,9001,'2026-07-02 00:00:00.000000','Synthetic cancellation')"
    );

    $pdo->exec(
        "INSERT INTO retail_tax_codes (client_id,code,name,status,created_by) "
        . "VALUES (1,'GST','Goods and Services Tax','active',9001)"
    );
    $gstCode = (int)$pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO retail_tax_rate_versions "
        . "(client_id,tax_code_id,rate_basis_points,effective_from_utc,status,created_by) "
        . "VALUES (1,{$gstCode},1000,'2026-01-01 00:00:00.000000','published',9001)"
    );
    $noTaxCode = (int)m2_scalar($pdo, "SELECT id FROM retail_tax_codes WHERE client_id=1 AND code='NO_TAX'");
    $pdo->exec(
        "INSERT INTO retail_product_tax_assignments "
        . "(client_id,product_id,tax_code_id,effective_from_utc,status,created_by) VALUES "
        . "(1,1001,{$gstCode},'2026-01-01 00:00:00.000000','published',9001),"
        . "(1,1002,{$noTaxCode},'2026-01-01 00:00:00.000000','published',9001)"
    );

    m23_insert_movement($pdo, [
        'movement_type' => 'opening_balance',
        'signed_quantity' => '1.000',
        'source_type' => 'reviewed_opening',
        'source_record_key' => 'm24-opening',
        'idempotency_key' => 'm24-opening',
        'device_uuid' => null,
        'reason_code' => 'synthetic_opening',
        'legacy_inventory_id' => 301,
    ]);
    m23_insert_movement($pdo, [
        'movement_type' => 'sale',
        'signed_quantity' => '-3.000',
        'source_type' => 'synthetic_sale',
        'source_record_key' => 'm24-sale',
        'idempotency_key' => 'm24-sale',
        'retail_sale_id' => 401,
        'occurred_at_utc' => '2026-08-06 11:00:00.000000',
    ]);
    return $pdo;
}

function m24_request(array $overrides = [], string $token = 'synthetic-catalogue-token'): array
{
    return [
        'server' => [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ],
        'body' => array_merge([
            'contract_version' => MERD_CATALOGUE_CONTRACT_VERSION,
            'snapshot_type' => 'full',
            'client_id' => 1,
            'store_id' => 11,
            'device_uuid' => 'catalogue-device',
        ], $overrides),
    ];
}

function m24_product(array $response, int $productId): array
{
    foreach ($response['snapshot']['products'] as $product) {
        if ($product['id'] === $productId) {
            return $product;
        }
    }
    m2_fail('Expected product is missing from catalogue snapshot.');
}

function m24_authorized_snapshot_test(): void
{
    $pdo = m24_reset_fixture();
    $request = m24_request();
    $now = new DateTimeImmutable('2026-08-06 12:00:00.000000', new DateTimeZone('UTC'));
    $response = merd_catalogue_handle_request($pdo, $request['server'], $request['body'], $now);

    m2_assert_same(true, $response['success'], 'Full snapshot did not use the success envelope.');
    m2_assert_same(MERD_CATALOGUE_CONTRACT_VERSION, $response['contract_version'], 'Contract version changed.');
    m2_assert_same('full', $response['snapshot_type'], 'Snapshot type changed.');
    m2_assert((bool)preg_match('/^sha256:[0-9a-f]{64}$/', $response['snapshot_revision']), 'Snapshot revision is not deterministic SHA-256 metadata.');
    m2_assert((bool)preg_match('/^m2f1_[A-Za-z0-9_-]{43}$/', $response['cursor_seed']), 'Cursor seed is not opaque/future-compatible.');
    m2_assert_same('2026-08-06T12:00:00.000000Z', $response['server_time_utc'], 'Server UTC serialization is incorrect.');
    m2_assert_same(1, $response['snapshot']['context']['client']['id'], 'Client context is incorrect.');
    m2_assert_same(11, $response['snapshot']['context']['store']['id'], 'Store context is incorrect.');
    m2_assert_same('AUD', $response['snapshot']['currency']['code'], 'Currency context is incorrect.');
    m2_assert_same([101,102], array_column($response['snapshot']['categories'], 'id'), 'Category ordering/isolation is incorrect.');
    m2_assert_same([1001,1002,1003,1004,1005], array_column($response['snapshot']['products'], 'id'), 'Product ordering/isolation is incorrect.');

    $product = m24_product($response, 1001);
    m2_assert_same('ABC-123', $product['sku'], 'SKU display normalization is incorrect.');
    m2_assert_same('abc-123', $product['sku_normalized'], 'Normalized SKU is incorrect.');
    m2_assert_same(['0012345','CASE-001','ALT-001'], array_column($product['barcodes'], 'barcode'), 'Exact multiple barcode order/value is incorrect.');
    m2_assert_same('7.0000', $product['resolved_price']['unit_price'], 'Store-promotion precedence is incorrect.');
    m2_assert_same('store', $product['resolved_price']['scope'], 'Resolved store price scope is incorrect.');
    m2_assert_same([1,2,3,4], array_column($product['effective_prices'], 'precedence'), 'Effective price candidates or ordering are incorrect.');
    foreach ($product['effective_prices'] as $price) {
        m2_assert((bool)preg_match('/^\d+\.\d{4}$/', $price['unit_price']), 'Money is not a four-place decimal string.');
    }
    m2_assert_same('GST', $product['resolved_tax']['tax_code'], 'GST was not resolved.');
    m2_assert_same(1000, $product['resolved_tax']['rate_basis_points'], 'Effective tax rate is incorrect.');
    m2_assert_same(true, $product['resolved_tax']['tax_inclusive'], 'Tax-inclusive contract changed.');
    m2_assert_same('-2.000', $product['stock']['balance'], 'Authoritative negative stock is incorrect.');
    m2_assert_same(2, $product['stock']['revision'], 'Stock revision is incorrect.');
    m2_assert_same('open', $product['stock']['negative_exception']['status'], 'Negative-stock exception is missing.');
    m2_assert_same(true, $product['sellable'], 'Negative stock incorrectly made a configured product unsellable.');

    $noTaxProduct = m24_product($response, 1002);
    m2_assert_same('kilogram', $noTaxProduct['unit_of_measure'], 'Unit of measure is incorrect.');
    m2_assert_same('NO_TAX', $noTaxProduct['resolved_tax']['tax_code'], 'NO_TAX was not resolved explicitly.');
    m2_assert_same('disabled', $noTaxProduct['lifecycle'], 'Disabled lifecycle is incorrect.');

    $barcodeFree = m24_product($response, 1003);
    m2_assert_same([], $barcodeFree['barcodes'], 'Barcode-free product gained a barcode.');
    m2_assert_same(['missing_effective_price','missing_effective_tax'], $barcodeFree['not_sellable_reasons'], 'Incomplete configuration reasons are not explicit.');
    m2_assert_same(false, $barcodeFree['sellable'], 'Incomplete product was sellable.');
    m2_assert_same('archived', m24_product($response, 1004)['lifecycle'], 'Archived lifecycle is incorrect.');
    m2_assert_same('tombstoned', m24_product($response, 1005)['lifecycle'], 'Tombstoned lifecycle is incorrect.');
    m2_assert(!in_array(2001, array_column($response['snapshot']['products'], 'id'), true), 'Other-tenant product leaked.');

    $repeated = merd_catalogue_handle_request($pdo, $request['server'], $request['body'], $now);
    m2_assert_same($response, $repeated, 'Repeated identical snapshot request was not deterministic.');
    m2_assert_same('', m2_scalar($pdo, 'SELECT COALESCE(last_sync,\'\') FROM devices WHERE id=1'), 'Snapshot download incorrectly advanced last_sync.');
    $secondDeviceRequest = m24_request(['device_uuid' => 'catalogue-device-2']);
    $secondDevice = merd_catalogue_handle_request(
        $pdo,
        $secondDeviceRequest['server'],
        $secondDeviceRequest['body'],
        $now
    );
    m2_assert_same($response['snapshot_revision'], $secondDevice['snapshot_revision'], 'Store snapshot revision varied by device UUID.');
}

function m24_authorization_and_contract_test(): void
{
    $pdo = m24_reset_fixture();
    $now = new DateTimeImmutable('2026-08-06 12:00:00', new DateTimeZone('UTC'));
    foreach ([
        [m24_request([], 'wrong-token'), MerdRequestException::class],
        [m24_request(['store_id' => 12]), MerdRequestException::class],
        [m24_request(['client_id' => 2, 'store_id' => 21]), MerdRequestException::class],
    ] as [$request, $exception]) {
        try {
            merd_catalogue_handle_request($pdo, $request['server'], $request['body'], $now);
        } catch (Throwable $caught) {
            m2_assert($caught instanceof $exception, 'Unauthorized snapshot used the wrong failure type.');
            m2_assert_same('device_unauthorized', $caught->errorCode, 'Unauthorized snapshot leaked scope details.');
            continue;
        }
        m2_fail('Unauthorized catalogue snapshot succeeded.');
    }

    $pdo->exec("UPDATE devices SET status='inactive' WHERE id=1");
    $inactive = m24_request();
    try {
        merd_catalogue_handle_request($pdo, $inactive['server'], $inactive['body'], $now);
        m2_fail('Inactive device catalogue snapshot succeeded.');
    } catch (MerdRequestException $exception) {
        m2_assert_same('device_unauthorized', $exception->errorCode, 'Inactive device failure changed.');
        $pdo->exec("UPDATE devices SET status='active' WHERE id=1");
    }

    $request = m24_request(['contract_version' => 'unknown']);
    try {
        merd_catalogue_handle_request($pdo, $request['server'], $request['body'], $now);
    } catch (MerdRequestException $exception) {
        m2_assert_same('unsupported_catalogue_contract', $exception->errorCode, 'Unsupported contract failure changed.');
        return;
    }
    m2_fail('Unsupported catalogue contract succeeded.');
}

function m24_response_schema_test(): void
{
    $pdo = m24_reset_fixture();
    $request = m24_request();
    $response = merd_catalogue_handle_request(
        $pdo,
        $request['server'],
        $request['body'],
        new DateTimeImmutable('2026-08-06 12:00:00', new DateTimeZone('UTC'))
    );
    foreach (['success','api','contract_version','snapshot_type','snapshot_revision','cursor_seed','server_time_utc','snapshot_generated_at_utc','snapshot'] as $key) {
        m2_assert(array_key_exists($key, $response), "Response schema is missing {$key}.");
    }
    foreach (['context','currency','categories','tax_codes','effective_tax_rates','product_tax_assignments','products','warnings'] as $key) {
        m2_assert(array_key_exists($key, $response['snapshot']), "Snapshot schema is missing {$key}.");
    }
    $encoded = json_encode($response, JSON_THROW_ON_ERROR);
    $decoded = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
    m2_assert_same($response, $decoded, 'Snapshot is not stable JSON data.');
    foreach ([
        __DIR__ . '/../api/sync_catalogue.php',
        __DIR__ . '/../api/includes/catalogue_snapshot.php',
        __FILE__,
    ] as $path) {
        $source = file_get_contents($path);
        m2_assert(is_string($source) && !str_contains($source, 'app.merdpos.com'), 'M2.4 test/runtime source references the production host.');
    }
}

function m24_run_tests(): void
{
    m24_authorized_snapshot_test();
    m24_authorization_and_contract_test();
    m24_response_schema_test();
    echo "M2.4 catalogue snapshot synthetic integration tests passed.\n";
}
