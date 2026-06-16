<?php
/**
 * MerdPOS login.php
 * Version: hashed-login-v1
 *
 * Backend numeric USER_ID + PIN/password verification.
 * Supports transparent migration from plaintext login_password / pin_code to password_hash().
 */

require_once "config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

const API_VERSION = 'hashed-login-v1';
const MAX_FAILED_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

function respond_json($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail_json($message = 'Invalid login.', $status = 400, $extra = []) {
    respond_json(array_merge([
        'success' => false,
        'api' => 'login.php',
        'version' => API_VERSION,
        'error' => $message,
    ], $extra), $status);
}

function require_numeric_string($value, $field, $minLen = 1) {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d+$/', $value) || strlen($value) < $minLen) {
        fail_json('Invalid login.', 400);
    }
    return $value;
}

function is_password_hash_value($value) {
    if (!is_string($value) || $value === '') return false;
    $info = password_get_info($value);
    return (int)($info['algo'] ?? 0) !== 0;
}

function stored_secret_matches($stored, $entered) {
    $stored = (string)$stored;
    $entered = (string)$entered;
    if ($stored === '') return false;
    if (is_password_hash_value($stored)) {
        return password_verify($entered, $stored);
    }
    return hash_equals($stored, $entered);
}

function employee_secret_matches($employee, $entered) {
    return stored_secret_matches($employee['login_password'] ?? '', $entered)
        || stored_secret_matches($employee['pin_code'] ?? '', $entered);
}

function employee_needs_hash_upgrade($employee) {
    return !is_password_hash_value((string)($employee['login_password'] ?? ''))
        || !is_password_hash_value((string)($employee['pin_code'] ?? ''));
}

function auth_attempt_table_exists($pdo) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'employee_auth_attempts'");
        $stmt->execute();
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function get_attempt_row($pdo, $clientId, $storeId, $userId, $deviceUuid, $action) {
    if (!auth_attempt_table_exists($pdo)) return null;
    $stmt = $pdo->prepare("\n        SELECT *\n        FROM employee_auth_attempts\n        WHERE client_id = ?\n          AND store_id = ?\n          AND user_id = ?\n          AND device_uuid = ?\n          AND action = ?\n        LIMIT 1\n    ");
    $stmt->execute([$clientId, $storeId, $userId, $deviceUuid, $action]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function reject_if_locked($pdo, $clientId, $storeId, $userId, $deviceUuid, $action) {
    $row = get_attempt_row($pdo, $clientId, $storeId, $userId, $deviceUuid, $action);
    if (!$row || empty($row['locked_until'])) return;
    $lockedUntil = strtotime((string)$row['locked_until']);
    if ($lockedUntil !== false && $lockedUntil > time()) {
        fail_json('Too many attempts. Try again later.', 429);
    }
}

function record_failed_attempt($pdo, $clientId, $storeId, $employeeId, $userId, $deviceUuid, $action) {
    if (!auth_attempt_table_exists($pdo)) return;

    $row = get_attempt_row($pdo, $clientId, $storeId, $userId, $deviceUuid, $action);
    $failed = $row ? ((int)$row['failed_attempts'] + 1) : 1;
    $lockedUntil = $failed >= MAX_FAILED_ATTEMPTS
        ? date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60)
        : null;

    if ($row) {
        $stmt = $pdo->prepare("\n            UPDATE employee_auth_attempts\n            SET employee_id = ?, failed_attempts = ?, locked_until = ?, last_failed_at = NOW()\n            WHERE id = ?\n        ");
        $stmt->execute([$employeeId, $failed, $lockedUntil, $row['id']]);
        return;
    }

    $stmt = $pdo->prepare("\n        INSERT INTO employee_auth_attempts\n            (client_id, store_id, employee_id, user_id, device_uuid, action, failed_attempts, locked_until, last_failed_at)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())\n    ");
    $stmt->execute([$clientId, $storeId, $employeeId, $userId, $deviceUuid, $action, $failed, $lockedUntil]);
}

function record_success_attempt($pdo, $clientId, $storeId, $employeeId, $userId, $deviceUuid, $action) {
    if (!auth_attempt_table_exists($pdo)) return;
    $row = get_attempt_row($pdo, $clientId, $storeId, $userId, $deviceUuid, $action);
    if (!$row) return;
    $stmt = $pdo->prepare("\n        UPDATE employee_auth_attempts\n        SET employee_id = ?, failed_attempts = 0, locked_until = NULL, last_success_at = NOW()\n        WHERE id = ?\n    ");
    $stmt->execute([$employeeId, $row['id']]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail_json('POST required.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        fail_json('Invalid login.', 400);
    }

    $clientId = filter_var($data['client_id'] ?? null, FILTER_VALIDATE_INT);
    $storeId = filter_var($data['store_id'] ?? null, FILTER_VALIDATE_INT);
    $deviceUuid = trim((string)($data['device_uuid'] ?? ''));
    $activationToken = trim((string)($data['activation_token'] ?? ''));
    $userId = require_numeric_string($data['user_id'] ?? '', 'user_id');
    $password = require_numeric_string($data['password'] ?? '', 'password', 4);

    if (!$clientId || !$storeId || $deviceUuid === '' || $activationToken === '') {
        fail_json('Invalid login.', 400);
    }

    $stmt = $pdo->prepare("\n        SELECT id\n        FROM devices\n        WHERE client_id = ?\n          AND store_id = ?\n          AND device_uuid = ?\n          AND activation_token = ?\n          AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->execute([$clientId, $storeId, $deviceUuid, $activationToken]);
    if (!$stmt->fetch()) {
        fail_json('Invalid login.', 401);
    }

    reject_if_locked($pdo, $clientId, $storeId, $userId, $deviceUuid, 'login');

    $stmt = $pdo->prepare("\n        SELECT\n            id, client_id, store_id, full_name, user_id, login_password, pin_code,\n            employee_type, role_name, hourly_rate, status\n        FROM employees\n        WHERE client_id = ?\n          AND user_id = ?\n          AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->execute([$clientId, $userId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee || !employee_secret_matches($employee, $password)) {
        record_failed_attempt($pdo, $clientId, $storeId, $employee['id'] ?? null, $userId, $deviceUuid, 'login');
        fail_json('Invalid login.', 401);
    }

    $employeeId = (int)$employee['id'];
    $passwordUpgraded = false;

    if (employee_needs_hash_upgrade($employee)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("\n            UPDATE employees\n            SET login_password = ?, pin_code = ?\n            WHERE client_id = ?\n              AND id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$hash, $hash, $clientId, $employeeId]);
        $passwordUpgraded = true;
    }

    record_success_attempt($pdo, $clientId, $storeId, $employeeId, $userId, $deviceUuid, 'login');

    respond_json([
        'success' => true,
        'api' => 'login.php',
        'version' => API_VERSION,
        'password_storage' => 'password_hash',
        'password_upgraded' => $passwordUpgraded,
        'employee' => [
            'id' => $employeeId,
            'client_id' => (int)$employee['client_id'],
            'store_id' => isset($employee['store_id']) ? (int)$employee['store_id'] : null,
            'full_name' => $employee['full_name'] ?? 'Employee',
            'user_id' => (string)$employee['user_id'],
            'employee_type' => $employee['employee_type'] ?? null,
            'role_name' => $employee['role_name'] ?? ($employee['employee_type'] ?? 'Staff'),
            'hourly_rate' => $employee['hourly_rate'] ?? '',
            'status' => $employee['status'] ?? 'active',
        ],
    ]);
} catch (Throwable $e) {
    fail_json('Login failed.', 500);
}
