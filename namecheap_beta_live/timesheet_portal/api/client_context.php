<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function client_context_users(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare(
        "SELECT e.id,e.full_name,e.user_id,e.store_id,"
        . "COALESCE(r.role_key,UPPER(TRIM(e.employee_type)),'USER') AS role_key,"
        . "COALESCE(r.role_label,NULLIF(e.role_name,''),UPPER(TRIM(e.employee_type)),'User') AS role_label,"
        . "COALESCE(r.authority_level,0) AS authority_level "
        . "FROM employees e LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id "
        . "WHERE e.client_id=? AND e.status='active' AND UPPER(TRIM(e.employee_type))<>'DEV' "
        . "ORDER BY e.full_name,e.user_id,e.id"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function client_context_state(PDO $pdo, array $user): array
{
    $canSelect = beta_actor_is_platform_dev($user);
    $activeClientId = (int)($user['client_id'] ?? 0);
    $clientStmt = $pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1');
    $clientStmt->execute([$activeClientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) throw new MerdWorkforceException('client_not_found', 'Selected client record not found.');
    $clients = $canSelect
        ? $pdo->query("SELECT id,name,client_code,status FROM clients WHERE status='active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
        : [];
    $users = $canSelect ? client_context_users($pdo, $activeClientId) : [];
    $impersonating = $canSelect && !empty($user['is_user_impersonation']);
    $effectiveRole = (string)($user['role_key'] ?? $user['role'] ?? 'USER');
    $effectiveLabel = (string)($user['role_label'] ?? $user['role_name'] ?? $effectiveRole);
    return [
        'success'=>true,
        'csrf'=>csrf_token(),
        'role'=>$effectiveRole,
        'role_label'=>$effectiveLabel,
        'actual_role'=>$canSelect ? 'DEV' : $effectiveRole,
        'authority_level'=>(int)($user['authority_level'] ?? 0),
        'can_select_client'=>$canSelect,
        'can_select_user'=>$canSelect,
        'client'=>$client,
        'clients'=>$clients,
        'users'=>$users,
        'active_client_id'=>$activeClientId,
        'active_user_id'=>$impersonating ? (int)($user['id'] ?? 0) : null,
        'impersonating'=>$impersonating,
        'effective_user'=>[
            'id'=>(int)($user['id'] ?? 0),
            'full_name'=>(string)($user['full_name'] ?? $user['name'] ?? ''),
            'user_id'=>(string)($user['user_id'] ?? ''),
            'store_id'=>isset($user['store_id']) ? (int)$user['store_id'] : null,
            'role_key'=>$effectiveRole,
            'role_label'=>$effectiveLabel,
        ],
        'actor'=>[
            'identity_scope'=>$canSelect ? 'platform' : (string)($user['identity_scope'] ?? 'employee'),
            'role_key'=>$canSelect ? 'DEV' : $effectiveRole,
            'role_label'=>$canSelect ? 'Developer' : $effectiveLabel,
            'full_name'=>$canSelect ? (string)($user['actor_full_name'] ?? $user['full_name'] ?? $user['name'] ?? '') : (string)($user['full_name'] ?? $user['name'] ?? ''),
        ],
        'home_client_id'=>$canSelect ? 0 : (int)($user['auth_client_id'] ?? $activeClientId),
        'cross_client_context'=>false,
        'identity_scope'=>(string)($user['identity_scope'] ?? ($canSelect ? 'platform' : 'employee')),
        'scope'=>$impersonating ? 'platform_impersonated_user' : ($canSelect ? 'platform_selected_client' : 'authenticated_client'),
    ];
}

try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    $canSelect = beta_actor_is_platform_dev($user);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$canSelect) throw new MerdWorkforceException('forbidden', 'Only the platform DEV identity can change Working context.');
        $input = request_input();
        require_csrf($input);
        $action = (string)($input['action'] ?? '');

        if ($action === 'select_client') {
            $clientId = filter_var($input['client_id'] ?? null, FILTER_VALIDATE_INT);
            if ($clientId === false || $clientId <= 0) throw new MerdWorkforceException('invalid_client', 'Choose a valid client.');
            $check = $pdo->prepare("SELECT id,name,client_code,status FROM clients WHERE id=? AND status='active' LIMIT 1");
            $check->execute([(int)$clientId]);
            $selected = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($selected)) throw new MerdWorkforceException('client_not_found', 'Active client not found.');
            $platformId = beta_actor_platform_identity_id($user);
            if ($platformId === null) throw new MerdWorkforceException('forbidden', 'Platform identity is unavailable.');
            $pdo->beginTransaction();
            try {
                merd_platform_identity_set_selected_client($pdo, $platformId, (int)$clientId);
                $auditUser = $user;$auditUser['client_id'] = (int)$clientId;
                beta_admin_audit($pdo,$auditUser,'dev.client_context.select','client',(string)$clientId,['selected_client_id'=>(int)$clientId,'impersonation_cleared'=>true]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            set_dev_active_client_id((int)$clientId);
            json_response(['success'=>true,'active_client_id'=>(int)$clientId,'client'=>$selected,'impersonation_cleared'=>true]);
        }

        if ($action === 'select_user') {
            $employeeId = filter_var($input['employee_id'] ?? null, FILTER_VALIDATE_INT);
            if ($employeeId === false || $employeeId <= 0) throw new MerdWorkforceException('invalid_user', 'Choose a valid Working user.');
            $selected = null;
            foreach (client_context_users($pdo, (int)$user['client_id']) as $candidate) {
                if ((int)($candidate['id'] ?? 0) === (int)$employeeId) { $selected = $candidate; break; }
            }
            if (!is_array($selected)) throw new MerdWorkforceException('user_not_found', 'Active Working user not found.');
            beta_admin_audit($pdo,$user,'dev.user_impersonation.select','employee',(string)$employeeId,[
                'target_user_id'=>(string)($selected['user_id'] ?? ''),
                'target_role_key'=>(string)($selected['role_key'] ?? ''),
            ]);
            json_response(['success'=>true,'selected_user'=>$selected]);
        }

        if ($action === 'exit_user') {
            $subjectId = !empty($user['is_user_impersonation']) ? (int)($user['id'] ?? 0) : 0;
            beta_admin_audit($pdo,$user,'dev.user_impersonation.exit','employee',$subjectId > 0 ? (string)$subjectId : null,[]);
            json_response(['success'=>true,'impersonation_cleared'=>true]);
        }

        json_response(['success'=>false,'error'=>'Unsupported client context action.'], 400);
    }

    json_response(client_context_state($pdo,$user));
} catch (Throwable $e) {
    beta_api_error($e);
}
