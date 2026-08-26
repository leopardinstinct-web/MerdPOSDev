<?php
declare(strict_types=1);

require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/timesheet_logic.php';

const LEGACY_GOOGLE_MAX_BYTES = 12582912; // 12 MiB per tab
const LEGACY_GOOGLE_TIMEOUT_SECONDS = 35;

function legacy_uuid_from_key(string $key): string
{
    $hex = hash('sha256', 'MERDPOS-LEGACY|' . $key);
    return substr($hex,0,8) . '-' . substr($hex,8,4) . '-5' . substr($hex,13,3)
        . '-a' . substr($hex,17,3) . '-' . substr($hex,20,12);
}

function legacy_norm(string $value): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]+/', '', trim($value)));
}

function legacy_spreadsheet_id(mixed $value): string
{
    $id = trim((string)$value);
    if ($id === '' || strlen($id) > 160 || preg_match('/^[A-Za-z0-9_-]{20,160}$/', $id) !== 1) {
        throw new MerdWorkforceException('invalid_spreadsheet_id', 'Enter a valid Google Spreadsheet ID.');
    }
    return $id;
}

function legacy_sheet_name(mixed $value): string
{
    $name = trim((string)$value);
    if ($name === '' || mb_strlen($name) > 160 || preg_match('/[\x00-\x1F]/u', $name)) {
        throw new MerdWorkforceException('invalid_sheet_name', 'Sheet/tab names must be 1–160 printable characters.');
    }
    return $name;
}

function legacy_google_csv_url(string $spreadsheetId, string $sheetName): string
{
    return 'https://docs.google.com/spreadsheets/d/' . rawurlencode($spreadsheetId)
        . '/gviz/tq?tqx=out:csv&sheet=' . rawurlencode($sheetName);
}

function legacy_google_fetch_tab(string $spreadsheetId, string $sheetName): string
{
    $spreadsheetId = legacy_spreadsheet_id($spreadsheetId);
    $sheetName = legacy_sheet_name($sheetName);
    $url = legacy_google_csv_url($spreadsheetId, $sheetName);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => LEGACY_GOOGLE_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MERDPOS-LegacyMigration/1.0',
        ]);
        $data = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        $finalHost = strtolower((string)(parse_url($finalUrl, PHP_URL_HOST) ?: ''));
        if ($data === false || $status < 200 || $status >= 400) {
            throw new RuntimeException('Google Sheet fetch failed (HTTP ' . $status . ($error ? ').' : ').'));
        }
        if ($finalHost !== '' && !($finalHost === 'docs.google.com' || str_ends_with($finalHost, '.googleusercontent.com'))) {
            throw new RuntimeException('Google Sheet redirected to an unexpected host.');
        }
    } else {
        $context = stream_context_create(['http'=>[
            'timeout'=>LEGACY_GOOGLE_TIMEOUT_SECONDS,
            'follow_location'=>1,
            'max_redirects'=>3,
            'header'=>"User-Agent: MERDPOS-LegacyMigration/1.0\r\n",
        ]]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false) throw new RuntimeException('Google Sheet fetch failed. Enable cURL or allow_url_fopen.');
    }

    if (strlen((string)$data) > LEGACY_GOOGLE_MAX_BYTES) {
        throw new RuntimeException('A Google Sheet tab exceeds the 12 MiB migration safety limit. Split the legacy tab before syncing.');
    }
    if (stripos((string)$data, '<html') !== false || stripos((string)$data, 'Sorry, unable to open') !== false) {
        throw new RuntimeException('Google returned an HTML/error page. The spreadsheet must be viewable by the migration service.');
    }
    return (string)$data;
}

function legacy_parse_csv_rows(string $csv, string $kind): array
{
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    $rawRows = [];
    $line = 0;
    while (($row = fgetcsv($fp)) !== false) {
        $line++;
        if ($row === [null] || count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) continue;
        $rawRows[] = ['line'=>$line,'values'=>array_map(static fn($v): string=>trim((string)$v),$row)];
    }
    fclose($fp);
    if (!$rawRows) return ['headers'=>[],'rows'=>[]];

    $headerIndex = 0;
    foreach ($rawRows as $i => $candidate) {
        $keys = array_map('legacy_norm', $candidate['values']);
        $score = 0;
        foreach (['name','username','userid','store','storename','logtype','date','time','payrate','amount','account','businessdate','submissiontype'] as $needle) {
            if (in_array($needle, $keys, true)) $score++;
        }
        if ($kind === 'financial' ? $score >= 2 : $score >= 2) { $headerIndex = $i; break; }
    }

    $headers = $rawRows[$headerIndex]['values'];
    $rows = [];
    foreach (array_slice($rawRows, $headerIndex + 1) as $source) {
        $item = ['_source_row'=>(int)$source['line'],'_raw'=>$source['values']];
        foreach ($headers as $i => $header) {
            $header = trim((string)$header);
            if ($header === '') continue;
            $item[$header] = $source['values'][$i] ?? '';
        }
        $rows[] = $item;
    }
    return ['headers'=>$headers,'rows'=>$rows];
}

function legacy_row_map(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        if (str_starts_with((string)$key, '_')) continue;
        $out[legacy_norm((string)$key)] = trim((string)$value);
    }
    return $out;
}

function legacy_field(array $row, array $aliases): string
{
    $map = legacy_row_map($row);
    foreach ($aliases as $alias) {
        $key = legacy_norm($alias);
        if (array_key_exists($key, $map)) return trim((string)$map[$key]);
    }
    return '';
}

function legacy_hash_row(array $row): string
{
    $map = legacy_row_map($row);
    ksort($map, SORT_STRING);
    return hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function legacy_redacted_row(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        if (str_starts_with((string)$key, '_')) continue;
        $norm = legacy_norm((string)$key);
        $sensitive = str_contains($norm, 'password') || str_contains($norm, 'pin')
            || str_contains($norm, 'secret') || str_contains($norm, 'token') || str_contains($norm, 'apikey');
        $out[(string)$key] = $sensitive && trim((string)$value) !== '' ? '[REDACTED]' : trim((string)$value);
    }
    return $out;
}

function legacy_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    foreach (['!Y-m-d','!d/m/Y','!d-m-Y','!d.m.Y','!m/d/Y','!d M Y','!j M Y','!d-M-Y','!j-M-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }
    $stamp = strtotime($value);
    if ($stamp === false) return null;
    return gmdate('Y-m-d', $stamp);
}

function legacy_time(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    foreach (['!H:i:s','!H:i','!G:i:s','!G:i','!g:i A','!g:i a','!g:i:s A','!g:i:s a'] as $format) {
        $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($time instanceof DateTimeImmutable && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) return $time->format('H:i:s');
    }
    return null;
}

function legacy_money(string $value): ?float
{
    $value = trim(str_replace([',','$','A$','AUD',' '], '', $value));
    if ($value === '' || !is_numeric($value)) return null;
    $amount = round((float)$value, 2);
    if (!is_finite($amount) || $amount < 0 || $amount > 9999999999.99) return null;
    return $amount;
}

function legacy_source_suggestions(): array
{
    $attendanceId = defined('SPREADSHEET_ID') ? trim((string)constant('SPREADSHEET_ID')) : '';
    $financialId = '';
    foreach (['FINANCIAL_SPREADSHEET_ID','FINANCE_SPREADSHEET_ID','SPREADSHEET_ID_FINANCIAL'] as $constant) {
        if (defined($constant) && trim((string)constant($constant)) !== '') { $financialId = trim((string)constant($constant)); break; }
    }
    return [
        'attendance_spreadsheet_id'=>$attendanceId,
        'attendance_sheets'=>[
            'timesheet'=>defined('SHEET_TIME_SHEET') ? (string)constant('SHEET_TIME_SHEET') : 'Time Sheet',
            'payrate'=>defined('SHEET_PAY_RATE') ? (string)constant('SHEET_PAY_RATE') : 'PayRate',
            'start_time'=>defined('SHEET_START_TIME') ? (string)constant('SHEET_START_TIME') : 'Start Time',
            'employee_setup'=>defined('SHEET_EMPLOYEE_SETUP') ? (string)constant('SHEET_EMPLOYEE_SETUP') : 'Employee Setup',
        ],
        'financial_spreadsheet_id'=>$financialId,
        'financial_sheets'=>[],
    ];
}

function legacy_decode_sheet_names(string $json, string $sourceType): array
{
    try { $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
    catch (Throwable) { return []; }
    if (!is_array($value)) return [];
    if ($sourceType === 'attendance') {
        $out = [];
        foreach (['timesheet','payrate','start_time','employee_setup'] as $key) {
            if (isset($value[$key]) && trim((string)$value[$key]) !== '') $out[$key] = trim((string)$value[$key]);
        }
        return $out;
    }
    $out = [];
    foreach ($value as $name) if (is_string($name) && trim($name) !== '') $out[] = trim($name);
    return array_values(array_unique($out));
}

function legacy_source_state(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare('SELECT source_type,provider,spreadsheet_id,sheet_names_json,status,updated_at FROM client_legacy_sources WHERE client_id=? ORDER BY source_type');
    $stmt->execute([$clientId]);
    $sources = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = (string)$row['source_type'];
        $sources[$type] = [
            'source_type'=>$type,
            'provider'=>(string)$row['provider'],
            'spreadsheet_id'=>(string)$row['spreadsheet_id'],
            'sheet_names'=>legacy_decode_sheet_names((string)$row['sheet_names_json'],$type),
            'status'=>(string)$row['status'],
            'updated_at'=>(string)$row['updated_at'],
        ];
    }
    return $sources;
}

function legacy_migration_state(PDO $pdo, int $clientId): array
{
    $pdo->prepare('INSERT IGNORE INTO client_migration_state (client_id) VALUES (?)')->execute([$clientId]);
    $stmt = $pdo->prepare('SELECT * FROM client_migration_state WHERE client_id=? LIMIT 1');
    $stmt->execute([$clientId]);
    return (array)$stmt->fetch(PDO::FETCH_ASSOC);
}

function legacy_recent_batches(PDO $pdo, int $clientId, int $limit = 10): array
{
    $limit = max(1, min(25, $limit));
    $stmt = $pdo->prepare(
        'SELECT public_id,mode,status,attendance_rows,financial_rows,inserted_rows,updated_rows,unchanged_rows,conflict_rows,rejected_rows,warning_rows,started_at,finished_at,error_message '
        . 'FROM legacy_migration_batches WHERE client_id=? ORDER BY id DESC LIMIT ' . $limit
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function legacy_open_conflicts(PDO $pdo, int $clientId, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        "SELECT c.id,b.public_id AS batch_id,c.source_type,c.source_key,c.conflict_code,c.message,c.existing_target_table,c.existing_target_key,c.created_at "
        . "FROM legacy_migration_conflicts c INNER JOIN legacy_migration_batches b ON b.id=c.batch_id "
        . "WHERE c.client_id=? AND c.status='open' ORDER BY c.id DESC LIMIT " . $limit
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function legacy_save_sources(PDO $pdo, array $actor, int $clientId, array $input): void
{
    $attendanceId = legacy_spreadsheet_id($input['attendance_spreadsheet_id'] ?? '');
    $attendanceSheets = $input['attendance_sheets'] ?? null;
    if (!is_array($attendanceSheets)) throw new MerdWorkforceException('invalid_attendance_sheets','Attendance sheet mapping is required.');
    $attendance = [];
    foreach (['timesheet','payrate','start_time','employee_setup'] as $key) $attendance[$key] = legacy_sheet_name($attendanceSheets[$key] ?? '');

    $financialIdRaw = trim((string)($input['financial_spreadsheet_id'] ?? ''));
    $financialTabsRaw = $input['financial_sheets'] ?? [];
    $financial = [];
    if ($financialIdRaw !== '') {
        $financialId = legacy_spreadsheet_id($financialIdRaw);
        if (!is_array($financialTabsRaw)) throw new MerdWorkforceException('invalid_financial_sheets','Financial sheet tabs must be a list.');
        foreach ($financialTabsRaw as $name) if (trim((string)$name) !== '') $financial[] = legacy_sheet_name($name);
        $financial = array_values(array_unique($financial));
        if (!$financial) throw new MerdWorkforceException('invalid_financial_sheets','Add at least one Financial tab name.');
    } else {
        $financialId = null;
    }

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO client_legacy_sources (client_id,source_type,provider,spreadsheet_id,sheet_names_json,status,created_by_employee_id,updated_by_employee_id) '
            . "VALUES (?,?,'google_public_csv',?,?,'active',?,?) "
            . "ON DUPLICATE KEY UPDATE spreadsheet_id=VALUES(spreadsheet_id),sheet_names_json=VALUES(sheet_names_json),status='active',updated_by_employee_id=VALUES(updated_by_employee_id)"
        );
        $upsert->execute([$clientId,'attendance',$attendanceId,json_encode($attendance,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$actor['id'],(int)$actor['id']]);
        if ($financialId !== null) {
            $upsert->execute([$clientId,'financial',$financialId,json_encode($financial,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$actor['id'],(int)$actor['id']]);
        } else {
            $pdo->prepare("UPDATE client_legacy_sources SET status='inactive',updated_by_employee_id=? WHERE client_id=? AND source_type='financial'")->execute([(int)$actor['id'],$clientId]);
        }
        $pdo->prepare('INSERT IGNORE INTO client_migration_state (client_id) VALUES (?)')->execute([$clientId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function legacy_identity_maps(PDO $pdo, int $clientId): array
{
    $employees = ['by_id'=>[],'by_name'=>[],'by_user'=>[]];
    $stmt = $pdo->prepare('SELECT id,full_name,user_id,store_id,status,employee_type,client_role_id,hourly_rate FROM employees WHERE client_id=? ORDER BY id');
    $stmt->execute([$clientId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $employees['by_id'][$id] = $row;
        $employees['by_name'][legacy_norm((string)$row['full_name'])][] = $row;
        if (trim((string)$row['user_id']) !== '') $employees['by_user'][trim((string)$row['user_id'])][] = $row;
    }

    $stores = ['by_id'=>[],'by_name'=>[],'by_code'=>[]];
    $stmt = $pdo->prepare(
        "SELECT s.id,s.store_name,s.store_code,s.status,COALESCE(s.timezone,c.default_timezone,'Australia/Sydney') AS timezone "
        . 'FROM stores s INNER JOIN clients c ON c.id=s.client_id WHERE s.client_id=? ORDER BY s.id'
    );
    $stmt->execute([$clientId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id=(int)$row['id'];$stores['by_id'][$id]=$row;
        $stores['by_name'][legacy_norm((string)$row['store_name'])][]=$row;
        if(trim((string)$row['store_code'])!=='')$stores['by_code'][legacy_norm((string)$row['store_code'])][]=$row;
    }
    return ['employees'=>$employees,'stores'=>$stores];
}

function legacy_match_employee(array $maps, string $name, string $userId = ''): array
{
    $byUser = $userId !== '' ? ($maps['employees']['by_user'][$userId] ?? []) : [];
    $byName = $name !== '' ? ($maps['employees']['by_name'][legacy_norm($name)] ?? []) : [];
    if (count($byUser) === 1 && count($byName) === 1 && (int)$byUser[0]['id'] !== (int)$byName[0]['id']) return ['status'=>'conflict','row'=>null,'message'=>'User ID and employee name match different SQL employees.'];
    if (count($byUser) > 1 || count($byName) > 1) return ['status'=>'conflict','row'=>null,'message'=>'Legacy employee identity is ambiguous in SQL.'];
    if (count($byUser) === 1) return ['status'=>'matched','row'=>$byUser[0],'message'=>''];
    if (count($byName) === 1) return ['status'=>'matched','row'=>$byName[0],'message'=>''];
    return ['status'=>'missing','row'=>null,'message'=>'Employee does not exist in SQL.'];
}

function legacy_match_store(array $maps, string $nameOrCode): array
{
    $key=legacy_norm($nameOrCode);
    if($key==='')return ['status'=>'missing','row'=>null,'message'=>'Store is missing.'];
    $byName=$maps['stores']['by_name'][$key]??[];$byCode=$maps['stores']['by_code'][$key]??[];
    $merged=[];foreach(array_merge($byName,$byCode) as $row)$merged[(int)$row['id']]=$row;
    if(count($merged)===1)return ['status'=>'matched','row'=>array_values($merged)[0],'message'=>''];
    if(count($merged)>1)return ['status'=>'conflict','row'=>null,'message'=>'Store identity is ambiguous in SQL.'];
    return ['status'=>'missing','row'=>null,'message'=>'Store does not exist in SQL.'];
}

function legacy_source_key(string $type, string $base, array &$seen): string
{
    $fingerprint = hash('sha256', $type . '|' . $base);
    $ordinal = ($seen[$type][$fingerprint] ?? 0) + 1;
    $seen[$type][$fingerprint] = $ordinal;
    return $type . ':' . substr(hash('sha256', $base . '|#' . $ordinal), 0, 56);
}

function legacy_stage_row(PDO $pdo, int $batchId, int $clientId, string $sourceType, string $sheetName, array $row, string $sourceKey, string $status, ?string $code, ?string $message, ?int $employeeId=null, ?int $storeId=null): int
{
    $stmt=$pdo->prepare(
        'INSERT INTO legacy_migration_stage_rows (batch_id,client_id,source_type,sheet_name,source_row_no,source_key,content_hash,payload_redacted,validation_status,resolution_code,resolution_message,matched_employee_id,matched_store_id) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$batchId,$clientId,$sourceType,$sheetName,(int)($row['_source_row']??0),$sourceKey,legacy_hash_row($row),json_encode(legacy_redacted_row($row),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$status,$code,$message,$employeeId,$storeId]);
    return (int)$pdo->lastInsertId();
}

function legacy_add_conflict(PDO $pdo, int $batchId, int $clientId, int $stageId, string $sourceType, string $sourceKey, string $code, string $message, ?string $targetTable=null, ?string $targetKey=null): void
{
    $stmt=$pdo->prepare('INSERT INTO legacy_migration_conflicts (batch_id,stage_row_id,client_id,source_type,source_key,conflict_code,message,existing_target_table,existing_target_key) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$batchId,$stageId,$clientId,$sourceType,$sourceKey,$code,substr($message,0,1000),$targetTable,$targetKey]);
}

function legacy_lineage(PDO $pdo, int $clientId, string $sourceType, string $sourceKey): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM legacy_migration_records WHERE client_id=? AND source_type=? AND source_key=? LIMIT 1');
    $stmt->execute([$clientId,$sourceType,$sourceKey]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
}

function legacy_save_lineage(PDO $pdo,int $clientId,string $sourceType,string $sourceKey,string $sourceHash,string $targetTable,string $targetKey,?string $targetHash,int $batchId,string $status='active'): void
{
    $stmt=$pdo->prepare(
        'INSERT INTO legacy_migration_records (client_id,source_type,source_key,source_hash,target_table,target_key,target_hash,first_batch_id,last_batch_id,status) VALUES (?,?,?,?,?,?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),target_table=VALUES(target_table),target_key=VALUES(target_key),target_hash=VALUES(target_hash),last_batch_id=VALUES(last_batch_id),status=VALUES(status),last_seen_at=CURRENT_TIMESTAMP'
    );
    $stmt->execute([$clientId,$sourceType,$sourceKey,$sourceHash,$targetTable,$targetKey,$targetHash,$batchId,$batchId,$status]);
}

function legacy_target_hash(array $values): string
{
    ksort($values,SORT_STRING);
    return hash('sha256',json_encode($values,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function legacy_employee_target_hash(array $row): string
{
    return legacy_target_hash(['full_name'=>(string)$row['full_name'],'user_id'=>(string)$row['user_id'],'status'=>(string)$row['status'],'employee_type'=>(string)$row['employee_type'],'store_id'=>(int)$row['store_id']]);
}

function legacy_apply_employee_setup(PDO $pdo,array $actor,int $clientId,int $batchId,array $item,array &$counts): void
{
    $row=$item['row'];$key=$item['source_key'];$hash=legacy_hash_row($row);
    $name=legacy_field($row,['NAME','FULL_NAME','EMPLOYEE','EMPLOYEE_NAME']);
    $userId=preg_replace('/\D+/','',legacy_field($row,['USER_ID','USERID','ID']));
    if($name===''||$userId==='')return;
    $maps=legacy_identity_maps($pdo,$clientId);$match=legacy_match_employee($maps,$name,$userId);
    if($match['status']==='conflict')return;
    if($match['status']==='matched'){
        $employee=$match['row'];legacy_save_lineage($pdo,$clientId,'employee_setup',$key,$hash,'employees',(string)$employee['id'],legacy_employee_target_hash($employee),$batchId);
        $counts['unchanged']++;return;
    }

    $storeText=legacy_field($row,['LOG_STORE','STORE','STORE_NAME']);$storeMatch=$storeText!==''?legacy_match_store($maps,$storeText):['status'=>'missing','row'=>null];
    $store=$storeMatch['status']==='matched'?$storeMatch['row']:null;
    if(!$store){foreach($maps['stores']['by_id'] as $candidate){if(strtolower((string)$candidate['status'])==='active'){$store=$candidate;break;}}}
    if(!$store)return;

    $base=strtoupper(legacy_field($row,['TYPE','ROLE','EMPLOYEE_TYPE']));if(!in_array($base,['USER','ADMIN','SUPER'],true))$base='USER';
    $roleStmt=$pdo->prepare("SELECT id,role_label FROM client_roles WHERE client_id=? AND role_key=? AND status='active' LIMIT 1");$roleStmt->execute([$clientId,$base]);$roleRow=$roleStmt->fetch(PDO::FETCH_ASSOC);
    $password=legacy_field($row,['PASSWORD','PIN','PASS']);
    $passwordValid=preg_match('/^\d{4,20}$/',$password)===1;
    $hashPassword=password_hash($passwordValid?$password:bin2hex(random_bytes(16)),PASSWORD_DEFAULT);
    $sourceStatus=strtolower(legacy_field($row,['STATUS','ACTIVE']));
    $status=$passwordValid&&in_array($sourceStatus,['active','inactive'],true)?$sourceStatus:($passwordValid?'active':'inactive');

    $stmt=$pdo->prepare('INSERT INTO employees (client_id,store_id,full_name,user_id,login_password,employee_type,pin_code,role_name,client_role_id,hourly_rate,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$clientId,(int)$store['id'],$name,$userId,$hashPassword,$base,$hashPassword,(string)($roleRow['role_label']??ucfirst(strtolower($base))),isset($roleRow['id'])?(int)$roleRow['id']:null,'0.00',$status]);
    $employeeId=(int)$pdo->lastInsertId();
    $employee=['full_name'=>$name,'user_id'=>$userId,'status'=>$status,'employee_type'=>$base,'store_id'=>(int)$store['id']];
    legacy_save_lineage($pdo,$clientId,'employee_setup',$key,$hash,'employees',(string)$employeeId,legacy_employee_target_hash($employee),$batchId);
    $counts['inserted']++;
}

function legacy_apply_payrate(PDO $pdo,int $clientId,int $batchId,array $item,array &$counts): void
{
    $row=$item['row'];$key=$item['source_key'];$name=legacy_field($row,['NAME','EMPLOYEE','EMPLOYEE_NAME','USER_NAME']);$rate=legacy_money(legacy_field($row,['PAY_RATE','PAYRATE','HOURLY_RATE','RATE']));
    if($name===''||$rate===null)return;$maps=legacy_identity_maps($pdo,$clientId);$match=legacy_match_employee($maps,$name);if($match['status']!=='matched')return;$employee=$match['row'];
    $date=legacy_date(legacy_field($row,['EFFECTIVE_FROM','EFFECTIVE_DATE','DATE']))??'1970-01-01';
    $existing=$pdo->prepare('SELECT id,hourly_rate FROM employee_hourly_rate_history WHERE client_id=? AND employee_id=? AND effective_from=? LIMIT 1');$existing->execute([$clientId,(int)$employee['id'],$date]);$target=$existing->fetch(PDO::FETCH_ASSOC);
    $sourceHash=legacy_hash_row($row);$targetKey=(int)$employee['id'].':'.$date;
    $lineage=legacy_lineage($pdo,$clientId,'payrate',$key);
    if(is_array($target)&&!$lineage&&abs((float)$target['hourly_rate']-$rate)>0.0001){$counts['conflict']++;return;}
    if(is_array($target)){
        if($lineage&&$lineage['source_hash']!==$sourceHash){$pdo->prepare('UPDATE employee_hourly_rate_history SET hourly_rate=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([number_format($rate,2,'.',''),(int)$target['id']]);$counts['updated']++;}
        else $counts['unchanged']++;
        $id=(int)$target['id'];
    }else{
        $stmt=$pdo->prepare('INSERT INTO employee_hourly_rate_history (client_id,employee_id,hourly_rate,effective_from,changed_by_employee_id) VALUES (?,?,?,?,NULL)');$stmt->execute([$clientId,(int)$employee['id'],number_format($rate,2,'.',''),$date]);$id=(int)$pdo->lastInsertId();$counts['inserted']++;
    }
    legacy_save_lineage($pdo,$clientId,'payrate',$key,$sourceHash,'employee_hourly_rate_history',(string)$id,legacy_target_hash(['employee_id'=>(int)$employee['id'],'date'=>$date,'rate'=>number_format($rate,2,'.','')]),$batchId);
    $current=$pdo->prepare('SELECT hourly_rate FROM employee_hourly_rate_history WHERE client_id=? AND employee_id=? AND effective_from<=CURDATE() ORDER BY effective_from DESC,id DESC LIMIT 1');$current->execute([$clientId,(int)$employee['id']]);$currentRate=$current->fetchColumn();if($currentRate!==false)$pdo->prepare('UPDATE employees SET hourly_rate=? WHERE id=? AND client_id=?')->execute([$currentRate,(int)$employee['id'],$clientId]);
}

function legacy_apply_start_time(PDO $pdo,int $clientId,int $batchId,array $item,array &$counts): void
{
    $row=$item['row'];$key=$item['source_key'];$storeText=legacy_field($row,['STORE','STORE_NAME','SHOP']);$time=legacy_time(legacy_field($row,['SHIFT_START_TIME','START_TIME','TIME']));if($storeText===''||$time===null)return;
    $maps=legacy_identity_maps($pdo,$clientId);$match=legacy_match_store($maps,$storeText);if($match['status']!=='matched')return;$store=$match['row'];$sourceHash=legacy_hash_row($row);
    $stmt=$pdo->prepare('SELECT id,shift_start_time FROM store_shift_start_times WHERE client_id=? AND store_id=? LIMIT 1');$stmt->execute([$clientId,(int)$store['id']]);$target=$stmt->fetch(PDO::FETCH_ASSOC);$lineage=legacy_lineage($pdo,$clientId,'start_time',$key);
    if(is_array($target)&&!$lineage&&substr((string)$target['shift_start_time'],0,8)!==$time){$counts['conflict']++;return;}
    if(is_array($target)){
        if($lineage&&$lineage['source_hash']!==$sourceHash){$pdo->prepare('UPDATE store_shift_start_times SET store_name=?,shift_start_time=? WHERE id=?')->execute([(string)$store['store_name'],$time,(int)$target['id']]);$counts['updated']++;}else$counts['unchanged']++;$id=(int)$target['id'];
    }else{$pdo->prepare('INSERT INTO store_shift_start_times (client_id,store_id,store_name,shift_start_time) VALUES (?,?,?,?)')->execute([$clientId,(int)$store['id'],(string)$store['store_name'],$time]);$id=(int)$pdo->lastInsertId();$counts['inserted']++;}
    legacy_save_lineage($pdo,$clientId,'start_time',$key,$sourceHash,'store_shift_start_times',(string)$id,legacy_target_hash(['store_id'=>(int)$store['id'],'time'=>$time]),$batchId);
}

function legacy_log_target_hash(array $row): string
{
    return legacy_target_hash(['employee_id'=>(int)$row['employee_id'],'store_id'=>(int)$row['store_id'],'user_name'=>(string)$row['user_name'],'store_name'=>(string)$row['store_name'],'log_type'=>(string)$row['log_type'],'log_date'=>(string)$row['log_date'],'log_time'=>substr((string)$row['log_time'],0,8),'log_datetime'=>(string)$row['log_datetime']]);
}

function legacy_apply_attendance_log(PDO $pdo,int $clientId,int $batchId,array $item,array &$counts): void
{
    $row=$item['row'];$key=$item['source_key'];$name=legacy_field($row,['USER_NAME','USERNAME','NAME','EMPLOYEE','EMPLOYEE_NAME']);$storeText=legacy_field($row,['STORE_NAME','STORE','SHOP']);$type=strtoupper(legacy_field($row,['LOG_TYPE','TYPE','ACTION']));
    $date=legacy_date(legacy_field($row,['DATE','LOG_DATE']));$time=legacy_time(legacy_field($row,['TIME','LOG_TIME']));if(!$date||!$time||!in_array($type,['IN','OUT'],true))return;
    $maps=legacy_identity_maps($pdo,$clientId);$employeeMatch=legacy_match_employee($maps,$name);$storeMatch=legacy_match_store($maps,$storeText);if($employeeMatch['status']!=='matched'||$storeMatch['status']!=='matched')return;$employee=$employeeMatch['row'];$store=$storeMatch['row'];
    $values=['employee_id'=>(int)$employee['id'],'store_id'=>(int)$store['id'],'user_name'=>(string)$employee['full_name'],'store_name'=>(string)$store['store_name'],'log_type'=>$type,'log_date'=>$date,'log_time'=>$time,'log_datetime'=>$date.' '.$time];
    $sourceHash=legacy_hash_row($row);$lineage=legacy_lineage($pdo,$clientId,'attendance_log',$key);$target=null;
    if($lineage){$stmt=$pdo->prepare('SELECT id,employee_id,store_id,user_name,store_name,log_type,log_date,log_time,log_datetime FROM employee_logs WHERE client_id=? AND id=? LIMIT 1');$stmt->execute([$clientId,(int)$lineage['target_key']]);$target=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($target)){$counts['conflict']++;return;}if((string)$lineage['target_hash']!==legacy_log_target_hash($target)){$counts['conflict']++;return;}if((string)$lineage['source_hash']===$sourceHash){$counts['unchanged']++;legacy_save_lineage($pdo,$clientId,'attendance_log',$key,$sourceHash,'employee_logs',(string)$target['id'],legacy_log_target_hash($target),$batchId);return;}$pdo->prepare('UPDATE employee_logs SET employee_id=?,store_id=?,user_name=?,store_name=?,log_type=?,log_date=?,log_time=?,log_datetime=? WHERE client_id=? AND id=?')->execute([$values['employee_id'],$values['store_id'],$values['user_name'],$values['store_name'],$values['log_type'],$values['log_date'],$values['log_time'],$values['log_datetime'],$clientId,(int)$target['id']]);$id=(int)$target['id'];$counts['updated']++;}
    else{$stmt=$pdo->prepare('SELECT id,employee_id,store_id,user_name,store_name,log_type,log_date,log_time,log_datetime FROM employee_logs WHERE client_id=? AND employee_id=? AND store_id=? AND log_type=? AND log_datetime=? ORDER BY id LIMIT 1');$stmt->execute([$clientId,$values['employee_id'],$values['store_id'],$type,$values['log_datetime']]);$target=$stmt->fetch(PDO::FETCH_ASSOC);if(is_array($target)){$id=(int)$target['id'];$counts['unchanged']++;}else{$stmt=$pdo->prepare('INSERT INTO employee_logs (client_id,employee_id,store_id,user_name,store_name,log_type,log_date,log_time,log_datetime) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$clientId,$values['employee_id'],$values['store_id'],$values['user_name'],$values['store_name'],$values['log_type'],$values['log_date'],$values['log_time'],$values['log_datetime']]);$id=(int)$pdo->lastInsertId();$counts['inserted']++;}}
    $stmt=$pdo->prepare('SELECT id,employee_id,store_id,user_name,store_name,log_type,log_date,log_time,log_datetime FROM employee_logs WHERE client_id=? AND id=?');$stmt->execute([$clientId,$id]);$saved=$stmt->fetch(PDO::FETCH_ASSOC);legacy_save_lineage($pdo,$clientId,'attendance_log',$key,$sourceHash,'employee_logs',(string)$id,is_array($saved)?legacy_log_target_hash($saved):null,$batchId);
}

function legacy_rebuild_imported_shifts(PDO $pdo,int $clientId,int $batchId,array &$counts): void
{
    $stmt=$pdo->prepare("SELECT r.source_key,l.id,l.employee_id,l.store_id,l.log_type,l.log_datetime,s.timezone FROM legacy_migration_records r INNER JOIN employee_logs l ON l.id=CAST(r.target_key AS UNSIGNED) AND l.client_id=r.client_id INNER JOIN stores s ON s.id=l.store_id AND s.client_id=l.client_id WHERE r.client_id=? AND r.source_type='attendance_log' AND r.status='active' ORDER BY l.employee_id,l.log_datetime,l.id");$stmt->execute([$clientId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $pending=[];
    foreach($rows as $row){$eid=(int)$row['employee_id'];$type=(string)$row['log_type'];if($type==='IN'){$pending[$eid]=$row;continue;}if($type!=='OUT'||!isset($pending[$eid]))continue;$in=$pending[$eid];unset($pending[$eid]);$tzName=(string)($in['timezone']?:'Australia/Sydney');try{$tz=new DateTimeZone($tzName);}catch(Throwable){$tz=new DateTimeZone('Australia/Sydney');}$inLocal=new DateTimeImmutable((string)$in['log_datetime'],$tz);$outLocal=new DateTimeImmutable((string)$row['log_datetime'],$tz);if($outLocal<=$inLocal)continue;$inUtc=$inLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$outUtc=$outLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$sourceKey='attendance_shift:'.substr(hash('sha256',(string)$in['source_key'].'|'.(string)$row['source_key']),0,56);$public=legacy_uuid_from_key($clientId.'|'.$sourceKey);$sourceHash=legacy_target_hash(['employee_id'=>$eid,'store_id'=>(int)$in['store_id'],'in'=>$inUtc,'out'=>$outUtc]);
        $existing=$pdo->prepare('SELECT id,employee_id,store_id,device_id,clock_in_at,clock_out_at,status,close_reason FROM attendance_shifts WHERE public_id=? AND client_id=? LIMIT 1');$existing->execute([$public,$clientId]);$shift=$existing->fetch(PDO::FETCH_ASSOC);if(is_array($shift)){$safe=$shift['device_id']===null&&(string)$shift['status']==='closed'&&(string)$shift['close_reason']==='none';$current=legacy_target_hash(['employee_id'=>(int)$shift['employee_id'],'store_id'=>(int)$shift['store_id'],'in'=>(string)$shift['clock_in_at'],'out'=>(string)$shift['clock_out_at']]);$lineage=legacy_lineage($pdo,$clientId,'attendance_shift',$sourceKey);if(!$safe||($lineage&&$lineage['target_hash']!==$current)){$counts['conflict']++;continue;}if($current!==$sourceHash){$pdo->prepare("UPDATE attendance_shifts SET store_id=?,employee_id=?,clock_in_at=?,clock_out_at=?,status='closed',close_reason='none' WHERE id=?")->execute([(int)$in['store_id'],$eid,$inUtc,$outUtc,(int)$shift['id']]);$counts['updated']++;}else$counts['unchanged']++;$id=(int)$shift['id'];}
        else{$pdo->prepare("INSERT INTO attendance_shifts (public_id,client_id,store_id,employee_id,device_id,clock_in_at,clock_out_at,status,close_reason) VALUES (?,?,?,?,NULL,?,?,'closed','none')")->execute([$public,$clientId,(int)$in['store_id'],$eid,$inUtc,$outUtc]);$id=(int)$pdo->lastInsertId();$counts['inserted']++;}
        legacy_save_lineage($pdo,$clientId,'attendance_shift',$sourceKey,$sourceHash,'attendance_shifts',(string)$id,$sourceHash,$batchId);
    }
}

function legacy_financial_type(array $row,string $sheetName): string
{
    $raw=strtolower(trim(legacy_field($row,['SUBMISSION_TYPE','TRANSACTION_TYPE','TYPE','ACTION'])));$tab=strtolower($sheetName);$candidate=$raw!==''?$raw:$tab;$candidate=str_replace([' ','-'],'_',$candidate);
    if(str_contains($candidate,'open'))return 'open_day';if(str_contains($candidate,'cash_in')||str_contains($candidate,'cashin'))return 'cash_in';if(str_contains($candidate,'cash_out')||str_contains($candidate,'cashout'))return 'cash_out';if(str_contains($candidate,'z_report')||str_contains($candidate,'zreport')||str_contains($candidate,'closing')||str_contains($candidate,'close_day'))return 'z_report';return '';
}

function legacy_financial_normalize(array $row,string $sheetName,array $maps): array
{
    $type=legacy_financial_type($row,$sheetName);$storeText=legacy_field($row,['STORE_NAME','STORE','SHOP','BRANCH']);$date=legacy_date(legacy_field($row,['BUSINESS_DATE','DATE','DAY']));$storeMatch=legacy_match_store($maps,$storeText);
    if($type===''||!$date||$storeMatch['status']!=='matched')return ['valid'=>false,'message'=>$type===''?'Financial row type could not be identified.':(!$date?'Financial business date is invalid.':'Financial store could not be matched.'),'type'=>$type,'store'=>null,'date'=>$date];
    $payload=[];
    if($type==='open_day'){$register=legacy_money(legacy_field($row,['REGISTER_OPENING','REGISTER OPEN','REGISTEROPENING','REGISTER']));$petty=legacy_money(legacy_field($row,['PETTY_CASH_OPENING','PETTY OPENING','PETTYCASHOPENING','PETTY']));if($register===null||$petty===null)return ['valid'=>false,'message'=>'Opening row needs Register opening and Petty Cash opening amounts.','type'=>$type,'store'=>$storeMatch['row'],'date'=>$date];$payload=['register_opening'=>$register,'petty_cash_opening'=>$petty];}
    elseif($type==='cash_in'||$type==='cash_out'){$account=trim(legacy_field($row,['ACCOUNT','CASH_ACCOUNT']));if(strcasecmp($account,'petty cash')===0)$account='Petty Cash';elseif(strcasecmp($account,'register')===0)$account='Register';$amount=legacy_money(legacy_field($row,['AMOUNT','VALUE','TOTAL']));$head=trim(legacy_field($row,['HEAD','REASON','CATEGORY','DESCRIPTION','NOTE']));if(!in_array($account,['Register','Petty Cash'],true)||$amount===null||$amount<=0||strlen($head)<2)return ['valid'=>false,'message'=>'Cash row needs Account, Reason/Category and a positive Amount.','type'=>$type,'store'=>$storeMatch['row'],'date'=>$date];$payload=['transactions'=>[['account'=>$account,'head'=>substr($head,0,120),'amount'=>$amount]]];}
    else{$register=legacy_money(legacy_field($row,['REGISTER_TOTAL','REGISTER CLOSING','REGISTERTOTAL','COUNTED_REGISTER_TOTAL']));$petty=legacy_money(legacy_field($row,['PETTY_CASH_ADDIN','PETTY ADDIN','PETTYCASHADDIN','TRANSFER_TO_PETTY_CASH']))??0.0;if($register===null)return ['valid'=>false,'message'=>'Closing/Z row needs the counted Register total.','type'=>$type,'store'=>$storeMatch['row'],'date'=>$date];$payload=['register_total'=>$register,'petty_cash_addin'=>$petty];}
    return ['valid'=>true,'message'=>'','type'=>$type,'store'=>$storeMatch['row'],'date'=>$date,'payload'=>$payload,'employee_name'=>legacy_field($row,['EMPLOYEE_NAME','EMPLOYEE','USER_NAME','NAME'])];
}

function legacy_financial_actor(PDO $pdo,int $clientId,array $normalized): ?array
{
    $maps=legacy_identity_maps($pdo,$clientId);$name=trim((string)($normalized['employee_name']??''));if($name!==''){$match=legacy_match_employee($maps,$name);if($match['status']==='matched')$employee=$match['row']??null;else$employee=null;}else$employee=null;
    if(!$employee){$stmt=$pdo->prepare("SELECT id,full_name,user_id,client_id,employee_type,role_name FROM employees WHERE client_id=? AND status='active' ORDER BY CASE UPPER(COALESCE(employee_type,'')) WHEN 'DEV' THEN 1 WHEN 'SUPER' THEN 2 WHEN 'ADMIN' THEN 3 ELSE 4 END,id LIMIT 1");$stmt->execute([$clientId]);$employee=$stmt->fetch(PDO::FETCH_ASSOC);}
    if(!is_array($employee))return null;$employee['client_id']=$clientId;$employee['employee_type']='SUPER';$employee['role_name']='SUPER';$employee['_suppress_sheet_outbox']=true;return $employee;
}

function legacy_apply_financial(PDO $pdo,int $clientId,int $batchId,array $item,array &$counts): void
{
    $row=$item['row'];$sheet=$item['sheet'];$key=$item['source_key'];$maps=legacy_identity_maps($pdo,$clientId);$normalized=legacy_financial_normalize($row,$sheet,$maps);if(!$normalized['valid'])return;$sourceHash=legacy_hash_row($row);$public=legacy_uuid_from_key($clientId.'|financial|'.$key);$lineage=legacy_lineage($pdo,$clientId,'financial',$key);
    $existing=$pdo->prepare('SELECT id,payload_hash,status FROM financial_submissions WHERE public_id=? AND client_id=? LIMIT 1');$existing->execute([$public,$clientId]);$target=$existing->fetch(PDO::FETCH_ASSOC);if(is_array($target)&&$lineage&&(string)$lineage['source_hash']!==$sourceHash){$counts['conflict']++;return;}if(is_array($target)){legacy_save_lineage($pdo,$clientId,'financial',$key,$sourceHash,'financial_submissions',$public,(string)$target['payload_hash'],$batchId);$counts['unchanged']++;return;}
    $actor=legacy_financial_actor($pdo,$clientId,$normalized);if(!$actor){$counts['conflict']++;return;}
    $input=['submission_id'=>$public,'submission_type'=>$normalized['type'],'store_id'=>(int)$normalized['store']['id'],'business_date'=>$normalized['date'],'payload'=>$normalized['payload']];
    if($normalized['type']==='open_day'){
        $day=$pdo->prepare('SELECT account,opening_amount FROM financial_day_accounts WHERE client_id=? AND store_id=? AND business_date=? ORDER BY account');$day->execute([$clientId,(int)$normalized['store']['id'],$normalized['date']]);$rows=$day->fetchAll(PDO::FETCH_ASSOC);if($rows){$opening=[];foreach($rows as $d)$opening[(string)$d['account']]=(float)$d['opening_amount'];if(isset($opening['Register'],$opening['Petty Cash'])&&abs($opening['Register']-(float)$normalized['payload']['register_opening'])<0.001&&abs($opening['Petty Cash']-(float)$normalized['payload']['petty_cash_opening'])<0.001){legacy_save_lineage($pdo,$clientId,'financial',$key,$sourceHash,'financial_day_accounts',(int)$normalized['store']['id'].':'.$normalized['date'],legacy_target_hash($opening),$batchId);$counts['unchanged']++;return;}$counts['conflict']++;return;}
    }
    try{$result=merd_submit_financial($pdo,$actor,$input);$lookup=$pdo->prepare('SELECT id,payload_hash FROM financial_submissions WHERE public_id=? AND client_id=?');$lookup->execute([$public,$clientId]);$saved=$lookup->fetch(PDO::FETCH_ASSOC);legacy_save_lineage($pdo,$clientId,'financial',$key,$sourceHash,'financial_submissions',$public,is_array($saved)?(string)$saved['payload_hash']:null,$batchId);$counts[!empty($result['duplicate'])?'unchanged':'inserted']++;}
    catch(MerdWorkforceException $e){$counts['conflict']++;}
}

function legacy_fetch_sources(array $sources): array
{
    $out=['attendance'=>[],'financial'=>[],'snapshot_parts'=>[]];
    if(isset($sources['attendance'])&&$sources['attendance']['status']==='active'){
        foreach($sources['attendance']['sheet_names'] as $key=>$tab){$csv=legacy_google_fetch_tab((string)$sources['attendance']['spreadsheet_id'],(string)$tab);$parsed=legacy_parse_csv_rows($csv,'attendance');$out['attendance'][$key]=['sheet'=>(string)$tab,'rows'=>$parsed['rows']];$out['snapshot_parts'][]=hash('sha256',$key.'|'.$tab.'|'.$csv);}
    }
    if(isset($sources['financial'])&&$sources['financial']['status']==='active'){
        foreach($sources['financial']['sheet_names'] as $tab){$csv=legacy_google_fetch_tab((string)$sources['financial']['spreadsheet_id'],(string)$tab);$parsed=legacy_parse_csv_rows($csv,'financial');$out['financial'][]=['sheet'=>(string)$tab,'rows'=>$parsed['rows']];$out['snapshot_parts'][]=hash('sha256','financial|'.$tab.'|'.$csv);}
    }
    sort($out['snapshot_parts'],SORT_STRING);$out['snapshot_hash']=hash('sha256',implode('|',$out['snapshot_parts']));return $out;
}

function legacy_validate_and_stage(PDO $pdo,int $batchId,int $clientId,array $fetched): array
{
    $maps=legacy_identity_maps($pdo,$clientId);$seen=[];$items=[];$counts=['attendance_rows'=>0,'financial_rows'=>0,'warning'=>0,'conflict'=>0,'rejected'=>0];$prospective=[];
    foreach(($fetched['attendance']['employee_setup']['rows']??[]) as $row){$name=legacy_field($row,['NAME','FULL_NAME','EMPLOYEE']);$userId=preg_replace('/\D+/','',legacy_field($row,['USER_ID','USERID','ID']));$base=legacy_norm($userId!==''?$userId:$name);$key=legacy_source_key('employee_setup',$base,$seen);$match=legacy_match_employee($maps,$name,$userId);$status='valid';$code=null;$message=null;if($name===''||$userId===''){$status='rejected';$code='employee_identity_missing';$message='Employee Setup row needs Name and numeric User ID.';$counts['rejected']++;}elseif($match['status']==='conflict'){$status='conflict';$code='employee_identity_conflict';$message=$match['message'];$counts['conflict']++;}elseif($match['status']==='missing'){$status='warning';$code='employee_will_be_created';$message='Employee does not exist in SQL and will be created during Sync. Existing SQL passwords are never overwritten.';$counts['warning']++;$prospective[legacy_norm($name)]=true;$prospective['uid:'.$userId]=true;}$stage=legacy_stage_row($pdo,$batchId,$clientId,'employee_setup',(string)($fetched['attendance']['employee_setup']['sheet']??'Employee Setup'),$row,$key,$status,$code,$message,$match['row']['id']??null,null);if($status==='conflict')legacy_add_conflict($pdo,$batchId,$clientId,$stage,'employee_setup',$key,$code??'conflict',$message??'Conflict');$items['employee_setup'][]=['row'=>$row,'source_key'=>$key,'stage_id'=>$stage,'status'=>$status];}
    foreach(['payrate'=>'payrate','start_time'=>'start_time','timesheet'=>'attendance_log'] as $sheetKey=>$sourceType){$sheet=$fetched['attendance'][$sheetKey]??null;if(!$sheet)continue;foreach($sheet['rows'] as $row){$counts['attendance_rows']++;$status='valid';$code=null;$message=null;$employeeId=null;$storeId=null;if($sourceType==='payrate'){$name=legacy_field($row,['NAME','EMPLOYEE','EMPLOYEE_NAME','USER_NAME']);$rate=legacy_money(legacy_field($row,['PAY_RATE','PAYRATE','HOURLY_RATE','RATE']));$match=legacy_match_employee($maps,$name);$prospectiveMatch=isset($prospective[legacy_norm($name)]);if($name===''||$rate===null){$status='rejected';$code='invalid_payrate';$message='PayRate row needs employee name and numeric rate.';}elseif($match['status']==='conflict'){$status='conflict';$code='employee_identity_conflict';$message=$match['message'];}elseif($match['status']==='missing'&&!$prospectiveMatch){$status='rejected';$code='employee_not_found';$message='PayRate employee is not in SQL or Employee Setup.';}else{$employeeId=$match['row']['id']??null;}$base=legacy_norm($name).'|'.(legacy_date(legacy_field($row,['EFFECTIVE_FROM','EFFECTIVE_DATE','DATE']))??'1970-01-01');}
        elseif($sourceType==='start_time'){$storeText=legacy_field($row,['STORE','STORE_NAME','SHOP']);$time=legacy_time(legacy_field($row,['SHIFT_START_TIME','START_TIME','TIME']));$match=legacy_match_store($maps,$storeText);if($storeText===''||!$time){$status='rejected';$code='invalid_start_time';$message='Start Time row needs Store and valid time.';}elseif($match['status']!=='matched'){$status='conflict';$code='store_not_found';$message=$match['message'];}else{$storeId=(int)$match['row']['id'];}$base=legacy_norm($storeText);}
        else{$name=legacy_field($row,['USER_NAME','USERNAME','NAME','EMPLOYEE','EMPLOYEE_NAME']);$storeText=legacy_field($row,['STORE_NAME','STORE','SHOP']);$type=strtoupper(legacy_field($row,['LOG_TYPE','TYPE','ACTION']));$date=legacy_date(legacy_field($row,['DATE','LOG_DATE']));$time=legacy_time(legacy_field($row,['TIME','LOG_TIME']));$em=legacy_match_employee($maps,$name);$st=legacy_match_store($maps,$storeText);$prospectiveMatch=isset($prospective[legacy_norm($name)]);if(!$date||!$time||!in_array($type,['IN','OUT'],true)){$status='rejected';$code='invalid_attendance_row';$message='Attendance row needs valid employee, store, IN/OUT, date and time.';}elseif($em['status']==='conflict'){$status='conflict';$code='employee_identity_conflict';$message=$em['message'];}elseif($em['status']==='missing'&&!$prospectiveMatch){$status='rejected';$code='employee_not_found';$message='Attendance employee is not in SQL or Employee Setup.';}elseif($st['status']!=='matched'){$status='conflict';$code='store_not_found';$message=$st['message'];}else{$employeeId=$em['row']['id']??null;$storeId=(int)$st['row']['id'];if($em['status']==='missing'){$status='warning';$code='employee_will_be_created';$message='Employee will be created from Employee Setup before attendance is applied.';}}$base=legacy_norm($name).'|'.legacy_norm($storeText).'|'.$type.'|'.$date.'|'.$time;}
        if($status==='rejected')$counts['rejected']++;elseif($status==='conflict')$counts['conflict']++;elseif($status==='warning')$counts['warning']++;$key=legacy_source_key($sourceType,$base,$seen);$stage=legacy_stage_row($pdo,$batchId,$clientId,$sourceType,(string)$sheet['sheet'],$row,$key,$status,$code,$message,$employeeId,$storeId);if($status==='conflict')legacy_add_conflict($pdo,$batchId,$clientId,$stage,$sourceType,$key,$code??'conflict',$message??'Conflict');$items[$sourceType][]=['row'=>$row,'source_key'=>$key,'stage_id'=>$stage,'status'=>$status,'sheet'=>(string)$sheet['sheet']];}}
    foreach($fetched['financial'] as $sheet){foreach($sheet['rows'] as $row){$counts['financial_rows']++;$normalized=legacy_financial_normalize($row,(string)$sheet['sheet'],$maps);$status=$normalized['valid']?'valid':'rejected';$code=$normalized['valid']?null:'invalid_financial_row';$message=$normalized['valid']?null:$normalized['message'];if(!$normalized['valid'])$counts['rejected']++;$base=legacy_field($row,['SUBMISSION_ID','ID','REFERENCE']);if($base==='')$base=($normalized['type']??'unknown').'|'.legacy_norm((string)legacy_field($row,['STORE_NAME','STORE','SHOP'])).'|'.($normalized['date']??'').'|'.json_encode(legacy_row_map($row));$key=legacy_source_key('financial',$base,$seen);$stage=legacy_stage_row($pdo,$batchId,$clientId,'financial',(string)$sheet['sheet'],$row,$key,$status,$code,$message,null,$normalized['store']['id']??null);$items['financial'][]=['row'=>$row,'source_key'=>$key,'stage_id'=>$stage,'status'=>$status,'sheet'=>(string)$sheet['sheet'],'normalized'=>$normalized];}}
    return ['items'=>$items,'counts'=>$counts];
}

function legacy_apply_items(PDO $pdo,array $actor,int $clientId,int $batchId,array $items,array &$counts): void
{
    foreach($items['employee_setup']??[] as $item)if(in_array($item['status'],['valid','warning'],true))legacy_apply_employee_setup($pdo,$actor,$clientId,$batchId,$item,$counts);
    foreach($items['payrate']??[] as $item)if(in_array($item['status'],['valid','warning'],true))legacy_apply_payrate($pdo,$clientId,$batchId,$item,$counts);
    foreach($items['start_time']??[] as $item)if($item['status']==='valid')legacy_apply_start_time($pdo,$clientId,$batchId,$item,$counts);
    foreach($items['attendance_log']??[] as $item)if(in_array($item['status'],['valid','warning'],true))legacy_apply_attendance_log($pdo,$clientId,$batchId,$item,$counts);
    legacy_rebuild_imported_shifts($pdo,$clientId,$batchId,$counts);
    $financial=$items['financial']??[];usort($financial,static function(array $a,array $b):int{$an=$a['normalized']??[];$bn=$b['normalized']??[];$date=strcmp((string)($an['date']??''),(string)($bn['date']??''));if($date!==0)return $date;$priority=['open_day'=>0,'cash_in'=>1,'cash_out'=>1,'z_report'=>2];return ($priority[$an['type']??'']??9)<=>($priority[$bn['type']??'']??9);});
    foreach($financial as $item)if($item['status']==='valid')legacy_apply_financial($pdo,$clientId,$batchId,$item,$counts);
}

function legacy_batch_summary(PDO $pdo,int $batchId): array
{
    $stmt=$pdo->prepare('SELECT validation_status,COUNT(*) count FROM legacy_migration_stage_rows WHERE batch_id=? GROUP BY validation_status');$stmt->execute([$batchId]);$stage=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$stage[(string)$row['validation_status']]=(int)$row['count'];
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM legacy_migration_conflicts WHERE batch_id=? AND status='open'");$stmt->execute([$batchId]);return ['stage'=>$stage,'open_conflicts'=>(int)$stmt->fetchColumn()];
}

function legacy_run_batch(PDO $pdo,array $actor,int $clientId,string $mode): array
{
    if(!in_array($mode,['preview','sync','final'],true))throw new MerdWorkforceException('invalid_migration_mode','Invalid migration mode.');
    $state=legacy_migration_state($pdo,$clientId);if($mode!=='preview'&&((string)$state['attendance_authority']==='merdpos_sql'||(string)$state['financial_authority']==='merdpos_sql'))throw new MerdWorkforceException('migration_cutover_complete','This client is already SQL-authoritative. Google can be previewed but cannot overwrite MERDPOS after cutover.');
    $sources=legacy_source_state($pdo,$clientId);if(!isset($sources['attendance'])||$sources['attendance']['status']!=='active')throw new MerdWorkforceException('attendance_source_missing','Configure the attendance Google Sheet before migration.');
    if($mode==='final'&&(!isset($sources['financial'])||$sources['financial']['status']!=='active'))throw new MerdWorkforceException('financial_source_missing','Configure the financial Google Sheet before final cutover.');
    @set_time_limit(240);
    $public=merd_uuid_v4();$stmt=$pdo->prepare("INSERT INTO legacy_migration_batches (public_id,client_id,mode,status,started_by_employee_id) VALUES (?,?,?,'running',?)");$stmt->execute([$public,$clientId,$mode,(int)$actor['id']]);$batchId=(int)$pdo->lastInsertId();
    try{$fetched=legacy_fetch_sources($sources);$validated=legacy_validate_and_stage($pdo,$batchId,$clientId,$fetched);$c=$validated['counts'];$apply=['inserted'=>0,'updated'=>0,'unchanged'=>0,'conflict'=>0,'rejected'=>$c['rejected'],'warning'=>$c['warning']];if($mode!=='preview')legacy_apply_items($pdo,$actor,$clientId,$batchId,$validated['items'],$apply);$summary=legacy_batch_summary($pdo,$batchId);$conflicts=$c['conflict']+$apply['conflict']+$summary['open_conflicts'];$status=$mode==='preview'?'staged':(($conflicts>0||$c['rejected']>0)?'completed_with_conflicts':'completed');$update=$pdo->prepare('UPDATE legacy_migration_batches SET status=?,source_snapshot_hash=?,attendance_rows=?,financial_rows=?,inserted_rows=?,updated_rows=?,unchanged_rows=?,conflict_rows=?,rejected_rows=?,warning_rows=?,summary_json=?,finished_at=UTC_TIMESTAMP() WHERE id=?');$update->execute([$status,$fetched['snapshot_hash'],$c['attendance_rows'],$c['financial_rows'],$apply['inserted'],$apply['updated'],$apply['unchanged'],$conflicts,$c['rejected'],$c['warning'],json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$batchId]);if($mode==='preview')$pdo->prepare('UPDATE client_migration_state SET last_preview_batch_id=? WHERE client_id=?')->execute([$batchId,$clientId]);else$pdo->prepare('UPDATE client_migration_state SET last_sync_batch_id=? WHERE client_id=?')->execute([$batchId,$clientId]);if($mode==='final'){
            $open=(int)$pdo->query("SELECT COUNT(*) FROM legacy_migration_conflicts WHERE client_id=".(int)$clientId." AND status='open'")->fetchColumn();if($status!=='completed'||$open>0)throw new MerdWorkforceException('final_sync_not_clean','Final cutover requires zero rejected rows and zero open conflicts. Review the migration report first.');$pdo->prepare("UPDATE client_migration_state SET attendance_authority='merdpos_sql',financial_authority='merdpos_sql',attendance_cutover_at=UTC_TIMESTAMP(),financial_cutover_at=UTC_TIMESTAMP(),cutover_by_employee_id=? WHERE client_id=?")->execute([(int)$actor['id'],$clientId]);}
        return ['batch_id'=>$public,'mode'=>$mode,'status'=>$status,'attendance_rows'=>$c['attendance_rows'],'financial_rows'=>$c['financial_rows'],'inserted'=>$apply['inserted'],'updated'=>$apply['updated'],'unchanged'=>$apply['unchanged'],'conflicts'=>$conflicts,'rejected'=>$c['rejected'],'warnings'=>$c['warning'],'summary'=>$summary];}
    catch(Throwable $e){$pdo->prepare("UPDATE legacy_migration_batches SET status='failed',error_message=?,finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([substr($e->getMessage(),0,1000),$batchId]);throw $e;}
}

function legacy_audit(PDO $pdo,array $actor,int $clientId,string $action,array $details): void
{
    try{$stmt=$pdo->prepare('INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$clientId,(int)$actor['id'],$action,'legacy_migration',(string)$clientId,json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64)]);}catch(Throwable $e){error_log('MERDPOS legacy migration audit failed: '.get_class($e));}
}
