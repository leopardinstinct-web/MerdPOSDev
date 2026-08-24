<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "FATAL PHP ERROR\n";
        echo $error['message'] . "\n";
        echo $error['file'] . ':' . $error['line'] . "\n";
    }
});

define('MERD_IMPORT_TOKEN', 'b7c2f4ecfc51fe8f4fb5bcaf26f8d23be87987edbec6cafe48f5cb02e24c37e5');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/maintenance_guard.php';

$providedToken = (string)($_GET['token'] ?? '');
merd_maintenance_guard([
    'enabled' => true,
    'administratively_authorized' => hash_equals((string)MERD_IMPORT_TOKEN, $providedToken),
]);

function setup_json(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function norm_name(string $value): string
{
    return strtolower(trim($value));
}

function money_or_zero($value): string
{
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) return '0.00';
    return number_format((float)$value, 2, '.', '');
}

function employee_rows(): array
{
    return [
        ['Abdallah','USER','0466927209','123456','INACTIVE','', ''],
        ['Adeel','USER','0414154723','123456','INACTIVE','', '18'],
        ['Anwer','USER','0422488743','061110','ACTIVE','', '18'],
        ['Hassan','USER','0475091571','123456','INACTIVE','', '19'],
        ['Karim','USER','0487494559','123456','ACTIVE','', '18'],
        ['Mohammad','USER','0473454153','636463','ACTIVE','', '18'],
        ['Mulham','SUPER','0404549095','123456','ACTIVE','Enmore Tobacco', ''],
        ['Sameer','USER','0493945963','123456','INACTIVE','', '18'],
        ['Shoeb','USER','0478290234','123456','INACTIVE','', ''],
        ['Sony','USER','0430543206','123456','INACTIVE','', ''],
        ['Tabib','USER','0432992153','123456','INACTIVE','', '19'],
        ['Irfan','USER','0466982562','123456','INACTIVE','', ''],
        ['Fahim','USER','0426285221','123456','ACTIVE','', '18'],
        ['Aiyappa','USER','0490061782','123456','INACTIVE','', '17'],
        ['Imran','SUPER','0426656624','4493','ACTIVE','', '20'],
        ['Chowdhury','USER','0449994192','123456','ACTIVE','', '18'],
        ['Super User','SUPER','1234','12345678','ACTIVE','', ''],
        ['Faisal','USER','0490131055','133799','ACTIVE','Double Bay Tobacco', '18'],
        ['Tawfiq','USER','0415708249','123456','ACTIVE','', '18'],
        ['Kamal','USER','0402620138','123456','INACTIVE','', ''],
        ['Sidrah','USER','0450162151','123456','ACTIVE','', '18'],
        ['Al Shahriar','USER','0430002465','123456','INACTIVE','', ''],
        ['Tanvir','USER','0451469762','123456','INACTIVE','', '17'],
        ['Ahmed','USER','0426185336','123456','INACTIVE','', '17'],
        ['Tahsin','USER','0421856002','123456','INACTIVE','', '17'],
        ['Mursiln','USER','0410278420','123456','ACTIVE','', '18'],
        ['Amena','USER','0406376993','123456','ACTIVE','', '18'],
        ['Rakibul Hasan','USER','0415137868','123456','INACTIVE','', '17'],
        ['Shafiqur Rahman','USER','0449227090','123412','INACTIVE','', '17'],
        ['Afrin','USER','0413540441','123456','INACTIVE','', '17'],
        ['Wahid','USER','0424296521','123456','ACTIVE','', '18'],
        ['J. Hassan','USER','0450577493','536390','ACTIVE','', '18'],
        ['Jahid','USER','0480612369','123456','ACTIVE','', '18'],
        ['Tarek','USER','0424763185','123456','INACTIVE','', '17'],
        ['Shartaz','USER','0410430102','10082006','ACTIVE','', '18'],
        ['Jahirul Islam','USER','0491039014','123456','INACTIVE','', '17'],
        ['Srabony','USER','0405688685','123456','ACTIVE','', '18'],
        ['Rahi','USER','0449995865','1513036','ACTIVE','', '18'],
        ['Abdallah Al-Qudah','SUPER','0451323121','123456','ACTIVE','', ''],
        ['Z. R. Anik','USER','0451773136','123456','ACTIVE','', '18'],
        ['Sami','USER','0416847840','123456','ACTIVE','', '18'],
        ['Ash','USER','0451444564','123456','ACTIVE','', '18'],
        ['Abid','USER','0426592362','123456','ACTIVE','', '18'],
    ];
}

function start_time_rows(): array
{
    return [
        ['Rosebay Tobacco', '06:00:00'],
        ['Enmore Tobacco', '07:00:00'],
        ['Marrickville Xpress', '07:00:00'],
        ['Double Bay Tobacco', '07:00:00'],
    ];
}

try {
    $mode = (string)($_GET['mode'] ?? 'page');

    if ($mode === 'page') {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>MerdPOS Employee Setup Import</title>
  <style>
    body{font:16px/1.45 system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 18px;color:#172033}
    button{background:#1c4587;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-weight:700;cursor:pointer}
    .note{background:#f5f7fa;border-left:4px solid #1c4587;padding:12px}
    pre{white-space:pre-wrap;background:#101827;color:#e7eefb;padding:12px;border-radius:8px;max-height:320px;overflow:auto}
  </style>
</head>
<body>
  <h1>Import Employee Setup + Start Time</h1>
  <p class="note">This updates beta SQL from the live Google Sheet configuration: roles, status, login IDs, passwords, pay rates, log stores, and store shift start times. It also relinks existing imported attendance logs to the corrected employee IDs.</p>
  <p><strong><?= count(employee_rows()) ?></strong> Employee Setup rows and <strong><?= count(start_time_rows()) ?></strong> Start Time rows are ready.</p>
  <button id="start">Start import</button>
  <pre id="log">Not started.</pre>
<script>
const button=document.getElementById('start'),log=document.getElementById('log');
button.addEventListener('click', async () => {
  if(!confirm('Update beta SQL from Employee Setup and Start Time now?')) return;
  button.disabled=true;
  log.textContent='Running...';
  try {
    const token=encodeURIComponent(new URLSearchParams(location.search).get('token')||'');
    const response=await fetch(`?token=${token}&mode=run`,{method:'POST',headers:{'X-Requested-With':'MerdPOS-Beta-Importer'}});
    const data=await response.json();
    log.textContent=JSON.stringify(data,null,2);
  } catch(error) {
    log.textContent='ERROR: '+error.message;
    button.disabled=false;
  }
});
</script>
</body>
</html>
        <?php
        exit;
    }

    if ($mode !== 'run' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'MerdPOS-Beta-Importer') {
        setup_json(['success' => false, 'error' => 'Invalid request.'], 405);
    }

    $stores = [];
    $stmt = $pdo->query('SELECT id, store_name FROM stores WHERE client_id=1');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $store) {
        $stores[norm_name((string)$store['store_name'])] = (int)$store['id'];
    }
    $defaultStoreId = $stores[norm_name('Marrickville Xpress')] ?? (int)$pdo->query('SELECT id FROM stores WHERE client_id=1 ORDER BY id LIMIT 1')->fetchColumn();

    $existing = [];
    $stmt = $pdo->query('SELECT id, full_name FROM employees WHERE client_id=1');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[norm_name((string)$row['full_name'])] = (int)$row['id'];
    }

    $update = $pdo->prepare(
        'UPDATE employees SET store_id=?, full_name=?, user_id=?, login_password=?, employee_type=?, pin_code=?, role_name=?, hourly_rate=?, status=? WHERE id=?'
    );
    $insert = $pdo->prepare(
        'INSERT INTO employees (client_id,store_id,full_name,user_id,login_password,employee_type,pin_code,role_name,hourly_rate,status) '
        . 'VALUES (1,?,?,?,?,?,?,?,?,?)'
    );

    $updated = 0;
    $inserted = 0;
    foreach (employee_rows() as $row) {
        [$name, $type, $userId, $password, $status, $logStore, $payRate] = $row;
        $storeId = $logStore !== '' && isset($stores[norm_name($logStore)]) ? $stores[norm_name($logStore)] : $defaultStoreId;
        $roleName = strtoupper($type) === 'SUPER' ? 'Manager' : 'Staff';
        $dbStatus = strtolower($status) === 'active' ? 'active' : 'inactive';
        $rate = money_or_zero($payRate);
        $key = norm_name($name);
        if (isset($existing[$key])) {
            $update->execute([$storeId, $name, $userId, $password, $type, $password, $roleName, $rate, $dbStatus, $existing[$key]]);
            $updated++;
        } else {
            $insert->execute([$storeId, $name, $userId, $password, $type, $password, $roleName, $rate, $dbStatus]);
            $existing[$key] = (int)$pdo->lastInsertId();
            $inserted++;
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_shift_start_times ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'client_id INT NOT NULL, '
        . 'store_id INT NOT NULL, '
        . 'store_name VARCHAR(150) NOT NULL, '
        . 'shift_start_time TIME NOT NULL, '
        . 'updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
        . 'UNIQUE KEY uq_store_shift_start (client_id, store_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $upsertStart = $pdo->prepare(
        'INSERT INTO store_shift_start_times (client_id,store_id,store_name,shift_start_time) VALUES (1,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE store_name=VALUES(store_name), shift_start_time=VALUES(shift_start_time), updated_at=CURRENT_TIMESTAMP'
    );
    $startTimes = 0;
    foreach (start_time_rows() as $row) {
        [$storeName, $time] = $row;
        $storeId = $stores[norm_name($storeName)] ?? null;
        if (!$storeId) continue;
        $upsertStart->execute([$storeId, $storeName, $time]);
        $startTimes++;
    }

    $relink = $pdo->exec(
        'UPDATE employee_logs l '
        . 'INNER JOIN employees e ON e.client_id=l.client_id AND LOWER(TRIM(e.full_name))=LOWER(TRIM(l.user_name)) '
        . 'SET l.employee_id=e.id '
        . 'WHERE l.client_id=1 AND (l.employee_id IS NULL OR l.employee_id<>e.id)'
    );

    $superRows = $pdo->query("SELECT full_name,user_id,employee_type,status,hourly_rate FROM employees WHERE client_id=1 AND employee_type='SUPER' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $remainingUnlinked = (int)$pdo->query('SELECT COUNT(*) FROM employee_logs WHERE client_id=1 AND employee_id IS NULL')->fetchColumn();

    setup_json([
        'success' => true,
        'message' => 'Employee Setup and Start Time imported into beta SQL.',
        'employees_updated' => $updated,
        'employees_inserted' => $inserted,
        'start_times_upserted' => $startTimes,
        'attendance_logs_relinked' => (int)$relink,
        'remaining_unlinked_attendance_logs' => $remainingUnlinked,
        'super_users' => $superRows,
    ]);
} catch (Throwable $e) {
    setup_json(['success' => false, 'error' => $e->getMessage()], 500);
}
