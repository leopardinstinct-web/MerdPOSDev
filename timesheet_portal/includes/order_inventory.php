<?php
declare(strict_types=1);
require_once __DIR__ . '/sheets.php';

/**
 * Inventory DBA remains the only catalogue authority. Reuse the existing
 * read/parse CSV transport without changing timesheet SPREADSHEET_ID.
 */
const ORDER_INVENTORY_SPREADSHEET_ID = '1SBgewVkpcEOe7yf8E4FSBRoArr6vJfX_vuxCZ60Mw08';
const ORDER_INVENTORY_TTL = 180;

function order_inventory_csv(): array
{
    $cache = sys_get_temp_dir() . '/merd_alpha_inventory_' . sha1(ORDER_INVENTORY_SPREADSHEET_ID) . '.csv';
    $csv = false;
    if (is_file($cache) && (time() - (int)filemtime($cache)) < ORDER_INVENTORY_TTL) {
        $csv = file_get_contents($cache);
    }
    if ($csv === false) {
        $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode(ORDER_INVENTORY_SPREADSHEET_ID)
            . '/gviz/tq?tqx=out:csv&sheet=Inventory';
        $csv = fetch_url($url);
        if (stripos($csv, '<html') !== false || strlen($csv) < 500) {
            throw new RuntimeException('Inventory DBA did not return a valid CSV export.');
        }
        $tmp = $cache . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $csv, LOCK_EX) !== false) {
            @rename($tmp, $cache);
        } else {
            @unlink($tmp);
        }
    }
    $parsed = parse_csv_string($csv);
    $required = [
        'Category','Name','Code_1','Code_2','Code_3',
        'Code_Box_1','Code_Box_2','Code_Box_3',
        'MX_Ceilings','ET_Ceilings','DT_Ceilings','RT_Ceilings',
        'Packaging','TobaccoOrder'
    ];
    foreach ($required as $column) {
        if (!in_array($column, $parsed['headers'], true)) {
            throw new RuntimeException('Inventory DBA missing required column: ' . $column);
        }
    }
    return $parsed['rows'];
}

function order_catalog(string $store): array
{
    if (!in_array($store, ['MX','ET','DT','RT'], true)) {
        throw new InvalidArgumentException('Unknown store.');
    }
    $products = [];
    foreach (order_inventory_csv() as $row) {
        $type = strtoupper(trim((string)($row['TobaccoOrder'] ?? '')));
        if ($type !== 'AUTO' && $type !== 'MANUAL') continue;
        $name = trim((string)($row['Name'] ?? ''));
        if ($name === '') continue;
        $unit = []; $box = [];
        foreach (['Code_1','Code_2','Code_3'] as $key) {
            $v = trim((string)($row[$key] ?? ''));
            if ($v !== '') $unit[] = $v;
        }
        foreach (['Code_Box_1','Code_Box_2','Code_Box_3'] as $key) {
            $v = trim((string)($row[$key] ?? ''));
            if ($v !== '') $box[] = $v;
        }
        $ceilingRaw = trim((string)($row[$store . '_Ceilings'] ?? ''));
        $ceiling = ($ceilingRaw !== '' && ctype_digit($ceilingRaw))
            ? (int)$ceilingRaw : null;
        $packRaw = trim((string)($row['Packaging'] ?? ''));
        $packaging = ($packRaw !== '' && ctype_digit($packRaw) && (int)$packRaw > 0)
            ? (int)$packRaw : null;
        $category = trim((string)($row['Category'] ?? ''));
        $id = substr(hash('sha256', implode("\x1f", [$category, $name, $type, ...$unit, ...$box])), 0, 24);
        // Duplicate catalogue rows must not be silently merged.
        if (isset($products[$id])) {
            throw new RuntimeException('Duplicate product identity in Inventory DBA: ' . $name);
        }
        $products[$id] = [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'type' => $type,
            'unit_barcodes' => array_values(array_unique($unit)),
            'box_barcodes' => array_values(array_unique($box)),
            'packaging' => $packaging,
            'ceiling' => $type === 'AUTO' ? $ceiling : null
        ];
    }
    return $products;
}

function order_barcode_match(array $catalog, string $barcode, string $type): array
{
    $matches = [];
    foreach ($catalog as $product) {
        if ($product['type'] !== $type) continue;
        if (in_array($barcode, $product['unit_barcodes'], true)) {
            $matches[] = ['product' => $product, 'quantity' => 1];
        }
        if (in_array($barcode, $product['box_barcodes'], true)) {
            if ($product['packaging'] === null) {
                throw new InvalidArgumentException('Box barcode has no valid Packaging value. Correct the count manually.');
            }
            $matches[] = ['product' => $product, 'quantity' => $product['packaging']];
        }
    }
    if (count($matches) !== 1) {
        throw new InvalidArgumentException(count($matches) === 0
            ? 'No matching ' . ($type === 'AUTO' ? 'cigarette' : 'accessory') . ' barcode in Inventory DBA.'
            : 'Barcode matches multiple catalogue entries. Select the product by name instead.');
    }
    return $matches[0];
}

function order_response_products(array $catalog, array $counts): array
{
    $result = [];
    foreach ($catalog as $id => $product) {
        $count = (int)($counts[$product['type']][$id] ?? 0);
        $product['count'] = $count;
        $product['order_quantity'] = $product['type'] === 'AUTO' && $product['ceiling'] !== null
            ? max(0, $product['ceiling'] - $count)
            : ($product['type'] === 'MANUAL' ? $count : null);
        $result[] = $product;
    }
    usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $result;
}
