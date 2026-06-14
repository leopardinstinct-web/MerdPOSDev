<?php
require_once "config.php";

$data = json_decode(file_get_contents("php://input"), true);

$client_id = $data["client_id"] ?? null;
$store_id = $data["store_id"] ?? null;
$store_name = $data["store_name"] ?? "";
$device_uuid = $data["device_uuid"] ?? "";
$activation_token = $data["activation_token"] ?? "";
$logs = $data["logs"] ?? [];

if (!$client_id || !$store_id || !$device_uuid || !$activation_token) {
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
    AND device_uuid = ?
    AND activation_token = ?
    AND status = 'active'
    LIMIT 1
");
$stmt->execute([$client_id, $store_id, $device_uuid, $activation_token]);

if (!$stmt->fetch()) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "error" => "Device not authorized"
    ]);
    exit;
}

$synced = [];

foreach ($logs as $log) {
    $stmt = $pdo->prepare("
        INSERT INTO employee_logs
        (
            client_id,
            store_id,
            employee_id,
            user_name,
            store_name,
            log_type,
            log_date,
            log_time,
            log_datetime,
            device_uuid,
            local_log_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            employee_id = VALUES(employee_id),
            user_name = VALUES(user_name),
            store_name = VALUES(store_name),
            log_type = VALUES(log_type),
            log_date = VALUES(log_date),
            log_time = VALUES(log_time),
            log_datetime = VALUES(log_datetime),
            synced_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $client_id,
        $store_id,
        $log["employee_id"] ?? null,
        $log["user_name"],
        $store_name ?: ($log["store_name"] ?? ""),
        strtoupper($log["log_type"]),
        $log["log_date"],
        $log["log_time"],
        $log["log_datetime"],
        $device_uuid,
        $log["local_log_id"]
    ]);

    $synced[] = $log["local_log_id"];
}

$pdo->prepare("
    UPDATE devices
    SET last_sync = NOW()
    WHERE device_uuid = ?
")->execute([$device_uuid]);

echo json_encode([
    "success" => true,
    "synced_log_ids" => $synced,
    "count" => count($synced)
]);