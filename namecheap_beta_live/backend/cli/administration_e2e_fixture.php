<?php
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
require_once dirname(__DIR__) . '/api/config.php';

const MERD_ADMIN_E2E_PREFIX = 'AUTOTEST Administration E2E';
const MERD_ADMIN_E2E_CLIENT_PREFIX = 'DUMMYADM';

function admin_e2e_fail(string $message): never {
    fwrite(STDERR, "ADMIN_E2E_FIXTURE_FAIL {$message}\n");
    exit(1);
}

function admin_e2e_client(PDO $pdo): array {
    $stmt=$pdo->query("SELECT id,name,client_code,status,default_currency,default_timezone FROM clients WHERE UPPER(client_code)='DUMMY' LIMIT 2");
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1) admin_e2e_fail('exact DUMMY client not found');
    $client=$rows[0];
    if(strtoupper((string)$client['client_code'])!=='DUMMY') admin_e2e_fail('client boundary mismatch');
    if(strtolower((string)$client['status'])!=='active') admin_e2e_fail('DUMMY client must already be active');
    return $client;
}

function admin_e2e_role(PDO $pdo,int $clientId,string $baseRole): array {
    $stmt=$pdo->prepare("SELECT id,role_key,role_label,base_role,authority_level FROM client_roles WHERE client_id=? AND UPPER(base_role)=? AND status='active' ORDER BY authority_level DESC,id LIMIT 1");
    $stmt->execute([$clientId,strtoupper($baseRole)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)) admin_e2e_fail("{$baseRole} role unavailable for DUMMY");
    return $row;
}
function admin_e2e_digits(PDO $pdo,int $clientId): string {
    for($i=0;$i<50;$i++) {
        $candidate='87'.gmdate('His').str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);
        $stmt=$pdo->prepare('SELECT 1 FROM employees WHERE client_id=? AND user_id=? LIMIT 1');
        $stmt->execute([$clientId,$candidate]);
        if(!$stmt->fetchColumn()) return $candidate;
    }
    admin_e2e_fail('could not allocate numeric DUMMY user ID');
}

function admin_e2e_password(): string {
    return (string)random_int(10000000,99999999);
}

function admin_e2e_anchor_store(PDO $pdo,int $clientId,string $name,string $code): int {
    $stmt=$pdo->prepare("INSERT INTO stores (client_id,store_name,store_code,status,week_start_day,timezone,currency_code) VALUES (?,?,?,'active',1,'Australia/Sydney','AUD')");
    $stmt->execute([$clientId,$name,$code]);
    $id=(int)$pdo->lastInsertId();
    $hours=$pdo->prepare("INSERT INTO store_weekly_hours (client_id,store_id,day_of_week,start_time,end_time,is_closed,updated_by_employee_id) VALUES (?,?,?,?,?,0,NULL)");
    foreach(range(1,7) as $day) $hours->execute([$clientId,$id,$day,'09:00:00','17:00:00']);
    return $id;
}

function admin_e2e_employee(PDO $pdo,int $clientId,int $storeId,array $role,string $name,string $userId,string $password): int {
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $stmt=$pdo->prepare('INSERT INTO employees (client_id,store_id,full_name,user_id,login_password,employee_type,pin_code,role_name,client_role_id,hourly_rate,status) VALUES (?,?,?,?,?,?,?,?,?,?,\'active\')');
    $stmt->execute([$clientId,$storeId,$name,$userId,$hash,strtoupper((string)$role['base_role']),$hash,(string)$role['role_label'],(int)$role['id'],'25.00']);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employee_store_access (client_id,employee_id,access_mode,updated_by_employee_id) VALUES (?,?,'selected',?)")->execute([$clientId,$id,$id]);
    $pdo->prepare('INSERT INTO employee_store_assignments (client_id,employee_id,store_id) VALUES (?,?,?)')->execute([$clientId,$id,$storeId]);
    $pdo->prepare('INSERT INTO employee_hourly_rate_history (client_id,employee_id,hourly_rate,effective_from,changed_by_employee_id) VALUES (?,?,25.00,CURDATE(),?)')->execute([$clientId,$id,$id]);
    return $id;
}

function admin_e2e_prepare(PDO $pdo,string $output): void {
    $client=admin_e2e_client($pdo); $clientId=(int)$client['id'];
    $run=gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
    $label=MERD_ADMIN_E2E_PREFIX.' '.$run;
    $store=admin_e2e_anchor_store($pdo,$clientId,$label.' Anchor','ADM'.substr(hash('sha256',$run),0,12));
    $roles=[];
    foreach(['DEV','SUPER','USER'] as $key) $roles[strtolower($key)]=admin_e2e_role($pdo,$clientId,$key);
    $employees=[];
    foreach(['dev','super','user'] as $key) {
        $password=admin_e2e_password(); $userId=admin_e2e_digits($pdo,$clientId);
        $id=admin_e2e_employee($pdo,$clientId,$store,$roles[$key],$label.' '.strtoupper($key),$userId,$password);
        $employees[$key]=['id'=>$id,'user_id'=>$userId,'password'=>$password,'name'=>$label.' '.strtoupper($key)];
    }
    $fixture=['run'=>$run,'prefix'=>$label,'client'=>['id'=>$clientId,'code'=>'DUMMY','original_name'=>(string)$client['name'],'original_status'=>(string)$client['status']],
        'anchor_store'=>['id'=>$store,'name'=>$label.' Anchor'],'employees'=>$employees,
        'temp_client'=>['code'=>MERD_ADMIN_E2E_CLIENT_PREFIX.strtoupper(substr(hash('sha256',$run),0,10)),'name'=>$label.' Client']];
    if(!str_starts_with($output,'/home/dridsheikh/.merdpos-test/')) admin_e2e_fail('fixture output must stay private');
    $dir=dirname($output);
    if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) admin_e2e_fail('could not create private fixture directory');
    $json=json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if(file_put_contents($output,$json,LOCK_EX)===false) admin_e2e_fail('could not write fixture');
    chmod($output,0600);
    echo "ADMIN_E2E_FIXTURE_READY run={$run} client_id={$clientId} anchor={$store} dev={$employees['dev']['id']} super={$employees['super']['id']} user={$employees['user']['id']}\n";
}

function admin_e2e_prefix_ids(PDO $pdo,int $clientId,string $run): array {
    if(!preg_match('/^\d{14}-[a-f0-9]{6}$/',$run)) admin_e2e_fail('invalid run id');
    $prefix=MERD_ADMIN_E2E_PREFIX.' '.$run;
    $q=$pdo->prepare('SELECT id FROM employees WHERE client_id=? AND full_name LIKE ?');$q->execute([$clientId,$prefix.'%']);$employees=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $q=$pdo->prepare('SELECT id,logo_path FROM stores WHERE client_id=? AND store_name LIKE ?');$q->execute([$clientId,$prefix.'%']);$stores=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT id,client_code FROM clients WHERE client_code LIKE ?');$q->execute([MERD_ADMIN_E2E_CLIENT_PREFIX.'%']);$clients=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($employees)>8||count($stores)>5||count($clients)>2) admin_e2e_fail('cleanup boundary count exceeded');
    return [$prefix,$employees,$stores,$clients];
}

function admin_e2e_delete_temp_clients(PDO $pdo,array $clients): void {
    foreach($clients as $client) {
        $id=(int)$client['id']; $code=strtoupper((string)$client['client_code']);
        if(!str_starts_with($code,MERD_ADMIN_E2E_CLIENT_PREFIX)) admin_e2e_fail('refusing temp-client cleanup boundary');
        $counts=[];
        foreach(['stores','employees','devices'] as $table){$q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE client_id=?");$q->execute([$id]);$counts[$table]=(int)$q->fetchColumn();}
        if(array_sum($counts)!==0) admin_e2e_fail('temporary client unexpectedly owns operational rows');
        $pdo->prepare('DELETE FROM dashboard_role_layouts WHERE client_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM client_role_authority WHERE client_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM client_roles WHERE client_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM clients WHERE id=? AND client_code LIKE ?')->execute([$id,MERD_ADMIN_E2E_CLIENT_PREFIX.'%']);
    }
}
function admin_e2e_cleanup(PDO $pdo,string $run): void {
    $client=admin_e2e_client($pdo); $clientId=(int)$client['id'];
    [$prefix,$employeeIds,$storeRows,$tempClients]=admin_e2e_prefix_ids($pdo,$clientId,$run);
    if(str_starts_with((string)$client['name'],$prefix)) {
        $pdo->prepare("UPDATE clients SET name='DUMMY',client_code='DUMMY',status='active' WHERE id=? AND client_code='DUMMY'")->execute([$clientId]);
    }
    admin_e2e_delete_temp_clients($pdo,$tempClients);
    $storeIds=array_map(static fn(array $row): int => (int)$row['id'],$storeRows);
    $pdo->beginTransaction();
    try {
        if($employeeIds) {
            $marks=implode(',',array_fill(0,count($employeeIds),'?')); $args=array_merge([$clientId],$employeeIds);
            $pdo->prepare("DELETE FROM employee_store_assignments WHERE client_id=? AND employee_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM employee_store_access WHERE client_id=? AND employee_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM employee_hourly_rate_history WHERE client_id=? AND employee_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM employees WHERE client_id=? AND id IN ({$marks}) AND full_name LIKE ?")->execute(array_merge($args,[$prefix.'%']));
        }
        if($storeIds) {
            $marks=implode(',',array_fill(0,count($storeIds),'?')); $args=array_merge([$clientId],$storeIds);
            $pdo->prepare("DELETE FROM store_weekly_hours WHERE client_id=? AND store_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM store_shift_start_times WHERE client_id=? AND store_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM stores WHERE client_id=? AND id IN ({$marks}) AND store_name LIKE ?")->execute(array_merge($args,[$prefix.'%']));
        }
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    $logoDir=dirname(__DIR__,2).'/timesheet_portal/uploads/store_logos';
    foreach($storeRows as $row) {
        $relative=trim((string)($row['logo_path']??''));
        if($relative!=='' && str_starts_with($relative,'uploads/store_logos/')) {
            $file=dirname(__DIR__,2).'/timesheet_portal/'.$relative;
            if(is_file($file) && realpath(dirname($file))===realpath($logoDir)) @unlink($file);
        }
    }
    echo "ADMIN_E2E_FIXTURE_CLEAN run={$run} employees=".count($employeeIds)." stores=".count($storeRows)." clients=".count($tempClients)."\n";
}

function admin_e2e_audit(PDO $pdo): void {
    $client=admin_e2e_client($pdo); $clientId=(int)$client['id'];
    $counts=[];
    foreach([['stores','store_name',MERD_ADMIN_E2E_PREFIX.' %'],['employees','full_name',MERD_ADMIN_E2E_PREFIX.' %'],['clients','client_code',MERD_ADMIN_E2E_CLIENT_PREFIX.'%']] as [$table,$column,$like]) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE ".($table==='clients'?'1=1':'client_id=?').' AND '.$column.' LIKE ?');
        $args=$table==='clients'?[$like]:[$clientId,$like]; $q->execute($args); $counts[$table]=(int)$q->fetchColumn();
    }
    $dummyName=(string)$client['name'];
    echo "ADMIN_E2E_AUDIT stores={$counts['stores']} employees={$counts['employees']} clients={$counts['clients']} dummy_name=".json_encode($dummyName)."\n";
}

$action=$argv[1]??'';
if($action==='audit') { admin_e2e_audit($pdo); exit(0); }
if($action==='prepare') { $output=$argv[2]??''; if($output==='') admin_e2e_fail('prepare needs private output path'); admin_e2e_prepare($pdo,$output); exit(0); }
if($action==='cleanup') { $run=$argv[2]??''; admin_e2e_cleanup($pdo,$run); exit(0); }
admin_e2e_fail('usage: administration_e2e_fixture.php audit | prepare <private-output> | cleanup <run>');
