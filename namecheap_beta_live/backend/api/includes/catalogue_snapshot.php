<?php
declare(strict_types=1);

const MERD_CATALOGUE_CONTRACT_VERSION = 'm2.catalogue.full.v1';

function merd_catalogue_decimal(mixed $value, int $scale): string
{
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        throw new RuntimeException('Invalid catalogue decimal value.');
    }
    $text = trim((string)$value);
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $text)) {
        throw new RuntimeException('Invalid catalogue decimal value.');
    }
    $negative = str_starts_with($text, '-');
    $unsigned = $negative ? substr($text, 1) : $text;
    [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
    if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
        throw new RuntimeException('Catalogue decimal exceeds its contract scale.');
    }
    $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
    $normalized = ltrim($whole, '0');
    $normalized = $normalized === '' ? '0' : $normalized;
    $result = $scale > 0 ? $normalized . '.' . $fraction : $normalized;
    return $negative && $result !== '0' && $result !== '0.' . str_repeat('0', $scale)
        ? '-' . $result
        : $result;
}

function merd_catalogue_utc(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s.u',
        (string)$value,
        new DateTimeZone('UTC')
    );
    if (!$date) {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string)$value,
            new DateTimeZone('UTC')
        );
    }
    if (!$date) {
        throw new RuntimeException('Invalid catalogue UTC timestamp.');
    }
    return $date->format('Y-m-d\TH:i:s.u\Z');
}

function merd_catalogue_lifecycle(array $row): string
{
    if (!empty($row['tombstoned_at'])) {
        return 'tombstoned';
    }
    if (!empty($row['archived_at']) || ($row['status'] ?? '') === 'archived') {
        return 'archived';
    }
    if (($row['status'] ?? '') !== 'active') {
        return 'disabled';
    }
    return 'active';
}

function merd_catalogue_query_all(PDO $pdo, string $sql, array $parameters): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function merd_catalogue_full_snapshot(
    PDO $pdo,
    array $authorizedDevice,
    DateTimeImmutable $snapshotTime
): array {
    $clientId = (int)($authorizedDevice['device']['client_id'] ?? 0);
    $storeId = (int)($authorizedDevice['device']['store_id'] ?? 0);
    $deviceUuid = (string)($authorizedDevice['device']['device_uuid'] ?? '');
    if ($clientId <= 0 || $storeId <= 0 || $deviceUuid === '') {
        throw new RuntimeException('Authorized device context is incomplete.');
    }
    $utc = $snapshotTime->setTimezone(new DateTimeZone('UTC'));
    $databaseTime = $utc->format('Y-m-d H:i:s.u');

    $contextStatement = $pdo->prepare(
        'SELECT c.id AS client_id,c.name AS client_name,c.status AS client_status,'
        . 's.id AS store_id,s.store_name,s.store_code,s.status AS store_status '
        . 'FROM clients c JOIN stores s ON s.client_id=c.id '
        . 'WHERE c.id=? AND s.id=? LIMIT 1'
    );
    $contextStatement->execute([$clientId, $storeId]);
    $context = $contextStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($context)) {
        throw new RuntimeException('Authorized catalogue scope is unavailable.');
    }

    $currencyStatement = $pdo->prepare(
        'SELECT currency_code FROM retail_catalogue_settings WHERE client_id=?'
    );
    $currencyStatement->execute([$clientId]);
    $currency = $currencyStatement->fetchColumn();
    if (!is_string($currency) || $currency === '') {
        throw new RuntimeException('Catalogue currency configuration is unavailable.');
    }

    $categoryRows = merd_catalogue_query_all(
        $pdo,
        'SELECT id,name,status FROM retail_categories WHERE client_id=? ORDER BY id',
        [$clientId]
    );
    $categories = array_map(static fn (array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'status' => (string)$row['status'],
    ], $categoryRows);

    $barcodeRows = merd_catalogue_query_all(
        $pdo,
        'SELECT id,product_id,barcode,is_primary FROM retail_product_barcodes '
        . 'WHERE client_id=? ORDER BY product_id,id',
        [$clientId]
    );
    $barcodesByProduct = [];
    foreach ($barcodeRows as $row) {
        $barcodesByProduct[(int)$row['product_id']][] = [
            'id' => (int)$row['id'],
            'barcode' => (string)$row['barcode'],
            'is_primary' => (bool)$row['is_primary'],
        ];
    }

    $priceRows = merd_catalogue_query_all(
        $pdo,
        'SELECT id,product_id,store_id,price_type,precedence_rank,unit_price,'
        . 'currency_code,effective_from_utc,effective_to_utc,promotion_name,'
        . 'campaign_reference FROM retail_price_versions '
        . "WHERE client_id=? AND (store_id IS NULL OR store_id=?) AND status='published' "
        . 'AND effective_from_utc<=? AND (effective_to_utc IS NULL OR effective_to_utc>?) '
        . 'ORDER BY product_id,precedence_rank,id',
        [$clientId, $storeId, $databaseTime, $databaseTime]
    );
    $pricesByProduct = [];
    foreach ($priceRows as $row) {
        $pricesByProduct[(int)$row['product_id']][] = [
            'id' => (int)$row['id'],
            'scope' => $row['store_id'] === null ? 'client' : 'store',
            'store_id' => $row['store_id'] === null ? null : (int)$row['store_id'],
            'type' => (string)$row['price_type'],
            'precedence' => (int)$row['precedence_rank'],
            'unit_price' => merd_catalogue_decimal($row['unit_price'], 4),
            'currency_code' => (string)$row['currency_code'],
            'effective_from_utc' => merd_catalogue_utc($row['effective_from_utc']),
            'effective_to_utc' => merd_catalogue_utc($row['effective_to_utc']),
            'promotion_name' => $row['promotion_name'] === null ? null : (string)$row['promotion_name'],
            'campaign_reference' => $row['campaign_reference'] === null
                ? null : (string)$row['campaign_reference'],
        ];
    }

    $taxCodeRows = merd_catalogue_query_all(
        $pdo,
        'SELECT id,code,name,status FROM retail_tax_codes WHERE client_id=? ORDER BY id',
        [$clientId]
    );
    $taxCodes = array_map(static fn (array $row): array => [
        'id' => (int)$row['id'],
        'code' => (string)$row['code'],
        'name' => (string)$row['name'],
        'status' => (string)$row['status'],
    ], $taxCodeRows);
    $taxCodesById = [];
    foreach ($taxCodes as $taxCode) {
        $taxCodesById[$taxCode['id']] = $taxCode;
    }

    $taxRateRows = merd_catalogue_query_all(
        $pdo,
        'SELECT id,tax_code_id,rate_basis_points,effective_from_utc,effective_to_utc '
        . "FROM retail_tax_rate_versions WHERE client_id=? AND status='published' "
        . 'AND effective_from_utc<=? AND (effective_to_utc IS NULL OR effective_to_utc>?) '
        . 'ORDER BY tax_code_id,effective_from_utc,id',
        [$clientId, $databaseTime, $databaseTime]
    );
    $taxRates = [];
    $taxRatesByCode = [];
    foreach ($taxRateRows as $row) {
        $rate = [
            'id' => (int)$row['id'],
            'tax_code_id' => (int)$row['tax_code_id'],
            'rate_basis_points' => (int)$row['rate_basis_points'],
            'effective_from_utc' => merd_catalogue_utc($row['effective_from_utc']),
            'effective_to_utc' => merd_catalogue_utc($row['effective_to_utc']),
        ];
        $taxRates[] = $rate;
        $taxRatesByCode[$rate['tax_code_id']] = $rate;
    }

    $assignmentRows = merd_catalogue_query_all(
        $pdo,
        'SELECT id,product_id,tax_code_id,effective_from_utc,effective_to_utc '
        . "FROM retail_product_tax_assignments WHERE client_id=? AND status='published' "
        . 'AND effective_from_utc<=? AND (effective_to_utc IS NULL OR effective_to_utc>?) '
        . 'ORDER BY product_id,effective_from_utc,id',
        [$clientId, $databaseTime, $databaseTime]
    );
    $assignments = [];
    $assignmentsByProduct = [];
    foreach ($assignmentRows as $row) {
        $assignment = [
            'id' => (int)$row['id'],
            'product_id' => (int)$row['product_id'],
            'tax_code_id' => (int)$row['tax_code_id'],
            'effective_from_utc' => merd_catalogue_utc($row['effective_from_utc']),
            'effective_to_utc' => merd_catalogue_utc($row['effective_to_utc']),
        ];
        $assignments[] = $assignment;
        $assignmentsByProduct[$assignment['product_id']] = $assignment;
    }

    $productRows = merd_catalogue_query_all(
        $pdo,
        'SELECT p.id,p.category_id,p.sku,p.sku_normalized,p.name,p.description,'
        . 'p.status,p.archived_at,p.tombstoned_at,p.unit_of_measure,'
        . 'i.id AS inventory_id,i.reorder_level,b.quantity AS stock_quantity,'
        . 'b.revision AS stock_revision,e.status AS exception_status,'
        . 'e.first_detected_at_utc,e.latest_detected_at_utc,'
        . 'e.lowest_observed_balance,e.latest_balance,e.balance_recovered_at_utc '
        . 'FROM retail_products p '
        . 'LEFT JOIN retail_store_inventory i ON i.client_id=p.client_id '
        . 'AND i.product_id=p.id AND i.store_id=? '
        . 'LEFT JOIN retail_stock_balances b ON b.client_id=p.client_id '
        . 'AND b.product_id=p.id AND b.store_id=? '
        . 'LEFT JOIN retail_negative_stock_exceptions e ON e.client_id=p.client_id '
        . 'AND e.product_id=p.id AND e.store_id=? '
        . 'WHERE p.client_id=? ORDER BY p.id',
        [$storeId, $storeId, $storeId, $clientId]
    );

    $products = [];
    $warnings = [];
    foreach ($productRows as $row) {
        $productId = (int)$row['id'];
        $lifecycle = merd_catalogue_lifecycle($row);
        $candidatePrices = $pricesByProduct[$productId] ?? [];
        $resolvedPrice = $candidatePrices[0] ?? null;
        $assignment = $assignmentsByProduct[$productId] ?? null;
        $taxCode = $assignment === null ? null : ($taxCodesById[$assignment['tax_code_id']] ?? null);
        $taxRate = $taxCode === null ? null : ($taxRatesByCode[$taxCode['id']] ?? null);
        $resolvedTax = $assignment !== null && $taxCode !== null && $taxRate !== null
            && $taxCode['status'] === 'active'
            ? [
                'assignment_id' => $assignment['id'],
                'tax_code_id' => $taxCode['id'],
                'tax_code' => $taxCode['code'],
                'tax_code_name' => $taxCode['name'],
                'tax_rate_version_id' => $taxRate['id'],
                'rate_basis_points' => $taxRate['rate_basis_points'],
                'tax_inclusive' => true,
            ]
            : null;

        $reasons = [];
        if ($lifecycle !== 'active') {
            $reasons[] = 'product_' . $lifecycle;
        }
        if ($row['inventory_id'] === null) {
            $reasons[] = 'store_unavailable';
        }
        if ($resolvedPrice === null) {
            $reasons[] = 'missing_effective_price';
        }
        if ($resolvedTax === null) {
            $reasons[] = 'missing_effective_tax';
        }
        foreach ($reasons as $reason) {
            $warnings[] = ['code' => $reason, 'product_id' => $productId];
        }

        $negativeException = $row['exception_status'] === null ? null : [
            'status' => (string)$row['exception_status'],
            'first_detected_at_utc' => merd_catalogue_utc($row['first_detected_at_utc']),
            'latest_detected_at_utc' => merd_catalogue_utc($row['latest_detected_at_utc']),
            'lowest_observed_balance' => merd_catalogue_decimal($row['lowest_observed_balance'], 3),
            'latest_balance' => merd_catalogue_decimal($row['latest_balance'], 3),
            'balance_recovered_at_utc' => merd_catalogue_utc($row['balance_recovered_at_utc']),
        ];
        $products[] = [
            'id' => $productId,
            'category_id' => $row['category_id'] === null ? null : (int)$row['category_id'],
            'sku' => $row['sku'] === null ? null : trim((string)$row['sku']),
            'sku_normalized' => $row['sku_normalized'] === null ? null : (string)$row['sku_normalized'],
            'name' => (string)$row['name'],
            'description' => $row['description'] === null ? null : (string)$row['description'],
            'unit_of_measure' => (string)$row['unit_of_measure'],
            'lifecycle' => $lifecycle,
            'archived_at_utc' => merd_catalogue_utc($row['archived_at']),
            'tombstoned_at_utc' => merd_catalogue_utc($row['tombstoned_at']),
            'barcodes' => $barcodesByProduct[$productId] ?? [],
            'store' => [
                'available' => $row['inventory_id'] !== null,
                'reorder_level' => $row['reorder_level'] === null
                    ? null : merd_catalogue_decimal($row['reorder_level'], 3),
            ],
            'effective_prices' => $candidatePrices,
            'resolved_price' => $resolvedPrice,
            'resolved_tax' => $resolvedTax,
            'stock' => [
                'balance' => merd_catalogue_decimal($row['stock_quantity'] ?? '0', 3),
                'revision' => (int)($row['stock_revision'] ?? 0),
                'negative_exception' => $negativeException,
            ],
            'sellable' => $reasons === [],
            'not_sellable_reasons' => $reasons,
        ];
    }

    $snapshot = [
        'context' => [
            'client' => [
                'id' => (int)$context['client_id'],
                'name' => (string)$context['client_name'],
                'status' => (string)$context['client_status'],
            ],
            'store' => [
                'id' => (int)$context['store_id'],
                'name' => (string)$context['store_name'],
                'code' => (string)$context['store_code'],
                'status' => (string)$context['store_status'],
            ],
            'device_uuid' => $deviceUuid,
        ],
        'currency' => ['code' => $currency, 'money_scale' => 4, 'tax_inclusive' => true],
        'categories' => $categories,
        'tax_codes' => $taxCodes,
        'effective_tax_rates' => $taxRates,
        'product_tax_assignments' => $assignments,
        'products' => $products,
        'warnings' => $warnings,
    ];
    $revisionMaterial = $snapshot;
    unset($revisionMaterial['context']['device_uuid']);
    $canonical = json_encode(
        $revisionMaterial,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
    $hash = hash('sha256', $canonical, true);
    $opaque = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    $generatedAt = $utc->format('Y-m-d\TH:i:s.u\Z');

    return [
        'success' => true,
        'api' => 'sync_catalogue.php',
        'contract_version' => MERD_CATALOGUE_CONTRACT_VERSION,
        'snapshot_type' => 'full',
        'snapshot_revision' => 'sha256:' . bin2hex($hash),
        'cursor_seed' => 'm2f1_' . $opaque,
        'server_time_utc' => $generatedAt,
        'snapshot_generated_at_utc' => $generatedAt,
        'snapshot' => $snapshot,
    ];
}

function merd_catalogue_handle_request(
    PDO $pdo,
    array $server,
    array $body,
    ?DateTimeImmutable $now = null
): array {
    merd_request_require_method($server, 'POST');
    merd_request_require_json_content_type($server);
    $auth = merd_device_authenticate_request($pdo, $server, $body);
    merd_catalogue_validate_request($body);
    return merd_catalogue_full_snapshot(
        $pdo,
        $auth,
        $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))
    );
}

function merd_catalogue_validate_request(array $body): void
{
    $version = merd_request_text($body['contract_version'] ?? null, 'contract_version', 80);
    if ($version !== MERD_CATALOGUE_CONTRACT_VERSION) {
        throw new MerdRequestException(
            'unsupported_catalogue_contract',
            409,
            'Catalogue contract version is not supported.'
        );
    }
    if (($body['snapshot_type'] ?? null) !== 'full') {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
}
