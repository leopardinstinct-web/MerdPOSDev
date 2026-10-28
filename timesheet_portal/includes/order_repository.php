<?php
declare(strict_types=1);
require_once __DIR__ . '/order_inventory.php';

/** Private persistent orders. Only the server owns this SQLite file. */
function order_private_dir(): string {
    $configured = getenv('MERD_ALPHA_ORDER_DATA_DIR');
    $dir = $configured ?: dirname(__DIR__, 4) . '/.merdpos_alpha_orders';
    if (!is_dir($dir)) {
        $old = umask(0077);
        try { if (!@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Order storage unavailable.'); }
        finally { umask($old); }
    }
    if (is_link($dir) || !is_writable($dir)) throw new RuntimeException('Order storage not writable.');
    return $dir;
}
function order_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = order_private_dir() . '/orders.sqlite3';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);
    @chmod($path, 0600);
    $pdo->exec('PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS orders (
 id TEXT PRIMARY KEY,
 store TEXT NOT NULL CHECK(store IN ('MX','ET','DT','RT')),
 author_id TEXT NOT NULL, author_name TEXT NOT NULL,
 submitted_at TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('PENDING','APPROVED','REJECTED')),
 payload TEXT NOT NULL, draft_nonce TEXT NOT NULL UNIQUE,
 revision INTEGER NOT NULL DEFAULT 1, updated_at TEXT,
 reviewer_id TEXT, reviewer_name TEXT, reviewed_at TEXT,
 review_note TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_orders_store_date ON orders (store,submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status,submitted_at DESC);
SQL);
    return $pdo;
}
function order_user_id(array $user): string {
    $id = trim((string)($user['user_id'] ?? ''));
    if ($id === '') throw new RuntimeException('Sign in again; account ID unavailable.');
    return $id;
}
function order_sanitize_row(array $p, int $count): array {
    $unit = array_slice($p['unit_barcodes'] ?? [], 0, 3);
    return [
        'type' => $p['type'], 'category' => $p['category'],
        'name' => $p['name'], 'barcodes' => implode(',', $unit),
        'on_hand' => $p['type'] === 'AUTO' ? $count : null,
        'ceiling' => $p['type'] === 'AUTO' ? $p['ceiling'] : null,
        'order_quantity' => $p['type'] === 'AUTO' ? max(0, (int)$p['ceiling'] - $count) : $count
    ];
}
function order_build_payload(string $store, array $catalog, array $counts, string $captured): array {
    $rows=[]; $invalid=[];
    foreach ($catalog as $id=>$p) {
        $type=$p['type'];
        $qty=(int)($counts[$type][$id] ?? 0);
        if ($type === 'AUTO' && $p['ceiling'] === null) { $invalid[] = $p['name']; continue; }
        $row=order_sanitize_row($p,$qty);
        if ($row['order_quantity']>0) $rows[]=$row;
    }
    // Blank store ceilings are NOT zero and cannot be replenished. Exclude
    // those products explicitly; preserve their names for SUPER visibility.
    if (!$rows) throw new InvalidArgumentException('Order is empty. Count stock or add accessories first.');
    usort($rows,fn($a,$b)=>strcmp($a['type'],$b['type']) ?: strnatcasecmp($a['name'],$b['name']));
    return ['store'=>$store,'captured_at'=>$captured,'rows'=>$rows,
        'excluded_missing_ceiling'=>$invalid,
        'cigarette_units'=>array_sum(array_map(fn($x)=>$x['type']==='AUTO'?$x['order_quantity']:0,$rows)),
        'accessory_units'=>array_sum(array_map(fn($x)=>$x['type']==='MANUAL'?$x['order_quantity']:0,$rows))];
}
function order_create(array $user,string $store,string $nonce,array $catalog,array $counts,string $captured): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$nonce)) throw new InvalidArgumentException('Draft token invalid. Reload the page.');
    $payload=order_build_payload($store,$catalog,$counts,$captured);
    $payload['draft']=['catalogue'=>$catalog,'counts'=>$counts];
    $db=order_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $check=$db->prepare('SELECT id FROM orders WHERE draft_nonce=?');
        $check->execute([$nonce]);
        if ($check->fetch()) throw new DomainException('This draft has already been submitted.');
        $id='ORD-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
        $now=gmdate('Y-m-d\TH:i:s\Z');
        $stmt=$db->prepare("INSERT INTO orders(id,store,author_id,author_name,submitted_at,status,payload,draft_nonce) VALUES (?,?,?,?,?,'PENDING',?,?)");
        $stmt->execute([$id,$store,order_user_id($user),(string)$user['name'],$now,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$nonce]);
        $db->commit();
        return ['id'=>$id,'status'=>'PENDING','store'=>$store];
    } catch(Throwable $e) {if ($db->inTransaction()) $db->rollBack();throw $e;}
}
function order_rows(array $user): array {
    $db=order_db();
    if (!empty($user['is_super'])) {
        $rows=$db->query('SELECT * FROM orders ORDER BY submitted_at DESC, id DESC LIMIT 500')->fetchAll();
    } else {
        $stmt=$db->prepare('SELECT * FROM orders WHERE author_id=? ORDER BY submitted_at DESC,id DESC LIMIT 100');
        $stmt->execute([order_user_id($user)]);$rows=$stmt->fetchAll();
    }
    $viewer=order_user_id($user);
    return array_map(fn($row)=>order_public_row($row,$viewer),$rows);
}
function order_public_row(array $row,string $viewerId): array {
    $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
    return [
        'id'=>$row['id'],'store'=>$row['store'],'author_name'=>$row['author_name'],
        'editable'=>$row['status']==='PENDING' && $row['author_id']===$viewerId,
        'revision'=>(int)$row['revision'],
        'submitted_at'=>$row['submitted_at'],'status'=>$row['status'],
        'reviewer_name'=>$row['reviewer_name'],'reviewed_at'=>$row['reviewed_at'],
        'review_note'=>$row['review_note'],
        'cigarette_units'=>$payload['cigarette_units'],'accessory_units'=>$payload['accessory_units'],
        'rows'=>$payload['rows'],
        'excluded_missing_ceiling'=>$payload['excluded_missing_ceiling'] ?? []
    ];
}
function order_get_super(array $user,string $id): array {
    if (empty($user['is_super'])) throw new DomainException('Only SUPER can access approved order files.');
    if (!preg_match('/^ORD-[0-9]{8}-[A-F0-9]{10}$/D',$id)) throw new InvalidArgumentException('Invalid order ID.');
    $stmt=order_db()->prepare('SELECT * FROM orders WHERE id=?');
    $stmt->execute([$id]);$row=$stmt->fetch();
    if (!$row) throw new InvalidArgumentException('Order not found.');
    return $row;
}
function order_review(array $user,string $id,string $action,string $note=''): array {
    if (empty($user['is_super'])) throw new DomainException('Only SUPER may review orders.');
    if (!in_array($action,['approve','reject'],true)) throw new InvalidArgumentException('Unknown review action.');
    if (mb_strlen($note)>240) throw new InvalidArgumentException('Review note too long.');
    $db=order_db();$db->exec('BEGIN IMMEDIATE');
    $generated=[];
    try {
        $stmt=$db->prepare('SELECT * FROM orders WHERE id=?');
        $stmt->execute([$id]);$row=$stmt->fetch();
        if (!$row) throw new InvalidArgumentException('Order not found.');
        if ($row['status']!=='PENDING') throw new DomainException('Order is already reviewed and locked.');
        if ($action==='approve') {
            require_once __DIR__ . '/order_export.php';
            $row['reviewer_name']=(string)$user['name'];
            $generated=order_build_files($row);
        }
        $stmt=$db->prepare('UPDATE orders SET status=?,reviewer_id=?,reviewer_name=?,reviewed_at=?,review_note=? WHERE id=? AND status=?');
        $stmt->execute([$action==='approve'?'APPROVED':'REJECTED',order_user_id($user),(string)$user['name'],gmdate('Y-m-d\TH:i:s\Z'),trim($note),$id,'PENDING']);
        if ($stmt->rowCount()!==1) throw new RuntimeException('Order review conflict.');
        $db->commit();
        return ['id'=>$id,'status'=>$action==='approve'?'APPROVED':'REJECTED'];
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($generated as $file) @unlink($file);
        throw $e;
    }
}


function order_pending_for_user(array $user,string $id): array {
    if (!preg_match('/^ORD-[0-9]{8}-[A-F0-9]{10}$/D',$id)) throw new InvalidArgumentException('Invalid order ID.');
    $stmt=order_db()->prepare('SELECT * FROM orders WHERE id=? AND author_id=?');
    $stmt->execute([$id,order_user_id($user)]);
    $row=$stmt->fetch();
    if (!$row) throw new DomainException('Order not found or not yours.');
    if ($row['status']!=='PENDING') throw new DomainException('Approved or rejected orders cannot be edited.');
    return $row;
}
function order_update_pending(array $user,string $id,int $revision,string $store,string $nonce,array $catalog,array $counts,string $captured): array {
    $payload=order_build_payload($store,$catalog,$counts,$captured);
    $payload['draft']=['catalogue'=>$catalog,'counts'=>$counts];
    $db=order_db();$db->exec('BEGIN IMMEDIATE');
    try {
        $stmt=$db->prepare('SELECT * FROM orders WHERE id=? AND author_id=? AND status=?');
        $stmt->execute([$id,order_user_id($user),'PENDING']);
        $row=$stmt->fetch();
        if (!$row || $row['store']!==$store || (int)$row['revision']!==$revision) {
            throw new DomainException('Order was reviewed or changed. Reload the list before editing.');
        }
        $update=$db->prepare("UPDATE orders SET payload=?,draft_nonce=?,revision=revision+1,updated_at=? WHERE id=? AND author_id=? AND status='PENDING' AND revision=?");
        $update->execute([json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$nonce,gmdate('Y-m-d\TH:i:s\Z'),$id,order_user_id($user),$revision]);
        if ($update->rowCount()!==1) throw new RuntimeException('Concurrent order update.');
        $db->commit();
        return ['id'=>$id,'status'=>'PENDING','store'=>$store];
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
