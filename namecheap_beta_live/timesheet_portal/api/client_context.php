<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function client_context_state(PDO $pdo, array $user): array
{
    $canSelect = beta_user_is_dev($user);
    $activeClientId = (int)$user['client_id'];
    $homeClientId = (int)($user['auth_client_id'] ?? $user['client_id']);

    $clientStmt = $pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1');
    $clientStmt->execute([$activeClientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new MerdWorkforceException('client_not_found', 'Selected client record not found.');
    }

    $clients = [];
    if ($canSelect) {
        $clients = $pdo->query("SELECT id,name,client_code,status FROM clients WHERE status='active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'success' => true,
        'csrf' => csrf_token(),
        'role' => (string)($user['role_key'] ?? $user['role'] ?? 'USER'),
        'authority_level' => (int)($user['authority_level'] ?? 0),
        'can_select_client' => $canSelect,
        'client' => $client,
        'clients' => $clients,
        'active_client_id' => $activeClientId,
        'home_client_id' => $homeClientId,
        'cross_client_context' => $canSelect && $activeClientId !== $homeClientId,
        'scope' => $canSelect ? 'dev_selected_client' : 'authenticated_client',
    ];
}

function client_context_persist(PDO $pdo, array $user, int $selectedClientId): void
{
    $authClientId = (int)($user['auth_client_id'] ?? $user['client_id']);
    $stmt = $pdo->prepare(
        'INSERT INTO dev_client_preferences (employee_id,auth_client_id,selected_client_id) VALUES (?,?,?) '
        . 'ON DUPLICATE KEY UPDATE auth_client_id=VALUES(auth_client_id),selected_client_id=VALUES(selected_client_id),updated_at=CURRENT_TIMESTAMP'
    );
    $stmt->execute([(int)$user['id'], $authClientId, $selectedClientId]);
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $canSelect = beta_user_is_dev($user);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!beta_user_is_dev($user)) throw new MerdWorkforceException('forbidden', 'Only DEV can switch the working client.');
        $input = request_input();
        require_csrf($input);
        if ((string)($input['action'] ?? '') !== 'select_client') {
            json_response(['success' => false, 'error' => 'Unsupported client action.'], 400);
        }

        $clientId = filter_var($input['client_id'] ?? null, FILTER_VALIDATE_INT);
        if ($clientId === false || $clientId <= 0) {
            throw new MerdWorkforceException('invalid_client', 'Choose a valid client.');
        }

        $check = $pdo->prepare("SELECT id FROM clients WHERE id=? AND status='active' LIMIT 1");
        $check->execute([(int)$clientId]);
        if (!$check->fetchColumn()) {
            throw new MerdWorkforceException('client_not_found', 'Active client not found.');
        }

        $pdo->beginTransaction();
        try {
            client_context_persist($pdo, $user, (int)$clientId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        set_dev_active_client_id((int)$clientId);
        $user['client_id'] = (int)$clientId;
        $user['active_client_id'] = (int)$clientId;
        $user['is_cross_client_context'] = (int)$clientId !== (int)($user['auth_client_id'] ?? $clientId);

        try {
            $audit = $pdo->prepare(
                'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
            );
            $audit->execute([
                (int)($user['auth_client_id'] ?? $clientId),
                (int)$user['id'],
                'dev.client_context.select',
                'client',
                (string)$clientId,
                json_encode(['selected_client_id' => (int)$clientId], JSON_UNESCAPED_SLASHES),
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ]);
        } catch (Throwable $e) {
            error_log('MERDPOS DEV client context audit failed: ' . get_class($e));
        }

        // Refresh the permission snapshot against the newly selected client.
        $user = beta_apply_dev_role_preview($pdo, $user);
        json_response(client_context_state($pdo, $user));
    }

    if ($canSelect) {
        client_context_persist($pdo, $user, (int)$user['client_id']);
    }

    json_response(client_context_state($pdo, $user));
} catch (Throwable $e) {
    beta_api_error($e);
}
