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
    // Count unread recent active announcements for supervisors or all users
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM announcements a
        LEFT JOIN announcement_reads r ON a.id = r.announcement_id AND r.user_id = :user_id
        WHERE a.is_active = 1 
          AND a.audience IN ('supervisors','all') 
          AND a.created_at >= (NOW() - INTERVAL 30 DAY)
          AND r.id IS NULL
    ");
    $stmt->execute(['user_id' => $userId]);
    $count = (int) $stmt->fetchColumn();

    // Count unread duty excuses for supervisor's office
    $offStmt = $pdo->prepare('SELECT office_name FROM supervisors WHERE user_id = :user_id LIMIT 1');
    $offStmt->execute(['user_id' => $userId]);
    $supervisorOffice = trim((string) ($offStmt->fetchColumn() ?: ($user['office_name'] ?? '')));

    if ($supervisorOffice !== '') {
        $excuseCountStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM duty_excuses de
            LEFT JOIN applications a ON a.application_id = de.application_id
            LEFT JOIN duty_excuse_reads r ON (r.excuse_id = de.excuse_id AND r.user_id = :user_id)
            WHERE COALESCE(NULLIF(TRIM(de.office_name), ''), NULLIF(TRIM(a.preferred_office), '')) = :office
              AND r.id IS NULL
        ");
        $excuseCountStmt->execute(['user_id' => $userId, 'office' => $supervisorOffice]);
        $count += (int) $excuseCountStmt->fetchColumn();
    }

    echo json_encode(['success' => true, 'count' => $count]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
