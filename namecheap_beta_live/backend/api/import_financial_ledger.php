<?php
declare(strict_types=1);

define('MERD_IMPORT_TOKEN', 'b7c2f4ecfc51fe8f4fb5bcaf26f8d23be87987edbec6cafe48f5cb02e24c37e5');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/maintenance_guard.php';

$expectedImportToken = defined('MERD_IMPORT_TOKEN') ? (string)constant('MERD_IMPORT_TOKEN') : '';
$providedImportToken = (string)($_GET['token'] ?? '');
merd_maintenance_guard([
    'enabled' => $expectedImportToken !== '',
    'administratively_authorized' => $expectedImportToken !== ''
        && hash_equals($expectedImportToken, $providedImportToken),
]);

const MERD_FINANCIAL_IMPORT_SOURCE = 'google_sheet_general_ledger';
const MERD_FINANCIAL_IMPORT_DEFAULT_BATCH = 500;
const MERD_FINANCIAL_IMPORT_MAX_BATCH = 1000;

function merd_import_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function merd_import_uuid(string $seed): string
{
    $hex = hash('sha256', $seed);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
        . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

function merd_import_date(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!d/m/Y', trim($value), new DateTimeZone('UTC'));
    return $date && $date->format('d/m/Y') === trim($value) ? $date->format('Y-m-d') : null;
}

function merd_import_amount(string $value): ?string
{
    $normalized = str_replace([',', '$', ' '], '', trim($value));
    if ($normalized === '') return '0.00';
    if (!is_numeric($normalized)) return null;
    $amount = (float)$normalized;
    if (!is_finite($amount) || abs($amount) > 9999999999.99) return null;
    return number_format($amount, 2, '.', '');
}

function merd_import_employee_name(string $head): ?string
{
    if (!preg_match('/\(([^()]*)\)\s*$/u', trim($head), $match)) return null;
    $name = trim($match[1]);
    return $name === '' ? null : $name;
}

function merd_import_progress(PDO $pdo, int $sourceRows): array
{
    $legacy = $pdo->query(
        "SELECT COUNT(*) FROM financial_submissions WHERE payload LIKE '%\"source\":\"" . MERD_FINANCIAL_IMPORT_SOURCE . "\"%'"
    )->fetchColumn();
    $ledger = $pdo->query(
        "SELECT COUNT(*) FROM financial_ledger_entries l INNER JOIN financial_submissions f ON f.id=l.submission_id "
        . "WHERE f.payload LIKE '%\"source\":\"" . MERD_FINANCIAL_IMPORT_SOURCE . "\"%'"
    )->fetchColumn();
    return [
        'source_rows' => $sourceRows,
        'legacy_submissions' => (int)$legacy,
        'legacy_ledger_entries' => (int)$ledger,
        'complete' => (int)$ledger === $sourceRows,
    ];
}

$csvPath = __DIR__ . '/../imports/financial_general_ledger.csv';
if (!is_file($csvPath) || !is_readable($csvPath)) {
    merd_import_json(['success' => false, 'error' => 'Financial General Ledger CSV is missing.'], 404);
}

$file = new SplFileObject($csvPath, 'r');
$file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
$headers = $file->fgetcsv();
$expectedHeaders = ['DATE', 'STORE_NAME', 'ACCOUNT', 'TYPE', 'HEAD', 'AMOUNT'];
$headers = is_array($headers) ? array_map(static fn($value) => strtoupper(trim((string)$value)), $headers) : [];
if ($headers !== $expectedHeaders) {
    merd_import_json(['success' => false, 'error' => 'Unexpected financial CSV headers.', 'headers' => $headers], 400);
}

$countFile = new SplFileObject($csvPath, 'r');
$countFile->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
$countFile->fgetcsv();
$sourceRows = 0;
while (!$countFile->eof()) {
    $countedRow = $countFile->fgetcsv();
    if (is_array($countedRow) && $countedRow !== [null]) $sourceRows++;
}
$mode = (string)($_GET['mode'] ?? 'page');
if ($mode === 'status') merd_import_json(['success' => true, 'progress' => merd_import_progress($pdo, $sourceRows)]);

if ($mode === 'page') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>MerdPOS financial history import</title>
  <style>
    body{font:16px/1.45 system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 18px;color:#172033}
    button{background:#1c4587;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-weight:700;cursor:pointer}
    button:disabled{opacity:.55;cursor:not-allowed}.bar{height:14px;background:#e8edf5;border-radius:9px;overflow:hidden;margin:18px 0}
    .bar span{display:block;height:100%;width:0;background:#24a148}.note{background:#f5f7fa;border-left:4px solid #1c4587;padding:12px}
    pre{white-space:pre-wrap;background:#101827;color:#e7eefb;padding:12px;border-radius:8px;max-height:260px;overflow:auto}
  </style>
</head>
<body>
  <h1>Import financial history</h1>
  <p class="note">This imports the saved Google Sheets General Ledger into the beta SQL tables. It is repeat-safe and stops if live beta financial submissions already exist.</p>
  <p><strong><?= number_format($sourceRows) ?></strong> source rows are ready.</p>
  <button id="start">Start import</button>
  <div class="bar"><span id="fill"></span></div>
  <p id="status">Not started.</p>
  <pre id="log"></pre>
<script>
const total=<?= (int)$sourceRows ?>, button=document.getElementById('start'), fill=document.getElementById('fill');
const status=document.getElementById('status'), log=document.getElementById('log');
const token=encodeURIComponent(new URLSearchParams(location.search).get('token')||'');
let next=2, inserted=0, duplicates=0;
function message(text){log.textContent=text+'\n'+log.textContent;}
async function run(){
  button.disabled=true;
  try{
    const response=await fetch(`?token=${token}&mode=batch&start=${next}&limit=500`,{method:'POST',headers:{'X-Requested-With':'MerdPOS-Beta-Importer'}});
    const data=await response.json();
    if(!response.ok||!data.success) throw new Error(data.error||JSON.stringify(data.errors||[])||'Import failed.');
    inserted+=data.inserted; duplicates+=data.duplicates; next=data.next_row;
    const done=Math.min(total,data.processed_through-1), pct=Math.round(done/total*100);
    fill.style.width=pct+'%'; status.textContent=`${done.toLocaleString()} / ${total.toLocaleString()} rows (${pct}%)`;
    message(`Rows ${data.start_row}-${data.processed_through}: ${data.inserted} inserted, ${data.duplicates} already present.`);
    if(data.errors.length) throw new Error(JSON.stringify(data.errors.slice(0,5)));
    if(!data.done) return run();
    status.textContent=`Complete: ${data.progress.legacy_ledger_entries.toLocaleString()} financial rows imported.`;
    message('IMPORT COMPLETE. Keep the Google Sheet unchanged until reconciliation is signed off.');
  }catch(error){status.textContent='Stopped: '+error.message; message('ERROR: '+error.message); button.disabled=false;}
}
button.addEventListener('click',()=>{if(confirm('Import the saved General Ledger into beta SQL now?')) run();});
</script>
</body>
</html>
    <?php
    exit;
}

if ($mode !== 'batch' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'MerdPOS-Beta-Importer') {
    merd_import_json(['success' => false, 'error' => 'Invalid import request.'], 405);
}

$start = filter_var($_GET['start'] ?? null, FILTER_VALIDATE_INT);
$limit = filter_var($_GET['limit'] ?? MERD_FINANCIAL_IMPORT_DEFAULT_BATCH, FILTER_VALIDATE_INT);
if (!$start || $start < 2 || !$limit || $limit < 1 || $limit > MERD_FINANCIAL_IMPORT_MAX_BATCH) {
    merd_import_json(['success' => false, 'error' => 'Invalid import batch.'], 400);
}

$nonLegacy = $pdo->query(
    "SELECT COUNT(*) FROM financial_submissions WHERE payload NOT LIKE '%\"source\":\"" . MERD_FINANCIAL_IMPORT_SOURCE . "\"%'"
)->fetchColumn();
if ((int)$nonLegacy > 0) {
    merd_import_json(['success' => false, 'error' => 'Live beta financial submissions already exist. Historical import stopped to protect them.'], 409);
}
if ($start === 2) {
    $orphanAccounts = (int)$pdo->query('SELECT COUNT(*) FROM financial_day_accounts')->fetchColumn();
    $legacyProgress = merd_import_progress($pdo, $sourceRows);
    if ($orphanAccounts > 0 && $legacyProgress['legacy_submissions'] === 0) {
        merd_import_json(['success' => false, 'error' => 'Financial day accounts already exist without legacy submissions. Import stopped.'], 409);
    }
}

$stores = [];
$storeQuery = $pdo->query("SELECT id, client_id, store_name FROM stores WHERE status='active'");
foreach ($storeQuery->fetchAll(PDO::FETCH_ASSOC) as $store) $stores[mb_strtolower(trim((string)$store['store_name']))] = $store;

$employees = [];
$fallbackByClient = [];
$employeeQuery = $pdo->query("SELECT id, client_id, full_name, employee_type, status FROM employees ORDER BY (employee_type='SUPER') DESC,(employee_type='ADMIN') DESC,id");
foreach ($employeeQuery->fetchAll(PDO::FETCH_ASSOC) as $employee) {
    $clientId = (int)$employee['client_id'];
    $employees[$clientId][mb_strtolower(trim((string)$employee['full_name']))] = (int)$employee['id'];
    if (!isset($fallbackByClient[$clientId]) && $employee['status'] === 'active') $fallbackByClient[$clientId] = (int)$employee['id'];
}

$insertSubmission = $pdo->prepare(
    "INSERT IGNORE INTO financial_submissions "
    . "(public_id,client_id,store_id,employee_id,submission_type,business_date,payload,payload_hash,status,accepted_at,sheet_synced_at) "
    . "VALUES (?,?,?,?,?,?,?,?, 'sheet_synced',?,?)"
);
$findSubmission = $pdo->prepare('SELECT id FROM financial_submissions WHERE public_id=? LIMIT 1');
$insertLedger = $pdo->prepare(
    'INSERT IGNORE INTO financial_ledger_entries '
    . '(submission_id,line_no,client_id,store_id,business_date,account,entry_type,head,amount,created_at) '
    . 'VALUES (?,1,?,?,?,?,?,?,?,?)'
);
$ensureDay = $pdo->prepare(
    'INSERT IGNORE INTO financial_day_accounts '
    . '(client_id,store_id,business_date,account,opening_amount,opened_by_employee_id,opened_at) VALUES (?,?,?,?,0,?,?)'
);
$setOpening = $pdo->prepare(
    'UPDATE financial_day_accounts SET opening_amount=?,opened_by_employee_id=? WHERE client_id=? AND store_id=? AND business_date=? AND account=?'
);
$addIn = $pdo->prepare(
    'UPDATE financial_day_accounts SET in_total=in_total+? WHERE client_id=? AND store_id=? AND business_date=? AND account=?'
);
$addOut = $pdo->prepare(
    'UPDATE financial_day_accounts SET out_total=out_total+? WHERE client_id=? AND store_id=? AND business_date=? AND account=?'
);
$setClosing = $pdo->prepare(
    "UPDATE financial_day_accounts SET closing_amount=?,status='closed',closed_by_submission_id=?,closed_at=? "
    . 'WHERE client_id=? AND store_id=? AND business_date=? AND account=?'
);

$file->seek($start - 1);
$processed = 0;
$inserted = 0;
$duplicates = 0;
$errors = [];
$batchHash = hash_file('sha256', $csvPath);

while (!$file->eof() && $processed < $limit) {
    $sourceRow = $start + $processed;
    $row = $file->fgetcsv();
    if (!is_array($row) || $row === [null]) break;
    $processed++;
    if (count($row) < 6) {
        $errors[] = ['row' => $sourceRow, 'error' => 'Row has fewer than six columns.'];
        continue;
    }
    $data = array_combine($expectedHeaders, array_slice($row, 0, 6));
    $date = merd_import_date((string)$data['DATE']);
    $storeName = trim((string)$data['STORE_NAME']);
    $account = trim((string)$data['ACCOUNT']);
    $entryType = strtoupper(trim((string)$data['TYPE']));
    $head = trim((string)$data['HEAD']);
    $amount = merd_import_amount((string)$data['AMOUNT']);
    $store = $stores[mb_strtolower($storeName)] ?? null;
    if (!$date || !$store || !in_array($account, ['Register', 'Petty Cash'], true)
        || !in_array($entryType, ['OPENING', 'IN', 'OUT', 'CLOSING'], true) || $head === '' || $amount === null) {
        $errors[] = ['row' => $sourceRow, 'error' => 'Invalid date, store, account, type, head, or amount.'];
        continue;
    }
    $clientId = (int)$store['client_id'];
    $storeId = (int)$store['id'];
    $employeeName = merd_import_employee_name($head);
    $employeeId = $employeeName === null ? null : ($employees[$clientId][mb_strtolower($employeeName)] ?? null);
    $employeeId = $employeeId ?? ($fallbackByClient[$clientId] ?? null);
    if (!$employeeId) {
        $errors[] = ['row' => $sourceRow, 'error' => 'No employee is available for the client.'];
        continue;
    }
    $submissionType = ['OPENING' => 'open_day', 'IN' => 'cash_in', 'OUT' => 'cash_out', 'CLOSING' => 'z_report'][$entryType];
    $source = [$sourceRow, $date, $storeName, $account, $entryType, $head, $amount];
    $publicId = merd_import_uuid('merdpos-financial-legacy|' . json_encode($source, JSON_UNESCAPED_UNICODE));
    $payload = json_encode([
        'source' => MERD_FINANCIAL_IMPORT_SOURCE,
        'source_row' => $sourceRow,
        'source_file_sha256' => $batchHash,
        'original' => ['date' => (string)$data['DATE'], 'store_name' => $storeName, 'account' => $account,
            'type' => $entryType, 'head' => $head, 'amount' => (string)$data['AMOUNT']],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $payloadHash = hash('sha256', $payload);
    $timestamp = $date . ' 00:00:00';

    try {
        $pdo->beginTransaction();
        $insertSubmission->execute([$publicId, $clientId, $storeId, $employeeId, $submissionType, $date, $payload, $payloadHash, $timestamp, $timestamp]);
        if ($insertSubmission->rowCount() === 0) {
            $duplicates++;
            $pdo->commit();
            continue;
        }
        $submissionId = (int)$pdo->lastInsertId();
        if ($submissionId < 1) {
            $findSubmission->execute([$publicId]);
            $submissionId = (int)$findSubmission->fetchColumn();
        }
        $insertLedger->execute([$submissionId, $clientId, $storeId, $date, $account, $entryType, $head, $amount, $timestamp]);
        $ensureDay->execute([$clientId, $storeId, $date, $account, $employeeId, $timestamp]);
        if ($entryType === 'OPENING') $setOpening->execute([$amount, $employeeId, $clientId, $storeId, $date, $account]);
        elseif ($entryType === 'IN') $addIn->execute([$amount, $clientId, $storeId, $date, $account]);
        elseif ($entryType === 'OUT') $addOut->execute([$amount, $clientId, $storeId, $date, $account]);
        else $setClosing->execute([$amount, $submissionId, $timestamp, $clientId, $storeId, $date, $account]);
        $pdo->commit();
        $inserted++;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = ['row' => $sourceRow, 'error' => $exception->getMessage()];
    }
}

$processedThrough = $start + $processed - 1;
$done = $processed === 0 || $processedThrough >= $sourceRows + 1;
$progress = merd_import_progress($pdo, $sourceRows);
merd_import_json([
    'success' => count($errors) === 0,
    'start_row' => $start,
    'processed_through' => $processedThrough,
    'processed' => $processed,
    'inserted' => $inserted,
    'duplicates' => $duplicates,
    'errors' => $errors,
    'next_row' => $start + $processed,
    'done' => $done,
    'progress' => $progress,
], count($errors) === 0 ? 200 : 422);
