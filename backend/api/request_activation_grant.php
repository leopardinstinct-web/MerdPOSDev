<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';
require_once __DIR__ . '/includes/security_log.php';

$requestId = merd_request_id();
$log = new MerdPdoSecurityLogStore($pdo);

try {
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $data = merd_request_json(file_get_contents('php://input'));
    $clientCode = merd_request_text($data['client_code'] ?? null, 'client_code', 50);
    $setupKey = merd_request_text($data['setup_key'] ?? null, 'setup_key', 100);

    $stmt = $pdo->prepare(
        "SELECT id, name, client_code, setup_key FROM clients "
        . "WHERE client_code = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$clientCode]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client) || !merd_setup_key_matches($client['setup_key'] ?? null, $setupKey)) {
        merd_security_log_event(
            $log,
            $_SERVER,
            'activation_grant_request',
            'denied',
            ['request_id' => $requestId],
            ['endpoint' => 'request_activation_grant.php', 'reason_code' => 'setup_validation_failed']
        );
        merd_api_fail('setup_validation_failed', 'Setup validation failed.', 401, $requestId);
    }
    $clientId = (int)$client['id'];
    $storesStmt = $pdo->prepare(
        "SELECT id, store_name, store_code FROM stores "
        . "WHERE client_id = ? AND status = 'active' ORDER BY store_name, id"
    );
    $storesStmt->execute([$clientId]);
    $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);
    $issued = merd_activation_grant_issue(new MerdPdoActivationGrantStore($pdo), $clientId);
    merd_security_log_event(
        $log,
        $_SERVER,
        'activation_grant_request',
        'success',
        ['client_id' => $clientId, 'request_id' => $requestId],
        ['endpoint' => 'request_activation_grant.php']
    );
    merd_api_send(merd_api_success([
        'api' => 'request_activation_grant.php',
        'version' => 'activation-grant-v1',
        'client' => [
            'id' => $clientId,
            'name' => (string)$client['name'],
            'client_code' => (string)$client['client_code'],
        ],
        'stores' => $stores,
        'activation_grant' => $issued['grant'],
        'grant_expires_at' => $issued['expires_at']->format(DateTimeInterface::ATOM),
    ]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (MerdSecurityControlUnavailable $e) {
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503, $requestId);
} catch (Throwable $e) {
    error_log('activation grant request failed');
    merd_api_fail('internal_error', 'Service temporarily unavailable.', 500, $requestId);
}
