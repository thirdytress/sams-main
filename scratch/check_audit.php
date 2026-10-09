<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$cols = $pdo->query('DESCRIBE audit_logs')->fetchAll(PDO::FETCH_ASSOC);
echo "=== audit_logs columns ===\n";
print_r($cols);

$sample = $pdo->query('SELECT * FROM audit_logs ORDER BY 1 DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo "=== sample rows ===\n";
print_r($sample);
