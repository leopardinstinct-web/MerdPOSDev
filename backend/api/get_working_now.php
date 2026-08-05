<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';

$requestId = merd_request_id();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    merd_request_require_method($_SERVER, 'GET');
    $auth = merd_device_authenticate_request($pdo, $_SERVER, [], $_GET);
    $stmt = $pdo->prepare(
        "SELECT latest.employee_id, latest.user_name, latest.store_id, latest.store_name, latest.log_type, "
        . 'latest.log_date, latest.log_time, latest.log_datetime, latest.synced_at, latest.local_log_id, '
        . 'e.full_name, e.user_id, e.role_name, e.employee_type, '
        . 'TIMESTAMPDIFF(MINUTE, latest.log_datetime, UTC_TIMESTAMP()) AS working_minutes '
        . 'FROM employee_logs latest INNER JOIN ('
        . 'SELECT employee_id, MAX(log_datetime) AS max_log_datetime FROM employee_logs '
        . 'WHERE client_id = ? GROUP BY employee_id) last_log '
        . 'ON last_log.employee_id = latest.employee_id AND last_log.max_log_datetime = latest.log_datetime '
        . 'LEFT JOIN employees e ON e.client_id = latest.client_id AND e.id = latest.employee_id '
        . "WHERE latest.client_id = ? AND UPPER(latest.log_type) = 'IN' "
        . 'ORDER BY latest.store_name, COALESCE(e.full_name, latest.user_name)'
    );
    $stmt->execute([$auth['client_id'], $auth['client_id']]);
    $people = [];
    $latestLogSync = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $syncValue = $row['synced_at'] ?? null;
        if ($syncValue && ($latestLogSync === null || strtotime($syncValue) > strtotime($latestLogSync))) {
            $latestLogSync = $syncValue;
        }
        $people[] = [
            'employee_id' => isset($row['employee_id']) ? (int)$row['employee_id'] : null,
            'user_id' => $row['user_id'] ?? null,
            'employee_name' => $row['full_name'] ?: ($row['user_name'] ?? ''),
            'role_name' => $row['role_name'] ?? '', 'employee_type' => $row['employee_type'] ?? '',
            'store_id' => isset($row['store_id']) ? (int)$row['store_id'] : null,
            'store_name' => $row['store_name'] ?? '', 'since_date' => $row['log_date'] ?? '',
            'since_time' => $row['log_time'] ?? '', 'since_datetime' => $row['log_datetime'] ?? '',
            'working_minutes' => isset($row['working_minutes']) ? (int)$row['working_minutes'] : null,
            'last_log_sync_time' => $row['synced_at'] ?? null, 'local_log_id' => $row['local_log_id'] ?? '',
        ];
    }
    merd_api_send(merd_api_success([
        'api' => 'get_working_now.php', 'version' => 'working-now-v2-secure-device',
        'server_time' => gmdate('Y-m-d H:i:s'),
        'device_last_sync' => $auth['device']['last_sync'] ?? null,
        'latest_log_sync_time' => $latestLogSync,
        'scope' => ['client_id' => $auth['client_id'], 'authorized_device_store_id' => $auth['store_id'], 'employee_store_filter' => null],
        'count' => count($people), 'people' => $people,
    ]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('get_working_now request failed');
    merd_api_fail('internal_error', 'Could not load working employees.', 500, $requestId);
}
