<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

foreach (['announcements', 'notifications', 'duty_excuses', 'temporary_duty_requests'] as $t) {
    try {
        $stmt = $pdo->query("SELECT * FROM `{$t}` ORDER BY 1 DESC LIMIT 3");
        echo "=== TABLE {$t} ===\n";
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        echo "{$t}: " . $e->getMessage() . "\n";
    }
}
