<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function store_logo_audit(PDO $pdo, array $user, int $storeId, ?string $previous, string $next): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$user['client_id'],
            (int)$user['id'],
            'store.logo.update',
            'store',
            (string)$storeId,
            json_encode(['previous_logo_path' => $previous, 'logo_path' => $next], JSON_UNESCAPED_SLASHES),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS store logo audit write failed: ' . get_class($e));
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['success' => false, 'error' => 'POST required.'], 405);
    }

    $user = beta_require_active_user();
    $pdo = portal_db();
    beta_require_permission($user, 'stores.logo.manage', $pdo);
    require_csrf($_POST);

    $storeId = filter_var($_POST['store_id'] ?? null, FILTER_VALIDATE_INT);
    if ($storeId === false || $storeId < 1) {
        throw new MerdWorkforceException('invalid_store', 'Choose a valid store.');
    }

    $storeStmt = $pdo->prepare('SELECT id,logo_path FROM stores WHERE id=? AND client_id=? LIMIT 1');
    $storeStmt->execute([(int)$storeId, (int)$user['client_id']]);
    $store = $storeStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        throw new MerdWorkforceException('store_not_found', 'Store not found in the working client.');
    }

    $file = $_FILES['logo'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new MerdWorkforceException('logo_upload_failed', 'Choose a PNG, JPEG or WebP logo image.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 2 * 1024 * 1024) {
        throw new MerdWorkforceException('logo_too_large', 'Store logo must be 2 MB or smaller.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new MerdWorkforceException('logo_upload_failed', 'Uploaded logo could not be verified.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->file($tmp));
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new MerdWorkforceException('invalid_logo_type', 'Store logo must be PNG, JPEG or WebP.');
    }

    $imageInfo = @getimagesize($tmp);
    if (!is_array($imageInfo) || (int)$imageInfo[0] < 32 || (int)$imageInfo[1] < 32 || (int)$imageInfo[0] > 4096 || (int)$imageInfo[1] > 4096) {
        throw new MerdWorkforceException('invalid_logo_dimensions', 'Logo dimensions must be between 32×32 and 4096×4096 pixels.');
    }

    $relativeDir = 'uploads/store_logos';
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Store logo directory could not be created.');
    }

    $guardPath = $absoluteDir . '/.htaccess';
    if (!is_file($guardPath)) {
        @file_put_contents($guardPath, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n");
    }

    $filename = sprintf('c%d_s%d_%s.%s', (int)$user['client_id'], (int)$storeId, bin2hex(random_bytes(8)), $extensions[$mime]);
    $absolutePath = $absoluteDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $absolutePath)) {
        throw new RuntimeException('Store logo could not be saved.');
    }
    @chmod($absolutePath, 0644);

    $relativePath = $relativeDir . '/' . $filename;
    $update = $pdo->prepare('UPDATE stores SET logo_path=? WHERE id=? AND client_id=?');
    $update->execute([$relativePath, (int)$storeId, (int)$user['client_id']]);

    $previous = trim((string)($store['logo_path'] ?? ''));
    if ($previous !== '' && str_starts_with($previous, $relativeDir . '/')) {
        $previousAbsolute = dirname(__DIR__) . '/' . $previous;
        if (is_file($previousAbsolute) && realpath(dirname($previousAbsolute)) === realpath($absoluteDir)) {
            @unlink($previousAbsolute);
        }
    }

    store_logo_audit($pdo, $user, (int)$storeId, $previous !== '' ? $previous : null, $relativePath);
    json_response([
        'success' => true,
        'csrf' => csrf_token(),
        'store_id' => (int)$storeId,
        'logo_path' => $relativePath,
        'message' => 'Store logo saved.',
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
