<?php
require_once "config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['success' => false, 'error' => 'POST required'], 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        respond_json(['success' => false, 'error' => 'Invalid JSON body'], 400);
    }

    $clientId = $data['client_id'] ?? null;
    $storeId = $data['store_id'] ?? null;
    $deviceUuid = trim((string)($data['device_uuid'] ?? ''));
    $activationToken = trim((string)($data['activation_token'] ?? ''));
    $employeeId = $data['employee_id'] ?? null;
    $oldPassword = trim((string)($data['old_password'] ?? ''));
    $newPassword = trim((string)($data['new_password'] ?? ''));

    if (!$clientId || !$storeId || !$deviceUuid || !$activationToken || !$employeeId || $oldPassword === '' || $newPassword === '') {
        respond_json(['success' => false, 'error' => 'Missing required fields'], 400);
    }

    if (!preg_match('/^\d+$/', $newPassword)) {
        respond_json(['success' => false, 'error' => 'New password must be numeric'], 400);
    }

    if (strlen($newPassword) < 4) {
        respond_json(['success' => false, 'error' => 'New password must be at least 4 digits'], 400);
    }

    $stmt = $pdo->prepare("\n        SELECT id\n        FROM devices\n        WHERE client_id = ?\n        AND store_id = ?\n        AND device_uuid = ?\n        AND activation_token = ?\n        AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->execute([$clientId, $storeId, $deviceUuid, $activationToken]);

    if (!$stmt->fetch()) {
        respond_json(['success' => false, 'error' => 'Device not authorized'], 401);
    }

    $stmt = $pdo->prepare("\n        SELECT id, full_name, login_password\n        FROM employees\n        WHERE client_id = ?\n        AND id = ?\n        AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->execute([$clientId, $employeeId]);
    $employee = $stmt->fetch();

    if (!$employee) {
        respond_json(['success' => false, 'error' => 'Employee not found or inactive'], 404);
    }

    if ((string)$employee['login_password'] !== $oldPassword) {
        respond_json(['success' => false, 'error' => 'Current password is incorrect'], 403);
    }

    $stmt = $pdo->prepare("\n        UPDATE employees\n        SET login_password = ?, pin_code = ?\n        WHERE client_id = ?\n        AND id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$newPassword, $newPassword, $clientId, $employeeId]);

    respond_json([
        'success' => true,
        'message' => 'Password changed successfully',
        'employee_id' => (string)$employeeId,
        'employee_name' => $employee['full_name'] ?? ''
    ]);
} catch (Throwable $e) {
    respond_json(['success' => false, 'error' => $e->getMessage()], 500);
}
