<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $storeId = filter_var($_GET['store_id'] ?? null, FILTER_VALIDATE_INT);
        $businessDate = trim((string)($_GET['business_date'] ?? ''));
        if (!$storeId) throw new MerdWorkforceException('invalid_store', 'Choose a store.');
        json_response(['success' => true, 'statement' => merd_financial_statement(portal_db(), $user, (int)$storeId, $businessDate)]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success' => false, 'error' => 'GET or POST required.'], 405);
    $input = request_input();
    require_csrf($input);
    json_response(['success' => true, 'result' => merd_submit_financial(portal_db(), $user, $input)]);
} catch (Throwable $e) { beta_api_error($e); }
