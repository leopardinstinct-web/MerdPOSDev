<?php
declare(strict_types=1);

const MERD_CATALOGUE_INCREMENTAL_CONTRACT_VERSION = 'm2.catalogue.incremental.v1';
const MERD_CATALOGUE_CURSOR_RETENTION_DAYS = 30;
const MERD_CATALOGUE_CURSOR_RETENTION_COUNT = 256;
const MERD_CATALOGUE_BATCH_RETENTION_HOURS = 24;
const MERD_CATALOGUE_DEFAULT_PAGE_SIZE = 100;
const MERD_CATALOGUE_MAX_PAGE_SIZE = 250;

function merd_catalogue_token(string $prefix): string
{
    return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function merd_catalogue_database_time(DateTimeImmutable $time): string
{
    return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

function merd_catalogue_register_snapshot(
    PDO $pdo,
    array $response,
    int $clientId,
    int $storeId,
    DateTimeImmutable $now
): array {
    $revision = (string)($response['snapshot_revision'] ?? '');
    $snapshot = $response['snapshot'] ?? null;
    if ($revision === '' || !is_array($snapshot)) {
        throw new RuntimeException('Catalogue snapshot cannot be registered.');
    }
    $nowText = merd_catalogue_database_time($now);
    $existing = $pdo->prepare(
        'SELECT cursor_token FROM retail_catalogue_cursor_snapshots '
        . 'WHERE client_id=? AND store_id=? AND snapshot_revision=? AND expires_at_utc>? '
        . 'ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([$clientId, $storeId, $revision, $nowText]);
    $cursor = $existing->fetchColumn();
    if (!is_string($cursor) || $cursor === '') {
        $cursor = merd_catalogue_token('m2c1_');
        $expires = $now->modify('+' . MERD_CATALOGUE_CURSOR_RETENTION_DAYS . ' days');
        $statement = $pdo->prepare(
            'INSERT INTO retail_catalogue_cursor_snapshots '
            . '(client_id,store_id,cursor_token,snapshot_revision,snapshot_json,created_at_utc,expires_at_utc) '
            . 'VALUES (?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $clientId,
            $storeId,
            $cursor,
            $revision,
            json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $nowText,
            merd_catalogue_database_time($expires),
        ]);
        merd_catalogue_prune_cursors($pdo, $clientId, $storeId, $now);
    }
    $response['cursor_seed'] = $cursor;
    return $response;
}

function merd_catalogue_prune_cursors(
    PDO $pdo,
    int $clientId,
    int $storeId,
    DateTimeImmutable $now
): void {
    $expired = $pdo->prepare(
        'DELETE FROM retail_catalogue_cursor_snapshots '
        . 'WHERE client_id=? AND store_id=? AND expires_at_utc<=?'
    );
    $expired->execute([$clientId, $storeId, merd_catalogue_database_time($now)]);
    $overflow = $pdo->prepare(
        'DELETE FROM retail_catalogue_cursor_snapshots WHERE client_id=? AND store_id=? '
        . 'AND id NOT IN (SELECT id FROM (SELECT id FROM retail_catalogue_cursor_snapshots '
        . 'WHERE client_id=? AND store_id=? ORDER BY id DESC LIMIT '
        . MERD_CATALOGUE_CURSOR_RETENTION_COUNT . ') retained)'
    );
    $overflow->execute([$clientId, $storeId, $clientId, $storeId]);
}

function merd_catalogue_cursor_row(
    PDO $pdo,
    int $clientId,
    int $storeId,
    string $cursor,
    DateTimeImmutable $now
): array {
    $statement = $pdo->prepare(
        'SELECT cursor_token,snapshot_revision,snapshot_json FROM retail_catalogue_cursor_snapshots '
        . 'WHERE client_id=? AND store_id=? AND cursor_token=? AND expires_at_utc>? LIMIT 1'
    );
    $statement->execute([$clientId, $storeId, $cursor, merd_catalogue_database_time($now)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new MerdRequestException(
            'catalogue_cursor_expired',
            409,
            'Catalogue cursor is unavailable; a full synchronization is required.'
        );
    }
    $decoded = json_decode((string)$row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stored catalogue cursor is invalid.');
    }
    $row['snapshot'] = $decoded;
    return $row;
}

function merd_catalogue_index(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !array_key_exists('id', $row)) {
            throw new RuntimeException('Catalogue entity lacks stable identity.');
        }
        $indexed[(string)$row['id']] = $row;
    }
    ksort($indexed, SORT_NATURAL);
    return $indexed;
}

function merd_catalogue_diff_entities(
    string $entityType,
    array $sourceRows,
    array $targetRows,
    int $upsertOrder,
    int $tombstoneOrder
): array {
    $source = merd_catalogue_index($sourceRows);
    $target = merd_catalogue_index($targetRows);
    $events = [];
    foreach ($target as $id => $payload) {
        if (!isset($source[$id]) || $source[$id] !== $payload) {
            $events[] = [
                'sort_order' => $upsertOrder,
                'entity_type' => $entityType,
                'operation' => 'upsert',
                'server_id' => (int)$id,
                'payload' => $payload,
            ];
        }
    }
    foreach ($source as $id => $_) {
        if (!isset($target[$id])) {
            $events[] = [
                'sort_order' => $tombstoneOrder,
                'entity_type' => $entityType,
                'operation' => 'tombstone',
                'server_id' => (int)$id,
                'payload' => null,
            ];
        }
    }
    return $events;
}

function merd_catalogue_incremental_events(array $source, array $target): array
{
    $events = [];
    $definitions = [
        ['category', 'categories', 10, 95],
        ['tax_code', 'tax_codes', 20, 94],
        ['tax_rate', 'effective_tax_rates', 30, 93],
        ['product', 'products', 40, 92],
        ['tax_assignment', 'product_tax_assignments', 50, 91],
    ];
    foreach ($definitions as [$entity, $key, $upsertOrder, $tombstoneOrder]) {
        $sourceRows = $source[$key] ?? null;
        $targetRows = $target[$key] ?? null;
        if (!is_array($sourceRows) || !is_array($targetRows)) {
            throw new RuntimeException('Stored catalogue snapshot is incomplete.');
        }
        array_push(
            $events,
            ...merd_catalogue_diff_entities($entity, $sourceRows, $targetRows, $upsertOrder, $tombstoneOrder)
        );
    }
    foreach (['currency', 'warnings'] as $key) {
        if (($source[$key] ?? null) !== ($target[$key] ?? null)) {
            $events[] = [
                'sort_order' => $key === 'currency' ? 5 : 60,
                'entity_type' => $key === 'currency' ? 'catalogue_meta' : 'warning_set',
                'operation' => 'replace',
                'server_id' => 0,
                'payload' => $target[$key] ?? ($key === 'warnings' ? [] : null),
            ];
        }
    }
    usort($events, static function (array $left, array $right): int {
        return [$left['sort_order'], $left['server_id'], $left['entity_type']]
            <=> [$right['sort_order'], $right['server_id'], $right['entity_type']];
    });
    return array_map(static function (array $event): array {
        unset($event['sort_order']);
        return $event;
    }, $events);
}

function merd_catalogue_create_batch(
    PDO $pdo,
    int $clientId,
    int $storeId,
    array $sourceRow,
    array $targetResponse,
    int $pageSize,
    DateTimeImmutable $now
): array {
    $targetResponse = merd_catalogue_register_snapshot(
        $pdo,
        $targetResponse,
        $clientId,
        $storeId,
        $now
    );
    $targetCursor = (string)$targetResponse['cursor_seed'];
    $events = merd_catalogue_incremental_events(
        $sourceRow['snapshot'],
        $targetResponse['snapshot']
    );
    $batchToken = merd_catalogue_token('m2b1_');
    $statement = $pdo->prepare(
        'INSERT INTO retail_catalogue_sync_batches '
        . '(client_id,store_id,batch_token,source_cursor_token,target_cursor_token,'
        . 'target_snapshot_revision,events_json,page_size,created_at_utc,expires_at_utc) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $statement->execute([
        $clientId,
        $storeId,
        $batchToken,
        (string)$sourceRow['cursor_token'],
        $targetCursor,
        (string)$targetResponse['snapshot_revision'],
        json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $pageSize,
        merd_catalogue_database_time($now),
        merd_catalogue_database_time($now->modify('+' . MERD_CATALOGUE_BATCH_RETENTION_HOURS . ' hours')),
    ]);
    return [
        'batch_token' => $batchToken,
        'source_cursor_token' => (string)$sourceRow['cursor_token'],
        'target_cursor_token' => $targetCursor,
        'target_snapshot_revision' => (string)$targetResponse['snapshot_revision'],
        'events' => $events,
        'page_size' => $pageSize,
    ];
}

function merd_catalogue_batch_row(
    PDO $pdo,
    int $clientId,
    int $storeId,
    string $batchToken,
    DateTimeImmutable $now
): array {
    $statement = $pdo->prepare(
        'SELECT batch_token,source_cursor_token,target_cursor_token,target_snapshot_revision,'
        . 'events_json,page_size FROM retail_catalogue_sync_batches '
        . 'WHERE client_id=? AND store_id=? AND batch_token=? AND expires_at_utc>? LIMIT 1'
    );
    $statement->execute([$clientId, $storeId, $batchToken, merd_catalogue_database_time($now)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new MerdRequestException('catalogue_page_expired', 409, 'Catalogue page expired; retry synchronization.');
    }
    $events = json_decode((string)$row['events_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($events)) {
        throw new RuntimeException('Stored catalogue batch is invalid.');
    }
    $row['events'] = $events;
    return $row;
}

function merd_catalogue_incremental_response(
    array $batch,
    int $pageIndex,
    array $authorizedDevice,
    DateTimeImmutable $now
): array {
    $pageSize = (int)$batch['page_size'];
    $events = $batch['events'];
    $pageCount = max(1, (int)ceil(count($events) / $pageSize));
    if ($pageIndex < 0 || $pageIndex >= $pageCount) {
        throw new MerdRequestException('invalid_catalogue_page', 400, 'Invalid catalogue page.');
    }
    $final = $pageIndex === $pageCount - 1;
    return [
        'success' => true,
        'api' => 'sync_catalogue.php',
        'contract_version' => MERD_CATALOGUE_INCREMENTAL_CONTRACT_VERSION,
        'sync_type' => 'incremental',
        'context' => [
            'client_id' => (int)$authorizedDevice['device']['client_id'],
            'store_id' => (int)$authorizedDevice['device']['store_id'],
            'device_uuid' => (string)$authorizedDevice['device']['device_uuid'],
        ],
        'source_cursor' => (string)$batch['source_cursor_token'],
        'batch_token' => (string)$batch['batch_token'],
        'page_index' => $pageIndex,
        'page_count' => $pageCount,
        'events' => array_slice($events, $pageIndex * $pageSize, $pageSize),
        'has_more' => !$final,
        'next_page_index' => $final ? null : $pageIndex + 1,
        'next_cursor' => $final ? (string)$batch['target_cursor_token'] : null,
        'target_snapshot_revision' => (string)$batch['target_snapshot_revision'],
        'server_time_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
    ];
}

function merd_catalogue_handle_incremental(
    PDO $pdo,
    array $authorizedDevice,
    array $body,
    DateTimeImmutable $now
): array {
    $clientId = (int)$authorizedDevice['device']['client_id'];
    $storeId = (int)$authorizedDevice['device']['store_id'];
    $batchToken = isset($body['batch_token'])
        ? merd_request_text($body['batch_token'], 'batch_token', 120)
        : null;
    $pageIndex = isset($body['page_index']) ? merd_request_nonnegative_int($body['page_index']) : 0;
    if ($batchToken !== null) {
        $batch = merd_catalogue_batch_row($pdo, $clientId, $storeId, $batchToken, $now);
        return merd_catalogue_incremental_response($batch, $pageIndex, $authorizedDevice, $now);
    }
    $cursor = merd_request_text($body['cursor'] ?? null, 'cursor', 120);
    $pageSize = isset($body['page_size'])
        ? merd_request_positive_int($body['page_size'], 'page_size')
        : MERD_CATALOGUE_DEFAULT_PAGE_SIZE;
    if ($pageSize > MERD_CATALOGUE_MAX_PAGE_SIZE) {
        throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
    }
    $source = merd_catalogue_cursor_row($pdo, $clientId, $storeId, $cursor, $now);
    $target = merd_catalogue_full_snapshot($pdo, $authorizedDevice, $now);
    $batch = merd_catalogue_create_batch($pdo, $clientId, $storeId, $source, $target, $pageSize, $now);
    return merd_catalogue_incremental_response($batch, 0, $authorizedDevice, $now);
}
