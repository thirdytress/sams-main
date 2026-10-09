<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

foreach (['shuffle_requests', 'shuffle_history', 'evaluations', 'duty_schedules', 'students', 'applications'] as $t) {
    try {
        echo "=== TABLE {$t} ===\n";
        $cols = $pdo->query("DESCRIBE `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  " . $c['Field'] . " | " . $c['Type'] . " | " . $c['Null'] . " | " . $c['Key'] . " | " . ($c['Default'] ?? 'NULL') . "\n";
        }
    } catch (Throwable $e) {
        echo "{$t}: " . $e->getMessage() . "\n";
    }
}
