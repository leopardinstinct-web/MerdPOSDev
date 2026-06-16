<?php
/**
 * MerdPOS get_employees.php
 * Version: client-wide-employees-v3-no-passwords
 *
 * Device is still authorised against the selected store/device token.
 * After device auth passes, employee list is loaded client-wide.
 * Password/PIN columns are intentionally NOT returned to the app.
 */

require_once "config.php";
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

const API_VERSION = 'client-wide-employees-v3-no-passwords';

function respond_json($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail_json($message, $status = 400) {
    respond_json([
        'success' => false,
        'api' => 'get_employees.php',
        'version' => API_VERSION,
        'error' => $message,
    ], $status);
}

$client_id = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT);
$store_id = filter_var($_GET['store_id'] ?? null, FILTER_VALIDATE_INT);
$activation_token = trim((string)($_GET['activation_token'] ?? ''));

if (!$client_id || !$store_id || $activation_token === '') {
    fail_json('Missing required fields.', 400);
}

try {
    $stmt = $pdo->prepare("\n        SELECT id\n        FROM devices\n        WHERE client_id = ?\n          AND store_id = ?\n          AND activation_token = ?\n          AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->execute([$client_id, $store_id, $activation_token]);

    if (!$stmt->fetch()) {
        fail_json('Device not authorized.', 401);
    }

    $stmt = $pdo->prepare("\n        SELECT\n            id,\n            client_id,\n            store_id,\n            full_name,\n            user_id,\n            employee_type,\n            role_name,\n            hourly_rate,\n            status\n        FROM employees\n        WHERE client_id = ?\n          AND status = 'active'\n        ORDER BY full_name\n    ");
    $stmt->execute([$client_id]);

    respond_json([
        'success' => true,
        'api' => 'get_employees.php',
        'version' => API_VERSION,
        'scope' => [
            'client_id' => (int)$client_id,
            'authorized_device_store_id' => (int)$store_id,
            'employee_store_filter' => null,
        ],
        'employees' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    fail_json('Could not load employees.', 500);
}
