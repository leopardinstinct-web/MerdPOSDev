<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/dashboard_access.php';

function clients_code(mixed $value): string
{
    $code = strtoupper(trim((string)$value));
    if (strlen($code) < 2 || strlen($code) > 50) throw new MerdWorkforceException('invalid_client_code', 'Client Code must be 2–50 characters.');
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,49}$/', $code)) throw new MerdWorkforceException('invalid_client_code', 'Client Code must start with a letter or number and use only A–Z, 0–9, hyphen or underscore.');
    if (in_array($code, ['ALL','NONE','NULL','SYSTEM','ADMIN','SUPER','DEV','CLIENT'], true)) throw new MerdWorkforceException('reserved_client_code', 'That Client Code is reserved. Choose a more specific identifier.');
    return $code;
}

function clients_name(mixed $value): string
{
    $name = trim((string)$value);
    if ($name === '' || mb_strlen($name) > 100) throw new MerdWorkforceException('invalid_client_name', 'Enter a client name up to 100 characters.');
    return $name;
}

function clients_status(mixed $value): string
{
    $status = strtolower(trim((string)$value));
    if (!in_array($status, ['active','inactive'], true)) throw new MerdWorkforceException('invalid_status', 'Choose active or inactive.');
    return $status;
}

function clients_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() === 1;
}

function clients_state(PDO $pdo): array
{
    $hasMigrationState = clients_table_exists($pdo, 'client_migration_state');
    $migrationSelect = $hasMigrationState
        ? "COALESCE(ms.attendance_authority,'google_legacy') AS attendance_authority,COALESCE(ms.financial_authority,'google_legacy') AS financial_authority,ms.attendance_cutover_at,ms.financial_cutover_at,"
        : "'google_legacy' AS attendance_authority,'google_legacy' AS financial_authority,NULL AS attendance_cutover_at,NULL AS financial_cutover_at,";
    $migrationJoin = $hasMigrationState ? ' LEFT JOIN client_migration_state ms ON ms.client_id=c.id ' : ' ';

    $stmt = $pdo->query(
        "SELECT c.id,c.name,c.client_code,c.status,c.default_currency,c.default_timezone,c.created_at,"
        . $migrationSelect
        . '(SELECT COUNT(*) FROM stores s WHERE s.client_id=c.id) AS store_count,'
        . '(SELECT COUNT(*) FROM employees e WHERE e.client_id=c.id) AS employee_count,'
        . "(SELECT COUNT(*) FROM employees e2 WHERE e2.client_id=c.id AND e2.status='active') AS active_employee_count,"
        . '(SELECT COUNT(*) FROM devices d WHERE d.client_id=c.id) AS device_count '
        . 'FROM clients c' . $migrationJoin . 'ORDER BY c.id ASC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function clients_seed_roles(PDO $pdo, int $clientId, int $actorEmployeeId): void
{
    $legacy = $pdo->prepare(
        'INSERT INTO client_role_authority (client_id,role_name,authority_level,updated_by_employee_id) VALUES (?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE authority_level=VALUES(authority_level),updated_by_employee_id=VALUES(updated_by_employee_id)'
    );
    foreach ([['USER',10],['ADMIN',50],['SUPER',90]] as [$role, $level]) $legacy->execute([$clientId, $role, $level, $actorEmployeeId]);

    $roleStmt = $pdo->prepare(
        'INSERT INTO client_roles (client_id,role_key,role_label,base_role,authority_level,is_system,status) VALUES (?,?,?,?,?,1,\'active\') '
        . 'ON DUPLICATE KEY UPDATE role_label=VALUES(role_label),base_role=VALUES(base_role),authority_level=VALUES(authority_level),is_system=1,status=\'active\''
    );
    foreach ([['USER','User','USER',10],['ADMIN','Admin','ADMIN',50],['SUPER','Super','SUPER',90],['DEV','Developer','DEV',1000]] as [$key,$label,$base,$level]) $roleStmt->execute([$clientId,$key,$label,$base,$level]);
}

function clients_seed_permissions(PDO $pdo, int $clientId): void
{
    $insert = $pdo->prepare('INSERT INTO client_permission_levels (client_id,permission_key,min_authority_level) VALUES (?,?,?) ON DUPLICATE KEY UPDATE min_authority_level=VALUES(min_authority_level)');
    foreach (merd_portal_permission_catalog() as $key => $rule) {
        $level = !empty($rule['dev_only']) ? 1000 : max(1, min(1000, (int)$rule['min_loa']));
        $insert->execute([$clientId, $key, $level]);
    }
}

function clients_widget_position(string $key): array
{
    return match ($key) {
        'working_now_count' => [0,0,3,2], 'pending_disputes' => [3,0,3,2], 'active_employees' => [6,0,3,2], 'sync_attention' => [9,0,3,2],
        'my_shift' => [0,2,4,2], 'my_disputes' => [4,2,4,3], 'working_now' => [0,5,6,4], 'workforce_by_store' => [6,5,6,4],
        'store_cash_position' => [0,9,6,4], 'cash_mix' => [6,9,6,4], 'today_sales_by_store' => [0,13,6,4], 'recent_attendance' => [0,17,12,5],
        default => [0,0,4,3],
    };
}

function clients_seed_dashboards(PDO $pdo, int $clientId): void
{
    $insert = $pdo->prepare('INSERT IGNORE INTO dashboard_role_layouts (client_id,role_id,widget_key,grid_x,grid_y,grid_w,grid_h) VALUES (?,?,?,?,?,?,?)');
    foreach (merd_dashboard_roles($pdo, $clientId, true) as $role) foreach (merd_dashboard_allowed_widgets($pdo, $clientId, $role) as $widgetKey) {
        [$x,$y,$w,$h] = clients_widget_position($widgetKey);$insert->execute([$clientId,(int)$role['id'],$widgetKey,$x,$y,$w,$h]);
    }
}

function clients_audit(PDO $pdo, array $user, int $targetClientId, string $action, array $details): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$targetClientId,(int)$user['id'],$action,'client',(string)$targetClientId,json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64)]);
    } catch (Throwable $e) { error_log('MERDPOS client admin audit failed: ' . get_class($e)); }
}

try {
    $user = beta_require_active_user();$pdo = portal_db();beta_require_permission($user, 'clients.manage', $pdo);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') json_response(['success'=>true,'csrf'=>csrf_token(),'clients'=>clients_state($pdo)]);

    $input=request_input();require_csrf($input);if((string)($input['action']??'')!=='save_client')json_response(['success'=>false,'error'=>'Unsupported client action.'],400);
    $id=isset($input['id'])&&$input['id']!==''&&$input['id']!==null?(int)$input['id']:null;$name=clients_name($input['name']??'');$code=clients_code($input['client_code']??'');$status=clients_status($input['status']??'active');
    $dupCode=$pdo->prepare('SELECT id FROM clients WHERE LOWER(TRIM(client_code))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1');$dupCode->execute([$code,$id,$id]);if($dupCode->fetchColumn())throw new MerdWorkforceException('duplicate_client_code','That Client Code is already assigned to another client.');
    $dupName=$pdo->prepare('SELECT id FROM clients WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1');$dupName->execute([$name,$id,$id]);if($dupName->fetchColumn())throw new MerdWorkforceException('duplicate_client_name','A client with that name already exists.');

    $pdo->beginTransaction();
    try {
        if($id===null){$setupKey=bin2hex(random_bytes(32));$stmt=$pdo->prepare('INSERT INTO clients (name,client_code,setup_key,status) VALUES (?,?,?,?)');$stmt->execute([$name,$code,$setupKey,$status]);$id=(int)$pdo->lastInsertId();clients_seed_roles($pdo,$id,(int)$user['id']);clients_seed_permissions($pdo,$id);clients_seed_dashboards($pdo,$id);if(clients_table_exists($pdo,'client_migration_state'))$pdo->prepare('INSERT IGNORE INTO client_migration_state (client_id) VALUES (?)')->execute([$id]);$action='client.create';}
        else{$check=$pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1 FOR UPDATE');$check->execute([$id]);$existing=$check->fetch(PDO::FETCH_ASSOC);if(!is_array($existing))throw new MerdWorkforceException('client_not_found','Client not found.');$stmt=$pdo->prepare('UPDATE clients SET name=?,client_code=?,status=? WHERE id=?');$stmt->execute([$name,$code,$status,$id]);$action='client.update';}
        $pdo->commit();
    } catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if((string)$e->getCode()==='23000')throw new MerdWorkforceException('duplicate_client','Client name or Client Code conflicts with an existing client.');throw $e;} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    clients_audit($pdo,$user,(int)$id,$action,['name'=>$name,'client_code'=>$code,'status'=>$status,'permission_model'=>'central_permission_loa_v1','legacy_migration_ready'=>clients_table_exists($pdo,'client_migration_state')]);
    json_response(['success'=>true,'csrf'=>csrf_token(),'message'=>$action==='client.create'?'Client created with roles, permissions and dashboards.':'Client saved.','client_id'=>(int)$id,'clients'=>clients_state($pdo)]);
} catch(Throwable $e){beta_api_error($e);}
