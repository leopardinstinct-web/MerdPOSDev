<?php
require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/timesheet_logic.php';

$user = beta_require_active_user();
$clientId = (int)$user['client_id'];
$weekStart = $_GET['week_start'] ?? monday_of_week();
$weekStart = monday_of_week($weekStart);

function sql_source_data(PDO $pdo, int $clientId): array
{
    $timesheetRows = [];
    $stmt = $pdo->prepare(
        'SELECT user_name, store_name, log_type, log_date, log_time '
        . 'FROM employee_logs WHERE client_id=? ORDER BY log_datetime, id'
    );
    $stmt->execute([$clientId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $timesheetRows[] = [
            'USER_NAME' => (string)$row['user_name'],
            'STORE_NAME' => (string)$row['store_name'],
            'LOG_TYPE' => (string)$row['log_type'],
            'DATE' => (string)$row['log_date'],
            'TIME' => (string)$row['log_time'],
            '_raw' => [
                (string)$row['user_name'],
                (string)$row['store_name'],
                (string)$row['log_type'],
                (string)$row['log_date'],
                (string)$row['log_time'],
            ],
        ];
    }

    $storeOrder = [];
    $storeStmt = $pdo->prepare('SELECT id,store_name FROM stores WHERE client_id=? ORDER BY id ASC');
    $storeStmt->execute([$clientId]);
    foreach ($storeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $storeOrder[strtolower(trim((string)$row['store_name']))] = (int)$row['id'];
    }

    $payrateRows = [];
    $employeeSetupRows = [];
    $stmt = $pdo->prepare(
        'SELECT full_name,user_id,employee_type,status,store_id,hourly_rate FROM employees WHERE client_id=? ORDER BY id ASC'
    );
    $stmt->execute([$clientId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rate = is_numeric($row['hourly_rate']) ? (string)(float)$row['hourly_rate'] : '';
        $payrateRows[] = [
            'NAME' => (string)$row['full_name'],
            'PAY_RATE' => $rate,
            '_raw' => [(string)$row['full_name'], $rate],
        ];
        $employeeSetupRows[] = [
            'NAME' => (string)$row['full_name'],
            'TYPE' => (string)$row['employee_type'],
            'USER_ID' => (string)$row['user_id'],
            'STATUS' => strtoupper((string)$row['status']),
            'PAY_RATE' => $rate,
            '_raw' => [(string)$row['full_name'], (string)$row['employee_type'], (string)$row['user_id'], '', strtoupper((string)$row['status']), '', $rate],
        ];
    }

    $startRows = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT t.store_name,t.shift_start_time FROM store_shift_start_times t '
            . 'INNER JOIN stores s ON s.id=t.store_id AND s.client_id=t.client_id '
            . 'WHERE t.client_id=? ORDER BY s.id ASC'
        );
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $startRows[] = [
                'Store Name' => (string)$row['store_name'],
                'Shift Start Time' => (string)$row['shift_start_time'],
                '_raw' => [(string)$row['store_name'], (string)$row['shift_start_time']],
            ];
        }
    } catch (Throwable $e) {
        if ($clientId === 1) {
            $fallback = [
                ['Rosebay Tobacco', '06:00:00'],
                ['Enmore Tobacco', '07:00:00'],
                ['Marrickville Xpress', '07:00:00'],
                ['Double Bay Tobacco', '07:00:00'],
            ];
            foreach ($fallback as $row) {
                $startRows[] = ['Store Name' => $row[0], 'Shift Start Time' => $row[1], '_raw' => $row];
            }
        }
    }

    $rateHistory = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT e.full_name,h.hourly_rate,h.effective_from '
            . 'FROM employee_hourly_rate_history h INNER JOIN employees e ON e.id=h.employee_id AND e.client_id=h.client_id '
            . 'WHERE h.client_id=? ORDER BY e.id,h.effective_from,h.id'
        );
        $stmt->execute([$clientId]);
        $rateHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rateHistory = [];
    }

    $weeklyHours = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT s.store_name,h.day_of_week,h.start_time,h.end_time,h.is_closed '
            . 'FROM store_weekly_hours h INNER JOIN stores s ON s.id=h.store_id AND s.client_id=h.client_id '
            . 'WHERE h.client_id=? ORDER BY s.id,h.day_of_week'
        );
        $stmt->execute([$clientId]);
        $weeklyHours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $weeklyHours = [];
    }

    return [
        'timesheet' => $timesheetRows,
        'payrate' => $payrateRows,
        'start_time' => $startRows,
        'employee_setup' => $employeeSetupRows,
        'rate_history' => $rateHistory,
        'weekly_hours' => $weeklyHours,
        'store_order' => $storeOrder,
    ];
}

function build_rate_history_map(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        $name = strtolower(trim((string)($row['full_name'] ?? '')));
        $date = trim((string)($row['effective_from'] ?? ''));
        if ($name === '' || $date === '' || !is_numeric($row['hourly_rate'] ?? null)) continue;
        $map[$name][] = [
            'effective_from' => $date,
            'hourly_rate' => (float)$row['hourly_rate'],
        ];
    }
    return $map;
}

function resolve_shift_rate(array $historyMap, string $employeeName, string $shiftDate, mixed $fallback): ?float
{
    $rows = $historyMap[strtolower(trim($employeeName))] ?? [];
    $resolved = null;
    foreach ($rows as $row) {
        if ($row['effective_from'] <= $shiftDate) $resolved = (float)$row['hourly_rate'];
        else break;
    }
    if ($resolved !== null) return $resolved;
    return is_numeric($fallback) ? (float)$fallback : null;
}

function build_weekly_hours_map(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        $store = strtolower(trim((string)($row['store_name'] ?? '')));
        $day = (int)($row['day_of_week'] ?? 0);
        if ($store === '' || $day < 1 || $day > 7) continue;
        $map[$store][$day] = [
            'start_time' => (string)($row['start_time'] ?? ''),
            'end_time' => (string)($row['end_time'] ?? ''),
            'is_closed' => (int)($row['is_closed'] ?? 0) === 1,
        ];
    }
    return $map;
}

function apply_schedule_and_effective_rates(array &$report, array $source): void
{
    $rateMap = build_rate_history_map($source['rate_history'] ?? []);
    $hoursMap = build_weekly_hours_map($source['weekly_hours'] ?? []);
    $storeTotals = [];
    $grandWage = 0.0;

    foreach ($report['employees'] as &$employee) {
        $employeeWage = 0.0;
        $ratesUsed = [];
        foreach ($employee['rows'] as &$row) {
            $storeKey = strtolower(trim((string)$row['store_name']));
            try {
                $weekday = (int)(new DateTimeImmutable((string)$row['in_date']))->format('N');
            } catch (Throwable $e) {
                $weekday = 0;
            }
            if ($weekday >= 1 && isset($hoursMap[$storeKey][$weekday])) {
                $schedule = $hoursMap[$storeKey][$weekday];
                if ($schedule['is_closed'] || $schedule['start_time'] === '') {
                    $row['is_late'] = false;
                } else {
                    $diff = minutes_from_time((string)$row['actual_in_time']) - minutes_from_time((string)$schedule['start_time']);
                    $row['is_late'] = $diff > 10 && $diff < 210;
                }
                $row['scheduled_start_time'] = $schedule['start_time'];
                $row['scheduled_end_time'] = $schedule['end_time'];
            }

            $rate = resolve_shift_rate(
                $rateMap,
                (string)$employee['employee_name'],
                (string)$row['in_date'],
                $employee['pay_rate'] ?? null
            );
            $row['applied_rate'] = $rate;
            $row['wage'] = $rate === null ? 0.0 : round((float)$row['total_hours'] * $rate, 2);
            $employeeWage += (float)$row['wage'];
            if ($rate !== null) $ratesUsed[number_format($rate, 2, '.', '')] = $rate;

            $store = (string)$row['store_name'];
            if (!isset($storeTotals[$store])) {
                $storeTotals[$store] = [
                    'employees' => [],
                    'total_hours_worked' => 0.0,
                    'total_amount' => 0.0,
                ];
            }
            $storeTotals[$store]['employees'][(string)$employee['employee_name']] = true;
            $storeTotals[$store]['total_hours_worked'] += (float)$row['total_hours'];
            $storeTotals[$store]['total_amount'] += (float)$row['wage'];
        }
        unset($row);
        $employee['total_wage'] = round($employeeWage, 2);
        $employee['pay_rate_varies'] = count($ratesUsed) > 1;
        $employee['rates_used'] = array_values($ratesUsed);
        $grandWage += $employeeWage;
    }
    unset($employee);

    $wageByEmployee = [];
    foreach ($report['employees'] as $employee) {
        $wageByEmployee[strtolower((string)$employee['employee_name'])] = (float)$employee['total_wage'];
    }
    foreach ($report['employee_summary'] as &$summary) {
        $key = strtolower((string)$summary['employee_name']);
        if (isset($wageByEmployee[$key])) $summary['total_wage'] = round($wageByEmployee[$key], 2);
    }
    unset($summary);

    if (!empty($report['is_super'])) {
        $order = $source['store_order'] ?? [];
        uksort($storeTotals, function (string $a, string $b) use ($order): int {
            $ai = $order[strtolower(trim($a))] ?? PHP_INT_MAX;
            $bi = $order[strtolower(trim($b))] ?? PHP_INT_MAX;
            if ($ai === $bi) return strcasecmp($a, $b);
            return $ai <=> $bi;
        });
        $report['store_summary'] = [];
        foreach ($storeTotals as $store => $totals) {
            $report['store_summary'][] = [
                'store_name' => $store,
                'total_employees_worked' => count($totals['employees']),
                'total_hours_worked' => round($totals['total_hours_worked'], 2),
                'total_amount' => round($totals['total_amount'], 2),
            ];
        }
    }

    $report['grand_total_wage'] = round($grandWage, 2);
    $report['rate_source'] = 'effective_dated_employee_hourly_rate_history';
    $report['schedule_source'] = !empty($source['weekly_hours']) ? 'store_weekly_hours' : 'legacy_store_shift_start_times';
}

try {
    $pdo = portal_db();
    $source = sql_source_data($pdo, $clientId);
    $employeeFilter = $user['is_super'] ? null : $user['name'];
    $report = build_report($source, $weekStart, $employeeFilter, (bool)$user['is_super']);
    apply_schedule_and_effective_rates($report, $source);
    $report['source'] = 'sql_employee_logs';
    $report['client_id'] = $clientId;
    json_response(['success' => true, 'report' => $report]);
} catch (Throwable $e) {
    error_log('MERDPOS timesheet generation failed: ' . get_class($e));
    json_response(['success' => false, 'error' => 'The timesheet could not be generated.'], 500);
}
