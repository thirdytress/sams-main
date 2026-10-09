<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/admin_notifications.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$adminUserId = (int) ($user['user_id'] ?? 0);
if ($adminUserId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid user']);
    exit;
}

$notificationId = (int) ($_POST['notification_id'] ?? 0);
$reportId = (int) ($_POST['report_id'] ?? 0);
$applicationId = (int) ($_POST['application_id'] ?? 0);
$type = trim((string) ($_POST['type'] ?? ''));
$markAll = !empty($_POST['mark_all']);

if (!$markAll && ($type === '' || ($notificationId <= 0 && $reportId <= 0 && $applicationId <= 0))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid payload']);
    exit;
}

$pdo = sams_pdo();

try {
    if ($markAll) {
        sams_admin_application_notifications_sync($pdo, $adminUserId);
        $stmtApplications = $pdo->prepare(
            'UPDATE admin_application_notifications
             SET is_read = 1, read_at = NOW()
             WHERE admin_user_id = :admin_user_id AND is_read = 0'
        );
        $stmtApplications->execute(['admin_user_id' => $adminUserId]);

        // Mark all meeting notifications for current admin as read
        $stmtMeetings = $pdo->prepare(
            'UPDATE admin_meeting_notifications
             SET is_read = 1, read_at = NOW()
             WHERE admin_user_id = :admin_user_id AND is_read = 0'
        );
        $stmtMeetings->execute(['admin_user_id' => $adminUserId]);

        // Mark all open student reports as read (is_new = 0)
        $stmtReports = $pdo->query(
            "UPDATE student_reports
             SET is_new = 0
             WHERE status = 'open' AND is_new = 1"
        );

        // Mark all duty excuses as read for current admin
        try {
            $pdo->prepare(
                'INSERT IGNORE INTO duty_excuse_reads (excuse_id, user_id, read_at)
                 SELECT excuse_id, :admin_user_id, NOW()
                 FROM duty_excuses'
            )->execute(['admin_user_id' => $adminUserId]);
        } catch (Throwable $e) {
            // ignore
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($type === 'duty_excuse' && $notificationId > 0) {
        try {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO duty_excuse_reads (excuse_id, user_id, read_at)
                 VALUES (:excuse_id, :admin_user_id, NOW())'
            );
            $stmt->execute([
                'excuse_id' => $notificationId,
                'admin_user_id' => $adminUserId,
            ]);
            echo json_encode(['success' => true]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'failed to mark read']);
            exit;
        }
    }
    if ($type === 'meeting' && $notificationId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE admin_meeting_notifications
             SET is_read = 1, read_at = NOW()
             WHERE notification_id = :notification_id
               AND admin_user_id = :admin_user_id
             LIMIT 1'
        );
        $stmt->execute([
            'notification_id' => $notificationId,
            'admin_user_id' => $adminUserId,
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($type === 'report' && $reportId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE student_reports
             SET is_new = 0
             WHERE report_id = :report_id
             LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($type === 'application' && $notificationId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE admin_application_notifications
             SET is_read = 1, read_at = NOW()
             WHERE notification_id = :notification_id
               AND admin_user_id = :admin_user_id
             LIMIT 1'
        );
        $stmt->execute([
            'notification_id' => $notificationId,
            'admin_user_id' => $adminUserId,
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'unsupported type']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'db error']);
}
