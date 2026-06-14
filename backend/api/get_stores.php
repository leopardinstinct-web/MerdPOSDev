<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "config.php";

$client_code = $_GET["client_code"] ?? "";
$setup_key = $_GET["setup_key"] ?? "";

$stmt = $pdo->prepare("
    SELECT id, name, client_code
    FROM clients
    WHERE client_code = ?
    AND setup_key = ?
    AND status = 'active'
    LIMIT 1
");

$stmt->execute([$client_code, $setup_key]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "error" => "Invalid company code or setup key"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, store_name, store_code
    FROM stores
    WHERE client_id = ?
    AND status = 'active'
    ORDER BY store_name
");

$stmt->execute([$client["id"]]);
$stores = $stmt->fetchAll();

echo json_encode([
    "success" => true,
    "client" => $client,
    "stores" => $stores
]);