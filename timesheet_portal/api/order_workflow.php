<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_repository.php';
header('Cache-Control: no-store, private');
$user=require_login();
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method==='GET') {
    try {
        $page=$_GET['page']??'1';
        $store=$_GET['store']??'';
        $status=$_GET['status']??'';
        if(!is_string($page)||!ctype_digit($page)||!is_string($store)||!is_string($status)) {
            throw new InvalidArgumentException('Invalid history filter.');
        }
        $result=order_list_page($user,(int)$page,$store,$status);
        json_response(['success'=>true]+$result+['super'=>!empty($user['is_super'])]);
    } catch(InvalidArgumentException $e) {
        json_response(['success'=>false,'error'=>$e->getMessage()],422);
    } catch(Throwable $e) {
        error_log('Alpha order list: '.$e->getMessage());
        json_response(['success'=>false,'error'=>'Order history is unavailable.'],503);
    }
}
if ($method!=='POST') {
    header('Allow: GET, POST');
    json_response(['success'=>false,'error'=>'Unsupported method.'],405);
}
start_app_session();
$expected=$_SESSION['order_store_csrf'] ?? null;
$token=$_POST['csrf_token'] ?? null;
if (!is_string($expected)||!is_string($token)||!hash_equals($expected,$token)) {
    json_response(['success'=>false,'error'=>'Invalid security token. Reload and try again.'],403);
}
$action=$_POST['action'] ?? '';
try {
    if ($action==='submit') {
        $store=$_SESSION['order_store_code'] ?? null;
        if (!is_string($store)||!in_array($store,['MX','ET','DT','RT'],true)) {
            json_response(['success'=>false,'error'=>'Select a store first.'],409);
        }
        $nonce=$_POST['draft_nonce'] ?? '';
        if (!is_string($nonce) || !isset($_SESSION['order_draft_nonce'][$store])
            || !hash_equals((string)$_SESSION['order_draft_nonce'][$store],$nonce)) {
            json_response(['success'=>false,'error'=>'Draft has already been submitted or changed. Reload the order page.'],409);
        }
        $snapshot=$_SESSION['order_draft_catalogue'][$store] ?? null;
        $counts=$_SESSION['order_draft_counts'][$store] ?? null;
        if (!is_array($snapshot) || !isset($snapshot['products']) || !is_array($counts)) {
            json_response(['success'=>false,'error'=>'No current order draft. Reload and count stock again.'],409);
        }
        $editing=$_SESSION['order_draft_edit'][$store] ?? null;
        if (is_array($editing)) {
            $result=order_update_pending($user,(string)$editing['id'],(int)$editing['revision'],$store,$nonce,$snapshot['products'],$counts,(string)$snapshot['captured_at']);
            $message='Updated pending order for SUPER review. You may start another order.';
        } else {
            $test=($_POST['test_order']??'')==='1' && !empty($user['is_super']);
            $result=order_create($user,$store,$nonce,$snapshot['products'],$counts,(string)$snapshot['captured_at'],$test);
            $message='Submitted to SUPER for approval. You may start another order.';
        }
        // A successful submission leaves a blank new draft for the same store.
        unset($_SESSION['order_draft_counts'][$store],$_SESSION['order_draft_catalogue'][$store],$_SESSION['order_draft_nonce'][$store],$_SESSION['order_draft_edit'][$store]);
        json_response(['success'=>true,'order'=>$result,'message'=>$message]);
    }
    if ($action==='edit') {
        $id=$_POST['order_id'] ?? '';
        if (!is_string($id)) throw new InvalidArgumentException('Invalid order ID.');
        $row=order_pending_for_user($user,$id);
        // Reopened order classification comes only from immutable stored payload.
        $storedOrder=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        $storedTestOrder=(bool)($storedOrder['test_order'] ?? false);
        if (!isset($storedOrder['draft']['catalogue'],$storedOrder['draft']['counts'])) {
            throw new DomainException('Original count snapshot is unavailable for editing. Start a new order.');
        }
        $store=$row['store'];
        $_SESSION['order_store_code']=$store;
        $_SESSION['order_draft_catalogue'][$store]=['products'=>$storedOrder['draft']['catalogue'],'captured_at'=>$storedOrder['captured_at']];
        $_SESSION['order_draft_counts'][$store]=$storedOrder['draft']['counts'];
        $_SESSION['order_draft_nonce'][$store]=bin2hex(random_bytes(16));
        $_SESSION['order_draft_edit'][$store]=['id'=>$id,'revision'=>(int)$row['revision'],'test_order'=>$storedTestOrder];
        json_response(['success'=>true,'store'=>$store,'editing'=>$id]);
    }
    if ($action==='new') {
        $store=$_SESSION['order_store_code'] ?? '';
        if (!is_string($store)||!in_array($store,['MX','ET','DT','RT'],true)) throw new InvalidArgumentException('Select a store first.');
        unset($_SESSION['order_draft_catalogue'][$store],$_SESSION['order_draft_counts'][$store],$_SESSION['order_draft_nonce'][$store],$_SESSION['order_draft_edit'][$store]);
        json_response(['success'=>true,'message'=>'New blank order started.']);
    }
    if ($action==='delete') {
        if (empty($user['is_super'])) json_response(['success'=>false,'error'=>'SUPER required.'],403);
        $id=$_POST['order_id']??'';
        if (!is_string($id)) throw new InvalidArgumentException('Invalid order ID.');
        json_response(['success'=>true,'order'=>order_delete_super($user,$id)]);
    }
    if ($action==='approve' || $action==='reject') {
        if (empty($user['is_super'])) json_response(['success'=>false,'error'=>'SUPER approval required.'],403);
        $id=$_POST['order_id'] ?? '';
        $note=$_POST['note'] ?? '';
        if (!is_string($id)||!is_string($note)) throw new InvalidArgumentException('Invalid review.');
        json_response(['success'=>true,'order'=>order_review($user,$id,$action,$note)]);
    }
    throw new InvalidArgumentException('Unsupported order action.');
} catch(DomainException $e) {
    json_response(['success'=>false,'error'=>$e->getMessage()],409);
} catch(InvalidArgumentException $e) {
    json_response(['success'=>false,'error'=>$e->getMessage()],422);
} catch(Throwable $e) {
    error_log('Alpha order workflow: '.$e->getMessage());
    json_response(['success'=>false,'error'=>'Order could not be saved. Please retry.'],503);
}
