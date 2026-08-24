<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success' => false, 'error' => 'POST required.'], 405);
    $user = beta_require_active_user();
    $input = request_input();
    require_csrf($input);
    $token = trim((string)($input['token'] ?? ''));
    if ($token === '') throw new MerdWorkforceException('missing_qr', 'Scan the current POS QR.');
    $result = merd_attendance_scan(portal_db(), $user, $token);
    start_app_session();
    unset($_SESSION['pending_qr']);
    json_response(['success' => true, 'result' => $result]);
} catch (Throwable $e) { beta_api_error($e); }
