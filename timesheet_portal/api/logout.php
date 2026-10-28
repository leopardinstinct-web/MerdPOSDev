<?php
require_once __DIR__ . '/../includes/auth.php';

// Logout is a state change and must not be triggered by a cross-site GET.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'error' => 'POST required'], 405);
}

start_app_session();
$expected = $_SESSION['portal_logout_csrf'] ?? null;
$submitted = $_POST['csrf_token'] ?? null;
if (!is_string($expected) || !is_string($submitted) || !hash_equals($expected, $submitted)) {
    json_response(['success' => false, 'error' => 'Invalid logout token'], 403);
}

logout_user();
json_response(['success' => true]);
