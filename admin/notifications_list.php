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

$pdo = sams_pdo();
try {
    $adminUserId = (int) ($user['user_id'] ?? 0);
    if ($adminUserId > 0) {
        sams_admin_meetings_generate_notifications($pdo, $adminUserId);
        sams_admin_application_notifications_sync($pdo, $adminUserId);
    }

    $applicationStmt = $pdo->prepare(
        "SELECT n.notification_id, n.application_id, n.created_at,
                s.student_id_number, u.first_name, u.last_name,
                a.preferred_office, a.submitted_at
         FROM admin_application_notifications n
         INNER JOIN applications a ON a.application_id = n.application_id
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE n.admin_user_id = :admin_user_id AND n.is_read = 0
         ORDER BY a.submitted_at DESC, n.notification_id DESC
         LIMIT 8"
    );
    $applicationStmt->execute(['admin_user_id' => $adminUserId]);
    $applicationRows = $applicationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $reportStmt = $pdo->query(
        "SELECT sr.report_id, sr.student_code, sr.application_id, sr.notes, sr.created_at, COALESCE(a.preferred_office, '') AS preferred_office
         FROM student_reports sr
         LEFT JOIN applications a ON a.application_id = sr.application_id
         WHERE sr.status = 'open'
         ORDER BY sr.created_at DESC
         LIMIT 8"
    );

    $meetingRows = [];
    if ($adminUserId > 0) {
        $meetingStmt = $pdo->prepare(
            "SELECT
                mn.notification_id,
                mn.meeting_id,
                mn.notify_type,
                mn.message,
                mn.scheduled_notify_at,
                m.title,
                m.category,
                m.meeting_date,
                m.start_time,
                m.location
             FROM admin_meeting_notifications mn
             INNER JOIN admin_meetings m ON m.meeting_id = mn.meeting_id
             WHERE mn.admin_user_id = :admin_user_id
               AND mn.is_read = 0
             ORDER BY mn.scheduled_notify_at DESC
             LIMIT 8"
        );
        $meetingStmt->execute(['admin_user_id' => $adminUserId]);
        $meetingRows = $meetingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $availabilityRows = [];
    try {
        $availabilityRows = $pdo->query(
            "SELECT r.request_id, r.application_id, r.requested_at,
                    s.student_id_number, u.first_name, u.last_name
             FROM availability_change_requests r
             INNER JOIN students s ON s.student_id = r.student_id
             INNER JOIN users u ON u.user_id = s.user_id
             WHERE r.status = 'pending'
             ORDER BY r.requested_at DESC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $exception) {
        $availabilityRows = [];
    }

    $items = [];
    foreach ($applicationRows as $row) {
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $items[] = [
            'type' => 'application',
            'notification_id' => (int) ($row['notification_id'] ?? 0),
            'application_id' => (int) ($row['application_id'] ?? 0),
            'student_code' => (string) ($row['student_id_number'] ?? ''),
            'title' => 'New applicant: ' . ($name !== '' ? $name : 'Student'),
            'preferred_office' => (string) ($row['preferred_office'] ?? ''),
            'snippet' => 'A new application is ready for review.',
            'created_at' => (string) ($row['submitted_at'] ?? $row['created_at'] ?? ''),
            'link_url' => 'application_view.php?application_id=' . (int) ($row['application_id'] ?? 0),
        ];
    }
    foreach ($reportStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'type' => 'report',
            'report_id' => (int) ($r['report_id'] ?? 0),
            'student_code' => (string) ($r['student_code'] ?? ''),
            'application_id' => (int) ($r['application_id'] ?? 0),
            'title' => 'Student report: ' . (string) ($r['student_code'] ?? 'Student'),
            'preferred_office' => (string) ($r['preferred_office'] ?? ''),
            'snippet' => mb_substr((string) ($r['notes'] ?? ''), 0, 140),
            'created_at' => (string) ($r['created_at'] ?? ''),
            'link_url' => 'report_detail.php?report_id=' . (int) ($r['report_id'] ?? 0),
        ];
    }

    foreach ($meetingRows as $row) {
        $title = trim((string) ($row['title'] ?? 'Meeting'));
        $meetingDate = (string) ($row['meeting_date'] ?? '');
        $startTime = (string) ($row['start_time'] ?? '');
        $whenText = trim($meetingDate . ' ' . $startTime);
        $location = trim((string) ($row['location'] ?? ''));

        $items[] = [
            'type' => 'meeting',
            'meeting_id' => (int) ($row['meeting_id'] ?? 0),
            'notification_id' => (int) ($row['notification_id'] ?? 0),
            'notify_type' => (string) ($row['notify_type'] ?? ''),
            'title' => 'Meeting: ' . $title,
            'student_code' => 'Meeting',
            'preferred_office' => (string) ($row['category'] ?? ''),
            'snippet' => trim((string) ($row['message'] ?? '') . ($location !== '' ? ' Location: ' . $location : '')),
            'created_at' => (string) ($row['scheduled_notify_at'] ?? ''),
            'link_url' => 'meetings.php?meeting_id=' . (int) ($row['meeting_id'] ?? 0),
            'meeting_at' => $whenText,
        ];
    }

    foreach ($availabilityRows as $row) {
        $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
        $items[] = [
            'type' => 'availability_change',
            'request_id' => (int) $row['request_id'],
            'application_id' => (int) $row['application_id'],
            'title' => 'Availability change request',
            'student_code' => (string) $row['student_id_number'],
            'preferred_office' => 'Scheduling',
            'snippet' => $name . ' requested a change to their time availability.',
            'created_at' => (string) $row['requested_at'],
            'link_url' => 'scheduling.php?request_id=' . (int) $row['request_id'],
        ];
    }

    if ($adminUserId > 0) {
        try {
            $excuseStmt = $pdo->prepare(
                "SELECT de.excuse_id, de.duty_date, de.excuse_type, de.reason, de.submitted_at,
                        COALESCE(NULLIF(TRIM(de.office_name), ''), NULLIF(TRIM(a.preferred_office), ''), 'Assigned Office') AS office_name,
                        s.student_id_number, u.first_name, u.last_name
                 FROM duty_excuses de
                 INNER JOIN students s ON s.student_id = de.student_id
                 INNER JOIN users u ON u.user_id = s.user_id
                 LEFT JOIN applications a ON a.application_id = de.application_id
                 LEFT JOIN duty_excuse_reads r ON (r.excuse_id = de.excuse_id AND r.user_id = :admin_user_id)
                 WHERE r.id IS NULL
                 ORDER BY de.submitted_at DESC
                 LIMIT 8"
            );
            $excuseStmt->execute(['admin_user_id' => $adminUserId]);
            $excuseRows = $excuseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($excuseRows as $row) {
                $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                $dateLabel = date('M d, Y', strtotime((string) $row['duty_date']));
                $items[] = [
                    'type' => 'duty_excuse',
                    'notification_id' => (int) $row['excuse_id'],
                    'student_code' => (string) ($row['student_id_number'] ?? ''),
                    'title' => 'Duty Excuse: ' . ($name !== '' ? $name : 'Student'),
                    'preferred_office' => (string) ($row['office_name'] ?? ''),
                    'snippet' => 'Excused on ' . $dateLabel . ' (' . (string) $row['excuse_type'] . '): ' . mb_substr((string) $row['reason'], 0, 80),
                    'created_at' => (string) ($row['submitted_at'] ?? ''),
                    'link_url' => 'duty_excuses.php',
                ];
            }
        } catch (Throwable $exception) {
            // ignore
        }
    }

    usort(
        $items,
        static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        }
    );

    $items = array_slice($items, 0, 10);
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
