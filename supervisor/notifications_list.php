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
$pdo = sams_pdo();
try {
    $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT a.id, a.title, a.body, a.created_at
        FROM announcements a
        LEFT JOIN announcement_reads r ON a.id = r.announcement_id AND r.user_id = :user_id
        WHERE a.is_active = 1 
          AND a.audience IN ('supervisors','all') 
          AND r.id IS NULL
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute(['user_id' => $userId]);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $body = (string) ($row['body'] ?? '');
        $snippet = mb_strlen($body) > 200 ? mb_substr($body, 0, 197) . '...' : $body;
        $items[] = [
            'type' => 'announcement',
            'notification_id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? 'Announcement'),
            'preferred_office' => 'Announcement',
            'snippet' => $snippet,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'link_url' => 'announcements.php',
        ];
    }

    // Duty excuse notifications for supervisor's office
    $offStmt = $pdo->prepare('SELECT office_name FROM supervisors WHERE user_id = :user_id LIMIT 1');
    $offStmt->execute(['user_id' => $userId]);
    $supervisorOffice = trim((string) ($offStmt->fetchColumn() ?: ($user['office_name'] ?? '')));

    if ($supervisorOffice !== '') {
        $excuseStmt = $pdo->prepare("
            SELECT de.excuse_id, de.duty_date, de.excuse_type, de.reason, de.submitted_at,
                   u.first_name, u.last_name, s.student_id_number
            FROM duty_excuses de
            INNER JOIN students s ON s.student_id = de.student_id
            INNER JOIN users u ON u.user_id = s.user_id
            LEFT JOIN applications a ON a.application_id = de.application_id
            LEFT JOIN duty_excuse_reads r ON (r.excuse_id = de.excuse_id AND r.user_id = :user_id)
            WHERE COALESCE(NULLIF(TRIM(de.office_name), ''), NULLIF(TRIM(a.preferred_office), '')) = :office
              AND r.id IS NULL
            ORDER BY de.submitted_at DESC
            LIMIT 10
        ");
        $excuseStmt->execute(['user_id' => $userId, 'office' => $supervisorOffice]);
        while ($row = $excuseStmt->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            $dateLabel = date('M d, Y', strtotime((string) $row['duty_date']));
            $items[] = [
                'type' => 'duty_excuse',
                'notification_id' => (int) $row['excuse_id'],
                'title' => 'Duty Excuse: ' . ($name !== '' ? $name : 'Student'),
                'preferred_office' => $supervisorOffice,
                'snippet' => 'Excused on ' . $dateLabel . ' (' . (string) $row['excuse_type'] . '): ' . mb_substr((string) $row['reason'], 0, 80),
                'created_at' => (string) ($row['submitted_at'] ?? ''),
                'link_url' => 'duty_excuses.php',
            ];
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
    $items = array_slice($items, 0, 10);

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
