<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/admin_notifications.php';
require_once __DIR__ . '/../config/reshuffle.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$pdo = sams_pdo();
sams_reshuffle_ensure_schema($pdo);

try {
    $adminUserId = (int) ($user['user_id'] ?? 0);

    if ($adminUserId > 0) {
        sams_admin_meetings_generate_notifications($pdo, $adminUserId);
        sams_admin_application_notifications_sync($pdo, $adminUserId);
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM student_reports WHERE status = 'open' AND is_new = 1");
    $reportCount = (int) $stmt->fetchColumn();

    $meetingCount = 0;
    if ($adminUserId > 0) {
        $meetingStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_meeting_notifications WHERE admin_user_id = :admin_user_id AND is_read = 0'
        );
        $meetingStmt->execute(['admin_user_id' => $adminUserId]);
        $meetingCount = (int) $meetingStmt->fetchColumn();
    }

    $applicationCount = 0;
    if ($adminUserId > 0) {
        $applicationStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_application_notifications
             WHERE admin_user_id = :admin_user_id AND is_read = 0'
        );
        $applicationStmt->execute(['admin_user_id' => $adminUserId]);
        $applicationCount = (int) $applicationStmt->fetchColumn();
    }

    $availabilityCount = 0;
    try {
        $availabilityCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM availability_change_requests WHERE status = 'pending'"
        )->fetchColumn();
    } catch (Throwable $exception) {
        $availabilityCount = 0;
    }

    $dutyExcuseCount = 0;
    if ($adminUserId > 0) {
        try {
            $excuseCountStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM duty_excuses de
                 LEFT JOIN duty_excuse_reads r ON (r.excuse_id = de.excuse_id AND r.user_id = :admin_user_id)
                 WHERE r.id IS NULL'
            );
            $excuseCountStmt->execute(['admin_user_id' => $adminUserId]);
            $dutyExcuseCount = (int) $excuseCountStmt->fetchColumn();
        } catch (Throwable $e) {
            $dutyExcuseCount = 0;
        }
    }

    $shuffleCount = 0;
    try {
        $shuffleCount = (int) $pdo->query("SELECT COUNT(*) FROM shuffle_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        $shuffleCount = 0;
    }

    $maxShuffleCount = 0;
    try {
        $maxShuffleCount = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE reshuffle_count >= 3")->fetchColumn();
    } catch (Throwable $e) {
        $maxShuffleCount = 0;
    }

    $count = $reportCount + $meetingCount + $availabilityCount + $applicationCount + $dutyExcuseCount + $shuffleCount + $maxShuffleCount;
    echo json_encode(['success' => true, 'count' => $count]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
