<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

function studio_asset_require_dev(array $user): void
{
    if (!beta_actual_user_is_dev($user)) {
        throw new MerdWorkforceException('forbidden', 'Developer access is required.');
    }
}

function studio_asset_storage_root(): string
{
    return dirname(__DIR__, 2) . '/backend/runtime/ui_studio_context_assets';
}

function studio_asset_safe_name(string $name): string
{
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', basename($name)) ?? '');
    if ($name === '') $name = 'studio-context';
    return mb_substr($name, 0, 180);
}

function studio_asset_sanitize_svg(string $xml): string
{
    if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
        throw new MerdWorkforceException('invalid_studio_asset', 'SVG contains unsupported declarations.');
    }
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') {
        throw new MerdWorkforceException('invalid_studio_asset', 'Choose a valid SVG image.');
    }

    $allowedElements = array_fill_keys([
        'svg','g','path','circle','rect','line','polyline','polygon','ellipse','defs',
        'linearGradient','radialGradient','stop','clipPath','mask','title','desc','use'
    ], true);
    $allowedAttributes = array_fill_keys([
        'xmlns','viewBox','width','height','fill','stroke','stroke-width','stroke-linecap',
        'stroke-linejoin','stroke-miterlimit','stroke-dasharray','stroke-dashoffset','opacity',
        'fill-rule','clip-rule','d','cx','cy','r','rx','ry','x','y','x1','x2','y1','y2',
        'points','transform','id','offset','stop-color','stop-opacity','gradientUnits',
        'gradientTransform','fx','fy','fr','clip-path','mask','href','xlink:href'
    ], true);

    $nodes = [];
    foreach ($doc->getElementsByTagName('*') as $node) $nodes[] = $node;
    foreach ($nodes as $node) {
        if (!isset($allowedElements[$node->localName])) {
            $node->parentNode?->removeChild($node);
            continue;
        }
        $attributes = [];
        foreach ($node->attributes ?? [] as $attribute) $attributes[] = $attribute;
        foreach ($attributes as $attribute) {
            $name = $attribute->nodeName;
            $value = trim((string)$attribute->nodeValue);
            if (str_starts_with(strtolower($name), 'on') || !isset($allowedAttributes[$name])) {
                $node->removeAttributeNode($attribute);
                continue;
            }
            if (($name === 'href' || $name === 'xlink:href') && !str_starts_with($value, '#')) {
                $node->removeAttributeNode($attribute);
                continue;
            }
            if (($name === 'clip-path' || $name === 'mask') && !preg_match('/^url\(#[A-Za-z0-9_.:-]+\)$/', $value)) {
                $node->removeAttributeNode($attribute);
                continue;
            }
            if (($name === 'fill' || $name === 'stroke') && stripos($value, 'url(') !== false
                && !preg_match('/^url\(#[A-Za-z0-9_.:-]+\)$/', $value)) {
                $node->removeAttributeNode($attribute);
            }
        }
    }
    if (!$doc->documentElement->hasAttribute('xmlns')) {
        $doc->documentElement->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    }
    return (string)$doc->saveXML($doc->documentElement);
}
function studio_asset_audit(PDO $pdo, array $user, array $asset): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs (client_id,employee_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$user['client_id'], (int)$user['id'], 'ui_studio.context_asset.upload',
            'ui_studio_asset', (string)$asset['token'],
            json_encode([
                'name'=>$asset['name'], 'mime'=>$asset['mime'], 'size'=>$asset['size'],
                'sha256'=>$asset['sha256']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (Throwable $e) {
        error_log('MERDPOS Studio asset audit write failed: ' . get_class($e));
    }
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['success'=>false,'error'=>'POST required.'], 405);
    }
    $user = beta_require_active_user();
    studio_asset_require_dev($user);
    $pdo = portal_db();
    require_csrf($_POST);

    $file = $_FILES['asset'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new MerdWorkforceException('studio_asset_upload_failed', 'Choose an image file to upload.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) {
        throw new MerdWorkforceException('studio_asset_too_large', 'Studio context images must be 5 MB or smaller.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new MerdWorkforceException('studio_asset_upload_failed', 'Uploaded image could not be verified.');
    }

    $name = studio_asset_safe_name((string)($file['name'] ?? 'studio-context'));
    $originalExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = strtolower((string)$finfo->file($tmp));
    $mimeMap = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
    $mime = $detectedMime;
    $ext = $mimeMap[$mime] ?? '';
    $contents = null;
    if ($originalExt === 'svg') {
        if ($size > 1024 * 1024) {
            throw new MerdWorkforceException('studio_asset_too_large', 'SVG context files must be 1 MB or smaller.');
        }
        $raw = (string)file_get_contents($tmp);
        $contents = studio_asset_sanitize_svg($raw);
        $mime = 'image/svg+xml';
        $ext = 'svg';
    } elseif ($ext === '') {
        throw new MerdWorkforceException('invalid_studio_asset', 'Use PNG, JPEG, WebP, GIF or SVG image files.');
    } else {
        $imageInfo = @getimagesize($tmp);
        if (!is_array($imageInfo) || (int)$imageInfo[0] < 1 || (int)$imageInfo[1] < 1
            || (int)$imageInfo[0] > 8192 || (int)$imageInfo[1] > 8192) {
            throw new MerdWorkforceException('invalid_studio_asset', 'Image dimensions must be between 1 and 8192 pixels.');
        }
    }

    $root = studio_asset_storage_root();
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Studio context storage could not be created.');
    }
    $token = bin2hex(random_bytes(32));
    $assetPath = $root . '/' . $token . '.bin';
    $manifestPath = $root . '/' . $token . '.json';
    if ($contents !== null) {
        if (file_put_contents($assetPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Studio SVG context could not be saved.');
        }
    } elseif (!move_uploaded_file($tmp, $assetPath)) {
        throw new RuntimeException('Studio image context could not be saved.');
    }
    @chmod($assetPath, 0640);
    $sha256 = hash_file('sha256', $assetPath);
    $storedSize = (int)filesize($assetPath);
    $manifest = [
        'token'=>$token, 'client_id'=>(int)$user['client_id'], 'employee_id'=>(int)$user['id'],
        'name'=>$name, 'mime'=>$mime, 'extension'=>$ext, 'size'=>$storedSize,
        'sha256'=>$sha256, 'created_at'=>(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    ];
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        @unlink($assetPath);
        throw new RuntimeException('Studio context metadata could not be saved.');
    }
    @chmod($manifestPath, 0640);

    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/beta/timesheet_portal/api/ui_studio_asset.php')));
    $portalDir = rtrim(dirname($scriptDir), '/');
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'app.merdpos.com')) ?: 'app.merdpos.com';
    $url = $scheme . '://' . $host . $portalDir . '/studio_context_asset.php?t=' . rawurlencode($token);
    $asset = [
        'token'=>$token, 'name'=>$name, 'mime'=>$mime, 'size'=>$storedSize,
        'sha256'=>$sha256, 'url'=>$url,
    ];
    studio_asset_audit($pdo, $user, $asset);
    json_response(['success'=>true,'csrf'=>csrf_token(),'asset'=>$asset]);
} catch (Throwable $e) {
    beta_api_error($e);
}
