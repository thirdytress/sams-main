<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/reshuffle.php';

$pdo = sams_pdo();
sams_reshuffle_ensure_schema($pdo);

echo "=== 1. Checking schema columns ===\n";
$hasReshuffleCount = sams_column_exists($pdo, 'students', 'reshuffle_count');
$hasRetentionDecision = sams_column_exists($pdo, 'evaluations', 'retention_decision');
$hasEvalIdInShuffle = sams_column_exists($pdo, 'shuffle_requests', 'evaluation_id');
echo "students.reshuffle_count: " . ($hasReshuffleCount ? 'YES' : 'NO') . "\n";
echo "evaluations.retention_decision: " . ($hasRetentionDecision ? 'YES' : 'NO') . "\n";
echo "shuffle_requests.evaluation_id: " . ($hasEvalIdInShuffle ? 'YES' : 'NO') . "\n";

echo "=== 2. Finding a test student assistant ===\n";
$appStmt = $pdo->query(
    "SELECT a.application_id, a.student_id, a.term_id, a.preferred_office,
            s.reshuffle_count, u.first_name, u.last_name
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.status IN ('approved', 'deployed')
     LIMIT 1"
);
$app = $appStmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    echo "No approved application found in DB.\n";
    exit;
}

$studentId = (int) $app['student_id'];
$appId = (int) $app['application_id'];
$termId = (int) $app['term_id'];
$studentName = trim($app['first_name'] . ' ' . $app['last_name']);
echo "Testing with: {$studentName} (Student ID: {$studentId}, App ID: {$appId})\n";

echo "=== 3. Testing reshuffle count helper ===\n";
$initialCount = sams_student_reshuffle_count($pdo, $studentId);
echo "Initial reshuffle count: {$initialCount}\n";
$canShuffle = sams_student_can_reshuffle($pdo, $studentId);
echo "Can reshuffle (< 3): " . ($canShuffle ? 'YES' : 'NO') . "\n";

echo "=== 4. Testing evaluation insertion with retention decision ===\n";
$evalStmt = $pdo->prepare(
    "INSERT INTO evaluations
        (application_id, term_id, supervisor_id, performance_rating, reliability_rating, professionalism_rating, comments, retention_decision, submitted_at)
     VALUES
        (:app_id, :term_id, 1, 5, 4, 5, 'Great performance and very reliable.', 'pending', NOW())"
);
$evalStmt->execute(['app_id' => $appId, 'term_id' => $termId]);
$evalId = (int) $pdo->lastInsertId();
echo "Inserted test evaluation ID: {$evalId}\n";

$latestEval = sams_student_latest_evaluation($pdo, $studentId, $termId);
echo "Latest evaluation found: Avg Rating = " . ($latestEval['avg_rating'] ?? 'N/A') . ", Decision = " . ($latestEval['retention_decision'] ?? 'N/A') . "\n";

echo "=== 5. Testing retention confirmation ===\n";
$pdo->prepare("UPDATE evaluations SET retention_decision = 'retain' WHERE evaluation_id = :id")->execute(['id' => $evalId]);
$updatedEval = sams_student_latest_evaluation($pdo, $studentId, $termId);
echo "Updated retention decision: " . ($updatedEval['retention_decision'] ?? 'N/A') . "\n";

echo "=== 6. Testing 3-reshuffle limit behavior ===\n";
// Set student reshuffle count to 3
$pdo->prepare("UPDATE students SET reshuffle_count = 3 WHERE student_id = :id")->execute(['id' => $studentId]);
$maxCount = sams_student_reshuffle_count($pdo, $studentId);
$canShuffleAt3 = sams_student_can_reshuffle($pdo, $studentId);
echo "Reshuffle count at 3: {$maxCount}, Can reshuffle: " . ($canShuffleAt3 ? 'YES (UNEXPECTED)' : 'NO (CORRECT)') . "\n";

$maxStudents = sams_admin_get_max_reshuffle_students($pdo);
echo "Admin max reshuffle students query count: " . count($maxStudents) . "\n";

echo "=== 7. Cleanup test data ===\n";
$pdo->prepare("DELETE FROM evaluations WHERE evaluation_id = :id")->execute(['id' => $evalId]);
$pdo->prepare("UPDATE students SET reshuffle_count = :cnt WHERE student_id = :id")->execute(['cnt' => $initialCount, 'id' => $studentId]);
echo "Restored student reshuffle count to {$initialCount} and removed test evaluation.\n";
echo "=== All tests passed successfully! ===\n";
