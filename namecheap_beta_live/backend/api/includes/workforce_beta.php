<?php
declare(strict_types=1);

final class MerdWorkforceException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

function merd_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function merd_b64url_decode(string $value): string|false
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        return false;
    }
    $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function merd_beta_json(string $json): array
{
    try {
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new MerdWorkforceException('invalid_payload', 'Invalid payload.');
    }
    if (!is_array($value) || array_is_list($value)) {
        throw new MerdWorkforceException('invalid_payload', 'Invalid payload.');
    }
    return $value;
}

function merd_verify_attendance_qr(PDO $pdo, int $clientId, string $token, ?DateTimeImmutable $now = null): array
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new MerdWorkforceException('crypto_unavailable', 'Attendance verification is temporarily unavailable.');
    }
    if (strlen($token) > 1400 || substr_count($token, '.') !== 1) {
        throw new MerdWorkforceException('invalid_qr', 'This attendance QR is invalid.');
    }
    [$encoded, $signatureEncoded] = explode('.', $token, 2);
    $json = merd_b64url_decode($encoded);
    $signature = merd_b64url_decode($signatureEncoded);
    if ($json === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        throw new MerdWorkforceException('invalid_qr', 'This attendance QR is invalid.');
    }
    $claims = merd_beta_json($json);
    $deviceUuid = trim((string)($claims['did'] ?? ''));
    $issuedAt = filter_var($claims['iat'] ?? null, FILTER_VALIDATE_INT);
    $expiresAt = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);
    $nonce = trim((string)($claims['n'] ?? ''));
    if (($claims['v'] ?? null) !== 1 || $deviceUuid === '' || strlen($deviceUuid) > 150
        || $issuedAt === false || $expiresAt === false || $nonce === '' || strlen($nonce) > 80) {
        throw new MerdWorkforceException('invalid_qr', 'This attendance QR is invalid.');
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $epoch = $now->getTimestamp();
    if ($expiresAt < $epoch - 15 || $issuedAt > $epoch + 60 || $expiresAt - $issuedAt > 180) {
        throw new MerdWorkforceException('expired_qr', 'This attendance QR has expired. Scan the current QR.');
    }
    $stmt = $pdo->prepare(
        "SELECT d.id AS device_id, d.device_uuid, d.store_id, s.store_name, k.public_key_b64 "
        . "FROM devices d INNER JOIN stores s ON s.id=d.store_id AND s.client_id=d.client_id "
        . "INNER JOIN attendance_device_keys k ON k.device_id=d.id AND k.status='active' "
        . "WHERE d.client_id=? AND d.device_uuid=? AND d.status='active' LIMIT 1"
    );
    $stmt->execute([$clientId, $deviceUuid]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    $publicKey = is_array($device) ? base64_decode((string)$device['public_key_b64'], true) : false;
    if (!is_array($device) || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || !sodium_crypto_sign_verify_detached($signature, $encoded, $publicKey)) {
        throw new MerdWorkforceException('invalid_qr', 'This attendance QR is not from an authorised POS.');
    }
    return [
        'token_hash' => hash('sha256', $token),
        'device_id' => (int)$device['device_id'],
        'device_uuid' => (string)$device['device_uuid'],
        'store_id' => (int)$device['store_id'],
        'store_name' => (string)$device['store_name'],
        'expires_at' => (int)$expiresAt,
    ];
}

function merd_sheet_outbox(PDO $pdo, int $clientId, string $type, string $aggregateType, string $aggregateId, array $payload): string
{
    $eventId = merd_uuid_v4();
    $stmt = $pdo->prepare(
        'INSERT INTO google_sheet_outbox '
        . '(event_id,client_id,event_type,aggregate_type,aggregate_id,payload) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        $eventId, $clientId, $type, $aggregateType, $aggregateId,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    return $eventId;
}

function merd_attendance_scan(PDO $pdo, array $employee, string $token, ?DateTimeImmutable $now = null): array
{
    $clientId = (int)$employee['client_id'];
    $employeeId = (int)$employee['id'];
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $qr = merd_verify_attendance_qr($pdo, $clientId, $token, $now);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id, full_name FROM employees WHERE client_id=? AND id=? AND status=\'active\' FOR UPDATE');
        $lock->execute([$clientId, $employeeId]);
        $employeeRow = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employeeRow)) {
            throw new MerdWorkforceException('employee_inactive', 'Your account is inactive.');
        }
        $duplicate = $pdo->prepare(
            'SELECT q.action,s.public_id,s.clock_in_at,s.clock_out_at FROM attendance_qr_uses q '
            . 'INNER JOIN attendance_shifts s ON s.id=q.shift_id WHERE q.token_hash=? AND q.employee_id=? LIMIT 1'
        );
        $duplicate->execute([$qr['token_hash'], $employeeId]);
        $prior = $duplicate->fetch(PDO::FETCH_ASSOC);
        if (is_array($prior)) {
            $pdo->commit();
            return [
                'duplicate' => true, 'action' => $prior['action'], 'shift_id' => $prior['public_id'],
                'store_name' => $qr['store_name'],
                'occurred_at' => $prior['action'] === 'IN' ? $prior['clock_in_at'] : $prior['clock_out_at'],
            ];
        }
        $open = $pdo->prepare(
            "SELECT * FROM attendance_shifts WHERE client_id=? AND employee_id=? AND status='open' LIMIT 1 FOR UPDATE"
        );
        $open->execute([$clientId, $employeeId]);
        $shift = $open->fetch(PDO::FETCH_ASSOC);
        $stamp = $now->format('Y-m-d H:i:s');
        if (!is_array($shift)) {
            $publicId = merd_uuid_v4();
            $insert = $pdo->prepare(
                'INSERT INTO attendance_shifts '
                . '(public_id,client_id,store_id,employee_id,device_id,clock_in_at,status) VALUES (?,?,?,?,?,?,\'open\')'
            );
            $insert->execute([$publicId, $clientId, $qr['store_id'], $employeeId, $qr['device_id'], $stamp]);
            $shiftId = (int)$pdo->lastInsertId();
            $action = 'IN';
        } else {
            if ((int)$shift['store_id'] !== $qr['store_id']) {
                $flagId = merd_uuid_v4();
                $flag = $pdo->prepare(
                    "INSERT INTO attendance_account_flags (public_id,client_id,employee_id,open_shift_id,attempted_store_id,attempted_device_id,reason) "
                    . "VALUES (?,?,?,?,?,?,'simultaneous_qr_at_different_store') "
                    . "ON DUPLICATE KEY UPDATE attempted_store_id=VALUES(attempted_store_id),attempted_device_id=VALUES(attempted_device_id)"
                );
                $flag->execute([$flagId,$clientId,$employeeId,(int)$shift['id'],$qr['store_id'],$qr['device_id']]);
                $pdo->prepare("UPDATE employees SET status='inactive' WHERE id=? AND client_id=?")->execute([$employeeId,$clientId]);
                merd_sheet_outbox($pdo,$clientId,'attendance_security_flag','employee',(string)$employeeId,[
                    'flag_id'=>$flagId,'employee_name'=>$employeeRow['full_name'],'open_shift_id'=>$shift['public_id'],
                    'attempted_store_name'=>$qr['store_name'],'reason'=>'simultaneous_qr_at_different_store','created_at_utc'=>$stamp,
                ]);
                $pdo->commit();
                throw new MerdWorkforceException('attendance_suspended', 'Attendance access is suspended after a simultaneous scan at another store. Contact a SUPER user.');
            }
            if ($now->getTimestamp() - strtotime((string)$shift['clock_in_at'] . ' UTC') < 60) {
                throw new MerdWorkforceException('scan_cooldown', 'You have just clocked in. Scan a newer QR when your shift ends.');
            }
            $shiftId = (int)$shift['id'];
            $publicId = (string)$shift['public_id'];
            $close = $pdo->prepare(
                "UPDATE attendance_shifts SET clock_out_at=?,status='closed',close_reason='qr' WHERE id=? AND status='open'"
            );
            $close->execute([$stamp, $shiftId]);
            $action = 'OUT';
        }
        $use = $pdo->prepare('INSERT INTO attendance_qr_uses (token_hash,employee_id,shift_id,action,used_at) VALUES (?,?,?,?,?)');
        $use->execute([$qr['token_hash'], $employeeId, $shiftId, $action, $stamp]);
        merd_sheet_outbox($pdo, $clientId, 'attendance_event', 'attendance_shift', $publicId . ':' . $action, [
            'employee_name' => (string)$employeeRow['full_name'], 'store_name' => $qr['store_name'],
            'log_type' => $action, 'occurred_at_utc' => $stamp, 'shift_id' => $publicId,
        ]);
        merd_sheet_outbox($pdo, $clientId, 'employee_log_store', 'employee', (string)$employeeId, [
            'employee_name' => (string)$employeeRow['full_name'],
            'log_store' => $action === 'IN' ? $qr['store_name'] : '', 'shift_id' => $publicId,
        ]);
        $pdo->commit();
        return [
            'duplicate' => false, 'action' => $action, 'shift_id' => $publicId,
            'store_name' => $qr['store_name'], 'occurred_at' => $stamp,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function merd_working_now(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare(
        "SELECT s.public_id AS shift_id,e.full_name,e.user_id,st.id AS store_id,st.store_name,s.clock_in_at,"
        . "TIMESTAMPDIFF(MINUTE,s.clock_in_at,UTC_TIMESTAMP()) AS working_minutes "
        . "FROM attendance_shifts s INNER JOIN employees e ON e.id=s.employee_id "
        . "INNER JOIN stores st ON st.id=s.store_id WHERE s.client_id=? AND s.status='open' "
        . 'ORDER BY st.store_name,e.full_name'
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function merd_employee_is_super(array $employee): bool
{
    return strtoupper((string)($employee['employee_type'] ?? '')) === 'SUPER'
        || strtoupper((string)($employee['role_name'] ?? '')) === 'SUPER';
}

function merd_list_attendance_flags(PDO $pdo, array $employee): array
{
    if (!merd_employee_is_super($employee)) return [];
    $stmt=$pdo->prepare(
        "SELECT f.public_id AS flag_id,e.full_name,e.user_id,st.store_name AS attempted_store,f.reason,f.created_at,f.status,s.public_id AS shift_id "
        . "FROM attendance_account_flags f INNER JOIN employees e ON e.id=f.employee_id INNER JOIN stores st ON st.id=f.attempted_store_id "
        . "INNER JOIN attendance_shifts s ON s.id=f.open_shift_id WHERE f.client_id=? ORDER BY f.status='open' DESC,f.created_at DESC LIMIT 100"
    );
    $stmt->execute([(int)$employee['client_id']]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function merd_resolve_attendance_flag(PDO $pdo,array $super,string $flagId,string $note): array
{
    if(!merd_employee_is_super($super)) throw new MerdWorkforceException('forbidden','SUPER approval is required.');
    if(strlen($note)>1000) throw new MerdWorkforceException('invalid_note','Resolution note is too long.');
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM attendance_account_flags WHERE public_id=? AND client_id=? FOR UPDATE");
        $stmt->execute([$flagId,(int)$super['client_id']]);$flag=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($flag)) throw new MerdWorkforceException('flag_not_found','Attendance flag not found.');
        if($flag['status']==='resolved'){ $pdo->commit(); return ['flag_id'=>$flagId,'status'=>'resolved','duplicate'=>true]; }
        $pdo->prepare("UPDATE attendance_account_flags SET status='resolved',resolved_by_employee_id=?,resolved_at=UTC_TIMESTAMP(),resolution_note=? WHERE id=?")
            ->execute([(int)$super['id'],$note,(int)$flag['id']]);
        $pdo->prepare("UPDATE employees SET status='active' WHERE id=? AND client_id=?")->execute([(int)$flag['employee_id'],(int)$super['client_id']]);
        merd_sheet_outbox($pdo,(int)$super['client_id'],'attendance_security_flag','employee',(string)$flag['employee_id'].':resolved',[
            'flag_id'=>$flagId,'status'=>'resolved','resolved_by'=>$super['full_name'],'resolved_at_utc'=>gmdate('Y-m-d H:i:s'),'resolution_note'=>$note,
        ]);
        $pdo->commit(); return ['flag_id'=>$flagId,'status'=>'resolved','duplicate'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function merd_create_dispute(
    PDO $pdo,
    array $employee,
    string $shiftPublicId,
    string $type,
    ?string $requestedInUtc,
    ?string $requestedOutUtc,
    ?int $proposedStoreId,
    string $reason,
    string $initialStatus = 'pending',
    string $origin = 'employee'
): array {
    $allowed = ['missing_out', 'wrong_in', 'wrong_out', 'wrong_store', 'delete_shift', 'new_shift', 'other'];
    if (!in_array($type, $allowed, true) || !in_array($initialStatus, ['awaiting_employee','pending'], true)
        || !in_array($origin, ['employee','pos_handover'], true) || strlen($reason) < 5 || strlen($reason) > 1000) {
        throw new MerdWorkforceException('invalid_dispute', 'Provide a dispute type and a clear reason.');
    }
    $pdo->beginTransaction();
    try {
        $shift = null;
        if ($type === 'new_shift') {
            if (!$proposedStoreId || !$requestedInUtc || !$requestedOutUtc
                || strtotime($requestedOutUtc . ' UTC') <= strtotime($requestedInUtc . ' UTC')
                || strtotime($requestedOutUtc . ' UTC') > time() + 300) {
                throw new MerdWorkforceException('invalid_new_shift', 'A new shift needs a valid store, IN and OUT time.');
            }
            $store = $pdo->prepare("SELECT id FROM stores WHERE id=? AND client_id=? AND status='active'");
            $store->execute([$proposedStoreId, (int)$employee['client_id']]);
            if (!$store->fetchColumn()) throw new MerdWorkforceException('store_not_found', 'Store not found.');
            $overlap=$pdo->prepare("SELECT id FROM attendance_shifts WHERE client_id=? AND employee_id=? AND status<>'void' AND clock_in_at<? AND COALESCE(clock_out_at,'9999-12-31 23:59:59')>? LIMIT 1");
            $overlap->execute([(int)$employee['client_id'],(int)$employee['id'],$requestedOutUtc,$requestedInUtc]);
            if($overlap->fetchColumn()) throw new MerdWorkforceException('shift_overlap','The proposed shift overlaps another shift.');
        } else {
            $stmt = $pdo->prepare('SELECT s.* FROM attendance_shifts s WHERE s.public_id=? AND s.client_id=? AND s.employee_id=? FOR UPDATE');
            $stmt->execute([$shiftPublicId, (int)$employee['client_id'], (int)$employee['id']]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($shift)) throw new MerdWorkforceException('shift_not_found', 'Shift not found.');
            $pending = $pdo->prepare("SELECT public_id,status FROM attendance_disputes WHERE shift_id=? AND status IN ('awaiting_employee','pending') LIMIT 1");
            $pending->execute([(int)$shift['id']]);
            $existing = $pending->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) { $pdo->commit(); return ['dispute_id'=>$existing['public_id'],'status'=>$existing['status'],'duplicate'=>true]; }
        }
        $publicId = merd_uuid_v4();
        $before = json_encode($shift ? ['clock_in_at'=>$shift['clock_in_at'],'clock_out_at'=>$shift['clock_out_at'],'store_id'=>(int)$shift['store_id'],'status'=>$shift['status']] : ['proposal'=>true], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare(
            'INSERT INTO attendance_disputes '
            . '(public_id,client_id,shift_id,employee_id,proposed_store_id,dispute_type,requested_clock_in_at,requested_clock_out_at,reason,before_snapshot,status,origin) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $publicId,(int)$employee['client_id'],$shift ? (int)$shift['id'] : null,(int)$employee['id'],$type==='new_shift'?$proposedStoreId:null,
            $type,$requestedInUtc,$requestedOutUtc,$reason,$before,$initialStatus,$origin,
        ]);
        merd_sheet_outbox($pdo, (int)$employee['client_id'], 'dispute_audit', 'attendance_dispute', $publicId, [
            'dispute_id' => $publicId, 'shift_id' => $shiftPublicId, 'employee_name' => $employee['full_name'],
            'proposed_store_id' => $proposedStoreId,
            'type' => $type, 'requested_clock_in_at_utc' => $requestedInUtc,
            'requested_clock_out_at_utc' => $requestedOutUtc, 'reason' => $reason,
            'status' => $initialStatus, 'submitted_at_utc' => gmdate('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
        return ['dispute_id' => $publicId, 'status' => $initialStatus, 'duplicate' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function merd_list_disputes(PDO $pdo, array $employee): array
{
    $where = merd_employee_is_super($employee)
        ? "d.client_id=? AND d.status<>'awaiting_employee' AND UPPER(COALESCE(e.employee_type,''))<>'SUPER'"
        : 'd.client_id=? AND d.employee_id=?';
    $args = merd_employee_is_super($employee)
        ? [(int)$employee['client_id']]
        : [(int)$employee['client_id'], (int)$employee['id']];
    $stmt = $pdo->prepare(
        'SELECT d.public_id AS dispute_id,d.dispute_type,d.origin,d.requested_clock_in_at,d.requested_clock_out_at,'
        . 'd.reason,d.status,d.submitted_at,d.decided_at,d.decision_note,s.public_id AS shift_id,'
        . 'COALESCE(d.requested_clock_in_at,s.clock_in_at) AS clock_in_at,COALESCE(d.requested_clock_out_at,s.clock_out_at) AS clock_out_at,'
        . 'e.full_name,COALESCE(pst.store_name,st.store_name) AS store_name FROM attendance_disputes d '
        . 'LEFT JOIN attendance_shifts s ON s.id=d.shift_id INNER JOIN employees e ON e.id=d.employee_id '
        . 'LEFT JOIN stores st ON st.id=s.store_id LEFT JOIN stores pst ON pst.id=d.proposed_store_id '
        . 'WHERE ' . $where . ' ORDER BY d.submitted_at DESC LIMIT 200'
    );
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function merd_cancel_dispute(PDO $pdo,array $employee,string $disputeId): array
{
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT id,status FROM attendance_disputes WHERE public_id=? AND client_id=? AND employee_id=? FOR UPDATE");
        $stmt->execute([$disputeId,(int)$employee['client_id'],(int)$employee['id']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new MerdWorkforceException('dispute_not_found','Dispute not found.');
        if(!in_array($row['status'],['awaiting_employee','pending'],true)) throw new MerdWorkforceException('dispute_not_pending','Only an open dispute can be cancelled.');
        $pdo->prepare("UPDATE attendance_disputes SET status='cancelled',decided_at=UTC_TIMESTAMP(),decision_note='Cancelled by employee' WHERE id=?")->execute([(int)$row['id']]);
        merd_sheet_outbox($pdo,(int)$employee['client_id'],'dispute_audit','attendance_dispute',$disputeId.':cancelled',['dispute_id'=>$disputeId,'employee_name'=>$employee['full_name'],'status'=>'cancelled','decided_by'=>$employee['full_name'],'decided_at_utc'=>gmdate('Y-m-d H:i:s'),'decision_note'=>'Cancelled by employee']);
        $pdo->commit();return ['dispute_id'=>$disputeId,'status'=>'cancelled'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function merd_confirm_handover_dispute(PDO $pdo,array $employee,string $disputeId,bool $confirmed): array
{
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT id,status,origin FROM attendance_disputes WHERE public_id=? AND client_id=? AND employee_id=? FOR UPDATE");
        $stmt->execute([$disputeId,(int)$employee['client_id'],(int)$employee['id']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||$row['origin']!=='pos_handover') throw new MerdWorkforceException('dispute_not_found','Handover dispute not found.');
        if($row['status']!=='awaiting_employee'){$pdo->commit();return ['dispute_id'=>$disputeId,'status'=>$row['status'],'duplicate'=>true];}
        $status=$confirmed?'pending':'cancelled';$note=$confirmed?'Confirmed by employee; sent to SUPER':'Employee said the handover report is incorrect';
        if($confirmed) $pdo->prepare("UPDATE attendance_disputes SET status='pending',employee_confirmed_at=UTC_TIMESTAMP(),decided_at=NULL,decision_note=? WHERE id=?")->execute([$note,(int)$row['id']]);
        else $pdo->prepare("UPDATE attendance_disputes SET status='cancelled',employee_confirmed_at=UTC_TIMESTAMP(),decided_at=UTC_TIMESTAMP(),decision_note=? WHERE id=?")->execute([$note,(int)$row['id']]);
        merd_sheet_outbox($pdo,(int)$employee['client_id'],'dispute_audit','attendance_dispute',$disputeId.':employee-'.$status,[
            'dispute_id'=>$disputeId,'employee_name'=>$employee['full_name'],'status'=>$status,'decided_by'=>$employee['full_name'],
            'decided_at_utc'=>gmdate('Y-m-d H:i:s'),'decision_note'=>$note,
        ]);
        $pdo->commit();return ['dispute_id'=>$disputeId,'status'=>$status,'duplicate'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function merd_decide_dispute(PDO $pdo, array $super, string $disputePublicId, string $decision, string $note): array
{
    if (!merd_employee_is_super($super)) {
        throw new MerdWorkforceException('forbidden', 'SUPER approval is required.');
    }
    if (!in_array($decision, ['approved', 'rejected'], true) || strlen($note) > 1000) {
        throw new MerdWorkforceException('invalid_decision', 'Invalid dispute decision.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT d.*,s.public_id AS shift_public_id,COALESCE(s.store_id,d.proposed_store_id) AS effective_store_id,'
            . 's.clock_in_at,s.clock_out_at,s.status AS shift_status,e.full_name,COALESCE(pst.store_name,st.store_name) AS store_name '
            . 'FROM attendance_disputes d LEFT JOIN attendance_shifts s ON s.id=d.shift_id '
            . 'INNER JOIN employees e ON e.id=d.employee_id LEFT JOIN stores st ON st.id=s.store_id '
            . 'LEFT JOIN stores pst ON pst.id=d.proposed_store_id WHERE d.public_id=? AND d.client_id=? FOR UPDATE'
        );
        $stmt->execute([$disputePublicId, (int)$super['client_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new MerdWorkforceException('dispute_not_found', 'Dispute not found.');
        if ($row['status'] !== 'pending') {
            $pdo->commit();
            return ['dispute_id' => $disputePublicId, 'status' => $row['status'], 'duplicate' => true];
        }
        $now = gmdate('Y-m-d H:i:s');
        $after = null;
        if ($decision === 'approved') {
            if ($row['dispute_type'] === 'new_shift') {
                $newIn=(string)$row['requested_clock_in_at'];$newOut=(string)$row['requested_clock_out_at'];
                $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE')->execute([(int)$row['employee_id']]);
                $overlap=$pdo->prepare("SELECT id FROM attendance_shifts WHERE client_id=? AND employee_id=? AND status<>'void' AND clock_in_at<? AND COALESCE(clock_out_at,'9999-12-31 23:59:59')>? LIMIT 1");
                $overlap->execute([(int)$super['client_id'],(int)$row['employee_id'],$newOut,$newIn]);
                if($overlap->fetchColumn()) throw new MerdWorkforceException('shift_overlap','The proposed shift now overlaps another shift.');
                $newShiftId=merd_uuid_v4();
                $insert=$pdo->prepare("INSERT INTO attendance_shifts (public_id,client_id,store_id,employee_id,device_id,clock_in_at,clock_out_at,status,close_reason) VALUES (?,?,?,?,NULL,?,?,'closed','approved_dispute')");
                $insert->execute([$newShiftId,(int)$super['client_id'],(int)$row['effective_store_id'],(int)$row['employee_id'],$newIn,$newOut]);
                foreach([['IN',$newIn],['OUT',$newOut]] as [$logType,$stamp]) merd_sheet_outbox($pdo,(int)$super['client_id'],'attendance_event','attendance_shift',$newShiftId.':'.$logType,[
                    'employee_name'=>$row['full_name'],'store_name'=>$row['store_name'],'log_type'=>$logType,'occurred_at_utc'=>$stamp,'shift_id'=>$newShiftId,'approved_dispute_id'=>$disputePublicId,
                ]);
                $row['shift_public_id']=$newShiftId;
                $after=json_encode(['created_shift_id'=>$newShiftId,'clock_in_at'=>$newIn,'clock_out_at'=>$newOut,'status'=>'closed'],JSON_THROW_ON_ERROR);
            } elseif ($row['dispute_type'] === 'delete_shift') {
                if (!$row['shift_id']) throw new MerdWorkforceException('shift_not_found','Shift not found.');
                $pdo->prepare("UPDATE attendance_shifts SET status='void' WHERE id=?")->execute([(int)$row['shift_id']]);
                merd_sheet_outbox($pdo,(int)$super['client_id'],'attendance_delete','attendance_shift',(string)$row['shift_public_id'],[
                    'employee_name'=>$row['full_name'],'store_name'=>$row['store_name'],'clock_in_at_utc'=>$row['clock_in_at'],'clock_out_at_utc'=>$row['clock_out_at'],'shift_id'=>$row['shift_public_id'],'dispute_id'=>$disputePublicId,
                ]);
                if($row['shift_status']==='open') merd_sheet_outbox($pdo,(int)$super['client_id'],'employee_log_store','employee',(string)$row['employee_id'],['employee_name'=>$row['full_name'],'log_store'=>'','shift_id'=>$row['shift_public_id']]);
                $after=json_encode(['status'=>'void'],JSON_THROW_ON_ERROR);
            } else {
                $newIn=$row['requested_clock_in_at']?:$row['clock_in_at'];$newOut=$row['requested_clock_out_at']?:$row['clock_out_at'];
                if(!$newOut||strtotime((string)$newOut.' UTC')<=strtotime((string)$newIn.' UTC')||strtotime((string)$newOut.' UTC')>time()+300) throw new MerdWorkforceException('invalid_correction','Approved times must form a completed shift and cannot be in the future.');
                $pdo->prepare("UPDATE attendance_shifts SET clock_in_at=?,clock_out_at=?,status='closed',close_reason='approved_dispute' WHERE id=?")->execute([$newIn,$newOut,(int)$row['shift_id']]);
                $after=json_encode(['clock_in_at'=>$newIn,'clock_out_at'=>$newOut,'status'=>'closed'],JSON_THROW_ON_ERROR);
                if(!$row['clock_out_at']){
                    if((string)$newIn!==(string)$row['clock_in_at']) merd_sheet_outbox($pdo,(int)$super['client_id'],'attendance_correction_in','attendance_shift',$row['shift_public_id'],['employee_name'=>$row['full_name'],'store_name'=>$row['store_name'],'old_clock_in_at_utc'=>$row['clock_in_at'],'new_clock_in_at_utc'=>$newIn,'dispute_id'=>$disputePublicId]);
                    merd_sheet_outbox($pdo,(int)$super['client_id'],'attendance_event','attendance_shift',$row['shift_public_id'].':OUT',['employee_name'=>$row['full_name'],'store_name'=>$row['store_name'],'log_type'=>'OUT','occurred_at_utc'=>$newOut,'shift_id'=>$row['shift_public_id'],'approved_dispute_id'=>$disputePublicId]);
                    merd_sheet_outbox($pdo,(int)$super['client_id'],'employee_log_store','employee',(string)$row['employee_id'],['employee_name'=>$row['full_name'],'log_store'=>'','shift_id'=>$row['shift_public_id']]);
                }else merd_sheet_outbox($pdo,(int)$super['client_id'],'attendance_correction','attendance_shift',$row['shift_public_id'],['employee_name'=>$row['full_name'],'store_name'=>$row['store_name'],'old_clock_in_at_utc'=>$row['clock_in_at'],'old_clock_out_at_utc'=>$row['clock_out_at'],'new_clock_in_at_utc'=>$newIn,'new_clock_out_at_utc'=>$newOut,'dispute_id'=>$disputePublicId]);
            }
        }
        $updateDispute = $pdo->prepare(
            'UPDATE attendance_disputes SET status=?,decided_by_employee_id=?,decided_at=?,decision_note=?,applied_at=?,after_snapshot=? WHERE id=?'
        );
        $updateDispute->execute([
            $decision, (int)$super['id'], $now, $note, $decision === 'approved' ? $now : null, $after, (int)$row['id'],
        ]);
        merd_sheet_outbox($pdo, (int)$super['client_id'], 'dispute_audit', 'attendance_dispute', $disputePublicId . ':' . $decision, [
            'dispute_id' => $disputePublicId, 'shift_id' => $row['shift_public_id'], 'employee_name' => $row['full_name'],
            'status' => $decision, 'decided_by' => $super['full_name'], 'decided_at_utc' => $now, 'decision_note' => $note,
        ]);
        $pdo->commit();
        return ['dispute_id' => $disputePublicId, 'status' => $decision, 'duplicate' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function merd_money_cents(mixed $value, string $message = 'Enter a valid amount.'): int
{
    if (!is_numeric($value)) throw new MerdWorkforceException('invalid_amount', $message);
    $amount = (float)$value;
    if (!is_finite($amount) || $amount < 0 || $amount > 9999999999.99) {
        throw new MerdWorkforceException('invalid_amount', $message);
    }
    return (int)round($amount * 100, 0, PHP_ROUND_HALF_UP);
}

function merd_money(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function merd_financial_store(PDO $pdo, array $employee, int $storeId, bool $requireWorking = true): string
{
    $store = $pdo->prepare("SELECT store_name FROM stores WHERE id=? AND client_id=? AND status='active' LIMIT 1");
    $store->execute([$storeId, (int)$employee['client_id']]);
    $storeName = $store->fetchColumn();
    if (!is_string($storeName)) throw new MerdWorkforceException('store_not_found', 'Store not found.');
    if ($requireWorking && !merd_employee_is_super($employee)) {
        $working = $pdo->prepare("SELECT id FROM attendance_shifts WHERE client_id=? AND employee_id=? AND store_id=? AND status='open' LIMIT 1");
        $working->execute([(int)$employee['client_id'], (int)$employee['id'], $storeId]);
        if (!$working->fetchColumn()) throw new MerdWorkforceException('not_working_at_store', 'Clock in at this store before using its financials.');
    }
    return $storeName;
}

function merd_financial_statement(PDO $pdo, array $employee, int $storeId, string $businessDate): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $businessDate) throw new MerdWorkforceException('invalid_business_date', 'Invalid business date.');
    $storeName = merd_financial_store($pdo, $employee, $storeId);
    $accounts = $pdo->prepare(
        'SELECT account,opening_amount,in_total,out_total,closing_amount,status FROM financial_day_accounts '
        . 'WHERE client_id=? AND store_id=? AND business_date=? ORDER BY account'
    );
    $accounts->execute([(int)$employee['client_id'], $storeId, $businessDate]);
    $byName = [];
    foreach ($accounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $available = (int)round(((float)$row['opening_amount'] + (float)$row['in_total'] - (float)$row['out_total']) * 100);
        $byName[(string)$row['account']] = [
            'account' => $row['account'], 'opening' => (float)$row['opening_amount'], 'cash_in' => (float)$row['in_total'],
            'cash_out' => (float)$row['out_total'], 'available' => $available / 100,
            'closing' => $row['closing_amount'] === null ? null : (float)$row['closing_amount'], 'status' => $row['status'],
        ];
    }
    $entries = $pdo->prepare(
        'SELECT f.public_id AS submission_id,l.account,l.entry_type,l.head,l.amount,l.created_at,e.full_name '
        . 'FROM financial_ledger_entries l INNER JOIN financial_submissions f ON f.id=l.submission_id '
        . 'INNER JOIN employees e ON e.id=f.employee_id WHERE l.client_id=? AND l.store_id=? AND l.business_date=? ORDER BY l.id DESC LIMIT 100'
    );
    $entries->execute([(int)$employee['client_id'], $storeId, $businessDate]);
    return [
        'store_id' => $storeId, 'store_name' => $storeName, 'business_date' => $businessDate,
        'day_status' => !$byName ? 'not_open' : (($byName['Register']['status'] ?? '') === 'closed' ? 'closed' : 'open'),
        'accounts' => array_values($byName), 'entries' => $entries->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function merd_financial_day_rows(PDO $pdo, int $clientId, int $storeId, string $businessDate): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM financial_day_accounts WHERE client_id=? AND store_id=? AND business_date=? ORDER BY account FOR UPDATE'
    );
    $stmt->execute([$clientId, $storeId, $businessDate]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(string)$row['account']] = $row;
    return $rows;
}

function merd_financial_ledger_line(PDO $pdo, int $submissionId, int $lineNo, int $clientId, int $storeId, string $date, string $account, string $type, string $head, int $amountCents): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO financial_ledger_entries (submission_id,line_no,client_id,store_id,business_date,account,entry_type,head,amount) VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$submissionId, $lineNo, $clientId, $storeId, $date, $account, $type, $head, merd_money($amountCents)]);
}

function merd_submit_financial(PDO $pdo, array $employee, array $input): array
{
    $publicId = strtolower(trim((string)($input['submission_id'] ?? '')));
    $type = trim((string)($input['submission_type'] ?? ''));
    $businessDate = trim((string)($input['business_date'] ?? ''));
    $storeId = filter_var($input['store_id'] ?? null, FILTER_VALIDATE_INT);
    $payload = $input['payload'] ?? null;
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $publicId)
        || !in_array($type, ['open_day', 'cash_in', 'cash_out', 'z_report'], true)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) || !$storeId || !is_array($payload)) {
        throw new MerdWorkforceException('invalid_financial_submission', 'Invalid financial submission.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $businessDate) throw new MerdWorkforceException('invalid_business_date', 'Invalid business date.');
    if ($type === 'open_day' && !merd_employee_is_super($employee)) throw new MerdWorkforceException('forbidden', 'Only a SUPER user can open a financial day.');
    $storeName = merd_financial_store($pdo, $employee, (int)$storeId, $type !== 'open_day');
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($payloadJson) > 100000) throw new MerdWorkforceException('payload_too_large', 'Financial submission is too large.');
    $payloadHash = hash('sha256', $type . '|' . $storeId . '|' . $businessDate . '|' . $payloadJson);

    $transactions = [];
    if ($type === 'cash_in' || $type === 'cash_out') {
        $transactions = $payload['transactions'] ?? null;
        if (!is_array($transactions) || !array_is_list($transactions) || count($transactions) < 1 || count($transactions) > 100) {
            throw new MerdWorkforceException('invalid_financial_rows', 'Add at least one valid transaction.');
        }
        foreach ($transactions as &$transaction) {
            if (!is_array($transaction) || !in_array((string)($transaction['account'] ?? ''), ['Register', 'Petty Cash'], true)
                || strlen(trim((string)($transaction['head'] ?? ''))) < 2 || strlen((string)$transaction['head']) > 120) {
                throw new MerdWorkforceException('invalid_financial_rows', 'A financial transaction is invalid.');
            }
            $transaction['_cents'] = merd_money_cents($transaction['amount'] ?? null);
            if ($transaction['_cents'] < 1) throw new MerdWorkforceException('invalid_financial_rows', 'Amount must be greater than zero.');
        }
        unset($transaction);
    } elseif ($type === 'open_day') {
        $registerOpening = merd_money_cents($payload['register_opening'] ?? null, 'Enter a valid Register opening.');
        $pettyOpening = merd_money_cents($payload['petty_cash_opening'] ?? null, 'Enter a valid Petty Cash opening.');
    } else {
        $registerTotal = merd_money_cents($payload['register_total'] ?? null, 'Enter a valid Register total.');
        $pettyAddin = merd_money_cents($payload['petty_cash_addin'] ?? 0, 'Enter a valid Petty Cash add-in.');
        if ($pettyAddin > $registerTotal) throw new MerdWorkforceException('invalid_z_report', 'Petty Cash add-in cannot exceed the counted Register total.');
    }

    $clientId = (int)$employee['client_id'];
    $pdo->beginTransaction();
    try {
        if (!merd_employee_is_super($employee) && $type !== 'open_day') {
            $working = $pdo->prepare("SELECT id FROM attendance_shifts WHERE client_id=? AND employee_id=? AND store_id=? AND status='open' LIMIT 1 FOR UPDATE");
            $working->execute([$clientId, (int)$employee['id'], (int)$storeId]);
            if (!$working->fetchColumn()) throw new MerdWorkforceException('not_working_at_store', 'Clock in at this store before using its financials.');
        }
        $existing = $pdo->prepare('SELECT status,accepted_at,payload_hash FROM financial_submissions WHERE public_id=? LIMIT 1 FOR UPDATE');
        $existing->execute([$publicId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($prior)) {
            if (!hash_equals((string)$prior['payload_hash'], $payloadHash)) throw new MerdWorkforceException('idempotency_conflict', 'Submission ID was already used for different data.');
            $pdo->commit();
            return ['submission_id' => $publicId, 'status' => $prior['status'], 'accepted_at' => $prior['accepted_at'], 'duplicate' => true];
        }
        $days = merd_financial_day_rows($pdo, $clientId, (int)$storeId, $businessDate);
        if ($type === 'open_day' && $days) throw new MerdWorkforceException('day_already_opened', 'This financial day has already been opened.');
        if ($type !== 'open_day' && (count($days) !== 2 || !isset($days['Register'], $days['Petty Cash']))) {
            throw new MerdWorkforceException('financial_day_not_open', 'A SUPER user must open this financial day first.');
        }
        if ($type !== 'open_day' && ($days['Register']['status'] !== 'open' || $days['Petty Cash']['status'] !== 'open')) {
            throw new MerdWorkforceException('financial_day_closed', 'This financial day is already closed.');
        }
        if ($type === 'cash_out') {
            $requested = ['Register' => 0, 'Petty Cash' => 0];
            foreach ($transactions as $transaction) $requested[$transaction['account']] += $transaction['_cents'];
            foreach ($requested as $account => $cents) {
                $available = (int)round(((float)$days[$account]['opening_amount'] + (float)$days[$account]['in_total'] - (float)$days[$account]['out_total']) * 100);
                if ($cents > $available) throw new MerdWorkforceException('insufficient_balance', $account . ' has $' . merd_money($available) . ' available. Cash OUT cannot exceed this amount.');
            }
        }
        if ($type === 'z_report') {
            $nextDate = $date->modify('+1 day')->format('Y-m-d');
            if (merd_financial_day_rows($pdo, $clientId, (int)$storeId, $nextDate)) throw new MerdWorkforceException('next_day_exists', 'The next financial day already exists. Review it before closing today.');
            $registerAvailable = (int)round(((float)$days['Register']['opening_amount'] + (float)$days['Register']['in_total'] - (float)$days['Register']['out_total']) * 100);
            $pettyAvailable = (int)round(((float)$days['Petty Cash']['opening_amount'] + (float)$days['Petty Cash']['in_total'] - (float)$days['Petty Cash']['out_total']) * 100);
            $sales = $registerTotal - $registerAvailable + $pettyAddin;
            if ($sales < 0) throw new MerdWorkforceException('closing_mismatch', 'Register total is below the recorded balance. Review Cash IN/OUT before closing.');
            $pettyClosing = $pettyAvailable + $pettyAddin;
        }

        $insert = $pdo->prepare(
            "INSERT INTO financial_submissions (public_id,client_id,store_id,employee_id,submission_type,business_date,payload,payload_hash,status) VALUES (?,?,?,?,?,?,?,?, 'sheet_pending')"
        );
        $insert->execute([$publicId, $clientId, (int)$storeId, (int)$employee['id'], $type, $businessDate, $payloadJson, $payloadHash]);
        $submissionId = (int)$pdo->lastInsertId();
        $line = 1;
        $sheetPayload = $payload;

        if ($type === 'open_day') {
            $open = $pdo->prepare('INSERT INTO financial_day_accounts (client_id,store_id,business_date,account,opening_amount,opened_by_employee_id) VALUES (?,?,?,?,?,?)');
            foreach ([['Register', $registerOpening], ['Petty Cash', $pettyOpening]] as [$account, $cents]) {
                $open->execute([$clientId, (int)$storeId, $businessDate, $account, merd_money($cents), (int)$employee['id']]);
                merd_financial_ledger_line($pdo, $submissionId, $line++, $clientId, (int)$storeId, $businessDate, $account, 'OPENING', 'OPENING', $cents);
            }
        } elseif ($type === 'cash_in' || $type === 'cash_out') {
            $field = $type === 'cash_in' ? 'in_total' : 'out_total';
            $update = $pdo->prepare("UPDATE financial_day_accounts SET {$field}={$field}+? WHERE id=?");
            foreach ($transactions as $transaction) {
                $update->execute([merd_money($transaction['_cents']), (int)$days[$transaction['account']]['id']]);
                merd_financial_ledger_line($pdo, $submissionId, $line++, $clientId, (int)$storeId, $businessDate, $transaction['account'], strtoupper(substr($type, 5)), trim((string)$transaction['head']), $transaction['_cents']);
            }
        } else {
            $close = $pdo->prepare("UPDATE financial_day_accounts SET in_total=in_total+?,out_total=out_total+?,closing_amount=?,status='closed',closed_by_submission_id=?,closed_at=UTC_TIMESTAMP() WHERE id=?");
            $close->execute([merd_money($sales), merd_money($pettyAddin), merd_money($registerTotal), $submissionId, (int)$days['Register']['id']]);
            $close->execute([merd_money($pettyAddin), '0.00', merd_money($pettyClosing), $submissionId, (int)$days['Petty Cash']['id']]);
            foreach ([
                ['Register','IN','Sales (ACTUAL)',$sales], ['Register','OUT','Cash Out (' . $employee['full_name'] . ')',$pettyAddin],
                ['Petty Cash','IN','Cash In (' . $employee['full_name'] . ')',$pettyAddin], ['Register','CLOSING','CLOSING',$registerTotal],
                ['Petty Cash','CLOSING','CLOSING',$pettyClosing],
            ] as [$account, $entryType, $head, $cents]) merd_financial_ledger_line($pdo, $submissionId, $line++, $clientId, (int)$storeId, $businessDate, $account, $entryType, $head, $cents);
            $open = $pdo->prepare('INSERT INTO financial_day_accounts (client_id,store_id,business_date,account,opening_amount,opened_by_employee_id) VALUES (?,?,?,?,?,?)');
            foreach ([['Register',$registerTotal],['Petty Cash',$pettyClosing]] as [$account,$cents]) {
                $open->execute([$clientId,(int)$storeId,$nextDate,$account,merd_money($cents),(int)$employee['id']]);
                merd_financial_ledger_line($pdo,$submissionId,$line++,$clientId,(int)$storeId,$nextDate,$account,'OPENING','OPENING',$cents);
            }
            $sheetPayload['_calculated'] = ['sales_actual' => (float)merd_money($sales), 'petty_cash_closing' => (float)merd_money($pettyClosing), 'next_business_date' => $nextDate];
        }
        merd_sheet_outbox($pdo, $clientId, 'financial_submission', 'financial_submission', $publicId, [
            'submission_id' => $publicId, 'submission_type' => $type, 'business_date' => $businessDate,
            'store_name' => $storeName, 'employee_name' => $employee['full_name'], 'payload' => $sheetPayload,
        ]);
        $pdo->commit();
        return ['submission_id' => $publicId, 'status' => 'sheet_pending', 'accepted_at' => gmdate('Y-m-d H:i:s'), 'duplicate' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
