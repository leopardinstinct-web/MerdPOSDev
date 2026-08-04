<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function reply(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
function positive_int(mixed $value): ?int {
    $v = filter_var($value, FILTER_VALIDATE_INT);
    return ($v !== false && $v > 0) ? (int)$v : null;
}
function clean_text(mixed $value, int $max): string {
    return mb_substr(trim((string)$value), 0, $max);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['success'=>false,'error'=>'POST required.'],405);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) reply(['success'=>false,'error'=>'Invalid request.'],400);

    $clientId = positive_int($body['client_id'] ?? null);
    $storeId = positive_int($body['store_id'] ?? null);
    $deviceUuid = clean_text($body['device_uuid'] ?? '', 191);
    $token = clean_text($body['activation_token'] ?? '', 255);
    if (!$clientId || !$storeId || $deviceUuid === '' || $token === '') reply(['success'=>false,'error'=>'Invalid request.'],400);

    $device = $pdo->prepare("SELECT id FROM devices WHERE client_id=? AND store_id=? AND device_uuid=? AND activation_token=? AND status='active' LIMIT 1");
    $device->execute([$clientId,$storeId,$deviceUuid,$token]);
    if (!$device->fetch(PDO::FETCH_ASSOC)) reply(['success'=>false,'error'=>'Unauthorized.'],401);

    $sales = is_array($body['sales'] ?? null) ? $body['sales'] : [];
    $movements = is_array($body['stock_movements'] ?? null) ? $body['stock_movements'] : [];
    $pdo->beginTransaction();

    $saleInsert = $pdo->prepare("INSERT IGNORE INTO retail_sales
      (client_id,store_id,local_sale_id,sale_number,cashier_id,cashier_name,subtotal,discount,tax,total,payment_method,status,device_uuid,sold_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $saleFind = $pdo->prepare("SELECT id FROM retail_sales WHERE client_id=? AND store_id=? AND device_uuid=? AND sale_number=? LIMIT 1");
    $lineInsert = $pdo->prepare("INSERT INTO retail_sale_lines
      (retail_sale_id,product_id,barcode,product_name,quantity,unit_price,unit_cost,line_total)
      VALUES (?,?,?,?,?,?,?,?)");

    $syncedSales = 0;
    foreach ($sales as $sale) {
        if (!is_array($sale)) continue;
        $saleNumber = clean_text($sale['sale_number'] ?? '',64);
        $cashierId = positive_int($sale['cashier_id'] ?? null);
        if ($saleNumber === '' || !$cashierId) continue;
        $soldAt = date('Y-m-d H:i:s', strtotime((string)($sale['created_at'] ?? 'now')) ?: time());
        $saleInsert->execute([$clientId,$storeId,(int)($sale['id'] ?? 0),$saleNumber,$cashierId,clean_text($sale['cashier_name'] ?? '',190),
          (float)($sale['subtotal'] ?? 0),(float)($sale['discount'] ?? 0),(float)($sale['tax'] ?? 0),(float)($sale['total'] ?? 0),
          clean_text($sale['payment_method'] ?? 'unknown',32),clean_text($sale['status'] ?? 'completed',32),$deviceUuid,$soldAt]);
        $saleFind->execute([$clientId,$storeId,$deviceUuid,$saleNumber]);
        $serverSaleId = (int)$saleFind->fetchColumn();
        if ($serverSaleId <= 0) continue;
        if ($saleInsert->rowCount() > 0) {
            foreach (($sale['lines'] ?? []) as $line) {
                if (!is_array($line)) continue;
                $lineInsert->execute([$serverSaleId,(int)($line['product_id'] ?? 0),clean_text($line['barcode'] ?? '',191),clean_text($line['product_name'] ?? '',255),
                  (float)($line['quantity'] ?? 0),(float)($line['unit_price'] ?? 0),(float)($line['unit_cost'] ?? 0),(float)($line['line_total'] ?? 0)]);
            }
        }
        $syncedSales++;
    }

    $moveInsert = $pdo->prepare("INSERT IGNORE INTO retail_stock_movements
      (client_id,store_id,product_id,movement_type,quantity,reference_code,note,device_uuid,moved_at)
      VALUES (?,?,?,?,?,?,?,?,?)");
    $syncedMoves = 0;
    foreach ($movements as $move) {
        if (!is_array($move)) continue;
        $ref = clean_text($move['reference'] ?? '',191);
        $productId = positive_int($move['product_id'] ?? null);
        if ($ref === '' || !$productId) continue;
        $movedAt = date('Y-m-d H:i:s', strtotime((string)($move['created_at'] ?? 'now')) ?: time());
        $moveInsert->execute([$clientId,$storeId,$productId,clean_text($move['movement_type'] ?? 'adjustment',40),(float)($move['quantity'] ?? 0),$ref,clean_text($move['note'] ?? '',255),$deviceUuid,$movedAt]);
        $syncedMoves++;
    }

    $pdo->commit();
    reply(['success'=>true,'api'=>'sync_retail.php','version'=>'retail-sync-v1','synced_sales'=>$syncedSales,'synced_movements'=>$syncedMoves]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('sync_retail.php: '.$e->getMessage());
    reply(['success'=>false,'error'=>'Retail sync failed.'],500);
}
