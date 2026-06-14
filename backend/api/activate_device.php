<?php
require_once "config.php";

$data = json_decode(file_get_contents("php://input"), true);

$client_id = $data["client_id"] ?? null;
$store_id = $data["store_id"] ?? null;
$device_uuid = $data["device_uuid"] ?? "";
$device_name = $data["device_name"] ?? "Android POS";

if (!$client_id || !$store_id || !$device_uuid) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "Missing required fields"
    ]);
    exit;
}

$activation_token = bin2hex(random_bytes(32));

$stmt = $pdo->prepare("
    INSERT INTO devices 
    (client_id, store_id, device_uuid, device_name, activation_token)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        store_id = VALUES(store_id),
        device_name = VALUES(device_name),
        activation_token = VALUES(activation_token),
        status = 'active'
");

$stmt->execute([
    $client_id,
    $store_id,
    $device_uuid,
    $device_name,
    $activation_token
]);

echo json_encode([
    "success" => true,
    "activation_token" => $activation_token
]);