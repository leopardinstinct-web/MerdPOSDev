<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $storeId = filter_var($_GET['store_id'] ?? null, FILTER_VALIDATE_INT);
        $businessDate = trim((string)($_GET['business_date'] ?? ''));
        if (!$storeId) throw new MerdWorkforceException('invalid_store', 'Choose a store.');

        $defaults = $pdo->prepare(
            'SELECT COALESCE(s.currency_code,c.default_currency,\'AUD\') AS currency_code,'
            . 'COALESCE(s.timezone,c.default_timezone,\'Australia/Sydney\') AS timezone '
            . 'FROM stores s INNER JOIN clients c ON c.id=s.client_id '
            . 'WHERE s.id=? AND s.client_id=? LIMIT 1'
        );
        $defaults->execute([(int)$storeId, (int)$user['client_id']]);
        $storeDefaults = $defaults->fetch(PDO::FETCH_ASSOC);
        if (!is_array($storeDefaults)) throw new MerdWorkforceException('store_not_found', 'Store not found.');

        $statement = merd_financial_statement($pdo, $user, (int)$storeId, $businessDate);
        $statement['currency_code'] = strtoupper((string)$storeDefaults['currency_code']);
        $statement['timezone'] = (string)$storeDefaults['timezone'];
        json_response(['success' => true, 'statement' => $statement]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success' => false, 'error' => 'GET or POST required.'], 405);
    $input = request_input();
    require_csrf($input);
    json_response(['success' => true, 'result' => merd_submit_financial($pdo, $user, $input)]);
} catch (Throwable $e) { beta_api_error($e); }
