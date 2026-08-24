<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$version_file = __DIR__ . '/.deployed_version';

if (file_exists($version_file)) {
    $hash = trim(file_get_contents($version_file));
} else {
    $hash = 'NO_VERSION_FILE_DEPLOYED';
}

echo json_encode([
    'deployed_commit' => $hash,
    'server_time'     => date('Y-m-d H:i:s'),
    'php_version'     => phpversion(),
    'api_status'      => 'ok'
]);
?>