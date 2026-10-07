<?php
declare(strict_types=1);

/**
 * Store roster planning.
 *
 * The plan side of attendance: which employees are expected at a store on each
 * day of a week. Replaces the hand-written weekly roster sheet.
 *
 * Authorization:
 *   - reading a store roster requires roster.view AND access to that store;
 *   - reading only your own assignments requires roster.view_own;
 *   - writing a week requires roster.manage AND access to that store;
 *   - every assigned employee must themselves have access to that store, so a
 *     manager cannot roster someone into a store they may not work at;
 *   - the platform DEV identity may work across stores, as elsewhere.
 * UI visibility is never the boundary: all of the above is enforced here.
 */

require_once __DIR__ . '/../includes/beta_api.php';

/**
 * Mirrors the attendance rule so rostering and clock-in cannot disagree about
 * where an employee may work: no access row means "all stores".
 */
function roster_employee_may_use_store(PDO $pdo, int $clientId, int $employeeId, int $storeId): bool
{
    $stmt = $pdo->prepare('SELECT access_mode FROM employee_store_access WHERE client_id=? AND employee_id=? LIMIT 1');
    $stmt->execute([$clientId, $employeeId]);
    $mode = strtolower((string)($stmt->fetchColumn() ?: 'all'));
    if ($mode !== 'selected') return true;

    $allowed = $pdo->prepare(
        'SELECT 1 FROM employee_store_assignments WHERE client_id=? AND employee_id=? AND store_id=? LIMIT 1'
    );
    $allowed->execute([$clientId, $employeeId, $storeId]);
    return (bool)$allowed->fetchColumn();
}

function roster_actor_scope(PDO $pdo, array $actor, int $clientId, int $storeId): bool
{
    if (beta_actual_user_is_dev($actor)) return true;
    $employeeId = beta_actor_employee_id($actor);
    if ($employeeId === null) return false;
    return roster_employee_may_use_store($pdo, $clientId, $employeeId, $storeId);
}

function roster_require_store(PDO $pdo, int $clientId, int $storeId): array
{
    if ($storeId <= 0) throw new MerdWorkforceException('invalid_store', 'Choose a valid store.');
    $stmt = $pdo->prepare('SELECT id,store_name,status FROM stores WHERE id=? AND client_id=? LIMIT 1');
    $stmt->execute([$storeId, $clientId]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$store) throw new MerdWorkforceException('invalid_store', 'That store is not part of the active client.');
    return $store;
}

/** The roster week is a Monday; the note always covers Monday to Sunday. */
function roster_week_start(mixed $value): string
{
    $text = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $text) {
        throw new MerdWorkforceException('invalid_week_start', 'Use a valid week start date (YYYY-MM-DD).');
    }
    if ((int)$date->format('N') !== 1) {
        throw new MerdWorkforceException('invalid_week_start', 'A roster week starts on Monday.');
    }
    return $text;
}

function roster_time(mixed $value, string $field): string
{
    $text = trim((string)$value);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $text)) {
        throw new MerdWorkforceException('invalid_time', "Use a valid 24-hour {$field} (HH:MM).");
    }
    return strlen($text) === 5 ? $text . ':00' : $text;
}

function roster_load(PDO $pdo, int $clientId, int $storeId, string $weekStart): array
{
    $weekStmt = $pdo->prepare(
        'SELECT id,store_id,week_start,status,note,updated_at FROM roster_weeks WHERE client_id=? AND store_id=? AND week_start=? LIMIT 1'
    );
    $weekStmt->execute([$clientId, $storeId, $weekStart]);
    $week = $weekStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $shifts = [];
    if ($week) {
        $shiftStmt = $pdo->prepare(
            'SELECT id,shift_date,slot_key,label,start_time,end_time,ends_next_day,position '
            . 'FROM roster_shifts WHERE roster_week_id=? ORDER BY shift_date,position,id'
        );
        $shiftStmt->execute([(int)$week['id']]);
        $rows = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

        $assignmentsByShift = [];
        if ($rows !== []) {
            $assignmentStmt = $pdo->prepare(
                'SELECT a.roster_shift_id,a.employee_id,a.position,e.full_name,e.user_id '
                . 'FROM roster_assignments a JOIN employees e ON e.id=a.employee_id '
                . 'WHERE a.roster_shift_id IN (' . implode(',', array_fill(0, count($rows), '?')) . ') '
                . 'ORDER BY a.position,a.id'
            );
            $assignmentStmt->execute(array_map(static fn(array $row): int => (int)$row['id'], $rows));
            foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
                $assignmentsByShift[(int)$assignment['roster_shift_id']][] = $assignment;
            }
        }
        foreach ($rows as $row) {
            $row['assignments'] = $assignmentsByShift[(int)$row['id']] ?? [];
            $shifts[] = $row;
        }
    }

    return ['week' => $week, 'shifts' => $shifts];
}

function roster_own_assignments(PDO $pdo, int $clientId, int $employeeId, string $weekStart): array
{
    $stmt = $pdo->prepare(
        'SELECT s.shift_date,s.slot_key,s.label,s.start_time,s.end_time,s.ends_next_day,s.store_id,st.store_name '
        . 'FROM roster_assignments a '
        . 'JOIN roster_shifts s ON s.id=a.roster_shift_id '
        . 'JOIN roster_weeks w ON w.id=s.roster_week_id '
        . 'JOIN stores st ON st.id=s.store_id '
        . 'WHERE a.client_id=? AND a.employee_id=? AND w.week_start=? '
        . 'ORDER BY s.shift_date,s.start_time'
    );
    $stmt->execute([$clientId, $employeeId, $weekStart]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Active employees who may actually be rostered at this store.
 *
 * The eligibility rule is not restated here: this calls roster_employee_may_use_store(),
 * the same function the write path uses, so the picker a planner sees and the
 * employees the writer accepts can never disagree. Only planners need the
 * assignable list, so the caller gates it on roster.manage - it is not part of
 * ordinary roster viewing. Names are only ever returned for a store the actor can
 * already reach, so this exposes nothing they could not read another way.
 */
function roster_assignable_employees(PDO $pdo, int $clientId, int $storeId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,full_name,user_id,status FROM employees WHERE client_id=? ORDER BY full_name,id'
    );
    $stmt->execute([$clientId]);
    $assignable = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $employee) {
        if (strtolower((string)$employee['status']) !== 'active') continue;
        $employeeId = (int)$employee['id'];
        if (!roster_employee_may_use_store($pdo, $clientId, $employeeId, $storeId)) continue;
        $assignable[] = [
            'id' => $employeeId,
            'full_name' => (string)$employee['full_name'],
            'user_id' => $employee['user_id'] === null ? null : (int)$employee['user_id'],
        ];
    }
    return $assignable;
}

function roster_save_week(PDO $pdo, array $actor, int $clientId, array $input): array
{
    $storeId = (int)($input['store_id'] ?? 0);
    $store = roster_require_store($pdo, $clientId, $storeId);
    // Authorization before business state: an actor who may not work at this store
    // must not learn whether it is active.
    if (!roster_actor_scope($pdo, $actor, $clientId, $storeId)) {
        throw new MerdWorkforceException('store_forbidden', 'You do not have access to that store.');
    }
    if (strtolower((string)$store['status']) !== 'active') {
        throw new MerdWorkforceException('store_inactive', 'That store is not active, so a roster cannot be planned for it.');
    }

    $weekStart = roster_week_start($input['week_start'] ?? '');
    $status = strtolower(trim((string)($input['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'published'], true)) {
        throw new MerdWorkforceException('invalid_status', 'Choose draft or published.');
    }
    $note = trim((string)($input['note'] ?? ''));
    $note = $note === '' ? null : mb_substr($note, 0, 255);

    $rawShifts = $input['shifts'] ?? null;
    if (!is_array($rawShifts)) {
        throw new MerdWorkforceException('invalid_roster', 'A list of shifts is required.');
    }
    if (count($rawShifts) > 70) {
        throw new MerdWorkforceException('invalid_roster', 'A single week cannot hold more than 70 shifts.');
    }

    $weekEnd = (new DateTimeImmutable($weekStart, new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
    $seenSlots = [];
    $shifts = [];
    foreach ($rawShifts as $index => $raw) {
        if (!is_array($raw)) throw new MerdWorkforceException('invalid_roster', 'Each shift must be an object.');
        $slotKey = strtolower(trim((string)($raw['slot_key'] ?? '')));
        if (!preg_match('/^[a-z0-9_]{1,24}$/', $slotKey)) {
            throw new MerdWorkforceException('invalid_slot', 'Each shift needs a slot key such as early or late.');
        }
        $shiftDate = trim((string)($raw['shift_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $shiftDate, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $shiftDate || $shiftDate < $weekStart || $shiftDate > $weekEnd) {
            throw new MerdWorkforceException('invalid_shift_date', 'Every shift date must fall inside the roster week.');
        }
        $unique = $shiftDate . '|' . $slotKey;
        if (isset($seenSlots[$unique])) {
            throw new MerdWorkforceException('duplicate_slot', "Two shifts share {$shiftDate} {$slotKey}.");
        }
        $seenSlots[$unique] = true;

        $start = roster_time($raw['start_time'] ?? '', 'start time');
        $end = roster_time($raw['end_time'] ?? '', 'end time');
        if ($start === $end) {
            throw new MerdWorkforceException('invalid_time', 'A shift cannot start and end at the same time.');
        }
        // Strict boolean: the JSON string "false" must not read as "ends next day".
        $flag = filter_var($raw['ends_next_day'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag === null) {
            throw new MerdWorkforceException('invalid_time', 'ends_next_day must be true or false.');
        }
        $endsNextDay = $flag ? 1 : 0;
        // A cross-midnight shift is expressed by the flag; refuse the ambiguous
        // combination of an earlier end time without it, and refuse the nonsense
        // of an end time later than the start that still claims the next day.
        if ($endsNextDay === 0 && $end < $start) {
            throw new MerdWorkforceException(
                'invalid_time',
                'A shift ending before it starts must be marked as ending the next day.'
            );
        }
        if ($endsNextDay === 1 && $end > $start) {
            throw new MerdWorkforceException(
                'invalid_time',
                'A shift that ends later than it starts cannot also be marked as ending the next day.'
            );
        }

        $rawAssignments = $raw['assignments'] ?? [];
        if (!is_array($rawAssignments)) {
            throw new MerdWorkforceException('invalid_roster', 'Assignments must be a list of employees.');
        }
        $employeeIds = [];
        foreach ($rawAssignments as $rawEmployee) {
            $employeeId = is_array($rawEmployee) ? (int)($rawEmployee['employee_id'] ?? 0) : (int)$rawEmployee;
            if ($employeeId <= 0) throw new MerdWorkforceException('invalid_employee', 'Choose valid employees for each shift.');
            if (in_array($employeeId, $employeeIds, true)) continue;
            $employeeIds[] = $employeeId;
        }
        if (count($employeeIds) > 12) {
            throw new MerdWorkforceException('invalid_roster', 'A shift cannot hold more than 12 employees.');
        }

        $shifts[] = [
            'shift_date' => $shiftDate,
            'slot_key' => $slotKey,
            'label' => mb_substr(trim((string)($raw['label'] ?? '')), 0, 48) ?: null,
            'start_time' => $start,
            'end_time' => $end,
            'ends_next_day' => $endsNextDay,
            'position' => (int)$index,
            'employee_ids' => $employeeIds,
        ];
    }

    // Every assigned employee must exist in this client and be allowed at the store.
    $employeeCheck = $pdo->prepare('SELECT id,status,full_name FROM employees WHERE id=? AND client_id=? LIMIT 1');
    foreach ($shifts as $shift) {
        foreach ($shift['employee_ids'] as $employeeId) {
            $employeeCheck->execute([$employeeId, $clientId]);
            $employee = $employeeCheck->fetch(PDO::FETCH_ASSOC);
            if (!$employee) throw new MerdWorkforceException('invalid_employee', 'Every assigned employee must belong to the active client.');
            if (strtolower((string)$employee['status']) !== 'active') {
                throw new MerdWorkforceException('invalid_employee', (string)$employee['full_name'] . ' is not an active employee.');
            }
            if (!roster_employee_may_use_store($pdo, $clientId, $employeeId, $storeId)) {
                throw new MerdWorkforceException('invalid_employee', (string)$employee['full_name'] . ' does not have access to that store.');
            }
        }
    }

    $actorEmployeeId = beta_actor_employee_id($actor);
    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO roster_weeks (client_id,store_id,week_start,status,note,created_by_employee_id,updated_by_employee_id) '
            . 'VALUES (?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE status=VALUES(status),note=VALUES(note),updated_by_employee_id=VALUES(updated_by_employee_id)'
        );
        $upsert->execute([$clientId, $storeId, $weekStart, $status, $note, $actorEmployeeId, $actorEmployeeId]);

        $weekIdStmt = $pdo->prepare('SELECT id FROM roster_weeks WHERE client_id=? AND store_id=? AND week_start=? LIMIT 1');
        $weekIdStmt->execute([$clientId, $storeId, $weekStart]);
        $weekId = (int)$weekIdStmt->fetchColumn();
        if ($weekId <= 0) throw new RuntimeException('roster week could not be resolved after upsert');

        // Replacing the week wholesale keeps the sheet authoritative: shifts and
        // assignments removed in the UI disappear here too, in one transaction.
        // The children are removed explicitly rather than relying on the foreign
        // key cascade, so this stays correct even if the constraint is ever
        // relaxed, and the intent is obvious to the next reader.
        $clearAssignments = $pdo->prepare(
            'DELETE a FROM roster_assignments a JOIN roster_shifts s ON s.id=a.roster_shift_id '
            . 'WHERE s.roster_week_id=? AND a.client_id=?'
        );
        $clearAssignments->execute([$weekId, $clientId]);
        $delete = $pdo->prepare('DELETE FROM roster_shifts WHERE roster_week_id=? AND client_id=?');
        $delete->execute([$weekId, $clientId]);

        $shiftInsert = $pdo->prepare(
            'INSERT INTO roster_shifts (roster_week_id,client_id,store_id,shift_date,slot_key,label,start_time,end_time,ends_next_day,position) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $assignmentInsert = $pdo->prepare(
            'INSERT INTO roster_assignments (roster_shift_id,client_id,store_id,employee_id,position,assigned_by_employee_id) '
            . 'VALUES (?,?,?,?,?,?)'
        );
        $assignmentCount = 0;
        foreach ($shifts as $shift) {
            $shiftInsert->execute([
                $weekId,
                $clientId,
                $storeId,
                $shift['shift_date'],
                $shift['slot_key'],
                $shift['label'],
                $shift['start_time'],
                $shift['end_time'],
                $shift['ends_next_day'],
                $shift['position'],
            ]);
            $shiftId = (int)$pdo->lastInsertId();
            $position = 0;
            foreach ($shift['employee_ids'] as $employeeId) {
                $assignmentInsert->execute([$shiftId, $clientId, $storeId, $employeeId, $position++, $actorEmployeeId]);
                $assignmentCount++;
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $audit = 'recorded';
    try {
        beta_admin_audit($pdo, $actor, 'roster.save_week', 'store_roster', (string)$storeId, [
            'week_start' => $weekStart,
            'status' => $status,
            'shifts' => count($shifts),
            'assignments' => $assignmentCount,
        ]);
    } catch (Throwable $auditError) {
        // The write has committed, and audit is part of the privileged-write
        // contract: a degrading audit sink is surfaced to the caller and logged in
        // a fixed, monitorable shape rather than silently swallowed.
        $audit = 'failed';
        error_log(sprintf(
            'MERDPOS_AUDIT_FAILURE action=roster.save_week store_id=%d week_start=%s actor_employee_id=%s error=%s',
            $storeId,
            $weekStart,
            $actorEmployeeId === null ? 'none' : (string)$actorEmployeeId,
            get_class($auditError)
        ));
    }

    return [
        'store_id' => $storeId,
        'store_name' => (string)$store['store_name'],
        'week_start' => $weekStart,
        'status' => $status,
        'shifts' => count($shifts),
        'assignments' => $assignmentCount,
        'audit' => $audit,
    ];
}

try {
    $sessionUser = beta_require_active_user();
    $pdo = portal_db();
    $clientId = (int)($sessionUser['auth_client_id'] ?? $sessionUser['client_id'] ?? 0);
    if ($clientId <= 0) throw new MerdWorkforceException('invalid_context', 'An active client context is required.');

    $actorRole = (string)($sessionUser['role_label'] ?? $sessionUser['role_name'] ?? $sessionUser['role'] ?? 'User');
    $actorLoa = (int)($sessionUser['authority_level'] ?? 0);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        beta_require_any_permission($sessionUser, ['roster.view', 'roster.view_own'], $pdo);
        $scope = strtolower(trim((string)($_GET['scope'] ?? 'store')));

        // The assignable-employee list is planning data rather than roster viewing,
        // so it is gated on roster.manage plus store access, and it deliberately
        // needs no week: the picker is not week-specific. Week resolution stays
        // below, where it is actually required, so the other scopes are unchanged.
        if ($scope === 'employees') {
            beta_require_permission($sessionUser, 'roster.manage', $pdo);
            $storeId = (int)($_GET['store_id'] ?? 0);
            $store = roster_require_store($pdo, $clientId, $storeId);
            if (!roster_actor_scope($pdo, $sessionUser, $clientId, $storeId)) {
                throw new MerdWorkforceException('store_forbidden', 'You do not have access to that store.');
            }
            json_response([
                'success' => true,
                'scope' => 'employees',
                'csrf' => csrf_token(),
                'actor_role' => $actorRole,
                'actor_loa' => $actorLoa,
                'active_client_id' => $clientId,
                'store' => $store,
                'employees' => roster_assignable_employees($pdo, $clientId, $storeId),
            ]);
        }

        $weekStart = roster_week_start($_GET['week_start'] ?? '');

        if ($scope === 'own') {
            beta_require_permission($sessionUser, 'roster.view_own', $pdo);
            $employeeId = beta_actor_employee_id($sessionUser);
            if ($employeeId === null) throw new MerdWorkforceException('invalid_context', 'Only an employee identity has personal shifts.');
            json_response([
                'success' => true,
                'scope' => 'own',
                'csrf' => csrf_token(),
                'actor_role' => $actorRole,
                'actor_loa' => $actorLoa,
                'week_start' => $weekStart,
                'shifts' => roster_own_assignments($pdo, $clientId, $employeeId, $weekStart),
            ]);
        }

        beta_require_permission($sessionUser, 'roster.view', $pdo);
        $storeId = (int)($_GET['store_id'] ?? 0);
        $store = roster_require_store($pdo, $clientId, $storeId);
        if (!roster_actor_scope($pdo, $sessionUser, $clientId, $storeId)) {
            throw new MerdWorkforceException('store_forbidden', 'You do not have access to that store.');
        }
        $state = roster_load($pdo, $clientId, $storeId, $weekStart);
        json_response([
            'success' => true,
            'scope' => 'store',
            'csrf' => csrf_token(),
            'actor_role' => $actorRole,
            'actor_loa' => $actorLoa,
            'active_client_id' => $clientId,
            'store' => $store,
            'week_start' => $weekStart,
            'week' => $state['week'],
            'shifts' => $state['shifts'],
        ]);
    }

    beta_require_permission($sessionUser, 'roster.manage', $pdo);
    $input = request_input();
    require_csrf($input);
    if ((string)($input['action'] ?? '') !== 'save_week') {
        json_response(['success' => false, 'error' => 'Unsupported roster action.'], 400);
    }
    json_response(['success' => true, 'csrf' => csrf_token(), 'week' => roster_save_week($pdo, $sessionUser, $clientId, $input)]);
} catch (Throwable $error) {
    beta_api_error($error);
}
