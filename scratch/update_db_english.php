<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

$pdo->exec("UPDATE duty_excuses SET reason = 'Severe flu and high fever, unable to report for scheduled duty.' WHERE excuse_id = 1");
$pdo->exec("UPDATE attendance_logs SET notes = 'Duty Excuse (Medical / Sickness): Severe flu and high fever, unable to report for scheduled duty.' WHERE notes LIKE '%May matinding lagnat%'");
$pdo->exec("UPDATE temporary_duty_requests SET reason = 'Class was suspended due to unexpected professor absence, making hours available for duty.' WHERE request_id = 5");

echo "Updated test database entries to English.\n";
