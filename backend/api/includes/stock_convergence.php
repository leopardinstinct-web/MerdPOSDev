<?php
declare(strict_types=1);

const MERD_STOCK_SYNC_CONTRACT_VERSION = 'm2.stock.sync.v1';

function merd_stock_decimal(mixed $value): string
{
    if (!is_int($value) && !is_string($value)) {
        throw new MerdRequestException('invalid_stock_quantity', 422, 'Stock quantity is invalid.');
    }
    $text = trim((string)$value);
    if (!preg_match('/^(-?)(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,3}))?$/D', $text, $matches)) {
        throw new MerdRequestException('invalid_stock_quantity', 422, 'Stock quantity is invalid.');
    }
    $fraction = str_pad($matches[3] ?? '', 3, '0');
    if ($matches[2] === '0' && $fraction === '000') {
        throw new MerdRequestException('invalid_stock_quantity', 422, 'Stock quantity is invalid.');
    }
    return ($matches[1] ?? '') . $matches[2] . '.' . $fraction;
}

function merd_stock_balance(PDO $pdo, int $clientId, int $storeId, int $productId): array
{
    $statement = $pdo->prepare(
        'SELECT CAST(b.quantity AS CHAR) quantity,b.revision,b.updated_at_utc,'
        . 'e.status negative_stock_status,CAST(e.lowest_observed_balance AS CHAR) lowest_observed_balance '
        . 'FROM retail_stock_balances b LEFT JOIN retail_negative_stock_exceptions e '
        . 'ON e.client_id=b.client_id AND e.store_id=b.store_id AND e.product_id=b.product_id '
        . 'WHERE b.client_id=? AND b.store_id=? AND b.product_id=?'
    );
    $statement->execute([$clientId, $storeId, $productId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row)
        ? [
            'quantity' => (string)$row['quantity'],
            'revision' => (int)$row['revision'],
            'updated_at_utc' => (string)$row['updated_at_utc'],
            'negative_stock' => $row['negative_stock_status'] !== null,
            'negative_stock_status' => $row['negative_stock_status'],
            'lowest_observed_balance' => $row['lowest_observed_balance'],
        ]
        : ['quantity' => '0.000', 'revision' => 0, 'updated_at_utc' => null, 'negative_stock' => false, 'negative_stock_status' => null, 'lowest_observed_balance' => null];
}

function merd_stock_apply_device_movement(PDO $pdo, array $auth, array $move): array
{
    $localId = merd_request_positive_int($move['id'] ?? null, 'stock_movements.id');
    $productId = merd_request_positive_int($move['server_product_id'] ?? $move['product_id'] ?? null, 'stock_movements.product_id');
    $quantity = merd_stock_decimal($move['quantity_decimal'] ?? $move['quantity'] ?? null);
    $reference = merd_request_text($move['reference'] ?? '', 'stock_movements.reference', 191);
    if ($reference === '') {
        throw new MerdRequestException('invalid_stock_reference', 422, 'Stock reference is invalid.');
    }
    $clientId = (int)$auth['client_id'];
    $storeId = (int)$auth['store_id'];
    $deviceUuid = (string)$auth['device_uuid'];
    $deviceKey = hash('sha256', $deviceUuid);
    $idempotencyKey = 'device:' . $deviceKey . ':movement:' . $localId;
    $sourceRecordKey = $deviceKey . ':' . $localId;
    $kind = (string)($move['movement_type'] ?? 'adjustment');
    if ($kind === 'sale' && $quantity[0] !== '-') {
        throw new MerdRequestException('invalid_stock_direction', 422, 'Stock movement direction is invalid.');
    }
    $movementType = $kind === 'sale' ? 'sale' : ($quantity[0] === '-' ? 'adjustment_decrease' : 'adjustment_increase');
    $reason = $movementType === 'sale' ? null : 'device_adjustment';
    $existing = $pdo->prepare(
        'SELECT id,product_id,movement_type,CAST(signed_quantity AS CHAR) signed_quantity FROM retail_stock_ledger_movements '
        . 'WHERE client_id=? AND store_id=? AND idempotency_key=? LIMIT 1'
    );
    $existing->execute([$clientId, $storeId, $idempotencyKey]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($existingRow)) {
        if ((int)$existingRow['product_id'] !== $productId
            || (string)$existingRow['movement_type'] !== $movementType
            || (string)$existingRow['signed_quantity'] !== $quantity) {
            throw new MerdRequestException('stock_replay_conflict', 409, 'Stock movement replay conflicts with the accepted record.');
        }
        return ['local_id' => $localId, 'outcome' => 'duplicate', 'server_movement_id' => (int)$existingRow['id'], 'balance' => merd_stock_balance($pdo, $clientId, $storeId, $productId)];
    }
    $product = $pdo->prepare('SELECT id FROM retail_products WHERE id=? AND client_id=? LIMIT 1');
    $product->execute([$productId, $clientId]);
    if (!$product->fetchColumn()) {
        throw new MerdRequestException('stock_product_unavailable', 422, 'Stock product is unavailable.');
    }
    $occurredAt = clean_timestamp($move['created_at'] ?? null);
    $insert = $pdo->prepare(
        'INSERT INTO retail_stock_ledger_movements '
        . '(client_id,store_id,product_id,movement_type,signed_quantity,balance_before,balance_after,balance_revision,'
        . 'source_type,source_record_key,idempotency_key,device_uuid,actor_type,actor_id,reason_code,note,occurred_at_utc,metadata_json) '
        . 'VALUES (?,?,?,?,?,0,0,0,?,?,?,?,?,?,?,?,?,?)'
    );
    $metadata = json_encode(['device_reference' => $reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $insert->execute([$clientId,$storeId,$productId,$movementType,$quantity,'device_movement',$sourceRecordKey,$idempotencyKey,$deviceUuid,'device',$deviceUuid,$reason,clean_text($move['note'] ?? '',500),$occurredAt,$metadata]);
    return ['local_id' => $localId, 'outcome' => 'accepted', 'server_movement_id' => (int)$pdo->lastInsertId(), 'balance' => merd_stock_balance($pdo, $clientId, $storeId, $productId)];
}
