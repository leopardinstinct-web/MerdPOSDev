<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
if (current_admin()) redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $clientId = post_int('client_id');
    $userId = post_string('user_id', 30);
    $password = post_string('password', 100);
    if ($clientId < 1 || !preg_match('/^\d+$/', $userId) || !preg_match('/^\d{4,20}$/', $password)) {
        $error = 'Invalid login.';
    } else {
        $stmt = $pdo->prepare("SELECT id, client_id, full_name, user_id, login_password, pin_code, role_name, employee_type, status FROM employees WHERE client_id=? AND user_id=? LIMIT 1");
        $stmt->execute([$clientId, $userId]);
        $row = $stmt->fetch();
        $isAdmin = $row && strtoupper((string)($row['status'] ?? '')) === 'ACTIVE' && (strtoupper((string)($row['role_name'] ?? '')) === 'ADMIN' || strtoupper((string)($row['employee_type'] ?? '')) === 'ADMIN');
        $matches = $row && (secret_matches((string)($row['login_password'] ?? ''), $password) || secret_matches((string)($row['pin_code'] ?? ''), $password));
        if (!$isAdmin || !$matches) {
            usleep(350000); $error = 'Invalid login or insufficient permission.';
        } else {
            if (!is_hash((string)$row['login_password'])) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $up = $pdo->prepare('UPDATE employees SET login_password=?, pin_code=? WHERE id=? AND client_id=?');
                $up->execute([$hash,$hash,$row['id'],$clientId]);
            }
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id'=>(int)$row['id'],'client_id'=>(int)$row['client_id'],'full_name'=>(string)$row['full_name'],'user_id'=>(string)$row['user_id']];
            $_SESSION['last_activity'] = time();
            audit($pdo, $_SESSION['admin'], 'login', 'session');
            redirect('index.php');
        }
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MerdPOS Admin</title><link rel="stylesheet" href="assets/admin.css"></head><body><div class="login-wrap"><section class="login-card"><h1>Merd<span style="color:#88C0D0">POS</span> Admin</h1><p>Active employees marked ADMIN only.</p><?php if($error): ?><div class="flash error"><?=e($error)?></div><?php endif; ?><form method="post"><?php csrf_field(); ?><label>Client ID<input name="client_id" inputmode="numeric" required></label><label>User ID<input name="user_id" inputmode="numeric" required></label><label>PIN / password<input type="password" name="password" inputmode="numeric" required minlength="4"></label><button>Log in</button></form></section></div></body></html>
