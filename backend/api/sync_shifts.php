<?php
require_once "config.php";

$data = json_decode(file_get_contents("php://input"), true);

$client_id = $data["client_id"] ?? null;
$store_id = $data["store_id"] ?? null;
$device_uuid = $data["device_uuid"] ?? "";
$activation_token = $data["activation_token"] ?? "";
$shifts = $data["shifts"] ?? [];

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

foreach ($shifts as $shift) {
    $stmt = $pdo->prepare("
        INSERT INTO shifts
        (
            client_id, store_id, employee_id, device_uuid,
            local_shift_id, clock_in, clock_out,
            break_minutes, total_minutes, status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            clock_out = VALUES(clock_out),
            break_minutes = VALUES(break_minutes),
            total_minutes = VALUES(total_minutes),
            status = VALUES(status),
            synced_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $client_id,
        $store_id,
        $shift["employee_id"],
        $device_uuid,
        $shift["local_shift_id"],
        $shift["clock_in"],
        $shift["clock_out"],
        $shift["break_minutes"] ?? 0,
        $shift["total_minutes"] ?? 0,
        $shift["status"] ?? "open"
    ]);

    $synced[] = $shift["local_shift_id"];
}

$pdo->prepare("
    UPDATE devices
    SET last_sync = NOW()
    WHERE device_uuid = ?
")->execute([$device_uuid]);

echo json_encode([
    "success" => true,
    "synced_shift_ids" => $synced
]);