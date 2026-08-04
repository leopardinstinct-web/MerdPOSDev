<?php
function render_header(string $title, array $admin): void {
    $page = $_GET['page'] ?? 'dashboard';
    $items = [
      'dashboard'=>'Dashboard','employees'=>'Employees','stores'=>'Stores','categories'=>'Categories',
      'products'=>'Products','inventory'=>'Inventory','suppliers'=>'Suppliers','purchases'=>'Purchases',
      'sales'=>'Sales','reports'=>'Reports','devices'=>'Devices','audit'=>'Audit logs','settings'=>'Settings'
    ];
    $flash = take_flash();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> · MerdPOS</title><link rel="stylesheet" href="assets/admin.css"></head><body>
    <aside class="sidebar"><div class="brand"><span>Merd</span><b>POS</b><small>ADMIN</small></div><nav><?php foreach($items as $k=>$label): ?><a class="<?=$page===$k?'active':''?>" href="index.php?page=<?=$k?>"><?=e($label)?></a><?php endforeach; ?></nav><div class="side-bottom"><div><?=e($admin['full_name'])?></div><small>Client <?=e((string)$admin['client_id'])?></small><a href="logout.php">Log out</a></div></aside>
    <main><header class="top"><div><h1><?=e($title)?></h1><p>Blue Ice administration console</p></div><span class="pill"><?=e(ADMIN_VERSION)?></span></header><?php if($flash): ?><div class="flash <?=e($flash[0])?>"><?=e($flash[1])?></div><?php endif; ?>
    <?php
}
function render_footer(): void { echo '</main></body></html>'; }
function csrf_field(): void { echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function status_badge(string $status): string { $c = strtolower($status)==='active' || strtolower($status)==='completed' ? 'ok' : 'muted'; return '<span class="badge '.$c.'">'.e($status).'</span>'; }
