<?php
/**
 * MerdPOS get_timesheet.php
 * Version: payroll-rounded-v7-auto-db-resolver-pdf-match
 *
 * Purpose:
 * - Match the legacy Google Apps Script / PDF payroll logic.
 * - Pair IN to the next OUT for the same employee.
 * - If another IN appears before OUT, discard the previous unmatched IN and keep the new IN.
 * - Ignore OUT without unmatched IN.
 * - Build punch datetime from log_date + log_time when those columns exist.
 * - Round IN and OUT independently to nearest 15 minutes.
 * - Calculate payable hours from rounded times.
 * - Allow cross-midnight shifts.
 * - Do not cap long shifts.
 * - Use employees.hourly_rate where available.
 * - Return store summary, employee summary, and employee-wise detailed report.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

const API_VERSION = 'payroll-rounded-v7-auto-db-resolver-pdf-match';
const DEFAULT_HOURLY_RATE = 18.00;
const ROUNDING_MINUTES = 15;
const LONG_SHIFT_NOTE_HOURS = 16.00;

function respond_json($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_json($message, $extra = [], $status = 500) {
    respond_json(array_merge([
        'success' => false,
        'api' => 'get_timesheet.php',
        'version' => API_VERSION,
        'error' => $message,
    ], $extra), $status);
}

function param($key, $default = null) {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? trim((string)$_GET[$key]) : $default;
}

function valid_date_or_fail($value, $name) {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        error_json("Invalid {$name}. Expected YYYY-MM-DD.", [], 400);
    }
    return $value;
}

function candidate_include_files() {
    $names = [
        'db.php', 'config.php', 'connection.php', 'database.php', 'connect.php',
        'db_connect.php', 'db_connection.php', 'mysql.php', 'mysqli.php',
        'init.php', 'bootstrap.php', 'config.inc.php', 'settings.php'
    ];

    $dirs = [];
    $base = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        $dirs[] = $base;
        $parent = dirname($base);
        if ($parent === $base) break;
        $base = $parent;
    }

    $more = [
        __DIR__ . '/includes', __DIR__ . '/config', __DIR__ . '/inc',
        dirname(__DIR__) . '/includes', dirname(__DIR__) . '/config', dirname(__DIR__) . '/inc',
        dirname(__DIR__, 2) . '/includes', dirname(__DIR__, 2) . '/config', dirname(__DIR__, 2) . '/inc',
        dirname(__DIR__, 2) . '/api', dirname(__DIR__, 3) . '/api'
    ];
    $dirs = array_values(array_unique(array_merge($dirs, $more)));

    $files = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir) || !is_readable($dir)) continue;
        foreach ($names as $name) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && is_readable($path) && realpath($path) !== realpath(__FILE__)) {
                $files[] = $path;
            }
        }
        // Light pattern scan in likely config dirs only.
        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*{db,DB,database,Database,config,Config,connect,Connect,connection,Connection}*.php', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path) && is_readable($path) && realpath($path) !== realpath(__FILE__)) {
                $files[] = $path;
            }
        }
    }

    return array_values(array_unique($files));
}

function is_pdo_conn($v) { return $v instanceof PDO; }
function is_mysqli_conn($v) { return $v instanceof mysqli; }

function detect_connection_from_vars($vars) {
    $names = ['pdo', 'conn', 'mysqli', 'db', 'link', 'con', 'connection', 'database', 'mysql', 'dbconn', 'db_conn'];
    foreach ($names as $n) {
        if (isset($vars[$n]) && (is_pdo_conn($vars[$n]) || is_mysqli_conn($vars[$n]))) {
            return $vars[$n];
        }
        if (isset($GLOBALS[$n]) && (is_pdo_conn($GLOBALS[$n]) || is_mysqli_conn($GLOBALS[$n]))) {
            return $GLOBALS[$n];
        }
    }
    foreach ($vars as $v) {
        if (is_pdo_conn($v) || is_mysqli_conn($v)) return $v;
    }
    foreach ($GLOBALS as $v) {
        if (is_pdo_conn($v) || is_mysqli_conn($v)) return $v;
    }
    return null;
}

function val_from_possible($sources, $keys) {
    foreach ($sources as $src) {
        if (!is_array($src)) continue;
        foreach ($keys as $k) {
            if (isset($src[$k]) && $src[$k] !== '') return $src[$k];
        }
    }
    foreach ($keys as $k) {
        if (defined($k)) return constant($k);
        $lower = strtolower($k);
        if (defined($lower)) return constant($lower);
        $env = getenv($k);
        if ($env !== false && $env !== '') return $env;
        $envLower = getenv($lower);
        if ($envLower !== false && $envLower !== '') return $envLower;
        if (isset($GLOBALS[$k]) && $GLOBALS[$k] !== '') return $GLOBALS[$k];
        if (isset($GLOBALS[$lower]) && $GLOBALS[$lower] !== '') return $GLOBALS[$lower];
    }
    return null;
}

function connect_from_config_arrays($localVars) {
    $sources = [];
    foreach (['config', 'db_config', 'database', 'db', 'settings'] as $name) {
        if (isset($localVars[$name]) && is_array($localVars[$name])) $sources[] = $localVars[$name];
        if (isset($GLOBALS[$name]) && is_array($GLOBALS[$name])) $sources[] = $GLOBALS[$name];
    }

    // Flatten common nested arrays like $config['database'].
    foreach ($sources as $src) {
        foreach (['database', 'db', 'mysql', 'mysqli'] as $nested) {
            if (isset($src[$nested]) && is_array($src[$nested])) $sources[] = $src[$nested];
        }
    }

    $host = val_from_possible($sources, ['DB_HOST', 'MYSQL_HOST', 'host', 'hostname', 'server']);
    $name = val_from_possible($sources, ['DB_NAME', 'MYSQL_DATABASE', 'database', 'dbname', 'db_name', 'name']);
    $user = val_from_possible($sources, ['DB_USER', 'MYSQL_USER', 'username', 'user', 'db_user']);
    $pass = val_from_possible($sources, ['DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD', 'password', 'pass', 'db_pass']);
    $port = val_from_possible($sources, ['DB_PORT', 'MYSQL_PORT', 'port']);
    $charset = val_from_possible($sources, ['DB_CHARSET', 'MYSQL_CHARSET', 'charset']);

    if (!$host || !$name || !$user) return null;
    if ($pass === null) $pass = '';
    if (!$port) $port = 3306;
    if (!$charset) $charset = 'utf8mb4';

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function resolve_db_connection(&$debugInfo = []) {
    $debugInfo = [
        'included_files_tried' => [],
        'included_files_loaded' => [],
        'connection_variables_seen' => [],
        'cwd' => getcwd(),
        'api_dir' => __DIR__,
    ];

    $existing = detect_connection_from_vars(get_defined_vars());
    if ($existing) return $existing;

    foreach (candidate_include_files() as $file) {
        $debugInfo['included_files_tried'][] = $file;
        try {
            include_once $file;
            $debugInfo['included_files_loaded'][] = $file;
        } catch (Throwable $e) {
            continue;
        }

        $vars = get_defined_vars();
        foreach (['pdo','conn','mysqli','db','link','con','connection','database','mysql','dbconn','db_conn'] as $n) {
            if (isset($vars[$n]) || isset($GLOBALS[$n])) $debugInfo['connection_variables_seen'][] = $n;
        }

        $conn = detect_connection_from_vars($vars);
        if ($conn) return $conn;

        $conn = connect_from_config_arrays($vars);
        if ($conn) return $conn;
    }

    $conn = connect_from_config_arrays(get_defined_vars());
    if ($conn) return $conn;

    // Last resort: support direct GET override for local debugging only if all four are provided.
    // Example: ?db_host=localhost&db_name=...&db_user=...&db_pass=...
    // Do not use this on public URLs except temporary local testing.
    $host = param('db_host');
    $name = param('db_name');
    $user = param('db_user');
    $pass = param('db_pass', '');
    $port = param('db_port', '3306');
    if ($host && $name && $user) {
        try {
            return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $e) {
            $debugInfo['direct_get_connection_error'] = $e->getMessage();
        }
    }

    return null;
}

function db_type($db) { return $db instanceof PDO ? 'pdo' : ($db instanceof mysqli ? 'mysqli' : 'unknown'); }

function db_all($db, $sql, $params = []) {
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($db instanceof mysqli) {
        if (empty($params)) {
            $res = $db->query($sql);
            if (!$res) throw new Exception($db->error);
            return $res->fetch_all(MYSQLI_ASSOC);
        }
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new Exception($db->error);
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $res = $stmt->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    throw new Exception('Unsupported database connection type.');
}

function db_one($db, $sql, $params = []) {
    $rows = db_all($db, $sql, $params);
    return $rows[0] ?? null;
}

function table_exists($db, $table) {
    try {
        $rows = db_all($db, "SHOW TABLES LIKE ?", [$table]);
        return count($rows) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function table_columns($db, $table) {
    try {
        $rows = db_all($db, "DESCRIBE `{$table}`");
        $cols = [];
        foreach ($rows as $r) {
            if (isset($r['Field'])) $cols[] = $r['Field'];
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function pick_col($cols, $candidates) {
    $map = [];
    foreach ($cols as $c) $map[strtolower($c)] = $c;
    foreach ($candidates as $cand) {
        $lc = strtolower($cand);
        if (isset($map[$lc])) return $map[$lc];
    }
    return null;
}

function qcol($c) { return '`' . str_replace('`', '``', $c) . '`'; }

function parse_datetime_from_row($row, $dateCol, $timeCol, $dateTimeCol) {
    if ($dateCol && $timeCol && isset($row[$dateCol]) && isset($row[$timeCol])) {
        $date = trim((string)$row[$dateCol]);
        $time = trim((string)$row[$timeCol]);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date, $m)) $date = $m[0];
        if (preg_match('/(\d{1,2}:\d{2}(?::\d{2})?)/', $time, $m)) $time = $m[1];
        if (substr_count($time, ':') === 1) $time .= ':00';
        return new DateTime($date . ' ' . $time);
    }
    if ($dateTimeCol && isset($row[$dateTimeCol])) {
        return new DateTime((string)$row[$dateTimeCol]);
    }
    return null;
}

function round_datetime_nearest_minutes(DateTime $dt, $minutes = 15) {
    $seconds = $minutes * 60;
    $ts = $dt->getTimestamp();
    $rounded = (int)(floor(($ts + ($seconds / 2)) / $seconds) * $seconds);
    $out = clone $dt;
    $out->setTimestamp($rounded);
    return $out;
}

function hours_between(DateTime $start, DateTime $end) {
    $seconds = $end->getTimestamp() - $start->getTimestamp();
    if ($seconds < 0) $seconds += 24 * 60 * 60;
    return round($seconds / 3600, 2);
}

function money($v) { return round((float)$v, 2); }
function hours2($v) { return round((float)$v, 2); }
function time_str(DateTime $dt) { return $dt->format('H:i:s'); }
function date_str(DateTime $dt) { return $dt->format('Y-m-d'); }

$weekStart = valid_date_or_fail(param('week_start', date('Y-m-d', strtotime('monday this week'))), 'week_start');
$weekEnd = valid_date_or_fail(param('week_end', date('Y-m-d', strtotime($weekStart . ' +6 days'))), 'week_end');
$clientId = param('client_id');
$storeId = param('store_id');
$employeeId = param('employee_id');
$debug = param('debug') === '1' || strtolower((string)param('debug')) === 'true';

$dbDebug = [];
$db = resolve_db_connection($dbDebug);
if (!$db) {
    error_json(
        'Database connection not found. v7 tried common include names and parent folders. Add/rename your DB include to db.php/config.php/connection.php/database.php/connect.php near the api folder, or paste your existing DB connection file so I can lock the include path exactly.',
        ['db_debug' => $debug ? $dbDebug : ['api_dir' => __DIR__, 'hint' => 'Run with &debug=1 to see include paths tried.']],
        500
    );
}

try {
    if (!table_exists($db, 'employee_logs')) {
        error_json('employee_logs table not found.', ['db_type' => db_type($db)], 500);
    }

    $logCols = table_columns($db, 'employee_logs');
    $empCols = table_exists($db, 'employees') ? table_columns($db, 'employees') : [];
    $storeCols = table_exists($db, 'stores') ? table_columns($db, 'stores') : [];

    $logIdCol = pick_col($logCols, ['id', 'log_id', 'employee_log_id']);
    $logEmpIdCol = pick_col($logCols, ['employee_id', 'user_id', 'staff_id']);
    $logEmpNameCol = pick_col($logCols, ['user_name', 'employee_name', 'name', 'user', 'staff_name']);
    $logStoreIdCol = pick_col($logCols, ['store_id', 'shop_id', 'branch_id']);
    $logStoreNameCol = pick_col($logCols, ['store_name', 'store', 'shop_name', 'branch_name']);
    $logTypeCol = pick_col($logCols, ['log_type', 'type', 'action', 'punch_type', 'status']);
    $logDateCol = pick_col($logCols, ['log_date', 'date', 'punch_date', 'entry_date']);
    $logTimeCol = pick_col($logCols, ['log_time', 'time', 'punch_time', 'entry_time']);
    $logDateTimeCol = pick_col($logCols, ['log_datetime', 'datetime', 'timestamp', 'created_at', 'logged_at', 'punch_datetime']);

    if (!$logTypeCol) error_json('Could not detect log type column in employee_logs.', ['employee_logs_columns' => $logCols], 500);
    if ((!$logDateCol || !$logTimeCol) && !$logDateTimeCol) {
        error_json('Could not detect date/time columns in employee_logs. Need log_date + log_time, or a datetime column.', ['employee_logs_columns' => $logCols], 500);
    }
    if (!$logEmpIdCol && !$logEmpNameCol) {
        error_json('Could not detect employee identifier/name column in employee_logs.', ['employee_logs_columns' => $logCols], 500);
    }

    $empIdCol = pick_col($empCols, ['id', 'employee_id', 'user_id', 'staff_id']);
    $empNameCol = pick_col($empCols, ['name', 'employee_name', 'user_name', 'full_name', 'staff_name']);
    $empRateCol = pick_col($empCols, ['hourly_rate', 'rate', 'wage_rate', 'pay_rate', 'hour_rate']);

    $storeIdMainCol = pick_col($storeCols, ['id', 'store_id', 'shop_id', 'branch_id']);
    $storeNameMainCol = pick_col($storeCols, ['name', 'store_name', 'shop_name', 'branch_name']);

    $employees = [];
    if ($empCols && $empIdCol) {
        $empRows = db_all($db, "SELECT * FROM `employees`");
        foreach ($empRows as $e) {
            $id = (string)$e[$empIdCol];
            $employees[$id] = [
                'name' => $empNameCol && isset($e[$empNameCol]) ? (string)$e[$empNameCol] : ('Employee ' . $id),
                'hourly_rate' => $empRateCol && isset($e[$empRateCol]) && $e[$empRateCol] !== '' ? (float)$e[$empRateCol] : DEFAULT_HOURLY_RATE,
            ];
        }
    }

    $stores = [];
    if ($storeCols && $storeIdMainCol) {
        $storeRows = db_all($db, "SELECT * FROM `stores`");
        foreach ($storeRows as $s) {
            $id = (string)$s[$storeIdMainCol];
            $stores[$id] = $storeNameMainCol && isset($s[$storeNameMainCol]) ? (string)$s[$storeNameMainCol] : ('Store ' . $id);
        }
    }

    $rangeStart = $weekStart . ' 00:00:00';
    $rangeEndPlus = date('Y-m-d 23:59:59', strtotime($weekEnd . ' +1 day'));

    $where = [];
    $params = [];

    if ($logDateCol) {
        $where[] = qcol($logDateCol) . ' BETWEEN ? AND ?';
        $params[] = $weekStart;
        $params[] = date('Y-m-d', strtotime($weekEnd . ' +1 day'));
    } else {
        $where[] = qcol($logDateTimeCol) . ' BETWEEN ? AND ?';
        $params[] = $rangeStart;
        $params[] = $rangeEndPlus;
    }
    if ($employeeId && $logEmpIdCol) { $where[] = qcol($logEmpIdCol) . ' = ?'; $params[] = $employeeId; }
    if ($storeId && $logStoreIdCol) { $where[] = qcol($logStoreIdCol) . ' = ?'; $params[] = $storeId; }
    if ($clientId && in_array('client_id', $logCols, true)) { $where[] = '`client_id` = ?'; $params[] = $clientId; }

    $orderParts = [];
    if ($logEmpIdCol) $orderParts[] = qcol($logEmpIdCol);
    elseif ($logEmpNameCol) $orderParts[] = qcol($logEmpNameCol);
    if ($logDateCol) $orderParts[] = qcol($logDateCol);
    if ($logTimeCol) $orderParts[] = qcol($logTimeCol);
    elseif ($logDateTimeCol) $orderParts[] = qcol($logDateTimeCol);
    if ($logIdCol) $orderParts[] = qcol($logIdCol);

    $sql = 'SELECT * FROM `employee_logs` WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . implode(', ', $orderParts);
    $rawLogs = db_all($db, $sql, $params);

    $normalized = [];
    foreach ($rawLogs as $r) {
        $actual = parse_datetime_from_row($r, $logDateCol, $logTimeCol, $logDateTimeCol);
        if (!$actual) continue;

        $type = strtoupper(trim((string)$r[$logTypeCol]));
        if ($type !== 'IN' && $type !== 'OUT') {
            if (in_array($type, ['CLOCK IN', 'CLOCK_IN', 'CHECKIN', 'CHECK IN'], true)) $type = 'IN';
            elseif (in_array($type, ['CLOCK OUT', 'CLOCK_OUT', 'CHECKOUT', 'CHECK OUT'], true)) $type = 'OUT';
            else continue;
        }

        $eid = $logEmpIdCol && isset($r[$logEmpIdCol]) ? (string)$r[$logEmpIdCol] : null;
        $ename = null;
        $rate = DEFAULT_HOURLY_RATE;
        if ($eid !== null && isset($employees[$eid])) {
            $ename = $employees[$eid]['name'];
            $rate = (float)$employees[$eid]['hourly_rate'];
        }
        if (!$ename && $logEmpNameCol && isset($r[$logEmpNameCol])) $ename = (string)$r[$logEmpNameCol];
        if (!$ename) $ename = $eid !== null ? ('Employee ' . $eid) : 'Unknown Employee';

        // If employee_logs itself contains hourly rate, use it only when employee table did not provide a value.
        $logRateCol = pick_col(array_keys($r), ['hourly_rate', 'rate', 'pay_rate']);
        if ((!$eid || !isset($employees[$eid])) && $logRateCol && $r[$logRateCol] !== '') $rate = (float)$r[$logRateCol];

        $sid = $logStoreIdCol && isset($r[$logStoreIdCol]) ? (string)$r[$logStoreIdCol] : null;
        $sname = null;
        if ($sid !== null && isset($stores[$sid])) $sname = $stores[$sid];
        if (!$sname && $logStoreNameCol && isset($r[$logStoreNameCol])) $sname = (string)$r[$logStoreNameCol];
        if (!$sname) $sname = $sid !== null ? ('Store ' . $sid) : 'Unknown Store';

        $normalized[] = [
            'log_id' => $logIdCol && isset($r[$logIdCol]) ? (string)$r[$logIdCol] : null,
            'employee_key' => $eid !== null ? ('id:' . $eid) : ('name:' . strtolower($ename)),
            'employee_id' => $eid,
            'user_name' => $ename,
            'store_id' => $sid,
            'store_name' => $sname,
            'log_type' => $type,
            'actual_dt' => $actual,
            'hourly_rate' => $rate,
        ];
    }

    usort($normalized, function($a, $b) {
        $c = strcmp($a['employee_key'], $b['employee_key']);
        if ($c !== 0) return $c;
        $t = $a['actual_dt']->getTimestamp() <=> $b['actual_dt']->getTimestamp();
        if ($t !== 0) return $t;
        return strcmp((string)$a['log_id'], (string)$b['log_id']);
    });

    $weekStartDt = new DateTime($weekStart . ' 00:00:00');
    $weekEndDt = new DateTime($weekEnd . ' 23:59:59');

    $openIn = [];
    $rows = [];
    $warnings = [];

    foreach ($normalized as $log) {
        $key = $log['employee_key'];
        if ($log['log_type'] === 'IN') {
            if (isset($openIn[$key])) {
                $warnings[] = [
                    'type' => 'in_without_out_replaced',
                    'employee_id' => $log['employee_id'],
                    'user_name' => $log['user_name'],
                    'old_in_log_id' => $openIn[$key]['log_id'],
                    'old_in_time' => $openIn[$key]['actual_dt']->format('Y-m-d H:i:s'),
                    'new_in_log_id' => $log['log_id'],
                    'new_in_time' => $log['actual_dt']->format('Y-m-d H:i:s'),
                    'message' => 'Found another IN without matching OUT. Ignored previous IN and kept this IN, matching legacy Apps Script behavior.'
                ];
            }
            $openIn[$key] = $log;
            continue;
        }

        if ($log['log_type'] === 'OUT') {
            if (!isset($openIn[$key])) {
                $warnings[] = [
                    'type' => 'out_without_in',
                    'employee_id' => $log['employee_id'],
                    'user_name' => $log['user_name'],
                    'out_log_id' => $log['log_id'],
                    'out_time' => $log['actual_dt']->format('Y-m-d H:i:s'),
                    'message' => 'Ignored OUT because there was no previous unmatched IN.'
                ];
                continue;
            }

            $in = $openIn[$key];
            unset($openIn[$key]);

            // Include shifts by actual IN datetime within requested week.
            if ($in['actual_dt'] < $weekStartDt || $in['actual_dt'] > $weekEndDt) {
                continue;
            }

            $roundedIn = round_datetime_nearest_minutes($in['actual_dt'], ROUNDING_MINUTES);
            $roundedOut = round_datetime_nearest_minutes($log['actual_dt'], ROUNDING_MINUTES);
            $totalHours = hours_between($roundedIn, $roundedOut);
            $wage = money($totalHours * (float)$in['hourly_rate']);

            $note = '';
            if ($totalHours >= LONG_SHIFT_NOTE_HOURS) {
                $note = 'Long shift included; no automatic cap applied.';
            }

            $rows[] = [
                'employee_id' => $in['employee_id'],
                'store_id' => $in['store_id'],
                'USER_NAME' => $in['user_name'],
                'STORE_NAME' => $in['store_name'],
                'IN_DATE' => date_str($in['actual_dt']),
                'ACTUAL_IN_TIME' => time_str($in['actual_dt']),
                'ROUNDED_IN_TIME' => time_str($roundedIn),
                'OUT_DATE' => date_str($log['actual_dt']),
                'ACTUAL_OUT_TIME' => time_str($log['actual_dt']),
                'ROUNDED_OUT_TIME' => time_str($roundedOut),
                'TOTAL_HOURS' => hours2($totalHours),
                'HOURLY_RATE' => money($in['hourly_rate']),
                'WAGE' => $wage,
                'in_log_id' => $in['log_id'],
                'out_log_id' => $log['log_id'],
                'note' => $note,
            ];
        }
    }

    foreach ($openIn as $in) {
        if ($in['actual_dt'] >= $weekStartDt && $in['actual_dt'] <= $weekEndDt) {
            $warnings[] = [
                'type' => 'in_without_out',
                'employee_id' => $in['employee_id'],
                'user_name' => $in['user_name'],
                'in_log_id' => $in['log_id'],
                'in_time' => $in['actual_dt']->format('Y-m-d H:i:s'),
                'message' => 'Unmatched IN at end of query window; not counted.'
            ];
        }
    }

    usort($rows, function($a, $b) {
        $c = strcasecmp($a['USER_NAME'], $b['USER_NAME']);
        if ($c !== 0) return $c;
        $d = strcmp($a['IN_DATE'] . ' ' . $a['ACTUAL_IN_TIME'], $b['IN_DATE'] . ' ' . $b['ACTUAL_IN_TIME']);
        if ($d !== 0) return $d;
        return strcasecmp($a['STORE_NAME'], $b['STORE_NAME']);
    });

    $employeeAgg = [];
    $storeAgg = [];
    foreach ($rows as $r) {
        $ek = $r['employee_id'] !== null ? (string)$r['employee_id'] : strtolower($r['USER_NAME']);
        if (!isset($employeeAgg[$ek])) {
            $employeeAgg[$ek] = [
                'employee_id' => $r['employee_id'],
                'employee_name' => $r['USER_NAME'],
                'total_hours' => 0.0,
                'total_wage' => 0.0,
                'hourly_rate' => $r['HOURLY_RATE'],
                'shift_count' => 0,
            ];
        }
        $employeeAgg[$ek]['total_hours'] += (float)$r['TOTAL_HOURS'];
        $employeeAgg[$ek]['total_wage'] += (float)$r['WAGE'];
        $employeeAgg[$ek]['shift_count']++;

        $sk = $r['store_id'] !== null ? (string)$r['store_id'] : strtolower($r['STORE_NAME']);
        if (!isset($storeAgg[$sk])) {
            $storeAgg[$sk] = [
                'store_id' => $r['store_id'],
                'store_name' => $r['STORE_NAME'],
                'employees' => [],
                'total_hours' => 0.0,
                'total_amount' => 0.0,
                'shift_count' => 0,
            ];
        }
        $storeAgg[$sk]['employees'][$ek] = true;
        $storeAgg[$sk]['total_hours'] += (float)$r['TOTAL_HOURS'];
        $storeAgg[$sk]['total_amount'] += (float)$r['WAGE'];
        $storeAgg[$sk]['shift_count']++;
    }

    $employeeSummary = array_values(array_map(function($e) {
        $e['total_hours'] = hours2($e['total_hours']);
        $e['total_wage'] = money($e['total_wage']);
        $e['hourly_rate'] = money($e['hourly_rate']);
        return $e;
    }, $employeeAgg));
    usort($employeeSummary, fn($a, $b) => strcasecmp($a['employee_name'], $b['employee_name']));

    $storeSummary = array_values(array_map(function($s) {
        $s['total_employees_worked'] = count($s['employees']);
        unset($s['employees']);
        $s['total_hours_worked'] = hours2($s['total_hours']);
        $s['total_amount'] = money($s['total_amount']);
        unset($s['total_hours']);
        return $s;
    }, $storeAgg));
    usort($storeSummary, fn($a, $b) => strcasecmp($a['store_name'], $b['store_name']));

    $employeeWise = [];
    foreach ($employeeSummary as $es) {
        $ek = $es['employee_id'] !== null ? (string)$es['employee_id'] : strtolower($es['employee_name']);
        $detail = array_values(array_filter($rows, function($r) use ($es, $ek) {
            $rk = $r['employee_id'] !== null ? (string)$r['employee_id'] : strtolower($r['USER_NAME']);
            return $rk === $ek;
        }));
        $employeeWise[] = [
            'employee_id' => $es['employee_id'],
            'employee_name' => $es['employee_name'],
            'hourly_rate' => $es['hourly_rate'],
            'rows' => $detail,
            'total_hours' => $es['total_hours'],
            'total_wage' => $es['total_wage'],
            'footer_hours_label' => 'Total Hours for ' . $es['employee_name'],
            'footer_wage_label' => $es['employee_name'] . "'s Wage @ " . rtrim(rtrim(number_format($es['hourly_rate'], 2, '.', ''), '0'), '.') . '/hour',
        ];
    }

    $totalPayableHours = 0.0;
    $totalWage = 0.0;
    foreach ($employeeSummary as $e) {
        $totalPayableHours += (float)$e['total_hours'];
        $totalWage += (float)$e['total_wage'];
    }

    $response = [
        'success' => true,
        'api' => 'get_timesheet.php',
        'version' => API_VERSION,
        'logic' => [
            'pairing' => 'Pair each IN to the next OUT for the same employee.',
            'duplicate_in' => 'If another IN appears before OUT, ignore the previous unmatched IN and keep the latest IN, matching legacy Apps Script behavior.',
            'out_without_in' => 'Ignored.',
            'rounding' => 'Round IN and OUT independently to nearest 15 minutes before calculating payable hours.',
            'payable_hours' => 'Calculated from rounded_out - rounded_in.',
            'cross_midnight' => 'Allowed.',
            'long_shift_cap' => 'No automatic cap. Long shifts are included and only noted.',
            'wage' => 'payable_hours × employees.hourly_rate, fallback 18 only if rate is missing.',
        ],
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'scope' => [
            'client_id' => $clientId,
            'store_id' => $storeId,
            'employee_id' => $employeeId,
        ],
        'summary' => [
            'employee_count' => count($employeeSummary),
            'store_count' => count($storeSummary),
            'shift_count' => count($rows),
            'raw_log_count_in_query_window' => count($rawLogs),
            'paired_log_count' => count($rows) * 2,
            'total_payable_hours' => hours2($totalPayableHours),
            'total_wage' => money($totalWage),
            'warning_count' => count($warnings),
        ],
        'store_summary' => $storeSummary,
        'employee_summary' => $employeeSummary,
        'employee_wise_detailed_report' => $employeeWise,
        'detailed_rows' => $rows,
        'warnings' => $warnings,
    ];

    if ($debug) {
        $response['debug'] = [
            'db_type' => db_type($db),
            'db_debug' => $dbDebug,
            'employee_logs_columns' => $logCols,
            'employees_columns' => $empCols,
            'stores_columns' => $storeCols,
            'detected_columns' => [
                'log_id' => $logIdCol,
                'log_employee_id' => $logEmpIdCol,
                'log_employee_name' => $logEmpNameCol,
                'log_store_id' => $logStoreIdCol,
                'log_store_name' => $logStoreNameCol,
                'log_type' => $logTypeCol,
                'log_date' => $logDateCol,
                'log_time' => $logTimeCol,
                'log_datetime' => $logDateTimeCol,
                'employee_id' => $empIdCol,
                'employee_name' => $empNameCol,
                'employee_hourly_rate' => $empRateCol,
                'store_id' => $storeIdMainCol,
                'store_name' => $storeNameMainCol,
            ],
            'sql' => $sql,
            'params' => $params,
        ];
    }

    respond_json($response);
} catch (Throwable $e) {
    error_json('Timesheet generation failed: ' . $e->getMessage(), $debug ? ['trace' => $e->getTraceAsString()] : [], 500);
}
