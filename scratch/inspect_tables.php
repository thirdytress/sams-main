<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
foreach (['supervisors', 'duty_schedules', 'temporary_duty_requests'] as $table) {
    echo "=== $table ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE `$table`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . "\n";
        }
    } catch (Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
