<?php
require_once "config.php";

echo json_encode([
    "success" => true,
    "message" => "CORS test working",
    "time" => date("Y-m-d H:i:s")
]);