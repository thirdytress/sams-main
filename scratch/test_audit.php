<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/audit.php';

$pdo = sams_pdo();
sams_audit_ensure_schema($pdo);
sams_audit_seed_initial_history($pdo);

$count = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
echo "Audit logs count: " . $count . "\n";

$rows = $pdo->query("SELECT * FROM audit_logs ORDER BY audit_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
