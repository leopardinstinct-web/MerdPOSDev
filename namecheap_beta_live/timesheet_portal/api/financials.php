<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();

    // Legacy financial helpers use a SUPER compatibility flag to mean
    // cross-store management. Set that flag from the named LOA permission,
    // never from the employee's nominal role label.
    $financeUser = $user;
    $canCrossStore = beta_has_permission($user, 'finance.cross_store', $pdo);
    $financeUser['employee_type'] = $canCrossStore ? 'SUPER' : 'USER';
    $financeUser['role_name'] = $canCrossStore ? 'SUPER' : 'USER';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        beta_require_permission($user, 'finance.view', $pdo);
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

        $statement = merd_financial_statement($pdo, $financeUser, (int)$storeId, $businessDate);
        $statement['currency_code'] = strtoupper((string)$storeDefaults['currency_code']);
        $statement['timezone'] = (string)$storeDefaults['timezone'];
        $statement['can_cross_store'] = $canCrossStore;
        $statement['can_open_day'] = beta_has_permission($user, 'finance.open_day', $pdo);
        json_response(['success' => true, 'statement' => $statement]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success' => false, 'error' => 'GET or POST required.'], 405);
    $input = request_input();
    require_csrf($input);
    $type = strtolower(trim((string)($input['submission_type'] ?? '')));
    if ($type === 'open_day') {
        beta_require_permission($user, 'finance.open_day', $pdo);
        // merd_submit_financial requires its legacy management signal for open_day.
        $financeUser['employee_type'] = 'SUPER';
        $financeUser['role_name'] = 'SUPER';
    } else {
        beta_require_permission($user, 'finance.submit', $pdo);
    }
    json_response(['success' => true, 'result' => merd_submit_financial($pdo, $financeUser, $input)]);
} catch (Throwable $e) { beta_api_error($e); }
