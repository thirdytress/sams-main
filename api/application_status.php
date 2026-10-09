<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$pdo = sams_pdo();

// Prefer application id stored in request params, session, or authenticated user
$submission = $_SESSION['registration_submission'] ?? [];
$appId = isset($_GET['application_id']) ? (int)$_GET['application_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($appId <= 0 && isset($submission['application_id'])) {
    $appId = (int) $submission['application_id'];
}

$user = sams_authenticated_user();
$userId = (int) ($user['user_id'] ?? 0);

// Fetch dynamic admin / SDAO contact info from the database
$adminContact = [
    'name'  => 'SDAO Administrator',
    'email' => 'sdao@nu-lipa.edu.ph',
    'phone' => '(043) 723-0706',
];

try {
    $adminStmt = $pdo->query(
        "SELECT first_name, last_name, email, phone_number 
         FROM users 
         WHERE role = 'admin' AND is_active = 1 
         ORDER BY user_id ASC 
         LIMIT 1"
    );
    $adminRow = $adminStmt ? $adminStmt->fetch(PDO::FETCH_ASSOC) : null;
    if ($adminRow) {
        $admName = trim((string)($adminRow['first_name'] ?? '') . ' ' . (string)($adminRow['last_name'] ?? ''));
        if ($admName !== '') {
            $adminContact['name'] = $admName;
        }
        if (!empty($adminRow['email'])) {
            $adminContact['email'] = (string) $adminRow['email'];
        }
        if (!empty($adminRow['phone_number'])) {
            $adminContact['phone'] = (string) $adminRow['phone_number'];
        }
    }
} catch (Throwable $e) {
    // fallback
}

try {
    if ($appId <= 0 && $userId > 0) {
        // Try to find the latest application for this user via students table.
        $stmt = $pdo->prepare(
            'SELECT a.application_id
             FROM applications a
             INNER JOIN students s ON s.student_id = a.student_id
             WHERE s.user_id = :user_id
             ORDER BY a.application_id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $appId = (int) ($row['application_id'] ?? 0);
        }
    }

    if ($appId <= 0 && isset($_GET['student_number'])) {
        $stNum = trim((string)$_GET['student_number']);
        if ($stNum !== '') {
            $stmt = $pdo->prepare(
                'SELECT a.application_id
                 FROM applications a
                 INNER JOIN students s ON s.student_id = a.student_id
                 WHERE s.student_id_number = :num
                 ORDER BY a.application_id DESC
                 LIMIT 1'
            );
            $stmt->execute(['num' => $stNum]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $appId = (int) ($row['application_id'] ?? 0);
            }
        }
    }

    if ($appId <= 0) {
        echo json_encode(['success' => false, 'message' => 'not_found', 'admin_contact' => $adminContact]);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT
            a.application_id,
            a.status,
            a.preferred_office,
            COALESCE(a.submitted_at, a.created_at) AS date_submitted,
            s.student_id_number AS student_number,
            s.program AS course,
            s.year_level,
            COALESCE(u.first_name, u.last_name) AS name_part,
            u.first_name,
            u.last_name
         FROM applications a
         LEFT JOIN students s ON a.student_id = s.student_id
         LEFT JOIN users u ON s.user_id = u.user_id
         WHERE a.application_id = :application_id
         LIMIT 1'
    );
    $stmt->execute(['application_id' => $appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        echo json_encode(['success' => false, 'message' => 'not_found', 'admin_contact' => $adminContact]);
        exit;
    }

    $fullName = trim(((string) ($app['first_name'] ?? '')) . ' ' . ((string) ($app['last_name'] ?? '')));
    if ($fullName === '') {
        $fullName = trim((string) ($app['name_part'] ?? ''));
    }

    $statusVal = strtoupper((string) ($app['status'] ?? 'PENDING'));

    $statusTitle = '⏳ Application Under Review';
    $statusSub   = "Your application is currently being reviewed by {$adminContact['name']} (SDAO). This typically takes 1-3 business days.";

    if ($statusVal === 'APPROVED') {
        $statusTitle = '🎉 Application Approved!';
        $statusSub   = "Congratulations! Your application has been approved by {$adminContact['name']}. You can now access your Student Assistant dashboard.";
    } elseif ($statusVal === 'REJECTED') {
        $statusTitle = 'Application Status Update';
        $statusSub   = "Your application has been reviewed and was declined for this term. For further details, please reach out to {$adminContact['name']} at SDAO.";
    } elseif ($statusVal === 'DRAFT') {
        $statusTitle = '📝 Application Saved as Draft';
        $statusSub   = 'Your application has been saved as a draft. Please complete your weekly time availability to submit your application.';
    }

    $item = [
        'application_id' => (int) ($app['application_id'] ?? 0),
        'full_name' => $fullName,
        'student_id' => (string) ($app['student_number'] ?? ''),
        'course' => (string) ($app['course'] ?? ''),
        'year_level' => (string) ($app['year_level'] ?? ''),
        'date_submitted' => (string) ($app['date_submitted'] ?? ''),
        'status' => $statusVal,
        'title' => $statusTitle,
        'sub' => $statusSub,
        'show_availability' => ($statusVal === 'DRAFT'),
        'admin_contact' => $adminContact,
    ];

    echo json_encode(['success' => true, 'item' => $item, 'admin_contact' => $adminContact]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db_error', 'admin_contact' => $adminContact]);
}
