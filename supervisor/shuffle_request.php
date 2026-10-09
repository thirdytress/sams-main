<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/reshuffle.php';
require_once __DIR__ . '/../config/audit.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'supervisor') {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
    http_response_code(400);
    exit('Invalid request token.');
}

$pdo = sams_pdo();
sams_reshuffle_ensure_schema($pdo);

$supervisorStmt = $pdo->prepare('SELECT supervisor_id, office_name FROM supervisors WHERE user_id = :user_id LIMIT 1');
$supervisorStmt->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisor = $supervisorStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$fromStudentId = (int) ($_POST['from_student_id'] ?? 0);
$toStudentId = (int) ($_POST['to_student_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));
$returnTo = (string) ($_POST['return_to'] ?? 'evaluation.php');
if (!in_array($returnTo, ['evaluation.php', 'students.php'], true)) {
    $returnTo = 'evaluation.php';
}

try {
    if (!$supervisor || $fromStudentId <= 0 || $reason === '') {
        throw new RuntimeException('Please select a student and provide a detailed reason for the reshuffle request.');
    }

    $term = sams_current_term($pdo);
    $termId = (int) ($term['term_id'] ?? 0);
    if ($termId <= 0) {
        throw new RuntimeException('No active academic term is currently configured.');
    }

    // 1. Verify student assignment in supervisor's office
    $accessStmt = $pdo->prepare(
        'SELECT a.application_id, u.first_name, u.last_name, s.student_id_number, s.reshuffle_count
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.student_id = :student_id AND a.term_id = :term_id
           AND a.status IN ("approved", "deployed")
           AND COALESCE(NULLIF(TRIM(a.preferred_office), ""), "Assigned Office") = :office
         LIMIT 1'
    );
    $accessStmt->execute([
        'student_id' => $fromStudentId,
        'term_id' => $termId,
        'office' => $supervisor['office_name'],
    ]);
    $studentApp = $accessStmt->fetch(PDO::FETCH_ASSOC);
    if (!$studentApp) {
        throw new RuntimeException('That student is not assigned to your office for the active term.');
    }

    $studentName = trim((string) ($studentApp['first_name'] ?? '') . ' ' . (string) ($studentApp['last_name'] ?? ''));

    // 2. Check if student has been evaluated first
    $latestEval = sams_student_latest_evaluation($pdo, $fromStudentId, $termId);
    if (!$latestEval) {
        throw new RuntimeException('Performance evaluation must be completed for ' . $studentName . ' before requesting a reshuffle.');
    }
    $evalId = (int) ($latestEval['eval_id'] ?? $latestEval['evaluation_id'] ?? $latestEval['id'] ?? 0);

    // 3. Check 3-reshuffle maximum limit
    $currentShuffles = sams_student_reshuffle_count($pdo, $fromStudentId);
    if ($currentShuffles >= 3) {
        throw new RuntimeException($studentName . ' has already reached the maximum limit of 3 office reshuffles and cannot be transferred again.');
    }

    if ($toStudentId === $fromStudentId) {
        throw new RuntimeException('Proposed replacement student must be different from the evaluated student.');
    }

    if ($toStudentId > 0) {
        $replacementStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM applications a
             WHERE a.student_id = :student_id AND a.term_id = :term_id
               AND a.status IN ("approved", "deployed")'
        );
        $replacementStmt->execute(['student_id' => $toStudentId, 'term_id' => $termId]);
        if ((int) $replacementStmt->fetchColumn() === 0) {
            throw new RuntimeException('Replacement student must have an approved application for this term.');
        }
    }

    // 4. Check for duplicate pending requests
    $duplicateStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM shuffle_requests
         WHERE term_id = :term_id AND from_student_id = :from_student_id AND status = "pending"'
    );
    $duplicateStmt->execute(['term_id' => $termId, 'from_student_id' => $fromStudentId]);
    if ((int) $duplicateStmt->fetchColumn() > 0) {
        throw new RuntimeException('There is already a pending reshuffle request for ' . $studentName . '.');
    }

    // 5. Insert shuffle request
    $insert = $pdo->prepare(
        'INSERT INTO shuffle_requests (term_id, supervisor_id, from_student_id, to_student_id, evaluation_id, reason, status)
         VALUES (:term_id, :supervisor_id, :from_student_id, :to_student_id, :evaluation_id, :reason, "pending")'
    );
    $insert->execute([
        'term_id' => $termId,
        'supervisor_id' => $supervisor['supervisor_id'],
        'from_student_id' => $fromStudentId,
        'to_student_id' => $toStudentId > 0 ? $toStudentId : null,
        'evaluation_id' => $evalId > 0 ? $evalId : null,
        'reason' => $reason,
    ]);

    $shuffleReqId = (int) $pdo->lastInsertId();

    sams_log_audit(
        $pdo,
        'RESHUFFLE_REQUEST',
        'Reshuffle',
        "Supervisor submitted Reshuffle Request for {$studentName} in {$supervisor['office_name']} (Transfer #" . ($currentShuffles + 1) . " of 3).",
        [
            'from_student_id' => $fromStudentId,
            'to_student_id' => $toStudentId,
            'reason' => $reason,
            'evaluation_id' => $evalId,
            'reshuffle_number' => $currentShuffles + 1,
            'office' => $supervisor['office_name'],
        ],
        $shuffleReqId,
        'shuffle_request'
    );

    $nextShuffleNumber = $currentShuffles + 1;
    $_SESSION['supervisor_shuffle_flash'] = 'Reshuffle request submitted for ' . $studentName . ' (Reshuffle #' . $nextShuffleNumber . ' of 3). Sent to Admin for review.';
} catch (Throwable $exception) {
    $_SESSION['supervisor_shuffle_flash_error'] = $exception->getMessage();
}

header('Location: ' . $returnTo);
exit;