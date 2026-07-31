<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sheets.php';

date_default_timezone_set(APP_TIMEZONE);

function parse_date_value(string $date): ?DateTimeImmutable
{
    $date = trim($date);
    if ($date === '') return null;

    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $date);
        if ($dt && $dt->format($format) === $date) return $dt;
    }

    try {
        return new DateTimeImmutable($date);
    } catch (Exception $e) {
        return null;
    }
}

function monday_of_week(?string $date = null): string
{
    $dt = $date ? new DateTimeImmutable($date) : new DateTimeImmutable('now');
    return $dt->modify('monday this week')->format('Y-m-d');
}

function week_label(string $weekStart): string
{
    $start = new DateTimeImmutable($weekStart);
    $end = $start->modify('+6 days');
    if ($start->format('M Y') === $end->format('M Y')) {
        return $start->format('d M') . ' - ' . $end->format('d M Y');
    }
    return $start->format('d M Y') . ' - ' . $end->format('d M Y');
}

function rounded_time_15(string $time): string
{
    $parts = explode(':', trim($time));
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);
    $total = ($h * 60) + $m + ($s / 60);
    $rounded = (int) round($total / 15) * 15;
    $rh = intdiv($rounded, 60);
    $rm = $rounded % 60;
    if ($rh >= 24) $rh = $rh % 24;
    return str_pad((string)$rh, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$rm, 2, '0', STR_PAD_LEFT) . ':00';
}

function minutes_from_time(string $time): int
{
    $parts = explode(':', trim($time));
    return ((int)($parts[0] ?? 0) * 60) + (int)($parts[1] ?? 0);
}

function total_hours_between(string $roundedIn, string $roundedOut): float
{
    $in = minutes_from_time($roundedIn);
    $out = minutes_from_time($roundedOut);
    $diff = $out - $in;
    if ($diff < 0) $diff += 24 * 60;
    return $diff / 60;
}

function build_payrate_map(array $payrateRows): array
{
    $map = [];
    foreach ($payrateRows as $row) {
        $name = get_field($row, ['NAME', 'USER_NAME', 'Employee Name', 'Employee'], 0);
        $rate = get_field($row, ['PAY_RATE', 'Pay Rate', 'Rate'], 1);
        if ($name !== '' && is_numeric($rate)) {
            $map[strtolower($name)] = (float)$rate;
        }
    }
    return $map;
}


function build_employee_user_id_map(array $employeeSetupRows): array
{
    $map = [];
    foreach ($employeeSetupRows as $row) {
        $name = get_field($row, ['USER_NAME', 'User Name', 'Employee Name', 'NAME', 'Name'], EMPLOYEE_COL_NAME);
        $userId = get_field($row, ['USER_ID', 'User ID', 'UserID', 'ID'], EMPLOYEE_COL_USER_ID);
        if ($name !== '') {
            $map[strtolower($name)] = $userId;
        }
    }
    return $map;
}

function build_start_time_map(array $startRows): array
{
    $map = [];
    foreach ($startRows as $row) {
        $store = get_field($row, ['Store Name', 'STORE_NAME', 'Store'], 0);
        $time = get_field($row, ['Shift Start Time', 'START_TIME', 'Start Time'], 1);
        if ($store !== '' && $time !== '') {
            $map[strtolower($store)] = $time;
        }
    }
    return $map;
}

function is_late_entry(string $store, string $actualInTime, array $startTimeMap): bool
{
    $start = $startTimeMap[strtolower($store)] ?? '';
    if ($start === '' || $actualInTime === '') return false;
    $diff = minutes_from_time($actualInTime) - minutes_from_time($start);
    return $diff > 10 && $diff < 210;
}

function sort_timesheet_rows(array $rows): array
{
    usort($rows, function ($a, $b) {
        $ad = get_field($a, ['DATE', 'Date'], -1);
        $bd = get_field($b, ['DATE', 'Date'], -1);
        $at = get_field($a, ['TIME', 'Time'], -1);
        $bt = get_field($b, ['TIME', 'Time'], -1);
        $ak = $ad . ' ' . $at;
        $bk = $bd . ' ' . $bt;
        return strcmp($ak, $bk);
    });
    return $rows;
}

function build_shift_rows(array $timesheetRows, array $startTimeMap, string $weekStart, ?string $employeeFilter = null): array
{
    $weekStartDt = new DateTimeImmutable($weekStart);
    $weekEndDt = $weekStartDt->modify('+6 days');
    $groups = [];

    foreach ($timesheetRows as $row) {
        $user = get_field($row, ['USER_NAME', 'User Name', 'Employee Name', 'NAME'], 0);
        $store = get_field($row, ['STORE_NAME', 'Store Name', 'Store'], 1);
        $log = strtoupper(get_field($row, ['LOG_TYPE', 'Log Type', 'Type'], 2));
        $date = get_field($row, ['DATE', 'Date'], 3);
        $time = get_field($row, ['TIME', 'Time'], 4);

        if ($user === '' || $store === '' || $log === '' || $date === '' || $time === '') continue;
        if ($employeeFilter !== null && strcasecmp($user, $employeeFilter) !== 0) continue;

        $key = strtolower($user) . '||' . strtolower($store);
        $groups[$key][] = [
            'user_name' => $user,
            'store_name' => $store,
            'log_type' => $log,
            'date' => normalize_date_string($date),
            'time' => normalize_time_string($time),
        ];
    }

    $shifts = [];
    foreach ($groups as $items) {
        usort($items, fn($a, $b) => strcmp($a['date'] . ' ' . $a['time'], $b['date'] . ' ' . $b['time']));
        $lastIn = null;
        foreach ($items as $item) {
            if ($item['log_type'] === 'IN') {
                $lastIn = $item;
            } elseif ($item['log_type'] === 'OUT' && $lastIn !== null) {
                $inDt = parse_date_value($lastIn['date']);
                if ($inDt && $inDt >= $weekStartDt && $inDt <= $weekEndDt) {
                    $roundedIn = rounded_time_15($lastIn['time']);
                    $roundedOut = rounded_time_15($item['time']);
                    $hours = total_hours_between($roundedIn, $roundedOut);
                    $shifts[] = [
                        'user_name' => $lastIn['user_name'],
                        'store_name' => $lastIn['store_name'],
                        'in_date' => $lastIn['date'],
                        'actual_in_time' => $lastIn['time'],
                        'rounded_in_time' => $roundedIn,
                        'out_date' => $item['date'],
                        'actual_out_time' => $item['time'],
                        'rounded_out_time' => $roundedOut,
                        'total_hours' => round($hours, 2),
                        'is_late' => is_late_entry($lastIn['store_name'], $lastIn['time'], $startTimeMap),
                    ];
                }
                $lastIn = null;
            }
        }
    }

    usort($shifts, function ($a, $b) {
        $c = strcasecmp($a['user_name'], $b['user_name']);
        if ($c !== 0) return $c;
        return strcmp($a['in_date'] . ' ' . $a['actual_in_time'], $b['in_date'] . ' ' . $b['actual_in_time']);
    });
    return $shifts;
}

function normalize_date_string(string $date): string
{
    $dt = parse_date_value($date);
    return $dt ? $dt->format('Y-m-d') : trim($date);
}

function normalize_time_string(string $time): string
{
    $time = trim($time);
    $parts = explode(':', $time);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function build_report(array $sourceData, string $weekStart, ?string $employeeFilter, bool $isSuper): array
{
    $payRateMap = build_payrate_map($sourceData['payrate']);
    $employeeUserIdMap = build_employee_user_id_map($sourceData['employee_setup']);
    $startTimeMap = build_start_time_map($sourceData['start_time']);
    $shifts = build_shift_rows($sourceData['timesheet'], $startTimeMap, $weekStart, $employeeFilter);

    $employees = [];
    $storeSummary = [];

    foreach ($shifts as $shift) {
        $name = $shift['user_name'];
        if (!isset($employees[$name])) {
            $employees[$name] = [
                'employee_name' => $name,
                'user_id' => $employeeUserIdMap[strtolower($name)] ?? '',
                'pay_rate' => $payRateMap[strtolower($name)] ?? null,
                'rows' => [],
                'total_hours' => 0,
                'total_wage' => 0,
                'missing_pay_rate' => !array_key_exists(strtolower($name), $payRateMap),
            ];
        }
        $employees[$name]['rows'][] = $shift;
        $employees[$name]['total_hours'] += $shift['total_hours'];

        $rate = $employees[$name]['pay_rate'];
        $amount = is_numeric($rate) ? $shift['total_hours'] * (float)$rate : 0;
        $employees[$name]['total_wage'] += $amount;

        if ($isSuper) {
            $store = $shift['store_name'];
            if (!isset($storeSummary[$store])) {
                $storeSummary[$store] = [
                    'store_name' => $store,
                    'employees' => [],
                    'total_hours' => 0,
                    'total_amount' => 0,
                ];
            }
            $storeSummary[$store]['employees'][$name] = true;
            $storeSummary[$store]['total_hours'] += $shift['total_hours'];
            $storeSummary[$store]['total_amount'] += $amount;
        }
    }

    ksort($employees, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($storeSummary, SORT_NATURAL | SORT_FLAG_CASE);

    $employeeSummary = [];
    $grandHours = 0;
    $grandWage = 0;
    foreach ($employees as &$emp) {
        $emp['total_hours'] = round($emp['total_hours'], 2);
        $emp['total_wage'] = round($emp['total_wage'], 2);
        $grandHours += $emp['total_hours'];
        $grandWage += $emp['total_wage'];
        $employeeSummary[] = [
            'employee_name' => $emp['employee_name'],
            'user_id' => $emp['user_id'] ?? '',
            'total_hours' => $emp['total_hours'],
            'total_wage' => $emp['total_wage'],
            'missing_pay_rate' => $emp['missing_pay_rate'],
        ];
    }
    unset($emp);

    $storeSummaryOut = [];
    foreach ($storeSummary as $store => $s) {
        $storeSummaryOut[] = [
            'store_name' => $store,
            'total_employees_worked' => count($s['employees']),
            'total_hours_worked' => round($s['total_hours'], 2),
            'total_amount' => round($s['total_amount'], 2),
        ];
    }

    return [
        'week_start' => $weekStart,
        'week_end' => (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d'),
        'week_label' => week_label($weekStart),
        'is_super' => $isSuper,
        'show_wages' => SHOW_WAGES_TO_EMPLOYEES || $isSuper,
        'store_summary' => $storeSummaryOut,
        'employee_summary' => $employeeSummary,
        'employees' => array_values($employees),
        'grand_total_hours' => round($grandHours, 2),
        'grand_total_wage' => round($grandWage, 2),
    ];
}

function available_weeks(array $timesheetRows, ?string $employeeFilter = null): array
{
    $weeks = [];
    $current = monday_of_week();
    $weeks[$current] = week_label($current);

    foreach ($timesheetRows as $row) {
        $user = get_field($row, ['USER_NAME', 'User Name', 'Employee Name', 'NAME'], 0);
        if ($employeeFilter !== null && strcasecmp($user, $employeeFilter) !== 0) continue;
        $log = strtoupper(get_field($row, ['LOG_TYPE', 'Log Type', 'Type'], 2));
        if ($log !== 'IN') continue;
        $date = normalize_date_string(get_field($row, ['DATE', 'Date'], 3));
        $dt = parse_date_value($date);
        if (!$dt) continue;
        $monday = $dt->modify('monday this week')->format('Y-m-d');
        $weeks[$monday] = week_label($monday);
    }

    krsort($weeks);
    $out = [];
    foreach ($weeks as $value => $label) {
        $out[] = ['value' => $value, 'label' => $label];
    }
    return $out;
}

function find_login_user(array $employeeSetupRows, string $userId, string $password): ?array
{
    $userId = trim($userId);
    $password = trim($password);

    foreach ($employeeSetupRows as $row) {
        $name = get_field($row, ['USER_NAME', 'User Name', 'Employee Name', 'NAME', 'Name'], EMPLOYEE_COL_NAME);
        $role = get_field($row, ['ROLE', 'Role', 'Access', 'User Type', 'TYPE', 'Type'], EMPLOYEE_COL_ROLE);
        $uid = get_field($row, ['USER_ID', 'User ID', 'UserID', 'ID'], EMPLOYEE_COL_USER_ID);
        $pwd = get_field($row, ['PASSWORD', 'Password', 'PIN', 'Passcode'], EMPLOYEE_COL_PASSWORD);

        if ($uid !== '' && $pwd !== '' && hash_equals($uid, $userId) && hash_equals($pwd, $password)) {
            $isSuper = strtoupper(trim($role)) === SUPER_ROLE_VALUE;
            return [
                'name' => $name !== '' ? $name : $uid,
                'user_id' => $uid,
                'role' => $role,
                'is_super' => $isSuper,
            ];
        }
    }
    return null;
}
