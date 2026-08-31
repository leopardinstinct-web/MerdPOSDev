<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../../backend/api/includes/portal_permissions.php';
require_once __DIR__ . '/../../backend/api/includes/workforce_beta.php';
require_once __DIR__ . '/dashboard_access.php';

function beta_api_error(Throwable $error): never
{
    if ($error instanceof MerdWorkforceException) {
        json_response(['success' => false, 'error_code' => $error->errorCode, 'error' => $error->getMessage()], 200);
    }
    error_log('timesheet beta API failure: ' . get_class($error));
    json_response(['success' => false, 'error_code' => 'internal_error', 'error' => 'The request could not be completed.'], 500);
}

function beta_permission_levels(PDO $pdo, int $clientId): array
{
    $catalog = merd_portal_permission_catalog();
    $levels = [];
    foreach ($catalog as $key => $rule) {
        $levels[$key] = !empty($rule['dev_only']) ? 1000 : max(1, min(1000, (int)$rule['min_loa']));
    }
    try {
        $stmt = $pdo->prepare('SELECT permission_key,min_authority_level FROM client_permission_levels WHERE client_id=?');
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['permission_key'];
            if (!isset($catalog[$key]) || !empty($catalog[$key]['dev_only'])) continue;
            $levels[$key] = max(1, min(1000, (int)$row['min_authority_level']));
        }
    } catch (Throwable $e) {
        error_log('MERDPOS permission level fallback: ' . get_class($e));
    }
    return $levels;
}

function beta_user_is_dev(array $user): bool
{
    return strtoupper(trim((string)($user['actual_employee_type'] ?? $user['role'] ?? ''))) === 'DEV';
}

function beta_actual_user_is_dev(array $user): bool
{
    return strtoupper(trim((string)($user['actual_role_key'] ?? $user['actual_employee_type'] ?? $user['role'] ?? ''))) === 'DEV';
}

function beta_has_permission(array $user, string $permission, ?PDO $pdo = null): bool
{
    $catalog = merd_portal_permission_catalog();
    if (!isset($catalog[$permission])) return false;
    if (!empty($user['is_role_preview']) && isset($user['permissions']) && is_array($user['permissions']) && array_key_exists($permission, $user['permissions'])) return (bool)$user['permissions'][$permission];
    if (!empty($catalog[$permission]['dev_only'])) return beta_user_is_dev($user);
    $authority = max(0, (int)($user['authority_level'] ?? 0));
    if ($pdo instanceof PDO && !empty($user['client_id'])) {
        $levels = beta_permission_levels($pdo, (int)$user['client_id']);
        return $authority >= (int)($levels[$permission] ?? 1000);
    }
    if (isset($user['permissions']) && is_array($user['permissions']) && array_key_exists($permission, $user['permissions'])) {
        return (bool)$user['permissions'][$permission];
    }
    return $authority >= (int)$catalog[$permission]['min_loa'];
}

function beta_require_permission(array $user, string $permission, ?PDO $pdo = null): void
{
    if (beta_has_permission($user, $permission, $pdo)) return;
    $catalog = merd_portal_permission_catalog();
    $label = (string)($catalog[$permission]['label'] ?? $permission);
    throw new MerdWorkforceException('forbidden', 'Your access level does not permit: ' . $label . '.');
}

function beta_require_any_permission(array $user, array $permissions, ?PDO $pdo = null): void
{
    foreach ($permissions as $permission) if (beta_has_permission($user, (string)$permission, $pdo)) return;
    throw new MerdWorkforceException('forbidden', 'Your access level does not permit this action.');
}

function beta_permission_snapshot(PDO $pdo, array $user): array
{
    $levels = beta_permission_levels($pdo, (int)$user['client_id']);
    $catalog = merd_portal_permission_catalog();
    $authority = max(0, (int)($user['authority_level'] ?? 0));
    $isDev = beta_user_is_dev($user);
    $permissions = [];
    foreach ($catalog as $key => $rule) {
        $permissions[$key] = !empty($rule['dev_only']) ? $isDev : $authority >= (int)($levels[$key] ?? 1000);
    }
    return [$permissions, $levels];
}

function beta_apply_dev_role_preview(PDO $pdo, array $user): array
{
    if (!beta_user_is_dev($user)) { $user['is_role_preview'] = false; return $user; }
    $user['actual_role_key'] = (string)($user['actual_role_key'] ?? $user['role_key'] ?? $user['role'] ?? 'DEV');
    $user['actual_role_label'] = (string)($user['actual_role_label'] ?? $user['role_label'] ?? $user['role_name'] ?? 'Developer');
    $user['actual_authority_level'] = (int)($user['actual_authority_level'] ?? $user['authority_level'] ?? 1000);
    $user['actual_client_role_id'] = $user['actual_client_role_id'] ?? ($user['client_role_id'] ?? null);
    $user['actual_permissions'] = (array)($user['actual_permissions'] ?? $user['permissions'] ?? []);
    $viewRoleKey = strtoupper(trim((string)($_COOKIE['merdpos_dev_view_role'] ?? 'ADMIN')));
    if (!in_array($viewRoleKey, ['DEV','ADMIN','SUPER','USER'], true)) $viewRoleKey = 'ADMIN';
    if ($viewRoleKey === 'DEV') {
        $user['is_role_preview'] = false; $user['view_role_key'] = 'DEV';
        $user['view_role_id'] = $user['actual_client_role_id']; return $user;
    }
    $viewRole = merd_dashboard_system_role($pdo, (int)$user['client_id'], $viewRoleKey);
    if (!$viewRole || strtolower((string)($viewRole['status'] ?? 'active')) !== 'active') { $user['is_role_preview'] = false; return $user; }
    $previewUser = $user;
    $previewUser['role'] = $viewRoleKey; $previewUser['actual_employee_type'] = $viewRoleKey; $previewUser['employee_type'] = $viewRoleKey;
    $previewUser['role_key'] = $viewRoleKey; $previewUser['role_label'] = (string)$viewRole['role_label'];
    $previewUser['authority_level'] = (int)$viewRole['authority_level']; $previewUser['is_dev'] = false;
    [$permissions, $levels] = beta_permission_snapshot($pdo, $previewUser);
    $user['role'] = $viewRoleKey; $user['employee_type'] = $viewRoleKey; $user['role_name'] = (string)$viewRole['role_label'];
    $user['client_role_id'] = (int)$viewRole['id']; $user['role_key'] = $viewRoleKey; $user['role_label'] = (string)$viewRole['role_label'];
    $user['authority_level'] = (int)$viewRole['authority_level']; $user['permissions'] = $permissions; $user['permission_levels'] = $levels;
    $user['is_management'] = !empty($permissions['workforce.view']) || !empty($permissions['timesheets.view_all']) || !empty($permissions['disputes.review']) || !empty($permissions['finance.cross_store']);
    $user['is_super'] = !empty($permissions['timesheets.view_all']); $user['is_admin'] = $viewRoleKey === 'ADMIN';
    $user['is_role_preview'] = true; $user['view_role_key'] = $viewRoleKey; $user['view_role_id'] = (int)$viewRole['id'];
    return $user;
}

function beta_enforce_route_permission(array $user, PDO $pdo): void
{
    if (PHP_SAPI === 'cli') return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    if ($script === '' || !str_ends_with($script, '.php')) return;
    $path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!str_contains($path, '/api/')) return;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $input = $method === 'POST' ? request_input() : [];
    $action = (string)($input['action'] ?? '');

    switch ($script) {
        case 'attendance_scan.php':
            beta_require_permission($user, 'attendance.scan', $pdo); return;
        case 'beta_state.php':
            // beta.js is shared by Dashboard, Disputes, Finance and Password.
            // The endpoint itself permission-scopes each data section, so route
            // access follows the consuming feature rather than dashboard.view.
            beta_require_any_permission($user, [
                'dashboard.view',
                'disputes.view_own',
                'disputes.review',
                'finance.view',
                'password.change_own',
            ], $pdo); return;
        case 'dashboard_data.php':
            beta_require_permission($user, 'dashboard.view', $pdo); return;
        case 'ui_studio_history.php':
            if (!beta_actual_user_is_dev($user)) throw new MerdWorkforceException('forbidden', 'Developer access is required.');
            return;
        case 'ui_studio_asset.php':
            if (!beta_actual_user_is_dev($user)) throw new MerdWorkforceException('forbidden', 'Developer access is required.');
            return;
        case 'dashboard_layout.php':
            $studioDashboard = beta_user_is_dev($user) && (($method === 'GET' && (string)($_GET['dev_studio'] ?? '') === '1') || ($method === 'POST' && !empty($input['dev_studio'])));
            if ($studioDashboard) return;
            beta_require_permission($user, $method === 'POST' ? 'dashboard.configure' : 'dashboard.view', $pdo); return;
        case 'change_password.php':
            beta_require_permission($user, 'password.change_own', $pdo); return;
        case 'weeks.php':
        case 'timesheet.php':
            beta_require_any_permission($user, ['timesheets.view_own','timesheets.view_all'], $pdo); return;
        case 'check_sheet.php':
        case 'dev_status.php':
            beta_require_permission($user, 'dev.status', $pdo); return;
        case 'clients.php':
            beta_require_permission($user, 'clients.manage', $pdo); return;
        case 'legacy_migration.php':
            beta_require_permission($user, 'legacy_migration.manage', $pdo); return;
        case 'defaults.php':
            beta_require_permission($user, 'defaults.manage', $pdo); return;
        case 'store_identity.php':
            beta_require_permission($user, 'stores.profile.manage', $pdo); return;
        case 'store_logo.php':
            beta_require_permission($user, 'stores.logo.manage', $pdo); return;
        case 'store_timings.php':
            beta_require_permission($user, 'stores.timings.manage', $pdo); return;
        case 'role_authority.php':
            beta_require_permission($user, $action === 'save_permissions' ? 'permissions.manage' : 'roles.manage', $pdo); return;
        case 'client_context.php':
            if ($method === 'POST' && !beta_user_is_dev($user)) beta_require_permission($user, 'client_context.switch', $pdo);
            return;
        case 'timesheet_google_refresh.php':
            if (!beta_actual_user_is_dev($user)) throw new MerdWorkforceException('forbidden', 'Only the actual DEV identity can refresh Google Time Sheet data.');
            return;
        case 'admin_directory.php':
            if ($method === 'GET') { beta_require_any_permission($user, ['stores.view','workforce.view'], $pdo); return; }
            if ($action === 'save_store') { beta_require_permission($user, 'stores.manage', $pdo); return; }
            if ($action === 'save_employee') { beta_require_permission($user, 'workforce.manage', $pdo); return; }
            throw new MerdWorkforceException('permission_policy_missing', 'This directory action has no permission policy.');
        case 'financials.php':
            if ($method === 'GET') { beta_require_permission($user, 'finance.view', $pdo); return; }
            $type = strtolower(trim((string)($input['submission_type'] ?? '')));
            beta_require_permission($user, $type === 'open_day' ? 'finance.open_day' : 'finance.submit', $pdo);
            return;
        case 'disputes.php':
            if ($method === 'GET') { beta_require_any_permission($user, ['disputes.view_own','disputes.review'], $pdo); return; }
            if ($action === 'decide') { beta_require_permission($user, 'disputes.review', $pdo); return; }
            if ($action === 'resolve_flag') { beta_require_permission($user, 'attendance_flags.resolve', $pdo); return; }
            if (in_array($action, ['create','cancel','confirm_handover','reject_handover'], true)) { beta_require_permission($user, 'disputes.submit_own', $pdo); return; }
            throw new MerdWorkforceException('permission_policy_missing', 'This dispute action has no permission policy.');
        case 'login.php':
        case 'logout.php':
        case 'me.php':
            return;
        default:
            throw new MerdWorkforceException('permission_policy_missing', 'This beta API is not registered in the portal permission policy.');
    }
}

function beta_require_active_user(): array
{
    $user = require_login();
    $pdo = portal_db();
    $authClientId = (int)($user['auth_client_id'] ?? $user['client_id']);
    $stmt = $pdo->prepare(
        'SELECT e.status,e.employee_type,e.role_name,e.client_role_id,'
        . 'r.role_key,r.role_label,r.base_role,r.authority_level,r.status AS role_status '
        . 'FROM employees e LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id '
        . 'WHERE e.id=? AND e.client_id=? LIMIT 1'
    );
    $stmt->execute([(int)$user['id'], $authClientId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($actor) || strtolower((string)$actor['status']) !== 'active') {
        throw new MerdWorkforceException('account_inactive','Your account is inactive. Contact an authorised manager.');
    }

    $roleRowValid = !empty($actor['client_role_id']) && !empty($actor['role_key']) && strtolower((string)($actor['role_status'] ?? 'active')) === 'active';
    $baseRole = strtoupper(trim((string)($roleRowValid ? $actor['base_role'] : $actor['employee_type'])));
    if (!in_array($baseRole, ['USER','ADMIN','SUPER','DEV'], true)) $baseRole = 'USER';
    $authority = 0;$roleKey = $baseRole;$roleLabel = (string)($actor['role_name'] ?: $baseRole);$clientRoleId = null;
    if ($roleRowValid) {
        $authority=(int)$actor['authority_level'];$roleKey=(string)$actor['role_key'];$roleLabel=(string)$actor['role_label'];$clientRoleId=(int)$actor['client_role_id'];
    } else {
        try {
            $fallback=$pdo->prepare("SELECT id,role_key,role_label,authority_level FROM client_roles WHERE client_id=? AND role_key=? AND status='active' LIMIT 1");
            $fallback->execute([$authClientId,$baseRole]);$role=$fallback->fetch(PDO::FETCH_ASSOC);
            if(is_array($role)){$authority=(int)$role['authority_level'];$roleKey=(string)$role['role_key'];$roleLabel=(string)$role['role_label'];$clientRoleId=(int)$role['id'];}
        } catch(Throwable $e){$authority=match($baseRole){'DEV'=>1000,'SUPER'=>90,'ADMIN'=>50,default=>10};}
    }
    if($baseRole==='DEV')$authority=1000;$authority=max(1,min(1000,$authority));
    $user['role']=$baseRole;$user['actual_employee_type']=$baseRole;$user['employee_type']=$baseRole;$user['role_name']=$roleLabel;$user['client_role_id']=$clientRoleId;$user['role_key']=$roleKey;$user['role_label']=$roleLabel;$user['authority_level']=$authority;$user['is_dev']=$baseRole==='DEV';

    if($baseRole==='DEV'){
        $contextId=(int)$user['client_id'];$clientStmt=$pdo->prepare("SELECT id FROM clients WHERE id=? AND status='active' LIMIT 1");$clientStmt->execute([$contextId]);
        if(!$clientStmt->fetchColumn()){clear_dev_active_client_id();$user['client_id']=$authClientId;$user['active_client_id']=$authClientId;$user['is_cross_client_context']=false;}
    }else{clear_dev_active_client_id();$user['client_id']=$authClientId;$user['active_client_id']=$authClientId;$user['is_cross_client_context']=false;}

    $user['auth_client_id']=$authClientId;$user['home_client_id']=$authClientId;
    [$permissions,$levels]=beta_permission_snapshot($pdo,$user);$user['permissions']=$permissions;$user['permission_levels']=$levels;
    $user['is_management']=!empty($permissions['workforce.view'])||!empty($permissions['timesheets.view_all'])||!empty($permissions['disputes.review'])||!empty($permissions['finance.cross_store']);
    $user['is_super']=!empty($permissions['timesheets.view_all']);$user['is_admin']=$baseRole==='ADMIN';
    if(isset($_SESSION['user'])&&is_array($_SESSION['user']))foreach(['role','actual_employee_type','employee_type','role_name','client_role_id','role_key','role_label','authority_level','is_super','is_management','is_admin','is_dev'] as $field)$_SESSION['user'][$field]=$user[$field];
    $user=beta_apply_dev_role_preview($pdo,$user);
    beta_enforce_route_permission($user,$pdo);return $user;
}

function parse_utc_datetime(mixed $value, bool $optional = true): ?string
{
    if (($value === null || $value === '') && $optional) return null;
    $text = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new DateTimeZone(APP_TIMEZONE));
    if (!$date) throw new MerdWorkforceException('invalid_datetime', 'Enter a valid date and time.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
