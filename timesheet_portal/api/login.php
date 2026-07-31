<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sheets.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

// Direct browser visit check. This stops the confusing "POST required" screen.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'error' => 'Login endpoint is working. Use the login form; do not open api/login.php directly.'
    ], 200);
}

// Accept normal FormData, JSON, and raw urlencoded payloads.
$input = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input');

if (empty($input) && stripos($contentType, 'application/json') !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}

if (empty($input) && $raw !== '') {
    parse_str($raw, $parsed);
    if (is_array($parsed)) $input = $parsed;
}

$userId = preg_replace('/\D+/', '', (string)($input['user_id'] ?? ''));
$password = preg_replace('/\D+/', '', (string)($input['password'] ?? ''));

if ($userId === '' || $password === '') {
    json_response(['success' => false, 'error' => 'Enter numeric User ID and Password.'], 200);
}

try {
    $setupResult = read_sheet_csv(SHEET_EMPLOYEE_SETUP);
    $setup = $setupResult['rows'];

    $user = find_login_user($setup, $userId, $password);
    if (!$user) {
        // Return HTTP 200 for wrong password so browser console does not show a scary 401 resource error.
        json_response([
            'success' => false,
            'error' => 'Invalid User ID or Password. Check Employee Setup Column C and D exactly.'
        ], 200);
    }

    login_user($user);
    json_response(['success' => true, 'user' => $user]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
