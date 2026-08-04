<?php
require_once __DIR__ . '/includes/bootstrap.php';
$admin = current_admin(); if ($admin) audit($pdo, $admin, 'logout', 'session');
session_unset(); session_destroy(); redirect('login.php');
