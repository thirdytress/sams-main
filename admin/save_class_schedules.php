<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$payload = json_decode((string) file_get_contents('php://input'), true);
$applicationId = (int) ($payload['application_id'] ?? 0);
$schedules = $payload['class_schedules'] ?? [];

if ($applicationId <= 0 || !is_array($schedules)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid class schedule data.']);
    exit;
}

$pdo = sams_pdo();
$applicationStmt = $pdo->prepare('SELECT term_id FROM applications WHERE application_id = :application_id LIMIT 1');
$applicationStmt->execute(['application_id' => $applicationId]);
$application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
if (!$application) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}

$allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$rows = [];
foreach ($schedules as $schedule) {
    $startMin = (int) ($schedule['startMin'] ?? -1);
    $endMin = (int) ($schedule['endMin'] ?? -1);
    if ($startMin < 0 || $endMin <= $startMin || $endMin > 1439) {
        continue;
    }
    foreach (($schedule['days'] ?? []) as $day) {
        if (!in_array($day, $allowedDays, true)) {
            continue;
        }
        $rows[] = [
            'day' => $day,
            'start' => sprintf('%02d:%02d:00', intdiv($startMin, 60), $startMin % 60),
            'end' => sprintf('%02d:%02d:00', intdiv($endMin, 60), $endMin % 60),
            'subject' => substr(trim((string) ($schedule['subjectCode'] ?? '')), 0, 50),
        ];
    }
}

try {
    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM class_schedules WHERE application_id = :application_id AND term_id = :term_id');
    $delete->execute([
        'application_id' => $applicationId,
        'term_id' => (int) $application['term_id'],
    ]);
    $insert = $pdo->prepare(
        'INSERT INTO class_schedules
         (application_id, term_id, day_of_week, start_time, end_time, subject_code)
         VALUES (:application_id, :term_id, :day_of_week, :start_time, :end_time, :subject_code)'
    );
    foreach ($rows as $row) {
        $insert->execute([
            'application_id' => $applicationId,
            'term_id' => (int) $application['term_id'],
            'day_of_week' => $row['day'],
            'start_time' => $row['start'],
            'end_time' => $row['end'],
            'subject_code' => $row['subject'] !== '' ? $row['subject'] : null,
        ]);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'count' => count($rows)]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Failed to save parsed class schedules: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save class schedules.']);
}
