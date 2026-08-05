<?php
require_once __DIR__ . '/includes/maintenance_guard.php';
merd_maintenance_guard();
require_once "config.php";

$csvPath = __DIR__ . "/../imports/timesheet_import.csv";

if (!file_exists($csvPath)) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "error" => "CSV file not found",
        "expected_path" => $csvPath
    ]);
    exit;
}

$clientId = 1;

$storeStmt = $pdo->prepare("
    SELECT id 
    FROM stores 
    WHERE client_id = ? 
    AND store_name = ?
    LIMIT 1
");

$employeeStmt = $pdo->prepare("
    SELECT id 
    FROM employees 
    WHERE client_id = ?
    AND full_name = ?
    LIMIT 1
");

$insertStmt = $pdo->prepare("
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
        synced_at = CURRENT_TIMESTAMP
");

$handle = fopen($csvPath, "r");

if (!$handle) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Could not open CSV file"
    ]);
    exit;
}

$headers = fgetcsv($handle);

if (!$headers) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "CSV has no header row"
    ]);
    exit;
}

$headers = array_map(function ($h) {
    return strtoupper(trim($h));
}, $headers);

$imported = 0;
$skipped = 0;
$unmatchedEmployees = [];
$unmatchedStores = [];
$errors = [];

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < count($headers)) {
        $skipped++;
        continue;
    }

    $data = array_combine($headers, $row);

    $userName = trim($data["USER_NAME"] ?? "");
    $storeName = trim($data["STORE_NAME"] ?? "");
    $logType = strtoupper(trim($data["LOG_TYPE"] ?? ""));
    $date = trim($data["DATE"] ?? "");
    $time = trim($data["TIME"] ?? "");

    if ($userName === "" || $storeName === "" || $logType === "" || $date === "" || $time === "") {
        $skipped++;
        continue;
    }

    if (!in_array($logType, ["IN", "OUT"])) {
        $skipped++;
        continue;
    }

    // Normalize date if needed
    $timestamp = strtotime($date . " " . $time);

    if (!$timestamp) {
        $errors[] = [
            "user" => $userName,
            "store" => $storeName,
            "date" => $date,
            "time" => $time,
            "error" => "Invalid datetime"
        ];
        $skipped++;
        continue;
    }

    $logDate = date("Y-m-d", $timestamp);
    $logTime = date("H:i:s", $timestamp);
    $logDatetime = date("Y-m-d H:i:s", $timestamp);

    // Match store
    $storeStmt->execute([$clientId, $storeName]);
    $store = $storeStmt->fetch();

    if (!$store) {
        $unmatchedStores[$storeName] = true;
        $skipped++;
        continue;
    }

    $storeId = $store["id"];

    // Match employee by name
    $employeeStmt->execute([$clientId, $userName]);
    $employee = $employeeStmt->fetch();

    $employeeId = $employee ? $employee["id"] : null;

    if (!$employee) {
        $unmatchedEmployees[$userName] = true;
    }

    $deviceUuid = "LEGACY-CSV-IMPORT";

    $localLogId = md5(
        $clientId . "|" .
        $storeName . "|" .
        $userName . "|" .
        $logType . "|" .
        $logDatetime
    );

    try {
        $insertStmt->execute([
            $clientId,
            $storeId,
            $employeeId,
            $userName,
            $storeName,
            $logType,
            $logDate,
            $logTime,
            $logDatetime,
            $deviceUuid,
            $localLogId
        ]);

        $imported++;
    } catch (Exception $e) {
        $errors[] = [
            "user" => $userName,
            "store" => $storeName,
            "datetime" => $logDatetime,
            "error" => $e->getMessage()
        ];
        $skipped++;
    }
}

fclose($handle);

echo json_encode([
    "success" => true,
    "message" => "Timesheet logs import completed",
    "imported_or_updated" => $imported,
    "skipped" => $skipped,
    "unmatched_employees" => array_keys($unmatchedEmployees),
    "unmatched_stores" => array_keys($unmatchedStores),
    "errors" => $errors
]);
