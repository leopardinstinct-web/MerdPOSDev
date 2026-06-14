<?php
/**
 * MerdPOS get_working_now.php
 * Version: working-now-v1-client-wide-current-in
 *
 * Returns employees under the client whose latest employee_logs entry is IN.
 * Device authorization remains store/device scoped, but the result is client-wide.
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

function respond_json($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_param($key) {
    $value = $_GET[$key] ?? '';
    $value = trim((string)$value);
    if ($value === '') {
        respond_json([
            'success' => false,
            'api' => 'get_working_now.php',
            'version' => 'working-now-v1-client-wide-current-in',
            'error' => "Missing required parameter: {$key}",
        ], 400);
    }
    return $value;
}

try {
    $clientId = require_param('client_id');
    $storeId = require_param('store_id');
    $deviceUuid = require_param('device_uuid');
    $activationToken = require_param('activation_token');

    $stmt = $pdo->prepare("\n        SELECT id, last_sync\n        FROM devices\n        WHERE client_id = ?\n        AND store_id = ?\n        AND device_uuid = ?\n        AND activation_token = ?\n        AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->execute([$clientId, $storeId, $deviceUuid, $activationToken]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        respond_json([
            'success' => false,
            'api' => 'get_working_now.php',
            'version' => 'working-now-v1-client-wide-current-in',
            'error' => 'Device not authorized',
        ], 401);
    }

    // Find each employee's latest log under this client. If the latest log is IN,
    // the employee is currently working. This is client-wide, not store-limited.
    $stmt = $pdo->prepare("\n        SELECT\n            latest.employee_id,\n            latest.user_name,\n            latest.store_id,\n            latest.store_name,\n            latest.log_type,\n            latest.log_date,\n            latest.log_time,\n            latest.log_datetime,\n            latest.synced_at,\n            latest.local_log_id,\n            e.full_name,\n            e.user_id,\n            e.role_name,\n            e.employee_type,\n            TIMESTAMPDIFF(MINUTE, latest.log_datetime, NOW()) AS working_minutes\n        FROM employee_logs latest\n        INNER JOIN (\n            SELECT employee_id, MAX(log_datetime) AS max_log_datetime\n            FROM employee_logs\n            WHERE client_id = ?\n            GROUP BY employee_id\n        ) last_log\n            ON last_log.employee_id = latest.employee_id\n            AND last_log.max_log_datetime = latest.log_datetime\n        LEFT JOIN employees e\n            ON e.client_id = latest.client_id\n            AND e.id = latest.employee_id\n        WHERE latest.client_id = ?\n        AND UPPER(latest.log_type) = 'IN'\n        ORDER BY latest.store_name, COALESCE(e.full_name, latest.user_name)\n    ");
    $stmt->execute([$clientId, $clientId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $people = [];
    $latestLogSync = null;

    foreach ($rows as $row) {
        $syncValue = $row['synced_at'] ?? null;
        if ($syncValue && ($latestLogSync === null || strtotime($syncValue) > strtotime($latestLogSync))) {
            $latestLogSync = $syncValue;
        }

        $people[] = [
            'employee_id' => isset($row['employee_id']) ? (int)$row['employee_id'] : null,
            'user_id' => $row['user_id'] ?? null,
            'employee_name' => $row['full_name'] ?: ($row['user_name'] ?? ''),
            'role_name' => $row['role_name'] ?? '',
            'employee_type' => $row['employee_type'] ?? '',
            'store_id' => isset($row['store_id']) ? (int)$row['store_id'] : null,
            'store_name' => $row['store_name'] ?? '',
            'since_date' => $row['log_date'] ?? '',
            'since_time' => $row['log_time'] ?? '',
            'since_datetime' => $row['log_datetime'] ?? '',
            'working_minutes' => isset($row['working_minutes']) ? (int)$row['working_minutes'] : null,
            'last_log_sync_time' => $row['synced_at'] ?? null,
            'local_log_id' => $row['local_log_id'] ?? '',
        ];
    }

    respond_json([
        'success' => true,
        'api' => 'get_working_now.php',
        'version' => 'working-now-v1-client-wide-current-in',
        'server_time' => date('Y-m-d H:i:s'),
        'device_last_sync' => $device['last_sync'] ?? null,
        'latest_log_sync_time' => $latestLogSync,
        'scope' => [
            'client_id' => (int)$clientId,
            'authorized_device_store_id' => (int)$storeId,
            'employee_store_filter' => null,
        ],
        'count' => count($people),
        'people' => $people,
    ]);
} catch (Throwable $e) {
    respond_json([
        'success' => false,
        'api' => 'get_working_now.php',
        'version' => 'working-now-v1-client-wide-current-in',
        'error' => $e->getMessage(),
    ], 500);
}
