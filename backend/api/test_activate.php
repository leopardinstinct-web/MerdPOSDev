<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "config.php";

$data = [
    "client_id" => 1,
    "store_id" => 1,
    "device_uuid" => "TEST-DEVICE-001",
    "device_name" => "Test Android POS"
];

$client_id = $data["client_id"];
$store_id = $data["store_id"];
$device_uuid = $data["device_uuid"];
$device_name = $data["device_name"];

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
    "client_id" => $client_id,
    "store_id" => $store_id,
    "device_uuid" => $device_uuid,
    "activation_token" => $activation_token
]);