<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function timings_actor(PDO $pdo, array $sessionUser): array
{
    $stmt = $pdo->prepare('SELECT id,client_id,full_name,employee_type,status FROM employees WHERE id=? AND client_id=? LIMIT 1');
    $stmt->execute([(int)$sessionUser['id'], (int)$sessionUser['client_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || strtolower((string)$row['status']) !== 'active') {
        throw new MerdWorkforceException('account_inactive', 'Your account is inactive.');
    }
    $role = strtoupper(trim((string)$row['employee_type']));
    if (!in_array($role, ['ADMIN', 'SUPER', 'DEV'], true)) {
        json_response(['success' => false, 'error' => 'ADMIN, SUPER or DEV access required.'], 403);
    }
    $row['employee_type'] = $role;
    return $row;
}

function timings_normalize_time(mixed $value): ?string
{
    $text = trim((string)$value);
    if ($text === '') return null;
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $text)) {
        throw new MerdWorkforceException('invalid_time', 'Use a valid 24-hour time.');
    }
    return strlen($text) === 5 ? $text . ':00' : $text;
}

function timings_load(PDO $pdo, int $clientId): array
{
    $storesStmt = $pdo->prepare(
        "SELECT id,store_name,status FROM stores WHERE client_id=? "
        . "ORDER BY CASE WHEN status='active' THEN 0 ELSE 1 END,store_name"
    );
    $storesStmt->execute([$clientId]);
    $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);

    $rowsStmt = $pdo->prepare(
        'SELECT store_id,day_of_week,start_time,end_time,is_closed '
        . 'FROM store_weekly_hours WHERE client_id=? ORDER BY store_id,day_of_week'
    );
    $rowsStmt->execute([$clientId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    return ['stores' => $stores, 'timings' => $rows];
}

function timings_audit(PDO $pdo, array $actor, array $storeIds, array $days): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) '
            . 'VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$actor['client_id'],
            (int)$actor['id'],
            'store_timings.update',
            'store_schedule',
            count($storeIds) === 1 ? (string)$storeIds[0] : 'all',
            json_encode(['store_ids' => $storeIds, 'days' => $days], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS store timing audit write failed: ' . get_class($e));
    }
}

try {
    $sessionUser = beta_require_active_user();
    $pdo = portal_db();
    $actor = timings_actor($pdo, $sessionUser);
    $clientId = (int)$actor['client_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $state = timings_load($pdo, $clientId);
        json_response([
            'success' => true,
            'csrf' => csrf_token(),
            'actor_role' => (string)$actor['employee_type'],
            'stores' => $state['stores'],
            'timings' => $state['timings'],
        ]);
    }

    $input = request_input();
    require_csrf($input);

    if ((string)($input['action'] ?? '') !== 'save_timings') {
        json_response(['success' => false, 'error' => 'Unsupported timing action.'], 400);
    }

    $scope = strtolower(trim((string)($input['scope'] ?? 'store')));
    if (!in_array($scope, ['store', 'all'], true)) {
        throw new MerdWorkforceException('invalid_scope', 'Choose one store or all active stores.');
    }

    $rawDays = $input['days'] ?? null;
    if (!is_array($rawDays)) {
        throw new MerdWorkforceException('invalid_schedule', 'A seven-day schedule is required.');
    }

    $days = [];
    foreach ($rawDays as $rawDay) {
        if (!is_array($rawDay)) continue;
        $day = filter_var($rawDay['day_of_week'] ?? null, FILTER_VALIDATE_INT);
        if ($day === false || $day < 1 || $day > 7 || isset($days[$day])) {
            throw new MerdWorkforceException('invalid_schedule', 'Each weekday must appear once.');
        }
        $closed = !empty($rawDay['is_closed']);
        $start = $closed ? null : timings_normalize_time($rawDay['start_time'] ?? null);
        $end = $closed ? null : timings_normalize_time($rawDay['end_time'] ?? null);
        if (!$closed && ($start === null || $end === null)) {
            throw new MerdWorkforceException('incomplete_schedule', 'Every open day needs both a start time and an end time.');
        }
        $days[(int)$day] = [
            'day_of_week' => (int)$day,
            'start_time' => $start,
            'end_time' => $end,
            'is_closed' => $closed ? 1 : 0,
        ];
    }
    ksort($days);
    if (count($days) !== 7) {
        throw new MerdWorkforceException('invalid_schedule', 'All seven weekdays are required.');
    }

    if ($scope === 'all') {
        $storesStmt = $pdo->prepare("SELECT id FROM stores WHERE client_id=? AND status='active' ORDER BY id");
        $storesStmt->execute([$clientId]);
        $storeIds = array_map('intval', $storesStmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $storeId = (int)($input['store_id'] ?? 0);
        $storeStmt = $pdo->prepare('SELECT id FROM stores WHERE client_id=? AND id=? LIMIT 1');
        $storeStmt->execute([$clientId, $storeId]);
        $found = $storeStmt->fetchColumn();
        if (!$found) throw new MerdWorkforceException('store_not_found', 'Store not found.');
        $storeIds = [(int)$found];
    }

    if (!$storeIds) {
        throw new MerdWorkforceException('no_stores', 'No stores are available for this schedule.');
    }

    $upsert = $pdo->prepare(
        'INSERT INTO store_weekly_hours '
        . '(client_id,store_id,day_of_week,start_time,end_time,is_closed,updated_by_employee_id) '
        . 'VALUES (?,?,?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE start_time=VALUES(start_time),end_time=VALUES(end_time),'
        . 'is_closed=VALUES(is_closed),updated_by_employee_id=VALUES(updated_by_employee_id),updated_at=CURRENT_TIMESTAMP'
    );
    $legacyUpsert = $pdo->prepare(
        'INSERT INTO store_shift_start_times (client_id,store_id,store_name,shift_start_time) '
        . 'SELECT ?,s.id,s.store_name,? FROM stores s WHERE s.client_id=? AND s.id=? '
        . 'ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),shift_start_time=VALUES(shift_start_time),updated_at=CURRENT_TIMESTAMP'
    );
    $legacyDelete = $pdo->prepare('DELETE FROM store_shift_start_times WHERE client_id=? AND store_id=?');

    $pdo->beginTransaction();
    try {
        foreach ($storeIds as $storeId) {
            foreach ($days as $day) {
                $upsert->execute([
                    $clientId,
                    $storeId,
                    $day['day_of_week'],
                    $day['start_time'],
                    $day['end_time'],
                    $day['is_closed'],
                    (int)$actor['id'],
                ]);
            }

            $legacyStart = null;
            if (!$days[1]['is_closed']) $legacyStart = $days[1]['start_time'];
            if ($legacyStart === null) {
                foreach ($days as $day) {
                    if (!$day['is_closed'] && $day['start_time'] !== null) {
                        $legacyStart = $day['start_time'];
                        break;
                    }
                }
            }
            if ($legacyStart !== null) {
                $legacyUpsert->execute([$clientId, $legacyStart, $clientId, $storeId]);
            } else {
                $legacyDelete->execute([$clientId, $storeId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    timings_audit($pdo, $actor, $storeIds, array_values($days));
    $state = timings_load($pdo, $clientId);
    json_response([
        'success' => true,
        'message' => $scope === 'all' ? 'Timings applied to all active stores.' : 'Store timings saved.',
        'stores_updated' => count($storeIds),
        'csrf' => csrf_token(),
        'stores' => $state['stores'],
        'timings' => $state['timings'],
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
