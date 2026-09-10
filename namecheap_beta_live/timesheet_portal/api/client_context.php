<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function client_context_state(PDO $pdo, array $user): array
{
    $canSelect = beta_actual_user_is_dev($user) && beta_user_is_platform_identity($user);
    $activeClientId = (int)($user['client_id'] ?? 0);
    $clientStmt = $pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1');
    $clientStmt->execute([$activeClientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) throw new MerdWorkforceException('client_not_found', 'Selected client record not found.');
    $clients = $canSelect
        ? $pdo->query("SELECT id,name,client_code,status FROM clients WHERE status='active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
        : [];
    return [
        'success'=>true,
        'csrf'=>csrf_token(),
        'role'=>(string)($user['role_key'] ?? $user['role'] ?? 'USER'),
        'role_label'=>(string)($user['role_label'] ?? $user['role_name'] ?? $user['role_key'] ?? 'USER'),
        'actual_role'=>beta_actual_user_is_dev($user) ? 'DEV' : (string)($user['role_key'] ?? $user['role'] ?? 'USER'),
        'authority_level'=>(int)($user['authority_level'] ?? 0),
        'can_select_client'=>$canSelect,
        'client'=>$client,
        'clients'=>$clients,
        'active_client_id'=>$activeClientId,
        'home_client_id'=>$canSelect ? 0 : (int)($user['auth_client_id'] ?? $activeClientId),
        'cross_client_context'=>false,
        'identity_scope'=>$canSelect ? 'platform' : 'employee',
        'scope'=>$canSelect ? 'platform_selected_client' : 'authenticated_client',
    ];
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $canSelect = beta_actual_user_is_dev($user) && beta_user_is_platform_identity($user);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$canSelect) throw new MerdWorkforceException('forbidden', 'Only the platform DEV identity can switch the Working client.');
        $input = request_input();
        require_csrf($input);
        if ((string)($input['action'] ?? '') !== 'select_client') json_response(['success'=>false,'error'=>'Unsupported client action.'], 400);
        $clientId = filter_var($input['client_id'] ?? null, FILTER_VALIDATE_INT);
        if ($clientId === false || $clientId <= 0) throw new MerdWorkforceException('invalid_client', 'Choose a valid client.');
        $check = $pdo->prepare("SELECT id FROM clients WHERE id=? AND status='active' LIMIT 1");
        $check->execute([(int)$clientId]);
        if (!$check->fetchColumn()) throw new MerdWorkforceException('client_not_found', 'Active client not found.');

        $platformId = beta_actor_platform_identity_id($user);
        if ($platformId === null) throw new MerdWorkforceException('forbidden', 'Platform identity is unavailable.');
        $pdo->beginTransaction();
        try {
            merd_platform_identity_set_selected_client($pdo, $platformId, (int)$clientId);
            $audit = $pdo->prepare(
                'INSERT INTO admin_audit_logs (client_id,employee_id,platform_identity_id,action,entity_type,entity_id,details,ip_address) VALUES (?,NULL,?,?,?,?,?,?)'
            );
            $audit->execute([
                (int)$clientId,$platformId,'dev.client_context.select','client',(string)$clientId,
                json_encode(['selected_client_id'=>(int)$clientId],JSON_UNESCAPED_SLASHES),
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        set_dev_active_client_id((int)$clientId);
        start_app_session();
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['client_id']=(int)$clientId;
            $_SESSION['user']['active_client_id']=(int)$clientId;
        }
        $user['client_id']=(int)$clientId;
        $user['active_client_id']=(int)$clientId;
        $user=beta_apply_dev_role_preview($pdo,$user);
        json_response(client_context_state($pdo,$user));
    }

    json_response(client_context_state($pdo,$user));
} catch (Throwable $e) {
    beta_api_error($e);
}
