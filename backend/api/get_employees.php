<?php
/**
 * MerdPOS get_employees.php
 * Version: client-wide-employees-v2
 *
 * Device is still authorised against the selected store/device token.
 * After device auth passes, employee login list is loaded client-wide so staff
 * can log in from any store device and still see their own cross-store timesheet.
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

$client_id = $_GET["client_id"] ?? null;
$store_id = $_GET["store_id"] ?? null;
$activation_token = $_GET["activation_token"] ?? "";

if (!$client_id || !$store_id || !$activation_token) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "api" => "get_employees.php",
        "version" => "client-wide-employees-v2",
        "error" => "Missing required fields"
    ]);
    exit;
}

try {
    // Keep device/store authorisation strict.
    $stmt = $pdo->prepare("
        SELECT id
        FROM devices
        WHERE client_id = ?
        AND store_id = ?
        AND activation_token = ?
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$client_id, $store_id, $activation_token]);

    if (!$stmt->fetch()) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "api" => "get_employees.php",
            "version" => "client-wide-employees-v2",
            "error" => "Device not authorized"
        ]);
        exit;
    }

    // Important change: client-wide employees, not selected-store-only employees.
    // Payroll/timesheet logs are store-aware separately in employee_logs.
    $stmt = $pdo->prepare("
        SELECT
            id,
            client_id,
            store_id,
            full_name,
            user_id,
            login_password,
            employee_type,
            pin_code,
            role_name,
            hourly_rate,
            status
        FROM employees
        WHERE client_id = ?
        AND status = 'active'
        ORDER BY full_name
    ");
    $stmt->execute([$client_id]);

    echo json_encode([
        "success" => true,
        "api" => "get_employees.php",
        "version" => "client-wide-employees-v2",
        "scope" => [
            "client_id" => (int)$client_id,
            "authorized_device_store_id" => (int)$store_id,
            "employee_store_filter" => null
        ],
        "employees" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "api" => "get_employees.php",
        "version" => "client-wide-employees-v2",
        "error" => $e->getMessage()
    ]);
}
