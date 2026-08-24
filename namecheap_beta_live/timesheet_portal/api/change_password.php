<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../../backend/api/includes/employee_auth.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success' => false, 'error' => 'POST required.'], 405);
    $user = require_login();
    $input = request_input();
    require_csrf($input);
    $current = trim((string)($input['current_password'] ?? ''));
    $new = trim((string)($input['new_password'] ?? ''));
    $confirm = trim((string)($input['confirm_password'] ?? ''));
    if (!preg_match('/^\d{6,20}$/', $new)) {
        throw new MerdWorkforceException('invalid_password', 'New password must contain 6–20 digits.');
    }
    if (!hash_equals($new, $confirm)) throw new MerdWorkforceException('password_mismatch', 'New passwords do not match.');
    if (hash_equals($current, $new)) throw new MerdWorkforceException('password_unchanged', 'Choose a different password.');
    $pdo = portal_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT id,client_id,login_password,pin_code FROM employees WHERE id=? AND client_id=? AND status='active' FOR UPDATE"
        );
        $stmt->execute([(int)$user['id'], (int)$user['client_id']]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employee) || !merd_employee_authenticates($employee, $current)) {
            throw new MerdWorkforceException('invalid_current_password', 'Current password is incorrect.');
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE employees SET login_password=?,pin_code=? WHERE id=? AND client_id=?');
        $update->execute([$hash, $hash, (int)$user['id'], (int)$user['client_id']]);
        try {
            $audit = $pdo->prepare(
                "INSERT INTO security_audit_events (client_id,employee_id,event_type,outcome,actor_type,actor_id,ip_address,user_agent,metadata) "
                . "VALUES (?,?, 'portal_password_change','success','employee',?,?,?,?)"
            );
            $audit->execute([(int)$user['client_id'], (int)$user['id'], (string)$user['user_id'],
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), '{}']);
        } catch (Throwable) { /* Password change must not fail if optional audit migration is pending. */ }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    start_app_session();
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    json_response(['success' => true, 'csrf' => csrf_token(), 'message' => 'Password changed.']);
} catch (Throwable $e) { beta_api_error($e); }
