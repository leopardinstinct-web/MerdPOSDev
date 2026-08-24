<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function client_context_role(array $user): string
{
    return strtoupper((string)($user['role'] ?? $user['actual_employee_type'] ?? $user['employee_type'] ?? 'USER'));
}

function client_context_state(PDO $pdo, array $user): array
{
    $activeClientId = (int)$user['client_id'];
    $homeClientId = (int)($user['auth_client_id'] ?? $user['client_id']);

    $clientsStmt = $pdo->query(
        'SELECT id,name,client_code,status,created_at FROM clients ORDER BY id ASC'
    );
    $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

    $client = null;
    foreach ($clients as $row) {
        if ((int)$row['id'] === $activeClientId) {
            $client = $row;
            break;
        }
    }
    if (!is_array($client)) {
        throw new MerdWorkforceException('client_not_found', 'Selected client record not found.');
    }

    $storesStmt = $pdo->prepare(
        'SELECT id,store_name,store_code,status FROM stores WHERE client_id=? ORDER BY id ASC'
    );
    $storesStmt->execute([$activeClientId]);
    $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);

    $employeeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=?');
    $employeeCountStmt->execute([$activeClientId]);

    $activeEmployeeCountStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE client_id=? AND status='active'");
    $activeEmployeeCountStmt->execute([$activeClientId]);

    $deviceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE client_id=?');
    $deviceCountStmt->execute([$activeClientId]);

    return [
        'success' => true,
        'csrf' => csrf_token(),
        'client' => $client,
        'clients' => $clients,
        'active_client_id' => $activeClientId,
        'home_client_id' => $homeClientId,
        'cross_client_context' => $activeClientId !== $homeClientId,
        'stores' => $stores,
        'counts' => [
            'stores' => count($stores),
            'employees' => (int)$employeeCountStmt->fetchColumn(),
            'active_employees' => (int)$activeEmployeeCountStmt->fetchColumn(),
            'devices' => (int)$deviceCountStmt->fetchColumn(),
        ],
        'scope' => 'dev_selected_client',
    ];
}

try {
    $user = beta_require_active_user();
    if (client_context_role($user) !== 'DEV') {
        json_response(['success' => false, 'error' => 'DEV access required.'], 403);
    }

    $pdo = portal_db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = request_input();
        require_csrf($input);
        if ((string)($input['action'] ?? '') !== 'select_client') {
            json_response(['success' => false, 'error' => 'Unsupported client action.'], 400);
        }

        $clientId = filter_var($input['client_id'] ?? null, FILTER_VALIDATE_INT);
        if ($clientId === false || $clientId <= 0) {
            throw new MerdWorkforceException('invalid_client', 'Choose a valid client.');
        }

        $check = $pdo->prepare('SELECT id FROM clients WHERE id=? LIMIT 1');
        $check->execute([(int)$clientId]);
        if (!$check->fetchColumn()) {
            throw new MerdWorkforceException('client_not_found', 'Client not found.');
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

        json_response(client_context_state($pdo, $user));
    }

    json_response(client_context_state($pdo, $user));
} catch (Throwable $e) {
    beta_api_error($e);
}
