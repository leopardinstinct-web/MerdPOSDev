<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_response.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/device_auth.php';
require_once __DIR__ . '/includes/stock_convergence.php';
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
    if (!is_scalar($value) && $value !== null) throw new MerdRequestException('invalid_request',400,'Invalid request.');
    return mb_substr(trim((string)$value), 0, $max);
}
function decimal_value(mixed $value): float {
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
        throw new MerdRequestException('invalid_request',400,'Invalid request.');
    }
    $number = (float)$value;
    if (!is_finite($number)) throw new MerdRequestException('invalid_request',400,'Invalid request.');
    return $number;
}
function clean_timestamp(mixed $value): string {
    $text = clean_text($value, 40);
    if ($text === '') throw new MerdRequestException('invalid_request',400,'Invalid request.');
    try { return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    catch (Throwable $e) { throw new MerdRequestException('invalid_request',400,'Invalid request.'); }
}

try {
    merd_request_require_method($_SERVER, 'POST');
    merd_request_require_json_content_type($_SERVER);
    $body = merd_request_json(file_get_contents('php://input'));
    $auth = merd_device_authenticate_request($pdo, $_SERVER, $body);
    $clientId = $auth['client_id'];
    $storeId = $auth['store_id'];
    $deviceUuid = $auth['device_uuid'];

    $sales = merd_request_list($body['sales'] ?? [], 1000);
    $movements = merd_request_list($body['stock_movements'] ?? [], 2000);
    $pdo->beginTransaction();

    $saleInsert = $pdo->prepare("INSERT IGNORE INTO retail_sales
      (client_id,store_id,local_sale_id,sale_number,cashier_id,cashier_name,subtotal,discount,tax,total,payment_method,status,device_uuid,sold_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $saleFind = $pdo->prepare("SELECT id FROM retail_sales WHERE client_id=? AND store_id=? AND device_uuid=? AND sale_number=? LIMIT 1");
    $lineInsert = $pdo->prepare("INSERT INTO retail_sale_lines
      (retail_sale_id,product_id,barcode,product_name,quantity,unit_price,unit_cost,line_total)
      VALUES (?,?,?,?,?,?,?,?)");
    $employeeCheck = $pdo->prepare("SELECT id FROM employees WHERE id=? AND client_id=? AND status='active' LIMIT 1");
    // Historical offline sale lines retain their canonical product reference
    // even when lifecycle changed after the sale was completed.
    $productCheck = $pdo->prepare("SELECT id FROM retail_products WHERE id=? AND client_id=? LIMIT 1");

    $syncedSales = 0;
    foreach ($sales as $sale) {
        if (!is_array($sale)) continue;
        $saleNumber = clean_text($sale['sale_number'] ?? '',64);
        $cashierId = positive_int($sale['cashier_id'] ?? null);
        if ($saleNumber === '' || !$cashierId) continue;
        $employeeCheck->execute([$cashierId,$clientId]);
        if (!$employeeCheck->fetchColumn()) throw new MerdRequestException('invalid_request',400,'Invalid request.');
        $soldAt = clean_timestamp($sale['created_at'] ?? null);
        $saleInsert->execute([$clientId,$storeId,merd_request_nonnegative_int($sale['id'] ?? 0),$saleNumber,$cashierId,clean_text($sale['cashier_name'] ?? '',190),
          decimal_value($sale['subtotal'] ?? 0),decimal_value($sale['discount'] ?? 0),decimal_value($sale['tax'] ?? 0),decimal_value($sale['total'] ?? 0),
          clean_text($sale['payment_method'] ?? 'unknown',32),clean_text($sale['status'] ?? 'completed',32),$deviceUuid,$soldAt]);
        $saleFind->execute([$clientId,$storeId,$deviceUuid,$saleNumber]);
        $serverSaleId = (int)$saleFind->fetchColumn();
        if ($serverSaleId <= 0) continue;
        if ($saleInsert->rowCount() > 0) {
            foreach (merd_request_list($sale['lines'] ?? [], 500) as $line) {
                if (!is_array($line)) continue;
                $lineProductId = positive_int($line['product_id'] ?? null);
                if (!$lineProductId) throw new MerdRequestException('invalid_request',400,'Invalid request.');
                $productCheck->execute([$lineProductId,$clientId]);
                if (!$productCheck->fetchColumn()) throw new MerdRequestException('invalid_request',400,'Invalid request.');
                $lineInsert->execute([$serverSaleId,$lineProductId,clean_text($line['barcode'] ?? '',191),clean_text($line['product_name'] ?? '',255),
                  decimal_value($line['quantity'] ?? 0),decimal_value($line['unit_price'] ?? 0),decimal_value($line['unit_cost'] ?? 0),decimal_value($line['line_total'] ?? 0)]);
            }
        }
        $syncedSales++;
    }

    $syncedMoves = 0;
    $movementOutcomes = [];
    foreach ($movements as $move) {
        if (!is_array($move)) continue;
        try {
            $outcome = merd_stock_apply_device_movement($pdo, $auth, $move);
            $movementOutcomes[] = $outcome;
            if ($outcome['outcome'] === 'accepted') $syncedMoves++;
        } catch (MerdRequestException $exception) {
            $localId = filter_var($move['id'] ?? null, FILTER_VALIDATE_INT);
            $movementOutcomes[] = [
                'local_id' => $localId === false ? null : (int)$localId,
                'outcome' => 'rejected',
                'error_code' => $exception->errorCode,
                'error' => $exception->getMessage(),
            ];
        }
    }

    merd_device_touch_last_sync($pdo, $auth);
    $pdo->commit();
    reply(['success'=>true,'api'=>'sync_retail.php','version'=>'retail-sync-v3-stock-convergence','stock_contract_version'=>MERD_STOCK_SYNC_CONTRACT_VERSION,'synced_sales'=>$syncedSales,'synced_movements'=>$syncedMoves,'movement_outcomes'=>$movementOutcomes]);
} catch (MerdRequestException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status);
} catch (MerdSecurityControlUnavailable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    merd_api_fail('security_control_unavailable', 'Service temporarily unavailable.', 503);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('sync_retail request failed');
    reply(['success'=>false,'error'=>'Retail sync failed.'],500);
}
