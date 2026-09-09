<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../../backend/api/includes/platform_identity.php';
require_once __DIR__ . '/../../backend/api/includes/portal_permissions.php';
require_once __DIR__ . '/../../backend/api/includes/workforce_beta.php';
require_once __DIR__ . '/dashboard_access.php';

function beta_api_error(Throwable $error): never
{
    if ($error instanceof MerdWorkforceException) {
        json_response(['success'=>false,'error_code'=>$error->errorCode,'error'=>$error->getMessage()], 200);
    }
    error_log('timesheet beta API failure: ' . get_class($error));
    json_response(['success'=>false,'error_code'=>'internal_error','error'=>'The request could not be completed.'], 500);
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
    return beta_actual_user_is_dev($user);
}

function beta_actual_user_is_dev(array $user): bool
{
    if (strtolower(trim((string)($user['identity_scope'] ?? ''))) === 'platform') {
        return strtoupper(trim((string)($user['actual_role_key'] ?? $user['role_key'] ?? $user['role'] ?? ''))) === 'DEV';
    }
    return strtoupper(trim((string)($user['actual_role_key'] ?? $user['actual_employee_type'] ?? $user['role'] ?? ''))) === 'DEV';
}

function beta_user_is_platform_identity(array $user): bool
{
    return strtolower(trim((string)($user['identity_scope'] ?? ''))) === 'platform'
        && (int)($user['platform_identity_id'] ?? $user['id'] ?? 0) > 0;
}

function beta_actor_employee_id(array $user): ?int
{
    if (beta_user_is_platform_identity($user)) return null;
    $id = (int)($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function beta_actor_platform_identity_id(array $user): ?int
{
    if (!beta_user_is_platform_identity($user)) return null;
    $id = (int)($user['platform_identity_id'] ?? $user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function beta_has_permission(array $user, string $permission, ?PDO $pdo = null): bool
{
    $catalog = merd_portal_permission_catalog();
    if (!isset($catalog[$permission])) return false;
    if (!empty($user['is_role_preview']) && isset($user['permissions']) && is_array($user['permissions']) && array_key_exists($permission, $user['permissions'])) {
        return (bool)$user['permissions'][$permission];
    }
    $isDev = beta_actual_user_is_dev($user);
    if (!empty($catalog[$permission]['dev_only'])) return $isDev;
    $roleKey = strtoupper(trim((string)($user['role_key'] ?? $user['role'] ?? 'USER')));
    if (!merd_permission_role_key_allowed($catalog[$permission], $roleKey, $isDev)) return false;
    $authority = max(0, (int)($user['authority_level'] ?? 0));
    if ($pdo instanceof PDO && !empty($user['client_id'])) {
        $levels = beta_permission_levels($pdo, (int)$user['client_id']);
        if ($authority < (int)($levels[$permission] ?? 1000)) return false;
        return merd_role_usability_enabled($pdo, (int)$user['client_id'], $roleKey, $permission);
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
    $isDev = beta_actual_user_is_dev($user);
    $roleKey = strtoupper(trim((string)($user['role_key'] ?? $user['role'] ?? 'USER')));
    $permissions = [];
    foreach ($catalog as $key => $rule) {
        if (!empty($rule['dev_only'])) { $permissions[$key] = $isDev; continue; }
        if (!merd_permission_role_key_allowed($rule, $roleKey, $isDev)) { $permissions[$key] = false; continue; }
        $allowed = $authority >= (int)($levels[$key] ?? 1000);
        if ($allowed) $allowed = merd_role_usability_enabled($pdo, (int)$user['client_id'], $roleKey, $key);
        $permissions[$key] = $allowed;
    }
    return [$permissions, $levels];
}

function beta_apply_dev_role_preview(PDO $pdo, array $user): array
{
    if (!beta_actual_user_is_dev($user)) { $user['is_role_preview'] = false; return $user; }
    $user['actual_role_key'] = (string)($user['actual_role_key'] ?? 'DEV');
    $user['actual_role_label'] = (string)($user['actual_role_label'] ?? 'Developer');
    $user['actual_authority_level'] = 1000;
    $user['actual_client_role_id'] = null;
    $user['actual_permissions'] = (array)($user['actual_permissions'] ?? $user['permissions'] ?? []);
    $viewRoleKey = strtoupper(trim((string)($_COOKIE['merdpos_dev_view_role'] ?? 'ADMIN')));
    if (!in_array($viewRoleKey, ['DEV','ADMIN','SUPER','USER'], true)) $viewRoleKey = 'ADMIN';
    if ($viewRoleKey === 'DEV') {
        $user['is_role_preview'] = false;
        $user['view_role_key'] = 'DEV';
        $user['view_role_id'] = null;
        return $user;
    }
    $viewRole = merd_dashboard_system_role($pdo, (int)$user['client_id'], $viewRoleKey);
    if (!$viewRole || strtolower((string)($viewRole['status'] ?? 'active')) !== 'active') {
        $user['is_role_preview'] = false;
        return $user;
    }
    $previewUser = $user;
    $previewUser['role'] = $viewRoleKey;
    $previewUser['role_key'] = $viewRoleKey;
    $previewUser['role_label'] = (string)$viewRole['role_label'];
    $previewUser['authority_level'] = (int)$viewRole['authority_level'];
    [$permissions, $levels] = beta_permission_snapshot($pdo, $previewUser);
    $user['role'] = $viewRoleKey;
    $user['employee_type'] = $viewRoleKey;
    $user['role_name'] = (string)$viewRole['role_label'];
    $user['client_role_id'] = (int)$viewRole['id'];
    $user['role_key'] = $viewRoleKey;
    $user['role_label'] = (string)$viewRole['role_label'];
    $user['authority_level'] = (int)$viewRole['authority_level'];
    $user['permissions'] = $permissions;
    $user['permission_levels'] = $levels;
    $user['is_management'] = !empty($permissions['workforce.view']) || !empty($permissions['timesheets.view_all']) || !empty($permissions['disputes.review']) || !empty($permissions['finance.cross_store']);
    $user['is_super'] = $viewRoleKey === 'SUPER';
    $user['is_admin'] = $viewRoleKey === 'ADMIN';
    $user['is_role_preview'] = true;
    $user['view_role_key'] = $viewRoleKey;
    $user['view_role_id'] = (int)$viewRole['id'];
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
        case 'attendance_scan.php': beta_require_permission($user, 'attendance.scan', $pdo); return;
        case 'beta_state.php':
            beta_require_any_permission($user, ['dashboard.view','disputes.view_own','disputes.review','finance.view','password.change_own'], $pdo); return;
        case 'dashboard_data.php': beta_require_permission($user, 'dashboard.view', $pdo); return;
        case 'ui_studio_history.php':
        case 'ui_studio_asset.php':
            if (!beta_actual_user_is_dev($user)) throw new MerdWorkforceException('forbidden', 'Developer access is required.');
            return;
        case 'dashboard_layout.php':
            $studioDashboard = beta_actual_user_is_dev($user) && (($method === 'GET' && (string)($_GET['dev_studio'] ?? '') === '1') || ($method === 'POST' && !empty($input['dev_studio'])));
            if ($studioDashboard) return;
            beta_require_permission($user, $method === 'POST' ? 'dashboard.configure' : 'dashboard.view', $pdo); return;
        case 'change_password.php': beta_require_permission($user, 'password.change_own', $pdo); return;
        case 'weeks.php':
        case 'timesheet.php': beta_require_any_permission($user, ['timesheets.view_own','timesheets.view_all'], $pdo); return;
        case 'check_sheet.php':
        case 'dev_status.php': beta_require_permission($user, 'dev.status', $pdo); return;
        case 'clients.php': beta_require_permission($user, 'clients.manage', $pdo); return;
        case 'legacy_migration.php': beta_require_permission($user, 'legacy_migration.manage', $pdo); return;
        case 'defaults.php': beta_require_permission($user, 'defaults.manage', $pdo); return;
        case 'store_identity.php': beta_require_permission($user, 'stores.profile.manage', $pdo); return;
        case 'store_logo.php': beta_require_permission($user, 'stores.logo.manage', $pdo); return;
        case 'store_timings.php': beta_require_permission($user, 'stores.timings.manage', $pdo); return;
        case 'role_authority.php':
            if ($method === 'GET') { beta_require_any_permission($user, ['roles.define','roles.manage'], $pdo); return; }
            if ($action === 'save_usability') { beta_require_permission($user, 'roles.manage', $pdo); return; }
            if ($action === 'save_permissions') { beta_require_permission($user, 'permissions.manage', $pdo); return; }
            beta_require_permission($user, 'roles.define', $pdo); return;
        case 'client_context.php':
            if ($method === 'POST') beta_require_permission($user, 'client_context.switch', $pdo);
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
            beta_require_permission($user, $type === 'open_day' ? 'finance.open_day' : 'finance.submit', $pdo); return;
        case 'disputes.php':
            if ($method === 'GET') { beta_require_any_permission($user, ['disputes.view_own','disputes.review'], $pdo); return; }
            if ($action === 'decide') { beta_require_permission($user, 'disputes.review', $pdo); return; }
            if ($action === 'resolve_flag') { beta_require_permission($user, 'attendance_flags.resolve', $pdo); return; }
            if (in_array($action, ['create','cancel','confirm_handover','reject_handover'], true)) { beta_require_permission($user, 'disputes.submit_own', $pdo); return; }
            throw new MerdWorkforceException('permission_policy_missing', 'This dispute action has no permission policy.');
        case 'login.php':
        case 'logout.php':
        case 'me.php': return;
        default: throw new MerdWorkforceException('permission_policy_missing', 'This beta API is not registered in the portal permission policy.');
    }
}

function beta_require_active_user(): array
{
    $user = require_login();
    $pdo = portal_db();

    if (!beta_user_is_platform_identity($user) && beta_actual_user_is_dev($user)) {
        $platform = merd_platform_identity_by_user_id($pdo, (string)($user['user_id'] ?? ''));
        if (is_array($platform) && merd_platform_identity_is_active_dev($platform)) {
            $selected = merd_platform_identity_selected_client($pdo, (int)$platform['id']) ?: (int)($user['client_id'] ?? 0);
            $user = [
                'id'=>(int)$platform['id'],'platform_identity_id'=>(int)$platform['id'],'identity_scope'=>'platform',
                'client_id'=>$selected,'active_client_id'=>$selected,'auth_client_id'=>0,'home_client_id'=>0,'store_id'=>null,
                'name'=>(string)$platform['full_name'],'full_name'=>(string)$platform['full_name'],'user_id'=>(string)$platform['user_id'],
                'role'=>'DEV','actual_employee_type'=>'DEV','employee_type'=>'DEV','role_name'=>'Developer',
                'client_role_id'=>null,'role_key'=>'DEV','role_label'=>'Developer','authority_level'=>1000,
                'is_dev'=>true,'is_super'=>true,'is_management'=>true,'is_admin'=>false,
            ];
            start_app_session();
            $_SESSION['user'] = $user;
            if ($selected > 0) $_SESSION['dev_active_client_id'] = $selected;
        }
    }

    if (beta_user_is_platform_identity($user)) {
        $identityId = (int)($user['platform_identity_id'] ?? $user['id'] ?? 0);
        $identity = merd_platform_identity_by_id($pdo, $identityId);
        if (!is_array($identity) || !merd_platform_identity_is_active_dev($identity)) {
            throw new MerdWorkforceException('account_inactive', 'Your platform account is inactive. Contact DEV administration.');
        }
        $contextId = (int)($user['client_id'] ?? 0);
        $clientStmt = $pdo->prepare("SELECT id FROM clients WHERE id=? AND status='active' LIMIT 1");
        $clientStmt->execute([$contextId]);
        if (!$clientStmt->fetchColumn()) {
            $contextId = merd_platform_identity_selected_client($pdo, $identityId) ?: 0;
            $clientStmt->execute([$contextId]);
            if ($contextId <= 0 || !$clientStmt->fetchColumn()) throw new MerdWorkforceException('client_not_found', 'Choose an active Working client.');
            set_dev_active_client_id($contextId);
        }
        $user['id']=$identityId;$user['platform_identity_id']=$identityId;$user['identity_scope']='platform';
        $user['client_id']=$contextId;$user['active_client_id']=$contextId;$user['auth_client_id']=0;$user['home_client_id']=0;$user['store_id']=null;
        $user['name']=(string)$identity['full_name'];$user['full_name']=(string)$identity['full_name'];$user['user_id']=(string)$identity['user_id'];
        $user['role']='DEV';$user['actual_employee_type']='DEV';$user['employee_type']='DEV';$user['role_name']='Developer';
        $user['client_role_id']=null;$user['role_key']='DEV';$user['role_label']='Developer';$user['authority_level']=1000;$user['is_dev']=true;
        [$permissions,$levels]=beta_permission_snapshot($pdo,$user);$user['permissions']=$permissions;$user['permission_levels']=$levels;
        $user['is_management']=true;$user['is_super']=true;$user['is_admin']=false;
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) $_SESSION['user']=array_merge($_SESSION['user'],$user);
        $user=beta_apply_dev_role_preview($pdo,$user);
        beta_enforce_route_permission($user,$pdo);
        return $user;
    }

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
    if (!in_array($baseRole, ['USER','ADMIN','SUPER'], true)) $baseRole = 'USER';
    $authority=0;$roleKey=$baseRole;$roleLabel=(string)($actor['role_name'] ?: $baseRole);$clientRoleId=null;
    if ($roleRowValid) {
        $authority=(int)$actor['authority_level'];$roleKey=(string)$actor['role_key'];$roleLabel=(string)$actor['role_label'];$clientRoleId=(int)$actor['client_role_id'];
    } else {
        try {
            $fallback=$pdo->prepare("SELECT id,role_key,role_label,authority_level FROM client_roles WHERE client_id=? AND role_key=? AND status='active' LIMIT 1");
            $fallback->execute([$authClientId,$baseRole]);$role=$fallback->fetch(PDO::FETCH_ASSOC);
            if(is_array($role)){$authority=(int)$role['authority_level'];$roleKey=(string)$role['role_key'];$roleLabel=(string)$role['role_label'];$clientRoleId=(int)$role['id'];}
        } catch(Throwable){$authority=match($baseRole){'SUPER'=>90,'ADMIN'=>50,default=>10};}
    }
    $authority=max(1,min(999,$authority));
    $user['identity_scope']='employee';$user['role']=$baseRole;$user['actual_employee_type']=$baseRole;$user['employee_type']=$baseRole;$user['role_name']=$roleLabel;
    $user['client_role_id']=$clientRoleId;$user['role_key']=$roleKey;$user['role_label']=$roleLabel;$user['authority_level']=$authority;$user['is_dev']=false;
    clear_dev_active_client_id();$user['client_id']=$authClientId;$user['active_client_id']=$authClientId;$user['is_cross_client_context']=false;
    $user['auth_client_id']=$authClientId;$user['home_client_id']=$authClientId;
    [$permissions,$levels]=beta_permission_snapshot($pdo,$user);$user['permissions']=$permissions;$user['permission_levels']=$levels;
    $user['is_management']=!empty($permissions['workforce.view'])||!empty($permissions['timesheets.view_all'])||!empty($permissions['disputes.review'])||!empty($permissions['finance.cross_store']);
    $user['is_super']=$baseRole==='SUPER';$user['is_admin']=$baseRole==='ADMIN';
    if(isset($_SESSION['user'])&&is_array($_SESSION['user']))foreach(['identity_scope','role','actual_employee_type','employee_type','role_name','client_role_id','role_key','role_label','authority_level','is_super','is_management','is_admin','is_dev'] as $field)$_SESSION['user'][$field]=$user[$field];
    beta_enforce_route_permission($user,$pdo);
    return $user;
}

function parse_utc_datetime(mixed $value, bool $optional = true): ?string
{
    if (($value === null || $value === '') && $optional) return null;
    $text = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new DateTimeZone(APP_TIMEZONE));
    if (!$date) throw new MerdWorkforceException('invalid_datetime', 'Enter a valid date and time.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
