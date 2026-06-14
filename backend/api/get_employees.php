<?php
require_once "config.php";

$client_id = $_GET["client_id"] ?? null;
$store_id = $_GET["store_id"] ?? null;
$activation_token = $_GET["activation_token"] ?? "";

if (!$client_id || !$store_id || !$activation_token) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "Missing required fields"
    ]);
    exit;
}

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
        "error" => "Device not authorized"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        id,
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
    AND store_id = ?
    AND status = 'active'
    ORDER BY full_name
");
$stmt->execute([$client_id, $store_id]);

echo json_encode([
    "success" => true,
    "employees" => $stmt->fetchAll()
]);