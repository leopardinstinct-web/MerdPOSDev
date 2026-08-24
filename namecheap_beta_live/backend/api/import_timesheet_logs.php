<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

define('MERD_IMPORT_TOKEN', 'b7c2f4ecfc51fe8f4fb5bcaf26f8d23be87987edbec6cafe48f5cb02e24c37e5');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/maintenance_guard.php';

$providedToken = (string)($_GET['token'] ?? '');
merd_maintenance_guard([
    'enabled' => true,
    'administratively_authorized' => hash_equals((string)MERD_IMPORT_TOKEN, $providedToken),
]);

const MERD_TIMESHEET_BATCH_SIZE = 500;
const MERD_TIMESHEET_MAX_BATCH_SIZE = 1000;
const MERD_TIMESHEET_DEVICE_UUID = 'LEGACY-CSV-IMPORT';

function timesheet_json(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$csvPath = __DIR__ . '/../imports/timesheet_import.csv';
if (!is_file($csvPath) || !is_readable($csvPath)) {
    timesheet_json(['success' => false, 'error' => 'Timesheet CSV is missing or unreadable.'], 404);
}

$countFile = new SplFileObject($csvPath, 'r');
$countFile->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
$header = $countFile->fgetcsv();
$expectedHeader = ['USER_NAME', 'STORE_NAME', 'LOG_TYPE', 'DATE', 'TIME'];
$header = is_array($header) ? array_map(function ($value) {
    return strtoupper(trim((string)$value));
}, $header) : [];
if ($header !== $expectedHeader) {
    timesheet_json(['success' => false, 'error' => 'Unexpected timesheet CSV headers.', 'headers' => $header], 400);
}

$sourceRows = 0;
while (!$countFile->eof()) {
    $row = $countFile->fgetcsv();
    if (is_array($row) && $row !== [null] && count(array_filter($row, function ($value) {
        return trim((string)$value) !== '';
    })) > 0) {
        $sourceRows++;
    }
}

$mode = (string)($_GET['mode'] ?? 'page');
if ($mode === 'status') {
    $importedCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM employee_logs WHERE device_uuid='" . MERD_TIMESHEET_DEVICE_UUID . "'"
    )->fetchColumn();
    timesheet_json([
        'success' => true,
        'source_rows' => $sourceRows,
        'legacy_logs_in_database' => $importedCount,
        'complete' => $importedCount >= $sourceRows,
    ]);
}

if ($mode === 'page') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>MerdPOS timesheet history import</title>
  <style>
    body{font:16px/1.45 system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 18px;color:#172033}
    button{background:#1c4587;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-weight:700;cursor:pointer}
    button:disabled{opacity:.55;cursor:not-allowed}.bar{height:14px;background:#e8edf5;border-radius:9px;overflow:hidden;margin:18px 0}
    .bar span{display:block;height:100%;width:0;background:#24a148}.note{background:#f5f7fa;border-left:4px solid #1c4587;padding:12px}
    pre{white-space:pre-wrap;background:#101827;color:#e7eefb;padding:12px;border-radius:8px;max-height:280px;overflow:auto}
  </style>
</head>
<body>
  <h1>Import timesheet history</h1>
  <p class="note">This imports the saved Google Sheets attendance events into the beta employee logs. It is repeat-safe.</p>
  <p><strong><?= number_format($sourceRows) ?></strong> source rows are ready.</p>
  <button id="start">Start import</button>
  <div class="bar"><span id="fill"></span></div>
  <p id="status">Not started.</p>
  <pre id="log"></pre>
<script>
const total=<?= (int)$sourceRows ?>,button=document.getElementById('start'),fill=document.getElementById('fill');
const status=document.getElementById('status'),log=document.getElementById('log');
const token=encodeURIComponent(new URLSearchParams(location.search).get('token')||'');
let next=2,unmatchedEmployees=new Set(),unmatchedStores=new Set();
function message(text){log.textContent=text+'\n'+log.textContent;}
async function run(){
  button.disabled=true;
  try{
    const response=await fetch(`?token=${token}&mode=batch&start=${next}&limit=500`,{method:'POST',headers:{'X-Requested-With':'MerdPOS-Beta-Importer'}});
    const data=await response.json();
    if(!response.ok||!data.success) throw new Error(data.error||JSON.stringify(data.errors||[])||'Import failed.');
    (data.unmatched_employees||[]).forEach(x=>unmatchedEmployees.add(x));
    (data.unmatched_stores||[]).forEach(x=>unmatchedStores.add(x));
    next=data.next_row;
    const done=Math.min(total,data.processed_through-1),pct=total?Math.round(done/total*100):100;
    fill.style.width=pct+'%';status.textContent=`${done.toLocaleString()} / ${total.toLocaleString()} rows (${pct}%)`;
    message(`Rows ${data.start_row}-${data.processed_through}: ${data.imported} saved, ${data.skipped} skipped.`);
    if(data.errors.length) throw new Error(JSON.stringify(data.errors.slice(0,5)));
    if(!data.done) return run();
    status.textContent=`Complete: ${data.database_count.toLocaleString()} legacy attendance events are in SQL.`;
    if(unmatchedEmployees.size) message('Unmatched employees (logs retained without employee ID): '+[...unmatchedEmployees].join(', '));
    if(unmatchedStores.size) message('Unmatched stores (rows skipped): '+[...unmatchedStores].join(', '));
    message('IMPORT COMPLETE. Keep the Google Sheet unchanged until reconciliation is signed off.');
  }catch(error){status.textContent='Stopped: '+error.message;message('ERROR: '+error.message);button.disabled=false;}
}
button.addEventListener('click',()=>{if(confirm('Import the saved timesheet history into beta SQL now?'))run();});
</script>
</body>
</html>
    <?php
    exit;
}

if ($mode !== 'batch' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'MerdPOS-Beta-Importer') {
    timesheet_json(['success' => false, 'error' => 'Invalid import request.'], 405);
}

$start = filter_var($_GET['start'] ?? null, FILTER_VALIDATE_INT);
$limit = filter_var($_GET['limit'] ?? MERD_TIMESHEET_BATCH_SIZE, FILTER_VALIDATE_INT);
if (!$start || $start < 2 || !$limit || $limit < 1 || $limit > MERD_TIMESHEET_MAX_BATCH_SIZE) {
    timesheet_json(['success' => false, 'error' => 'Invalid import batch.'], 400);
}

$stores = [];
$storeQuery = $pdo->query('SELECT id, client_id, store_name FROM stores');
foreach ($storeQuery->fetchAll(PDO::FETCH_ASSOC) as $store) {
    $stores[mb_strtolower(trim((string)$store['store_name']))] = $store;
}

$employees = [];
$employeeQuery = $pdo->query('SELECT id, client_id, full_name FROM employees');
foreach ($employeeQuery->fetchAll(PDO::FETCH_ASSOC) as $employee) {
    $employees[(int)$employee['client_id']][mb_strtolower(trim((string)$employee['full_name']))] = (int)$employee['id'];
}

$insert = $pdo->prepare(
    'INSERT INTO employee_logs '
    . '(client_id,store_id,employee_id,user_name,store_name,log_type,log_date,log_time,log_datetime,device_uuid,local_log_id) '
    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE synced_at=CURRENT_TIMESTAMP'
);

$file = new SplFileObject($csvPath, 'r');
$file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
$file->seek($start - 1);
$processed = 0;
$imported = 0;
$skipped = 0;
$errors = [];
$unmatchedEmployees = [];
$unmatchedStores = [];

while (!$file->eof() && $processed < $limit) {
    $sourceRow = $start + $processed;
    $row = $file->fgetcsv();
    if (!is_array($row) || $row === [null]) break;
    $processed++;
    if (count($row) < 5) {
        $skipped++;
        continue;
    }
    $data = array_combine($expectedHeader, array_slice($row, 0, 5));
    $userName = trim((string)$data['USER_NAME']);
    $storeName = trim((string)$data['STORE_NAME']);
    $logType = strtoupper(trim((string)$data['LOG_TYPE']));
    $date = trim((string)$data['DATE']);
    $time = trim((string)$data['TIME']);
    if ($userName === '' || $storeName === '' || !in_array($logType, ['IN', 'OUT'], true)) {
        $skipped++;
        continue;
    }
    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, new DateTimeZone('UTC'));
    if (!$dateTime) {
        $errors[] = ['row' => $sourceRow, 'error' => 'Invalid date or time.'];
        $skipped++;
        continue;
    }
    $store = $stores[mb_strtolower($storeName)] ?? null;
    if (!$store) {
        $unmatchedStores[$storeName] = true;
        $skipped++;
        continue;
    }
    $clientId = (int)$store['client_id'];
    $storeId = (int)$store['id'];
    $employeeId = $employees[$clientId][mb_strtolower($userName)] ?? null;
    if (!$employeeId) $unmatchedEmployees[$userName] = true;
    $logDate = $dateTime->format('Y-m-d');
    $logTime = $dateTime->format('H:i:s');
    $logDateTime = $dateTime->format('Y-m-d H:i:s');
    $localLogId = md5($clientId . '|' . $storeName . '|' . $userName . '|' . $logType . '|' . $logDateTime);
    try {
        $insert->execute([
            $clientId, $storeId, $employeeId, $userName, $storeName, $logType,
            $logDate, $logTime, $logDateTime, MERD_TIMESHEET_DEVICE_UUID, $localLogId,
        ]);
        $imported++;
    } catch (Throwable $exception) {
        $errors[] = ['row' => $sourceRow, 'error' => $exception->getMessage()];
        $skipped++;
    }
}

$processedThrough = $start + $processed - 1;
$done = $processed === 0 || $processedThrough >= $sourceRows + 1;
$databaseCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM employee_logs WHERE device_uuid='" . MERD_TIMESHEET_DEVICE_UUID . "'"
)->fetchColumn();

timesheet_json([
    'success' => count($errors) === 0,
    'start_row' => $start,
    'processed_through' => $processedThrough,
    'processed' => $processed,
    'imported' => $imported,
    'skipped' => $skipped,
    'unmatched_employees' => array_keys($unmatchedEmployees),
    'unmatched_stores' => array_keys($unmatchedStores),
    'errors' => $errors,
    'next_row' => $start + $processed,
    'done' => $done,
    'database_count' => $databaseCount,
], count($errors) === 0 ? 200 : 422);
