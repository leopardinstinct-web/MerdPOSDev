<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/role_authority.php';
require_once __DIR__ . '/../includes/dashboard_access.php';

function role_actor(array $sessionUser): array
{
    if (beta_has_permission($sessionUser, 'roles.define') || beta_has_permission($sessionUser, 'roles.manage')) return $sessionUser;
    throw new MerdWorkforceException('forbidden', 'Role administration is not permitted for this account.');
}

function role_label(mixed $value): string
{
    $label = trim((string)$value);
    if ($label === '' || mb_strlen($label) > 80) {
        throw new MerdWorkforceException('invalid_role_name', 'Enter a role name up to 80 characters.');
    }
    return $label;
}

function role_level(mixed $value): int
{
    $level = filter_var($value, FILTER_VALIDATE_INT);
    if ($level === false || $level < 1 || $level > 99) {
        throw new MerdWorkforceException('invalid_authority', 'Role LOA must be a whole number from 1 to 99.');
    }
    return (int)$level;
}

function permission_level(mixed $value): int
{
    $level = filter_var($value, FILTER_VALIDATE_INT);
    if ($level === false || $level < 1 || $level > 1000) {
        throw new MerdWorkforceException('invalid_permission_authority', 'Permission LOA must be a whole number from 1 to 1000.');
    }
    return (int)$level;
}

function role_key_from_label(PDO $pdo, int $clientId, string $label): string
{
    $base = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', trim($label)));
    $base = trim($base, '_');
    if ($base === '') $base = 'ROLE';
    $base = substr($base, 0, 26);
    if (in_array($base, ['USER','ADMIN','SUPER','DEV'], true)) $base .= '_CUSTOM';
    $key = $base;
    $suffix = 2;
    $check = $pdo->prepare('SELECT COUNT(*) FROM client_roles WHERE client_id=? AND role_key=?');
    while (true) {
        $check->execute([$clientId, $key]);
        if ((int)$check->fetchColumn() === 0) return $key;
        $tail = '_' . $suffix++;
        $key = substr($base, 0, 32 - strlen($tail)) . $tail;
    }
}

function role_permission_state(PDO $pdo, int $clientId): array
{
    $catalog=merd_portal_permission_catalog();$levels=beta_permission_levels($pdo,$clientId);
    $super=merd_dashboard_system_role($pdo,$clientId,'SUPER');$user=merd_dashboard_system_role($pdo,$clientId,'USER');$rows=[];
    foreach($catalog as $key=>$rule){
        $row=['permission_key'=>$key,'label'=>(string)$rule['label'],'category'=>(string)$rule['category'],
            'min_authority_level'=>!empty($rule['dev_only'])?1000:(int)($levels[$key]??1000),'dev_only'=>!empty($rule['dev_only']),'order'=>(int)($rule['order']??0)];
        foreach(['SUPER'=>$super,'USER'=>$user] as $target=>$role){
            $eligible=is_array($role)&&empty($rule['dev_only'])&&merd_permission_role_key_allowed($rule,$target,false)
                && (int)$role['authority_level'] >= (int)$row['min_authority_level'];
            $row[strtolower($target).'_available']=$eligible;
            $row[strtolower($target).'_enabled']=$eligible && merd_role_usability_enabled($pdo,$clientId,$target,$key);
        }
        $rows[]=$row;
    }
    usort($rows,fn(array $a,array $b):int=>strcmp($a['category'],$b['category'])?:($a['order']<=>$b['order'])?:strcmp($a['permission_key'],$b['permission_key']));
    return $rows;
}

function role_state(PDO $pdo, array $actor): array
{
    $clientId=(int)$actor['client_id'];
    $clientStmt=$pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1');
    $clientStmt->execute([$clientId]);$client=$clientStmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($client))throw new MerdWorkforceException('client_not_found','Working client was not found.');
    $stmt=$pdo->prepare("SELECT r.id,r.role_key,r.role_label,r.base_role,r.authority_level,r.is_system,r.status,"
        ."(SELECT COUNT(*) FROM employees e WHERE e.client_id=r.client_id AND e.client_role_id=r.id AND UPPER(TRIM(COALESCE(e.employee_type,'')))<>'DEV') employee_count,"
        ."(SELECT COUNT(*) FROM dashboard_role_layouts d WHERE d.client_id=r.client_id AND d.role_id=r.id) dashboard_widget_count "
        ."FROM client_roles r WHERE r.client_id=? AND UPPER(r.role_key)<>'DEV' ORDER BY r.authority_level,r.id");
    $stmt->execute([$clientId]);$roles=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $actualDev=beta_actual_user_is_dev($actor);$actorKey=strtoupper((string)($actor['role_key']??$actor['role']??'USER'));
    foreach($roles as &$role){$role['allowed_widgets']=merd_dashboard_allowed_widgets($pdo,$clientId,$role);$role['editable']=$actualDev;
        $role['deletable']=$actualDev&&empty($role['is_system'])&&(int)$role['employee_count']===0;$role['assignable']=true;}unset($role);
    return ['success'=>true,'csrf'=>csrf_token(),'client'=>$client,'roles'=>$roles,'permissions'=>role_permission_state($pdo,$clientId),
        'dev_authority_level'=>1000,'authorization_model'=>'platform_dev_ceiling_admin_usability_v1','actor_authority_level'=>(int)($actor['authority_level']??0),
        'actor_role_key'=>$actorKey,'can_define_roles'=>$actualDev,'can_manage_system_roles'=>$actualDev,
        'can_manage_permissions'=>$actualDev&&beta_has_permission($actor,'permissions.manage',$pdo),'can_manage_usability'=>beta_has_permission($actor,'roles.manage',$pdo)];
}

function role_audit(PDO $pdo, array $actor, string $action, string $entityType, string $entityId, array $details): void
{
    try{$stmt=$pdo->prepare('INSERT INTO admin_audit_logs (client_id,employee_id,platform_identity_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([(int)$actor['client_id'],beta_actor_employee_id($actor),beta_actor_platform_identity_id($actor),$action,$entityType,$entityId,
            json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64)]);
    }catch(Throwable $e){error_log('MERDPOS role audit failed: '.get_class($e));}
}

function sync_system_authority(PDO $pdo, int $clientId, string $roleKey, int $level, array $actor): void
{
    if(!in_array($roleKey,['USER','ADMIN','SUPER'],true))return;
    $stmt=$pdo->prepare('INSERT INTO client_role_authority (client_id,role_name,authority_level,updated_by_employee_id,updated_by_platform_identity_id) VALUES (?,?,?,?,?) '
        .'ON DUPLICATE KEY UPDATE authority_level=VALUES(authority_level),updated_by_employee_id=VALUES(updated_by_employee_id),updated_by_platform_identity_id=VALUES(updated_by_platform_identity_id),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$clientId,$roleKey,$level,beta_actor_employee_id($actor),beta_actor_platform_identity_id($actor)]);
}

function prune_role_dashboard(PDO $pdo, int $clientId, array $role): void
{
    $allowed = array_fill_keys(merd_dashboard_allowed_widgets($pdo, $clientId, $role), true);
    $stmt = $pdo->prepare('SELECT id,widget_key FROM dashboard_role_layouts WHERE client_id=? AND role_id=?');
    $stmt->execute([$clientId, (int)$role['id']]);
    $delete = $pdo->prepare('DELETE FROM dashboard_role_layouts WHERE id=? AND client_id=? AND role_id=?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($allowed[(string)$row['widget_key']])) $delete->execute([(int)$row['id'],$clientId,(int)$role['id']]);
    }
}

function prune_all_role_dashboards(PDO $pdo, int $clientId): void
{
    foreach (merd_dashboard_roles($pdo, $clientId, false) as $role) prune_role_dashboard($pdo, $clientId, $role);
}

function snapshot_role_dashboard_allowance(PDO $pdo, int $clientId): array
{
    $snapshot = [];
    foreach (merd_dashboard_roles($pdo, $clientId, false) as $role) {
        $snapshot[(int)$role['id']] = merd_dashboard_allowed_widgets($pdo, $clientId, $role);
    }
    return $snapshot;
}

function materialize_newly_allowed_dashboard_widgets(PDO $pdo, int $clientId, array $before): void
{
    $sizes = [
        'working_now_count'=>[3,2], 'pending_disputes'=>[3,2], 'active_employees'=>[3,2], 'sync_attention'=>[3,2],
        'my_shift'=>[4,2], 'my_disputes'=>[4,3], 'working_now'=>[6,4], 'workforce_by_store'=>[6,4],
        'store_cash_position'=>[6,4], 'cash_mix'=>[6,4], 'today_sales_by_store'=>[6,4], 'recent_attendance'=>[12,5],
        'attendance_change'=>[3,2], 'attendance_trend_7d'=>[6,4], 'sales_change'=>[3,2], 'sales_trend_7d'=>[6,4],
        'top_stores_sales'=>[6,4], 'sync_status_table'=>[8,4],
    ];
    $existingStmt = $pdo->prepare('SELECT widget_key,grid_y,grid_h FROM dashboard_role_layouts WHERE client_id=? AND role_id=? ORDER BY grid_y,grid_x,id');
    $insert = $pdo->prepare('INSERT IGNORE INTO dashboard_role_layouts (client_id,role_id,widget_key,grid_x,grid_y,grid_w,grid_h) VALUES (?,?,?,?,?,?,?)');
    foreach (merd_dashboard_roles($pdo, $clientId, false) as $role) {
        $roleId = (int)$role['id'];
        $prior = array_fill_keys((array)($before[$roleId] ?? []), true);
        $after = merd_dashboard_allowed_widgets($pdo, $clientId, $role);
        $newlyAllowed = array_values(array_filter($after, fn(string $key): bool => !isset($prior[$key])));
        if (!$newlyAllowed) continue;
        $existingStmt->execute([$clientId, $roleId]);
        $rows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        $present = array_fill_keys(array_map(fn(array $row): string => (string)$row['widget_key'], $rows), true);
        $nextY = 0;
        foreach ($rows as $row) $nextY = max($nextY, (int)$row['grid_y'] + (int)$row['grid_h']);
        foreach ($newlyAllowed as $key) {
            if (isset($present[$key])) continue;
            [$w,$h] = $sizes[$key] ?? [4,3];
            $insert->execute([$clientId,$roleId,$key,0,$nextY,$w,$h]);
            if ($insert->rowCount() > 0) { $present[$key] = true; $nextY += $h; }
        }
    }
}

function clone_admin_dashboard(PDO $pdo, int $clientId, array $newRole): void
{
    $admin = merd_dashboard_system_role($pdo, $clientId, 'ADMIN');
    if (!$admin) return;
    $allowed = array_fill_keys(merd_dashboard_allowed_widgets($pdo, $clientId, $newRole), true);
    $source = $pdo->prepare('SELECT widget_key,grid_x,grid_y,grid_w,grid_h FROM dashboard_role_layouts WHERE client_id=? AND role_id=? ORDER BY grid_y,grid_x,id');
    $source->execute([$clientId, (int)$admin['id']]);
    $insert = $pdo->prepare(
        'INSERT INTO dashboard_role_layouts (client_id,role_id,widget_key,grid_x,grid_y,grid_w,grid_h) VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($source->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($allowed[(string)$row['widget_key']])) continue;
        $insert->execute([$clientId, (int)$newRole['id'], (string)$row['widget_key'], (int)$row['grid_x'], (int)$row['grid_y'], (int)$row['grid_w'], (int)$row['grid_h']]);
    }
}

try {
    $sessionUser = beta_require_active_user();
    $actor = role_actor($sessionUser);
    $pdo = portal_db();
    $clientId = (int)$actor['client_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(role_state($pdo, $actor));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['success' => false, 'error' => 'GET or POST required.'], 405);
    }

    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? '');

    if ($action === 'save_usability') {
        beta_require_permission($actor,'roles.manage',$pdo);
        $actorKey=strtoupper((string)($actor['role_key']??$actor['role']??'USER'));
        if(!beta_actual_user_is_dev($actor)&&$actorKey!=='ADMIN')throw new MerdWorkforceException('role_forbidden','Only ADMIN may configure SUPER / USER usability.');
        $target=strtoupper(trim((string)($input['role_key']??'')));
        if(!in_array($target,['SUPER','USER'],true))throw new MerdWorkforceException('invalid_role','Choose SUPER or USER.');
        $enabled=$input['enabled']??null;if(!is_array($enabled))throw new MerdWorkforceException('invalid_usability','Provide application usability settings.');
        $targetRole=merd_dashboard_system_role($pdo,$clientId,$target);if(!$targetRole)throw new MerdWorkforceException('role_not_found','Target role is not configured.');
        $catalog=merd_portal_permission_catalog();$levels=beta_permission_levels($pdo,$clientId);$before=snapshot_role_dashboard_allowance($pdo,$clientId);
        $upsert=$pdo->prepare('INSERT INTO client_role_usability (client_id,role_key,permission_key,enabled,updated_by_employee_id,updated_by_platform_identity_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by_employee_id=VALUES(updated_by_employee_id),updated_by_platform_identity_id=VALUES(updated_by_platform_identity_id),updated_at=CURRENT_TIMESTAMP');
        $changed=[];$pdo->beginTransaction();try{
            foreach($enabled as $key=>$raw){if(!is_string($key)||!isset($catalog[$key]))throw new MerdWorkforceException('invalid_usability','Unknown application capability.');
                $rule=$catalog[$key];$available=empty($rule['dev_only'])&&merd_permission_role_key_allowed($rule,$target,false)&&(int)$targetRole['authority_level']>=(int)($levels[$key]??1000);
                if(!$available)throw new MerdWorkforceException('role_forbidden','That capability is outside the DEV-defined ceiling for '.$target.'.');
                $value=filter_var($raw,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($value===null)throw new MerdWorkforceException('invalid_usability','Usability values must be true or false.');
                $upsert->execute([$clientId,$target,$key,$value?1:0,beta_actor_employee_id($actor),beta_actor_platform_identity_id($actor)]);$changed[$key]=$value;}
            $targetRole=merd_dashboard_system_role($pdo,$clientId,$target);if($targetRole)prune_role_dashboard($pdo,$clientId,$targetRole);
            materialize_newly_allowed_dashboard_widgets($pdo,$clientId,$before);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        role_audit($pdo,$actor,'role.usability.update','client_role',$target,['role_key'=>$target,'enabled'=>$changed]);json_response(role_state($pdo,$actor));
    }

    if ($action === 'create_role') {
        beta_require_permission($actor, 'roles.define', $pdo);
        $label = role_label($input['role_label'] ?? '');
        $level = role_level($input['authority_level'] ?? null);
        if (!beta_actual_user_is_dev($actor) && $level > (int)$actor['authority_level']) {
            throw new MerdWorkforceException('role_forbidden', 'You cannot create a role above your own authority level.');
        }
        $dup = $pdo->prepare('SELECT id FROM client_roles WHERE client_id=? AND LOWER(TRIM(role_label))=LOWER(TRIM(?)) LIMIT 1');
        $dup->execute([$clientId, $label]);
        if ($dup->fetchColumn()) throw new MerdWorkforceException('duplicate_role', 'A role with that name already exists.');
        $key = role_key_from_label($pdo, $clientId, $label);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO client_roles (client_id,role_key,role_label,base_role,authority_level,is_system,status) VALUES (?,?,?,'ADMIN',?,0,'active')");
            $stmt->execute([$clientId, $key, $label, $level]);
            $roleId = (int)$pdo->lastInsertId();
            $newRole = merd_dashboard_role_by_id($pdo, $clientId, $roleId);
            if (!$newRole) throw new RuntimeException('New role could not be reloaded.');
            clone_admin_dashboard($pdo, $clientId, $newRole);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        role_audit($pdo, $actor, 'role.create', 'client_role', (string)$roleId, ['role_key'=>$key,'role_label'=>$label,'base_role'=>'ADMIN','authority_level'=>$level,'inherits_dashboard_from'=>'ADMIN']);
        json_response(role_state($pdo, $actor));
    }

    if ($action === 'save_role') {
        beta_require_permission($actor, 'roles.define', $pdo);
        $roleId = filter_var($input['role_id'] ?? null, FILTER_VALIDATE_INT);
        if ($roleId === false || $roleId <= 0) throw new MerdWorkforceException('invalid_role', 'Choose a valid role.');
        $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
        if (!$role) throw new MerdWorkforceException('role_not_found', 'Role not found.');
        $key = strtoupper((string)$role['role_key']);
        $actualDev = beta_actual_user_is_dev($actor);
        if ($key === 'DEV') throw new MerdWorkforceException('role_fixed', 'DEV authority is fixed at 1000.');
        if (!$actualDev && !empty($role['is_system'])) throw new MerdWorkforceException('role_forbidden', 'Only DEV can change system role authority.');
        if (!$actualDev && (int)$role['authority_level'] > (int)$actor['authority_level']) throw new MerdWorkforceException('role_forbidden', 'You cannot edit a role above your own authority level.');
        $level = role_level($input['authority_level'] ?? null);
        if (!$actualDev && $level > (int)$actor['authority_level']) throw new MerdWorkforceException('role_forbidden', 'You cannot raise a role above your own authority level.');
        $label = !empty($role['is_system']) ? (string)$role['role_label'] : role_label($input['role_label'] ?? $role['role_label']);

        if (empty($role['is_system'])) {
            $dup = $pdo->prepare('SELECT id FROM client_roles WHERE client_id=? AND LOWER(TRIM(role_label))=LOWER(TRIM(?)) AND id<>? LIMIT 1');
            $dup->execute([$clientId, $label, (int)$roleId]);
            if ($dup->fetchColumn()) throw new MerdWorkforceException('duplicate_role', 'A role with that name already exists.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE client_roles SET role_label=?,authority_level=? WHERE client_id=? AND id=?');
            $stmt->execute([$label, $level, $clientId, (int)$roleId]);
            sync_system_authority($pdo,$clientId,$key,$level,$actor);
            $updated = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
            if ($updated) prune_role_dashboard($pdo, $clientId, $updated);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        role_audit($pdo, $actor, 'role.update', 'client_role', (string)$roleId, ['role_label'=>$label,'authority_level'=>$level]);
        json_response(role_state($pdo, $actor));
    }

    if ($action === 'delete_role') {
        beta_require_permission($actor, 'roles.define', $pdo);
        $roleId = filter_var($input['role_id'] ?? null, FILTER_VALIDATE_INT);
        if ($roleId === false || $roleId <= 0) throw new MerdWorkforceException('invalid_role', 'Choose a valid role.');
        $role = merd_dashboard_role_by_id($pdo, $clientId, (int)$roleId);
        if (!$role) throw new MerdWorkforceException('role_not_found', 'Role not found.');
        if (!empty($role['is_system'])) throw new MerdWorkforceException('role_fixed', 'System roles cannot be deleted.');
        if (!beta_actual_user_is_dev($actor) && (int)$role['authority_level'] > (int)$actor['authority_level']) {
            throw new MerdWorkforceException('role_forbidden', 'You cannot delete a role above your own authority level.');
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=? AND client_role_id=?');
        $count->execute([$clientId, (int)$roleId]);
        if ((int)$count->fetchColumn() > 0) throw new MerdWorkforceException('role_in_use', 'Reassign employees before deleting this role.');
        $pdo->prepare('DELETE FROM client_roles WHERE client_id=? AND id=?')->execute([$clientId, (int)$roleId]);
        role_audit($pdo, $actor, 'role.delete', 'client_role', (string)$roleId, ['role_key'=>$role['role_key'],'role_label'=>$role['role_label'],'dashboard'=>'cascade_deleted']);
        json_response(role_state($pdo, $actor));
    }

    if ($action === 'save_permissions') {
        beta_require_permission($actor, 'permissions.manage', $pdo);
        $levels = $input['levels'] ?? null;
        if (!is_array($levels)) throw new MerdWorkforceException('invalid_permission_authority', 'Provide permission LOA levels.');
        $catalog = merd_portal_permission_catalog();
        $upsert = $pdo->prepare(
            'INSERT INTO client_permission_levels (client_id,permission_key,min_authority_level,updated_by_employee_id,updated_by_platform_identity_id) VALUES (?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE min_authority_level=VALUES(min_authority_level),updated_by_employee_id=VALUES(updated_by_employee_id),updated_by_platform_identity_id=VALUES(updated_by_platform_identity_id),updated_at=CURRENT_TIMESTAMP'
        );
        $changed = [];
        $allowedBefore = snapshot_role_dashboard_allowance($pdo, $clientId);
        $pdo->beginTransaction();
        try {
            foreach ($catalog as $key => $rule) {
                if (!empty($rule['dev_only'])) {
                    $upsert->execute([$clientId,$key,1000,beta_actor_employee_id($actor),beta_actor_platform_identity_id($actor)]);
                    continue;
                }
                if (!array_key_exists($key, $levels)) continue;
                $level = permission_level($levels[$key]);
                $upsert->execute([$clientId,$key,$level,beta_actor_employee_id($actor),beta_actor_platform_identity_id($actor)]);
                $changed[$key] = $level;
            }
            // Tightening removes widgets whose data is no longer authorised. Relaxation
            // materializes only widgets that became newly allowed in this save; it does not
            // re-add widgets that were already allowed and intentionally removed before.
            prune_all_role_dashboards($pdo, $clientId);
            materialize_newly_allowed_dashboard_widgets($pdo, $clientId, $allowedBefore);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        role_audit($pdo, $actor, 'permissions.update', 'client_permission_policy', (string)$clientId, ['levels'=>$changed,'dev_only_fixed'=>true]);
        json_response(role_state($pdo, $actor));
    }

    // Backward-compatible save for the original three authority inputs.
    if ($action === 'save_authority') {
        beta_require_permission($actor, 'roles.define', $pdo);
        if (!beta_actual_user_is_dev($actor)) throw new MerdWorkforceException('role_forbidden', 'Only DEV can change system role authority.');
        $levels = $input['levels'] ?? null;
        if (!is_array($levels)) throw new MerdWorkforceException('invalid_authority', 'Provide authority levels.');
        $pdo->beginTransaction();
        try {
            foreach (['USER','ADMIN','SUPER'] as $key) {
                $level = role_level($levels[$key] ?? $levels[strtolower($key)] ?? null);
                $role = merd_dashboard_system_role($pdo, $clientId, $key);
                if (!$role) throw new RuntimeException("{$key} role is missing.");
                $pdo->prepare('UPDATE client_roles SET authority_level=? WHERE id=? AND client_id=?')->execute([$level, (int)$role['id'], $clientId]);
                sync_system_authority($pdo,$clientId,$key,$level,$actor);
                $role['authority_level'] = $level;
                prune_role_dashboard($pdo, $clientId, $role);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        json_response(role_state($pdo, $actor));
    }

    json_response(['success' => false, 'error' => 'Unsupported role action.'], 400);
} catch (Throwable $e) {
    beta_api_error($e);
}
