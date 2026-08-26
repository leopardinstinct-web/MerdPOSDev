<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$managementPath = $root . '/timesheet_portal/assets/management.js';

if (!is_file($managementPath)) {
    fwrite(STDERR, "Portal loader-order validation failed: management.js is missing.\n");
    exit(1);
}

$source = (string)file_get_contents($managementPath);
$errors = [];

if (!str_contains($source, 'script.async=false;')) {
    $errors[] = 'Dynamically inserted classic scripts must set async=false so insertion order is execution order.';
}

$rolesNeedle = "appendScript('roles-module','assets/roles.js";
$navigationNeedle = "appendScript('merd-navigation','assets/navigation.js";
$rolesPosition = strpos($source, $rolesNeedle);
$navigationPosition = strpos($source, $navigationNeedle);

if ($rolesPosition === false) {
    $errors[] = 'management.js no longer wires the Roles module through the shared loader.';
}
if ($navigationPosition === false) {
    $errors[] = 'management.js no longer wires Navigation through the shared loader.';
}
if ($rolesPosition !== false && $navigationPosition !== false && $rolesPosition >= $navigationPosition) {
    $errors[] = 'Roles must be inserted before Navigation so the Operations structure is complete before navigation mounts.';
}
if (!str_contains($source, "if(can('roles.manage'))appendScript('roles-module'")) {
    $errors[] = 'Roles loader must remain permission-gated by roles.manage.';
}

if ($errors) {
    fwrite(STDERR, "MERDPOS portal loader-order validation FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}

echo "MERDPOS portal loader order validated: dynamic scripts execute in insertion order and Roles mounts before Navigation.\n";
