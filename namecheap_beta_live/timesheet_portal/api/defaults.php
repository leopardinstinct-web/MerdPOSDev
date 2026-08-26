<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function defaults_currency(mixed $value, bool $allowBlank = false): ?string
{
    $currency = strtoupper(trim((string)$value));
    if ($allowBlank && $currency === '') return null;
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new MerdWorkforceException('invalid_currency', 'Currency must be a three-letter code such as AUD, USD or PKR.');
    }
    return $currency;
}

function defaults_timezone(mixed $value, bool $allowBlank = false): ?string
{
    $timezone = trim((string)$value);
    if ($allowBlank && $timezone === '') return null;
    static $valid = null;
    if ($valid === null) $valid = array_fill_keys(DateTimeZone::listIdentifiers(), true);
    if (!isset($valid[$timezone])) {
        throw new MerdWorkforceException('invalid_timezone', 'Choose a valid IANA timezone.');
    }
    return $timezone;
}

function defaults_audit(PDO $pdo, array $user, string $action, string $entityType, string $entityId, array $details): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$user['client_id'],
            (int)$user['id'],
            $action,
            $entityType,
            $entityId,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS defaults audit write failed: ' . get_class($e));
    }
}

function defaults_state(PDO $pdo, array $user): array
{
    $clientId = (int)$user['client_id'];
    $clientStmt = $pdo->prepare(
        'SELECT id,name,client_code,status,default_currency,default_timezone FROM clients WHERE id=? LIMIT 1'
    );
    $clientStmt->execute([$clientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new MerdWorkforceException('client_not_found', 'Working client was not found.');
    }

    $storesStmt = $pdo->prepare(
        'SELECT id,store_name,store_code,status,currency_code,timezone,'
        . 'COALESCE(currency_code,?) AS effective_currency,COALESCE(timezone,?) AS effective_timezone '
        . 'FROM stores WHERE client_id=? ORDER BY id ASC'
    );
    $storesStmt->execute([(string)$client['default_currency'], (string)$client['default_timezone'], $clientId]);

    return [
        'success' => true,
        'csrf' => csrf_token(),
        'active_client_id' => $clientId,
        'client' => $client,
        'stores' => $storesStmt->fetchAll(PDO::FETCH_ASSOC),
        'currencies' => ['AUD','NZD','USD','CAD','GBP','EUR','PKR','INR','AED','SAR','SGD','JPY','CNY'],
        'timezones' => DateTimeZone::listIdentifiers(),
    ];
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    beta_require_permission($user, 'defaults.manage', $pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(defaults_state($pdo, $user));
    }

    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? '');

    if ($action === 'save_client_defaults') {
        $currency = defaults_currency($input['default_currency'] ?? '');
        $timezone = defaults_timezone($input['default_timezone'] ?? '');
        $stmt = $pdo->prepare('UPDATE clients SET default_currency=?,default_timezone=? WHERE id=?');
        $stmt->execute([$currency, $timezone, (int)$user['client_id']]);
        defaults_audit($pdo, $user, 'client.defaults.update', 'client', (string)$user['client_id'], [
            'default_currency' => $currency,
            'default_timezone' => $timezone,
        ]);
        json_response(defaults_state($pdo, $user));
    }

    if ($action === 'save_store_defaults') {
        $storeId = filter_var($input['store_id'] ?? null, FILTER_VALIDATE_INT);
        if ($storeId === false || $storeId < 1) {
            throw new MerdWorkforceException('invalid_store', 'Choose a store.');
        }
        $currency = defaults_currency($input['currency_code'] ?? '', true);
        $timezone = defaults_timezone($input['timezone'] ?? '', true);
        $check = $pdo->prepare('SELECT id FROM stores WHERE id=? AND client_id=? LIMIT 1');
        $check->execute([(int)$storeId, (int)$user['client_id']]);
        if (!$check->fetchColumn()) {
            throw new MerdWorkforceException('store_not_found', 'Store not found in the working client.');
        }
        $stmt = $pdo->prepare('UPDATE stores SET currency_code=?,timezone=? WHERE id=? AND client_id=?');
        $stmt->execute([$currency, $timezone, (int)$storeId, (int)$user['client_id']]);
        defaults_audit($pdo, $user, 'store.defaults.update', 'store', (string)$storeId, [
            'currency_code' => $currency,
            'timezone' => $timezone,
            'inherits_client_currency' => $currency === null,
            'inherits_client_timezone' => $timezone === null,
        ]);
        json_response(defaults_state($pdo, $user));
    }

    json_response(['success' => false, 'error' => 'Unsupported defaults action.'], 400);
} catch (Throwable $e) {
    beta_api_error($e);
}
