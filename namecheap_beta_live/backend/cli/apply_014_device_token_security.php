<?php
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "014 device token security failed: database connection unavailable.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function d014_fail(string $message): never {
    fwrite(STDERR, "014 device token security failed: {$message}\n");
    exit(1);
}

function d014_columns(PDO $pdo): array {
    $stmt=$pdo->query("SELECT column_name,data_type,column_type,character_maximum_length,is_nullable,collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='devices' ORDER BY ordinal_position");
    $result=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(string)$row['column_name']]=$row;
    return $result;
}

function d014_backup(PDO $pdo,array $columns): string {
    $dir='/home/dridsheikh/.merdpos-backups';
    if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) d014_fail('could not create private backup directory');
    $path=$dir.'/devices-014-'.gmdate('Ymd\THis\Z').'.json';
    $rows=$pdo->query('SELECT * FROM devices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $payload=['created_at_utc'=>gmdate(DATE_ATOM),'database'=>(string)$pdo->query('SELECT DATABASE()')->fetchColumn(),'columns'=>array_values($columns),'rows'=>$rows];
    $json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if(file_put_contents($path,$json,LOCK_EX)===false) d014_fail('could not write private devices backup');
    chmod($path,0600);
    return $path;
}

$columns=d014_columns($pdo);
$required=['id','client_id','store_id','device_uuid','activation_token','status','created_at'];
foreach($required as $name) if(!isset($columns[$name])) d014_fail("precondition failed: missing devices.{$name}");
foreach(['id','client_id','store_id'] as $name) if(!in_array((string)$columns[$name]['data_type'],['int','bigint'],true)) d014_fail("incompatible devices.{$name}");
if((string)$columns['device_uuid']['data_type']!=='varchar'||(int)$columns['device_uuid']['character_maximum_length']<150) d014_fail('incompatible devices.device_uuid');
if((string)$columns['activation_token']['data_type']!=='varchar'||(int)$columns['activation_token']['character_maximum_length']<150) d014_fail('incompatible devices.activation_token');
if(!in_array((string)$columns['status']['data_type'],['enum','varchar','char'],true)) d014_fail('incompatible devices.status');
if(!in_array((string)$columns['created_at']['data_type'],['datetime','timestamp'],true)) d014_fail('incompatible devices.created_at');

$targets=[
    'token_hash'=>"CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER activation_token",
    'previous_token_hash'=>"CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER token_hash",
    'token_expires_at'=>"DATETIME NULL AFTER previous_token_hash",
    'previous_token_valid_until'=>"DATETIME NULL AFTER token_expires_at",
    'token_rotated_at'=>"DATETIME NULL AFTER previous_token_valid_until",
    'revoked_at'=>"DATETIME NULL AFTER token_rotated_at",
    'activated_at'=>"DATETIME NULL AFTER revoked_at",
];
foreach(['token_hash','previous_token_hash'] as $name) {
    if(isset($columns[$name]) && ((string)$columns[$name]['data_type']!=='char'||(int)$columns[$name]['character_maximum_length']!==64)) d014_fail("incompatible devices.{$name}");
}
foreach(['token_expires_at','previous_token_valid_until','token_rotated_at','revoked_at','activated_at'] as $name) {
    if(isset($columns[$name]) && !in_array((string)$columns[$name]['data_type'],['datetime','timestamp'],true)) d014_fail("incompatible devices.{$name}");
}

$missing=array_values(array_filter(array_keys($targets),static fn(string $name): bool=>!isset($columns[$name])));
$legacyWithoutHash=0;
if(isset($columns['token_hash'])) {
    $legacyWithoutHash=(int)$pdo->query("SELECT COUNT(*) FROM devices WHERE activation_token IS NOT NULL AND activation_token<>'' AND token_hash IS NULL")->fetchColumn();
}
if(!$missing && $legacyWithoutHash===0) {
    $count=(int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
    echo "014 device token security already applied; devices={$count}, legacy_without_hash=0.\n";
    exit(0);
}

$backup=d014_backup($pdo,$columns);
try {
    foreach($targets as $name=>$definition) {
        if(!isset($columns[$name])) $pdo->exec("ALTER TABLE devices ADD COLUMN {$name} {$definition}");
    }
    $pdo->exec("UPDATE devices SET token_hash=SHA2(activation_token,256),token_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 DAY),activated_at=COALESCE(activated_at,created_at) WHERE token_hash IS NULL AND activation_token IS NOT NULL AND activation_token<>''");
} catch(Throwable $e) {
    d014_fail('migration error after backup '.$backup.': '.$e->getMessage());
}
$columns=d014_columns($pdo);
foreach(array_keys($targets) as $name) if(!isset($columns[$name])) d014_fail("verification failed: missing devices.{$name}");
foreach(['token_hash','previous_token_hash'] as $name) if((string)$columns[$name]['data_type']!=='char'||(int)$columns[$name]['character_maximum_length']!==64) d014_fail("verification failed: incompatible devices.{$name}");
foreach(['token_expires_at','previous_token_valid_until','token_rotated_at','revoked_at','activated_at'] as $name) if(!in_array((string)$columns[$name]['data_type'],['datetime','timestamp'],true)) d014_fail("verification failed: incompatible devices.{$name}");
$legacyWithoutHash=(int)$pdo->query("SELECT COUNT(*) FROM devices WHERE activation_token IS NOT NULL AND activation_token<>'' AND token_hash IS NULL")->fetchColumn();
if($legacyWithoutHash!==0) d014_fail("verification failed: {$legacyWithoutHash} legacy device tokens remain without hashes");
$count=(int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
echo "014 device token security applied; devices={$count}, legacy_without_hash=0, backup={$backup}.\n";
