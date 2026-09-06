<?php
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/includes/device_auth.php';

const MERD_ATT_E2E_PREFIX = 'AUTOTEST Attendance E2E';

function att_fail(string $message): never {
    fwrite(STDERR, "ATTENDANCE_E2E_FIXTURE_FAIL {$message}\n");
    exit(1);
}

function att_client(PDO $pdo): array {
    $stmt=$pdo->query("SELECT id,name,client_code,status FROM clients WHERE UPPER(client_code)='DUMMY' LIMIT 2");
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1) att_fail('exact DUMMY client not found');
    $client=$rows[0];
    if(strtoupper((string)$client['client_code'])!=='DUMMY') att_fail('client boundary mismatch');
    if(strtolower((string)$client['status'])!=='active') {
        $pdo->prepare("UPDATE clients SET status='active' WHERE id=? AND UPPER(client_code)='DUMMY'")->execute([(int)$client['id']]);
        $client['status']='active';
    }
    return $client;
}

function att_role(PDO $pdo,int $clientId,string $baseRole): array {
    $stmt=$pdo->prepare("SELECT id,role_key,role_label,base_role,authority_level FROM client_roles WHERE client_id=? AND UPPER(base_role)=? ORDER BY authority_level DESC,id LIMIT 1");
    $stmt->execute([$clientId,strtoupper($baseRole)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)) att_fail("{$baseRole} role unavailable for DUMMY");
    return $row;
}

function att_digits(PDO $pdo,int $clientId): string {
    for($i=0;$i<40;$i++) {
        $candidate='89'.gmdate('His').str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);
        $stmt=$pdo->prepare('SELECT 1 FROM employees WHERE client_id=? AND user_id=? LIMIT 1');
        $stmt->execute([$clientId,$candidate]);
        if(!$stmt->fetchColumn()) return $candidate;
    }
    att_fail('could not allocate DUMMY numeric user ID');
}

function att_password(): string {
    return (string)random_int(10000000,99999999);
}

function att_store(PDO $pdo,int $clientId,string $name,string $code): int {
    $stmt=$pdo->prepare("INSERT INTO stores (client_id,store_name,store_code,status,week_start_day) VALUES (?,?,?,'active',1)");
    $stmt->execute([$clientId,$name,$code]);
    return (int)$pdo->lastInsertId();
}

function att_employee(PDO $pdo,int $clientId,int $storeId,array $role,string $name,string $userId,string $password): int {
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $stmt=$pdo->prepare('INSERT INTO employees (client_id,store_id,full_name,user_id,login_password,employee_type,pin_code,role_name,client_role_id,hourly_rate,status) VALUES (?,?,?,?,?,?,?,?,?,?,\'active\')');
    $stmt->execute([$clientId,$storeId,$name,$userId,$hash,strtoupper((string)$role['base_role']),$hash,(string)$role['role_label'],(int)$role['id'],'25.00']);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employee_store_access (client_id,employee_id,access_mode,updated_by_employee_id) VALUES (?,?,'selected',?)")->execute([$clientId,$id,$id]);
    $pdo->prepare('INSERT INTO employee_store_assignments (client_id,employee_id,store_id) VALUES (?,?,?)')->execute([$clientId,$id,$storeId]);
    $pdo->prepare('INSERT INTO employee_hourly_rate_history (client_id,employee_id,hourly_rate,effective_from,changed_by_employee_id) VALUES (?,?,25.00,CURDATE(),?)')->execute([$clientId,$id,$id]);
    return $id;
}

function att_device(PDO $pdo,int $clientId,int $storeId,string $uuid,string $name): array {
    $token=merd_device_token_generate();
    $clock=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $store=new MerdPdoDeviceActivationStore($pdo);
    $store->begin();
    try {
        $id=$store->activate($clientId,$storeId,$uuid,$name,merd_device_token_hash($token),$clock->modify('+180 days'),$clock->modify('+7 days'),$clock);
        $store->commit();
    } catch(Throwable $e) {
        $store->rollback();
        throw $e;
    }
    return ['id'=>$id,'uuid'=>$uuid,'token'=>$token];
}

function att_prepare(PDO $pdo,string $output): void {
    $client=att_client($pdo); $clientId=(int)$client['id'];
    $run=gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
    $label=MERD_ATT_E2E_PREFIX.' '.$run;
    $userRole=att_role($pdo,$clientId,'USER'); $superRole=att_role($pdo,$clientId,'SUPER');
    $storeA=att_store($pdo,$clientId,$label.' Store A','AE2EA'.substr(hash('sha256',$run),0,8));
    $storeB=att_store($pdo,$clientId,$label.' Store B','AE2EB'.substr(hash('sha256',$run),0,8));
    $userPass=att_password(); $replacementPass=att_password(); $superPass=att_password();
    $userId=att_digits($pdo,$clientId); $replacementUserId=att_digits($pdo,$clientId); $superUserId=att_digits($pdo,$clientId);
    $employee=att_employee($pdo,$clientId,$storeA,$userRole,$label.' Employee',$userId,$userPass);
    $replacement=att_employee($pdo,$clientId,$storeA,$userRole,$label.' Replacement',$replacementUserId,$replacementPass);
    $super=att_employee($pdo,$clientId,$storeA,$superRole,$label.' Reviewer',$superUserId,$superPass);
    $deviceA=att_device($pdo,$clientId,$storeA,'autotest-attendance-a-'.$run,$label.' Device A');
    $deviceB=att_device($pdo,$clientId,$storeB,'autotest-attendance-b-'.$run,$label.' Device B');
    $fixture=['run'=>$run,'client'=>['id'=>$clientId,'code'=>'DUMMY'],'stores'=>['a'=>['id'=>$storeA,'name'=>$label.' Store A'],'b'=>['id'=>$storeB,'name'=>$label.' Store B']],
        'employees'=>['user'=>['id'=>$employee,'user_id'=>$userId,'password'=>$userPass,'name'=>$label.' Employee'],'replacement'=>['id'=>$replacement,'user_id'=>$replacementUserId,'password'=>$replacementPass,'name'=>$label.' Replacement'],'super'=>['id'=>$super,'user_id'=>$superUserId,'password'=>$superPass,'name'=>$label.' Reviewer']],
        'devices'=>['a'=>['id'=>$deviceA['id'],'uuid'=>$deviceA['uuid'],'token'=>$deviceA['token'],'store_id'=>$storeA],'b'=>['id'=>$deviceB['id'],'uuid'=>$deviceB['uuid'],'token'=>$deviceB['token'],'store_id'=>$storeB]]];
    $dir=dirname($output);
    if(!str_starts_with($output,'/home/dridsheikh/.merdpos-test/')) att_fail('fixture output must stay in private test directory');
    if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) att_fail('could not create private test directory');
    $json=json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if(file_put_contents($output,$json,LOCK_EX)===false) att_fail('could not write private fixture');
    chmod($output,0600);
    echo "ATTENDANCE_E2E_FIXTURE_READY run={$run} client_id={$clientId} store_a={$storeA} store_b={$storeB} employee={$employee} replacement={$replacement} super={$super}\n";
}

function att_cleanup(PDO $pdo,string $run): void {
    if(!preg_match('/^\d{14}-[a-f0-9]{6}$/',$run)) att_fail('invalid cleanup run id');
    $client=att_client($pdo); $clientId=(int)$client['id']; $prefix=MERD_ATT_E2E_PREFIX.' '.$run;
    $emp=$pdo->prepare('SELECT id FROM employees WHERE client_id=? AND full_name LIKE ?'); $emp->execute([$clientId,$prefix.'%']); $employeeIds=array_map('intval',$emp->fetchAll(PDO::FETCH_COLUMN));
    $stores=$pdo->prepare('SELECT id FROM stores WHERE client_id=? AND store_name LIKE ?'); $stores->execute([$clientId,$prefix.'%']); $storeIds=array_map('intval',$stores->fetchAll(PDO::FETCH_COLUMN));
    $devices=$pdo->prepare('SELECT id FROM devices WHERE client_id=? AND device_uuid LIKE ?'); $devices->execute([$clientId,'autotest-attendance-%-'.$run]); $deviceIds=array_map('intval',$devices->fetchAll(PDO::FETCH_COLUMN));
    if(count($employeeIds)>6||count($storeIds)>4||count($deviceIds)>4) att_fail('cleanup boundary count exceeded');
    $pdo->beginTransaction();
    try {
        if($employeeIds){$marks=implode(',',array_fill(0,count($employeeIds),'?')); $args=array_merge([$clientId],$employeeIds);
            foreach(['attendance_qr_uses','attendance_disputes','attendance_account_flags'] as $table){$col=$table==='attendance_qr_uses'?'employee_id':'employee_id';$pdo->prepare("DELETE FROM {$table} WHERE {$col} IN ({$marks})")->execute($employeeIds);}
            $pdo->prepare("DELETE FROM attendance_shifts WHERE client_id=? AND employee_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM employee_store_assignments WHERE client_id=? AND employee_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM employee_store_access WHERE client_id=? AND employee_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM employee_hourly_rate_history WHERE client_id=? AND employee_id IN ({$marks})")->execute($args);
            $pdo->prepare("DELETE FROM employees WHERE client_id=? AND id IN ({$marks}) AND full_name LIKE ?")->execute(array_merge($args,[$prefix.'%']));
        }
        if($deviceIds){$marks=implode(',',array_fill(0,count($deviceIds),'?')); $pdo->prepare("DELETE FROM attendance_device_keys WHERE client_id=? AND device_id IN ({$marks})")->execute(array_merge([$clientId],$deviceIds)); $pdo->prepare("DELETE FROM devices WHERE client_id=? AND id IN ({$marks}) AND device_uuid LIKE ?")->execute(array_merge([$clientId],$deviceIds,['autotest-attendance-%-'.$run]));}
        if($storeIds){$marks=implode(',',array_fill(0,count($storeIds),'?')); $args=array_merge([$clientId],$storeIds); $pdo->prepare("DELETE FROM store_weekly_hours WHERE client_id=? AND store_id IN ({$marks})")->execute($args); $pdo->prepare("DELETE FROM store_shift_start_times WHERE client_id=? AND store_id IN ({$marks})")->execute($args); $pdo->prepare("DELETE FROM stores WHERE client_id=? AND id IN ({$marks}) AND store_name LIKE ?")->execute(array_merge($args,[$prefix.'%']));}
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    echo "ATTENDANCE_E2E_FIXTURE_CLEAN run={$run} employees=".count($employeeIds)." stores=".count($storeIds)." devices=".count($deviceIds)."\n";
}


function att_audit(PDO $pdo): void {
    $client=att_client($pdo); $clientId=(int)$client['id'];
    $stmt=$pdo->prepare('SELECT store_name FROM stores WHERE client_id=? AND store_name LIKE ? ORDER BY store_name');
    $stmt->execute([$clientId,MERD_ATT_E2E_PREFIX.' %']); $runs=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) if(preg_match('/^AUTOTEST Attendance E2E (\d{14}-[a-f0-9]{6}) /',(string)$name,$m)) $runs[$m[1]]=true;
    if(!$runs){echo "ATTENDANCE_E2E_AUDIT none\n";return;}
    foreach(array_keys($runs) as $run){$prefix=MERD_ATT_E2E_PREFIX.' '.$run;
        $count=function(string $table,string $column,string $like) use($pdo,$clientId): int {$q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE client_id=? AND {$column} LIKE ?");$q->execute([$clientId,$like]);return (int)$q->fetchColumn();};
        echo "ATTENDANCE_E2E_AUDIT run={$run} stores=".$count('stores','store_name',$prefix.'%')." employees=".$count('employees','full_name',$prefix.'%')." devices=".$count('devices','device_uuid','autotest-attendance-%-'.$run)."\n";
    }
}

$action=$argv[1]??'';
if($action==='audit') { att_audit($pdo); exit(0); }
if($action==='prepare') { $output=$argv[2]??''; if($output==='') att_fail('prepare needs private output path'); att_prepare($pdo,$output); exit(0); }
if($action==='cleanup') { $run=$argv[2]??''; att_cleanup($pdo,$run); exit(0); }
att_fail('usage: attendance_e2e_fixture.php audit | prepare <private-output> | cleanup <run>');
