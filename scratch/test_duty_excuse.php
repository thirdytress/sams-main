<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/attendance.php';
require_once __DIR__ . '/../config/duty_excuses.php';

$pdo = sams_pdo();

echo "=== 1. Checking schema ===\n";
sams_duty_excuses_ensure_schema($pdo);
echo "Tables verified.\n";

echo "=== 2. Finding test student and application ===\n";
$appStmt = $pdo->query("SELECT a.application_id, a.student_id, a.term_id, a.preferred_office, s.user_id, u.first_name, u.last_name FROM applications a INNER JOIN students s ON s.student_id = a.student_id INNER JOIN users u ON u.user_id = s.user_id WHERE a.status IN ('approved', 'deployed') LIMIT 1");
$app = $appStmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    echo "No approved application found. Trying any application...\n";
    $app = $pdo->query("SELECT a.application_id, a.student_id, a.term_id, a.preferred_office, s.user_id, u.first_name, u.last_name FROM applications a INNER JOIN students s ON s.student_id = a.student_id INNER JOIN users u ON u.user_id = s.user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

if (!$app) {
    echo "No application found in DB.\n";
    exit;
}

echo "Testing with Student: " . $app['first_name'] . " " . $app['last_name'] . " (App ID: " . $app['application_id'] . ")\n";

$testDate = date('Y-m-d');
$testDay = date('l');

echo "=== 3. Simulating duty excuse insert ===\n";
$testExcuseInsert = $pdo->prepare(
    "INSERT INTO duty_excuses
        (student_id, application_id, term_id, duty_date, day_of_week, office_name,
         excuse_type, reason, proof_original_name, proof_stored_name, proof_path, status, submitted_at)
     VALUES
        (:student_id, :application_id, :term_id, :duty_date, :day_of_week, :office_name,
         'Medical / Sickness', 'Severe flu and high fever, unable to report for scheduled duty.',
         'medical_cert.pdf', 'test_proof.pdf', 'uploads/duty_excuse/test.pdf', 'excused', NOW())"
);
$testExcuseInsert->execute([
    'student_id' => $app['student_id'],
    'application_id' => $app['application_id'],
    'term_id' => $app['term_id'],
    'duty_date' => $testDate,
    'day_of_week' => $testDay,
    'office_name' => $app['preferred_office'],
]);
$testExcuseId = (int) $pdo->lastInsertId();
echo "Inserted test excuse ID: " . $testExcuseId . "\n";

echo "=== 4. Syncing attendance logs ===\n";
sams_duty_excuse_sync_attendance_logs(
    $pdo,
    (int) $app['application_id'],
    (int) $app['term_id'],
    $testDate,
    $testDay,
    'Medical / Sickness',
    'Severe flu and high fever'
);

$logCheck = $pdo->prepare("SELECT * FROM attendance_logs WHERE application_id = :app_id AND DATE(created_at) = :d");
$logCheck->execute(['app_id' => $app['application_id'], 'd' => $testDate]);
$logs = $logCheck->fetchAll(PDO::FETCH_ASSOC);
echo "Attendance logs on {$testDate}:\n";
foreach ($logs as $l) {
    echo " - Log ID: " . $l['log_id'] . " | Status: " . $l['status'] . " | Notes: " . $l['notes'] . "\n";
}

echo "=== 5. Testing normalize student logs ===\n";
$normLogs = sams_attendance_normalize_student_logs($pdo, (int) $app['application_id'], (int) $app['term_id']);
$todayNorm = array_filter($normLogs, fn($nl) => str_starts_with((string)($nl['created_at'] ?? ''), $testDate));
echo "Normalized logs for today:\n";
foreach ($todayNorm as $tn) {
    echo " - Duty: " . ($tn['duty_id'] ?? 'none') . " | Status: " . $tn['status'] . " | Notes: " . $tn['notes'] . "\n";
}

echo "=== 6. Cleaning up test record ===\n";
$pdo->exec("DELETE FROM duty_excuses WHERE excuse_id = {$testExcuseId}");
$pdo->exec("DELETE FROM attendance_logs WHERE application_id = {$app['application_id']} AND notes LIKE '%Duty Excuse%'");
echo "Cleaned up successfully.\n";
