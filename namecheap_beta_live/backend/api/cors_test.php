<?php
require_once __DIR__ . '/includes/maintenance_guard.php';
merd_maintenance_guard();
require_once "config.php";

echo json_encode([
    "success" => true,
    "message" => "CORS test working",
    "time" => date("Y-m-d H:i:s")
]);
