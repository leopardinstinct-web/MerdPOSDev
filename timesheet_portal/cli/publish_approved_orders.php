<?php
declare(strict_types=1);
// Run from the server CLI as the same OS account that owns Alpha private files.
// Optional order ID; without an ID, sync every approved undeleted order.
if (PHP_SAPI!=='cli') {http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/includes/order_repository.php';
require_once dirname(__DIR__).'/includes/order_drive.php';
$id=$argv[1]??null;
if ($id!==null && !preg_match('/^ORD-[0-9]{8}-[A-F0-9]{10}$/D',$id)) {fwrite(STDERR,"Invalid order ID\n");exit(2);}
if (order_drive_config()===null) {fwrite(STDERR,"Drive is not configured; no changes made.\n");exit(3);}
$sql="SELECT * FROM orders WHERE status='APPROVED' AND deleted_at IS NULL";
if ($id!==null) $sql.=" AND id=:id";
$sql.=" ORDER BY submitted_at ASC";
$st=order_db()->prepare($sql);
$st->execute($id!==null?[':id'=>$id]:[]);
$failed=0;$count=0;
while($row=$st->fetch()) {
    try {
        $result=order_drive_publish($row);
        if (($result['state']??'')!=='SAVED') throw new RuntimeException('Not saved');
        fwrite(STDOUT,$row['id']." SAVED\n");$count++;
    } catch(Throwable $e) {
        fwrite(STDERR,$row['id']." FAILED: ".$e->getMessage()."\n");$failed++;
    }
}
fwrite(STDOUT,"Saved ".$count."; failed ".$failed."\n");
exit($failed?1:0);
