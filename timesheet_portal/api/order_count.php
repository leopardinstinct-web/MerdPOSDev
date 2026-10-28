<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_inventory.php';

header('Cache-Control: no-store, private');
$user = require_login();
$store = $_SESSION['order_store_code'] ?? '';
if (!is_string($store) || !in_array($store, ['MX','ET','DT','RT'], true)) {
    json_response(['success' => false, 'error' => 'Select a store before counting stock.'], 409);
}
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    json_response(['success' => false, 'error' => 'Unsupported method.'], 405);
}
if (!isset($_SESSION['order_store_csrf']) || !is_string($_SESSION['order_store_csrf'])) {
    json_response(['success' => false, 'error' => 'Store session expired. Reload the order page.'], 403);
}
if ($method === 'POST') {
    $submitted = $_POST['csrf_token'] ?? null;
    if (!is_string($submitted) || !hash_equals($_SESSION['order_store_csrf'], $submitted)) {
        json_response(['success' => false, 'error' => 'Invalid security token. Reload the page.'], 403);
    }
}

try {
    $catalog = order_catalog($store);
    if (!isset($_SESSION['order_draft_counts']) || !is_array($_SESSION['order_draft_counts'])) {
        $_SESSION['order_draft_counts'] = [];
    }
    if (!isset($_SESSION['order_draft_counts'][$store]) || !is_array($_SESSION['order_draft_counts'][$store])) {
        $_SESSION['order_draft_counts'][$store] = ['AUTO' => [], 'MANUAL' => []];
    }
    $counts =& $_SESSION['order_draft_counts'][$store];
    foreach (['AUTO','MANUAL'] as $type) {
        if (!isset($counts[$type]) || !is_array($counts[$type])) $counts[$type] = [];
    }
    if ($method === 'POST') {
        $action = $_POST['action'] ?? '';
        $type = $_POST['type'] ?? '';
        if (!is_string($type) || !in_array($type, ['AUTO','MANUAL'], true)) {
            throw new InvalidArgumentException('Select cigarettes or accessories.');
        }
        $id = null;
        $increment = 1;
        if ($action === 'scan') {
            $barcode = $_POST['barcode'] ?? '';
            if (!is_string($barcode) || !preg_match('/^[A-Za-z0-9]{4,32}$/D', trim($barcode))) {
                throw new InvalidArgumentException('Invalid barcode. Use the exact barcode digits.');
            }
            $matched = order_barcode_match($catalog, trim($barcode), $type);
            $id = $matched['product']['id'];
            $increment = $matched['quantity'];
        } elseif (in_array($action, ['select','set','remove'], true)) {
            $id = $_POST['product_id'] ?? null;
            if (!is_string($id) || !isset($catalog[$id]) || $catalog[$id]['type'] !== $type) {
                throw new InvalidArgumentException('Selected product is no longer in the catalogue.');
            }
        } else {
            throw new InvalidArgumentException('Unsupported action.');
        }
        if ($action === 'remove') {
            unset($counts[$type][$id]);
        } elseif ($action === 'set') {
            $raw = $_POST['quantity'] ?? null;
            if (!is_string($raw) || !ctype_digit($raw) || strlen($raw) > 5 || (int)$raw > 99999) {
                throw new InvalidArgumentException('Quantity must be an integer from 0 to 99999.');
            }
            $quantity = (int)$raw;
            if ($quantity === 0) unset($counts[$type][$id]);
            else $counts[$type][$id] = $quantity;
        } else {
            $current = (int)($counts[$type][$id] ?? 0);
            if ($current + $increment > 99999) {
                throw new InvalidArgumentException('Quantity exceeds the maximum allowed.');
            }
            $counts[$type][$id] = $current + $increment;
        }
    }
    json_response([
        'success' => true,
        'store' => $store,
        'products' => order_response_products($catalog, $counts),
        'csrf_token' => $_SESSION['order_store_csrf'],
        'draft_only' => true
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('MERDPOS Alpha Inventory order-count error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Could not load the Inventory DBA catalogue. Check the published sheet and try again.'], 503);
}
