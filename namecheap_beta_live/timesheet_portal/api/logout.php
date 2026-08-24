<?php
require_once __DIR__ . '/../includes/auth.php';
logout_user();
json_response(['success' => true]);
