<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "Existing tables:\n";
print_r($tables);
