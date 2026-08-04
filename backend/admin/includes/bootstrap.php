<?php
declare(strict_types=1);

$adminCookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')), '/') . '/';
if ($adminCookiePath === '//') {
    $adminCookiePath = '/';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $adminCookiePath,
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once dirname(__DIR__, 2) . '/api/config.php';

// Must be set after config.php because shared API config may set another content type.
header_remove('Content-Type');
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('Database connection unavailable.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

const ADMIN_VERSION = 'admin-v1';
const SESSION_TIMEOUT = 1800;

function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(419); exit('Session expired. Please go back and try again.');
    }
}
function post_string(string $key, int $max = 255): string {
    $v = trim((string)($_POST[$key] ?? ''));
    return mb_substr($v, 0, $max);
}
function post_int(string $key): int { return max(0, (int)($_POST[$key] ?? 0)); }
function post_decimal(string $key): float { return round((float)($_POST[$key] ?? 0), 3); }
function is_hash(string $value): bool { return (int)(password_get_info($value)['algo'] ?? 0) !== 0; }
function secret_matches(string $stored, string $entered): bool {
    if ($stored === '') return false;
    return is_hash($stored) ? password_verify($entered, $stored) : hash_equals($stored, $entered);
}
function current_admin(): ?array { return $_SESSION['admin'] ?? null; }
function require_admin(): array {
    $admin = current_admin();
    if (!$admin) redirect('login.php');
    if (time() - (int)($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        session_unset(); session_destroy(); redirect('login.php?expired=1');
    }
    $_SESSION['last_activity'] = time();
    return $admin;
}
function flash(string $type, string $message): void { $_SESSION['flash'] = [$type, $message]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function audit(PDO $pdo, array $admin, string $action, string $entity, ?string $id = null, ?string $details = null): void {
    $stmt = $pdo->prepare('INSERT INTO admin_audit_logs (client_id, employee_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$admin['client_id'], (int)$admin['id'], $action, $entity, $id, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}
function table_columns(PDO $pdo, string $table): array {
    $allowed = ['employees','stores','devices']; if (!in_array($table, $allowed, true)) return [];
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
    return array_column($rows, 'Field');
}
function first_existing(array $columns, array $choices): ?string {
    foreach ($choices as $c) if (in_array($c, $columns, true)) return $c;
    return null;
}
