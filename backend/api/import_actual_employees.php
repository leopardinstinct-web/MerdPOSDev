<?php
require_once "config.php";

$pdo->exec("
ALTER TABLE employees 
ADD COLUMN IF NOT EXISTS user_id VARCHAR(50) NULL AFTER full_name,
ADD COLUMN IF NOT EXISTS login_password VARCHAR(100) NULL AFTER user_id,
ADD COLUMN IF NOT EXISTS employee_type VARCHAR(20) NULL AFTER login_password
");

$pdo->exec("DELETE FROM employees");
$pdo->exec("ALTER TABLE employees AUTO_INCREMENT = 1");

$employees = [
    ['Abdallah', 'USER', '0466927209', '123456', 'INACTIVE', null],
    ['Adeel', 'USER', '0414154723', '123456', 'INACTIVE', 18],
    ['Anwer', 'USER', '0422488743', '061110', 'ACTIVE', 18],
    ['Hassan', 'USER', '0475091571', '123456', 'INACTIVE', 19],
    ['Karim', 'USER', '0487494559', '123456', 'ACTIVE', 18],
    ['Mohammad', 'USER', '0473454153', '636463', 'ACTIVE', 18],
    ['Mulham', 'SUPER', '0404549095', '123456', 'ACTIVE', null],
    ['Sameer', 'USER', '0493945963', '123456', 'INACTIVE', 18],
    ['Shoeb', 'USER', '0478290234', '123456', 'INACTIVE', null],
    ['Sony', 'USER', '0430543206', '123456', 'INACTIVE', null],
    ['Tabib', 'USER', '0432992153', '123456', 'INACTIVE', 19],
    ['Irfan', 'USER', '0466982562', '123456', 'INACTIVE', null],
    ['Fahim', 'USER', '0426285221', '123456', 'ACTIVE', 18],
    ['Aiyappa', 'USER', '0490061782', '123456', 'INACTIVE', 17],
    ['Imran', 'USER', '0426656624', '4493', 'ACTIVE', 20],
    ['Chowdhury', 'USER', '0449994192', '123456', 'ACTIVE', 18],
    ['Super User', 'SUPER', '1234', '12345678', 'ACTIVE', null],
    ['Faisal', 'USER', '0490131055', '133799', 'ACTIVE', 18],
    ['Tawfiq', 'USER', '0415708249', '123456', 'ACTIVE', 18],
    ['Kamal', 'USER', '0402620138', '123456', 'INACTIVE', null],
    ['Sidrah', 'USER', '0450162151', '123456', 'ACTIVE', 18],
    ['Al Shahriar', 'USER', '0430002465', '123456', 'INACTIVE', null],
    ['Tanvir', 'USER', '0451469762', '123456', 'INACTIVE', 17],
    ['Ahmed', 'USER', '0426185336', '123456', 'INACTIVE', 17],
    ['Tahsin', 'USER', '0421856002', '123456', 'INACTIVE', 17],
    ['Mursiln', 'USER', '0410278420', '123456', 'ACTIVE', 18],
    ['Amena', 'USER', '0406376993', '123456', 'ACTIVE', 18],
    ['Rakibul Hasan', 'USER', '0415137868', '123456', 'ACTIVE', 17],
    ['Shafiqur Rahman', 'USER', '0449227090', '123412', 'INACTIVE', 17],
    ['Afrin', 'USER', '0413540441', '123456', 'ACTIVE', 17],
    ['Wahid', 'USER', '0424296521', '123456', 'ACTIVE', 18],
    ['J. Hassan', 'USER', '0450577493', '536390', 'ACTIVE', 18],
    ['Jahid', 'USER', '0480612369', '123456', 'ACTIVE', 18],
    ['Tarek', 'USER', '0424763185', '123456', 'INACTIVE', 17],
    ['Shartaz', 'USER', '0410430102', '10082006', 'ACTIVE', 18],
    ['Jahirul Islam', 'USER', '0491039014', '123456', 'INACTIVE', 17],
    ['Srabony', 'USER', '0405688685', '123456', 'ACTIVE', 17],
    ['Rahi', 'USER', '0449995865', '123456', 'ACTIVE', 17],
];

$stmt = $pdo->prepare("
    INSERT INTO employees
    (
        client_id,
        store_id,
        full_name,
        user_id,
        login_password,
        employee_type,
        pin_code,
        role_name,
        hourly_rate,
        status
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($employees as $emp) {
    [$name, $type, $userId, $password, $status, $payRate] = $emp;

    $roleName = $type === 'SUPER' ? 'Manager' : 'Staff';
    $dbStatus = strtolower($status);

    $stmt->execute([
        1,
        1,
        $name,
        $userId,
        $password,
        $type,
        $password,
        $roleName,
        $payRate ?? 0,
        $dbStatus
    ]);
}

echo json_encode([
    "success" => true,
    "message" => "Actual employee database imported successfully",
    "count" => count($employees)
]);