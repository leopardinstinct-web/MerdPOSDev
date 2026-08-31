<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/legacy_migration.php';
require_once __DIR__ . '/../includes/legacy_known_fetch.php';

const MERD_GOOGLE_TIMESHEET_REFRESH_DEVICE = 'GOOGLE-TIMESHEET-REFRESH';

function timesheet_refresh_source(PDO $pdo, int $clientId): array
{
    $sources = legacy_source_state($pdo, $clientId);
    $attendance = $sources['attendance'] ?? null;
    if (!is_array($attendance) || ($attendance['status'] ?? '') !== 'active') {
        throw new MerdWorkforceException('attendance_source_missing', 'This Working client has no active Google attendance source.');
    }
    if (($attendance['provider'] ?? '') !== 'google_public_csv') {
        throw new MerdWorkforceException('attendance_source_invalid', 'The Working client attendance source is not a Google public CSV source.');
    }
    $sheetName = trim((string)($attendance['sheet_names']['timesheet'] ?? ''));
    if (legacy_norm($sheetName) !== 'timesheet') {
        throw new MerdWorkforceException('timesheet_source_invalid', 'The Working client attendance source is not mapped to the Time Sheet worksheet.');
    }

    $state = $pdo->prepare('SELECT attendance_authority FROM client_migration_state WHERE client_id=? LIMIT 1');
    $state->execute([$clientId]);
    $authority = strtolower(trim((string)($state->fetchColumn() ?: 'google_legacy')));
    if ($authority === 'merdpos_sql') {
        throw new MerdWorkforceException('timesheet_refresh_retired', 'This Working client is already SQL-authoritative. The pre-live Google Time Sheet refresh is disabled.');
    }
    $lineage = $pdo->prepare("SELECT COUNT(*) FROM legacy_migration_records WHERE client_id=? AND source_type='attendance_log' AND status='active'");
    $lineage->execute([$clientId]);
    if ((int)$lineage->fetchColumn() > 0) {
        throw new MerdWorkforceException('timesheet_refresh_lineage_exists', 'This Working client already has formal attendance migration lineage. Use the migration workflow instead of destructive refresh.');
    }
    return [$attendance, $sheetName];
}

function timesheet_refresh_prepare_rows(PDO $pdo, int $clientId, array $rows): array
{
    if (!$rows) {
        throw new MerdWorkforceException('timesheet_source_empty', 'Google Time Sheet is empty. SQL was not changed.');
    }

    $maps = legacy_identity_maps($pdo, $clientId);
    $prepared = [];
    $errors = [];
    $unmatchedEmployees = [];

    foreach ($rows as $row) {
        $sourceRow = (int)($row['_source_row'] ?? 0);
        $userName = legacy_field($row, ['USER_NAME', 'User Name', 'Employee Name', 'NAME']);
        $storeName = legacy_field($row, ['STORE_NAME', 'Store Name', 'Store']);
        $logType = strtoupper(legacy_field($row, ['LOG_TYPE', 'Log Type', 'Type']));
        $logDate = legacy_date(legacy_field($row, ['DATE', 'Date']));
        $logTime = legacy_time(legacy_field($row, ['TIME', 'Time']));

        if ($userName === '' || $storeName === '' || !in_array($logType, ['IN', 'OUT'], true) || $logDate === null || $logTime === null) {
            $errors[] = 'row ' . $sourceRow . ' has invalid employee/store/log/date/time data';
            continue;
        }

        $storeMatch = legacy_match_store($maps, $storeName);
        if (($storeMatch['status'] ?? '') !== 'matched') {
            $errors[] = 'row ' . $sourceRow . ' store "' . $storeName . '" cannot be mapped uniquely to this Working client';
            continue;
        }
        $employeeMatch = legacy_match_employee($maps, $userName);
        if (($employeeMatch['status'] ?? '') === 'conflict') {
            $errors[] = 'row ' . $sourceRow . ' employee "' . $userName . '" is ambiguous in this Working client';
            continue;
        }
        $employeeId = ($employeeMatch['status'] ?? '') === 'matched' ? (int)$employeeMatch['row']['id'] : null;
        if ($employeeId === null) $unmatchedEmployees[$userName] = true;

        $store = $storeMatch['row'];
        $logDateTime = $logDate . ' ' . $logTime;
        $localLogId = 'google-ts-' . $sourceRow . '-' . substr(hash('sha256', implode('|', [
            (string)$clientId, $userName, $storeName, $logType, $logDateTime,
        ])), 0, 40);
        $prepared[] = [
            'employee_id' => $employeeId,
            'store_id' => (int)$store['id'],
            'user_name' => $userName,
            'store_name' => (string)$store['store_name'],
            'log_type' => $logType,
            'log_date' => $logDate,
            'log_time' => $logTime,
            'log_datetime' => $logDateTime,
            'local_log_id' => $localLogId,
        ];
    }

    if ($errors) {
        $preview = implode('; ', array_slice($errors, 0, 5));
        $more = count($errors) > 5 ? '; +' . (count($errors) - 5) . ' more' : '';
        throw new MerdWorkforceException('timesheet_refresh_validation_failed', 'Time Sheet validation failed before SQL replacement: ' . $preview . $more . '.');
    }

    return [$prepared, array_keys($unmatchedEmployees)];
}
function timesheet_refresh_replace(PDO $pdo, array $actor, int $clientId, string $sheetName, string $csv, array $prepared, array $unmatchedEmployees): array
{
    $delete = $pdo->prepare('DELETE FROM employee_logs WHERE client_id=?');
    $insert = $pdo->prepare(
        'INSERT INTO employee_logs '
        . '(client_id,store_id,employee_id,user_name,store_name,log_type,log_date,log_time,log_datetime,device_uuid,local_log_id) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $snapshotHash = hash('sha256', $csv);

    $pdo->beginTransaction();
    try {
        $delete->execute([$clientId]);
        $deleted = $delete->rowCount();
        foreach ($prepared as $item) {
            $insert->execute([
                $clientId,
                $item['store_id'],
                $item['employee_id'],
                $item['user_name'],
                $item['store_name'],
                $item['log_type'],
                $item['log_date'],
                $item['log_time'],
                $item['log_datetime'],
                MERD_GOOGLE_TIMESHEET_REFRESH_DEVICE,
                $item['local_log_id'],
            ]);
        }
        $audit = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $audit->execute([
            $clientId,
            (int)$actor['id'],
            'dev.timesheet_google_refresh',
            'client',
            (string)$clientId,
            json_encode([
                'sheet_name' => $sheetName,
                'deleted_rows' => $deleted,
                'inserted_rows' => count($prepared),
                'unmatched_employee_count' => count($unmatchedEmployees),
                'source_snapshot_hash' => $snapshotHash,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'deleted_rows' => (int)$deleted,
        'inserted_rows' => count($prepared),
        'unmatched_employees' => $unmatchedEmployees,
        'source_snapshot_hash' => $snapshotHash,
    ];
}
try {
    $user = beta_require_active_user();
    $pdo = portal_db();
    if (!beta_actual_user_is_dev($user)) {
        throw new MerdWorkforceException('forbidden', 'Only the actual DEV identity can refresh Google Time Sheet data.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['success' => false, 'error' => 'POST required.'], 405);
    }

    $input = request_input();
    require_csrf($input);
    if ((string)($input['action'] ?? '') !== 'refresh_timesheet') {
        json_response(['success' => false, 'error' => 'Unsupported Time Sheet refresh action.'], 400);
    }
    @set_time_limit(180);

    $clientId = (int)$user['client_id'];
    $requestedClientId = filter_var($input['client_id'] ?? null, FILTER_VALIDATE_INT);
    if ($requestedClientId === false || (int)$requestedClientId !== $clientId) {
        throw new MerdWorkforceException('stale_working_client', 'Working client changed before sync. Reload the account menu and try again.');
    }

    [$attendance, $sheetName] = timesheet_refresh_source($pdo, $clientId);
    $csv = legacy_google_fetch_tab((string)$attendance['spreadsheet_id'], $sheetName);
    $parsed = legacy_parse_known_csv_rows($csv, 'timesheet', $sheetName);
    [$prepared, $unmatchedEmployees] = timesheet_refresh_prepare_rows($pdo, $clientId, $parsed['rows']);
    $result = timesheet_refresh_replace($pdo, $user, $clientId, $sheetName, $csv, $prepared, $unmatchedEmployees);
    json_response([
        'success' => true,
        'client_id' => $clientId,
        'sheet_name' => $sheetName,
        'source_rows' => count($prepared),
        'deleted_rows' => $result['deleted_rows'],
        'inserted_rows' => $result['inserted_rows'],
        'unmatched_employee_count' => count($unmatchedEmployees),
        'source_snapshot_hash' => $result['source_snapshot_hash'],
        'message' => 'Time Sheet synced from Google.',
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
