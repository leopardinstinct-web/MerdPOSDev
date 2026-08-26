<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/config.php';

$url = getenv('MERD_SHEETS_SYNC_URL') ?: '';
$secret = getenv('MERD_SHEETS_SYNC_SECRET') ?: '';
if ($url === '' || $secret === '') {
    fwrite(STDERR, "MERD_SHEETS_SYNC_URL and MERD_SHEETS_SYNC_SECRET are required.\n");
    exit(2);
}

$limit = max(1, min(50, (int)($argv[1] ?? 25)));
$pdo->beginTransaction();
try {
    /*
     * Do not mirror a historical Google -> MERDPOS import back into Google.
     *
     * Two guards are intentional:
     *  1) a running migration batch temporarily freezes outbound events for that
     *     client, closing the tiny window before lineage is stamped; and
     *  2) financial submissions permanently recorded as legacy lineage are
     *     excluded even after the batch completes.
     *
     * Native MERDPOS events are unaffected and keep their normal Sheet mirror.
     */
    $select = $pdo->prepare(
        "SELECT o.id,o.event_id,o.event_type,o.payload,o.attempts "
        . "FROM google_sheet_outbox o "
        . "LEFT JOIN legacy_migration_records lr ON lr.client_id=o.client_id "
        . " AND lr.source_type='financial' AND lr.target_table='financial_submissions' "
        . " AND lr.target_key=o.aggregate_id AND lr.status='active' "
        . "WHERE ((o.status IN ('pending','failed') AND o.available_at<=UTC_TIMESTAMP()) "
        . "OR (o.status='processing' AND o.locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))) "
        . "AND o.attempts<10 AND lr.id IS NULL "
        . "AND NOT EXISTS (SELECT 1 FROM legacy_migration_batches mb "
        . " WHERE mb.client_id=o.client_id AND mb.status='running') "
        . "ORDER BY o.id LIMIT {$limit} FOR UPDATE"
    );
    $select->execute();
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { $pdo->commit(); echo "No pending Sheet events.\n"; exit(0); }
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $mark = $pdo->prepare("UPDATE google_sheet_outbox SET status='processing',locked_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id IN ({$marks})");
    $mark->execute($ids);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$events = array_map(static function(array $row): array {
    return ['event_id' => $row['event_id'], 'event_type' => $row['event_type'], 'payload' => json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR)];
}, $rows);
$timestamp = time();
$nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$eventsJson = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$body = json_encode([
    'ts' => $timestamp, 'nonce' => $nonce, 'events' => $events,
    'signature' => hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $eventsJson, $secret),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $body]);
$raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
$response = is_string($raw) ? json_decode($raw, true) : null;
$byId = [];
if ($status >= 200 && $status < 300 && is_array($response) && ($response['success'] ?? false)) {
    foreach (($response['results'] ?? []) as $result) if (isset($result['event_id'])) $byId[$result['event_id']] = $result;
}

$synced = $failed = 0;
foreach ($rows as $row) {
    $result = $byId[$row['event_id']] ?? null;
    if (is_array($result) && ($result['success'] ?? false)) {
        $update = $pdo->prepare("UPDATE google_sheet_outbox SET status='synced',synced_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?");
        $update->execute([(int)$row['id']]);
        if ($row['event_type'] === 'financial_submission') {
            $payload = json_decode($row['payload'], true);
            $done = $pdo->prepare("UPDATE financial_submissions SET status='sheet_synced',sheet_synced_at=UTC_TIMESTAMP() WHERE public_id=?");
            $done->execute([(string)($payload['submission_id'] ?? '')]);
        }
        $synced++;
    } else {
        $error = is_array($result) ? (string)($result['error'] ?? 'sheet_rejected') : ('HTTP ' . $status . ' ' . $curlError);
        $delay = min(3600, 30 * (2 ** min(6, (int)$row['attempts'])));
        $update = $pdo->prepare("UPDATE google_sheet_outbox SET status='failed',available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error=? WHERE id=?");
        $update->execute([$delay, substr($error, 0, 1000), (int)$row['id']]);
        if ($row['event_type'] === 'financial_submission') {
            $payload = json_decode($row['payload'], true);
            $pdo->prepare("UPDATE financial_submissions SET status='sheet_failed' WHERE public_id=?")
                ->execute([(string)($payload['submission_id'] ?? '')]);
        }
        $failed++;
    }
}
echo "Sheet sync: {$synced} synced, {$failed} failed.\n";
exit($failed ? 1 : 0);
