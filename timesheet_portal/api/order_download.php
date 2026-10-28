<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_repository.php';
header('Cache-Control: no-store, private');
$user=require_login();
if (empty($user['is_super'])) {
    json_response(['success'=>false,'error'=>'Only SUPER can download approved orders.'],403);
}
$id=$_GET['id'] ?? '';
$format=$_GET['format'] ?? 'pdf';
if (!is_string($id)||!is_string($format)||!in_array($format,['pdf','xlsx'],true)) {
    json_response(['success'=>false,'error'=>'Invalid document request.'],400);
}
try {
    $row=order_get_super($user,$id);
    if ($row['status']!=='APPROVED') json_response(['success'=>false,'error'=>'Order must be approved before download.'],409);
    require_once __DIR__ . '/../includes/order_export.php';
    $path=order_export_path($id,$format);
    if (!is_file($path)) throw new RuntimeException('Approved file is unavailable.');
    $type=$format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: '.$type);
    header('Content-Disposition: attachment; filename="'.$id.'.'.$format.'"');
    header('Content-Length: '.filesize($path));
    readfile($path);
} catch(InvalidArgumentException $e) {
    json_response(['success'=>false,'error'=>'Invalid order file.'],404);
} catch(Throwable $e) {
    error_log('Alpha download: '.$e->getMessage());
    json_response(['success'=>false,'error'=>'Approved file is temporarily unavailable.'],503);
}
