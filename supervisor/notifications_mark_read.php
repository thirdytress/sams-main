<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid user']);
    exit;
}

$notificationId = (int) ($_POST['notification_id'] ?? 0);
$type = trim((string) ($_POST['type'] ?? ''));
$markAll = !empty($_POST['mark_all']);

if (!$markAll && (!in_array($type, ['announcement', 'duty_excuse'], true) || $notificationId <= 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid payload']);
    exit;
}

$pdo = sams_pdo();

try {
    if ($markAll) {
        // Mark all active announcements for supervisors/all as read
        $stmt = $pdo->prepare('
            INSERT INTO announcement_reads (announcement_id, user_id, read_at)
            SELECT id, :uid, NOW()
            FROM announcements
            WHERE is_active = 1 AND audience IN ("supervisors", "all")
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ');
        $stmt->execute(['uid' => $userId]);

        // Mark all duty excuses for this supervisor's office as read
        $offStmt = $pdo->prepare('SELECT office_name FROM supervisors WHERE user_id = :user_id LIMIT 1');
        $offStmt->execute(['user_id' => $userId]);
        $supervisorOffice = trim((string) ($offStmt->fetchColumn() ?: ($user['office_name'] ?? '')));
        if ($supervisorOffice !== '') {
            $pdo->prepare('
                INSERT IGNORE INTO duty_excuse_reads (excuse_id, user_id, read_at)
                SELECT de.excuse_id, :uid, NOW()
                FROM duty_excuses de
                LEFT JOIN applications a ON a.application_id = de.application_id
                WHERE COALESCE(NULLIF(TRIM(de.office_name), ""), NULLIF(TRIM(a.preferred_office), "")) = :office
            ')->execute(['uid' => $userId, 'office' => $supervisorOffice]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($type === 'announcement' && $notificationId > 0) {
        $ins = $pdo->prepare(
            'INSERT INTO announcement_reads (announcement_id, user_id, read_at) 
             VALUES (:aid, :uid, NOW())
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        );
        $ins->execute(['aid' => $notificationId, 'uid' => $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($type === 'duty_excuse' && $notificationId > 0) {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO duty_excuse_reads (excuse_id, user_id, read_at)
             VALUES (:excuse_id, :uid, NOW())'
        );
        $ins->execute(['excuse_id' => $notificationId, 'uid' => $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'unsupported type']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'db error', 'error' => $e->getMessage()]);
}
