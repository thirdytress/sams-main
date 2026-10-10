<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/audit.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$flash = '';
$error = '';
$adminName = trim((string) ($user['name'] ?? 'SAMS Admin'));
$adminUserId = (int) ($user['user_id'] ?? $user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(sams_csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid request token. Please refresh the page and try again.');
        }
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $action = (string) ($_POST['request_action'] ?? '');
        $reviewNote = trim((string) ($_POST['review_note'] ?? ''));

        if ($requestId <= 0 || !in_array($action, ['approve', 'decline'], true)) {
            throw new RuntimeException('Invalid temporary duty review request.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT r.*, u.email, u.first_name, u.last_name
             FROM temporary_duty_requests r
             INNER JOIN students s ON s.student_id = r.student_id
             INNER JOIN users u ON u.user_id = s.user_id
             WHERE r.request_id = :request_id AND r.status = 'pending' FOR UPDATE"
        );
        $stmt->execute(['request_id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new RuntimeException('Request is no longer pending or has already been reviewed.');
        }

        $studentFullName = trim((string) $request['first_name'] . ' ' . (string) $request['last_name']);
        $dutyId = null;

        if ($action === 'approve') {
            $day = date('l', strtotime((string) $request['duty_date']));
            $hasOffice = sams_column_exists($pdo, 'duty_schedules', 'office_name');
            $hasDate = sams_column_exists($pdo, 'duty_schedules', 'scheduled_date');
            
            if (!$hasDate) {
                throw new RuntimeException('Database is missing duty_schedules.scheduled_date.');
            }

            $sql = $hasOffice
                ? 'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, scheduled_date, status) VALUES (:application_id, :office_name, :term_id, :day_of_week, :start_time, :end_time, :scheduled_date, "deployed")'
                : 'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, scheduled_date, status) VALUES (:application_id, :term_id, :day_of_week, :start_time, :end_time, :scheduled_date, "deployed")';
            
            $insert = $pdo->prepare($sql);
            $params = [
                'application_id' => (int) $request['application_id'],
                'term_id' => (int) $request['term_id'],
                'day_of_week' => $day,
                'start_time' => $request['start_time'],
                'end_time' => $request['end_time'],
                'scheduled_date' => $request['duty_date'],
            ];
            if ($hasOffice) {
                $params['office_name'] = $request['office_name'];
            }
            $insert->execute($params);
            $dutyId = (int) $pdo->lastInsertId();
        }

        $update = $pdo->prepare(
            'UPDATE temporary_duty_requests
             SET status = :status, reviewed_at = NOW(), reviewed_by = :reviewed_by,
                 review_note = :review_note, duty_id = :duty_id
             WHERE request_id = :request_id'
        );
        $update->execute([
            'status' => $action === 'approve' ? 'approved' : 'declined',
            'reviewed_by' => $adminUserId,
            'review_note' => $reviewNote !== '' ? substr($reviewNote, 0, 500) : null,
            'duty_id' => $dutyId,
            'request_id' => $requestId,
        ]);

        $pdo->commit();

        // Audit Logging
        sams_log_audit(
            $pdo,
            $action === 'approve' ? 'TEMP_DUTY_APPROVE' : 'TEMP_DUTY_DECLINE',
            'TEMPORARY_DUTY',
            "Admin {$adminName} " . ($action === 'approve' ? 'approved' : 'declined') . " Temporary Duty Request #{$requestId} for student {$studentFullName}.",
            ['action' => $action, 'review_note' => $reviewNote, 'duty_date' => $request['duty_date']],
            $requestId,
            'temporary_duty_request',
            $adminUserId,
            'admin',
            $adminName,
            (string) ($user['email'] ?? '')
        );

        // Email Notification
        try {
            sams_send_temporary_duty_email(
                (string) $request['email'],
                $studentFullName,
                $action === 'approve' ? 'approved' : 'declined',
                $request,
                $reviewNote
            );
        } catch (Throwable $mailException) {
            error_log('[sams] Temporary duty notification failed: ' . $mailException->getMessage());
        }

        $flash = $action === 'approve' 
            ? "Temporary duty request for {$studentFullName} was successfully approved!" 
            : "Temporary duty request for {$studentFullName} was declined.";
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

// Fetch requests with student user info and reviewer info
$requests = $pdo->query(
    "SELECT r.*, s.student_id_number, u.first_name, u.last_name, u.email AS student_email, u.profile_image,
            CONCAT(rev.first_name, ' ', rev.last_name) AS reviewer_name
     FROM temporary_duty_requests r
     INNER JOIN students s ON s.student_id = r.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     LEFT JOIN users rev ON rev.user_id = r.reviewed_by
     ORDER BY (r.status = 'pending') DESC, r.requested_at DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Metrics
$totalCount = count($requests);
$pendingCount = count(array_filter($requests, static fn (array $r): bool => $r['status'] === 'pending'));
$approvedCount = count(array_filter($requests, static fn (array $r): bool => $r['status'] === 'approved'));
$declinedCount = count(array_filter($requests, static fn (array $r): bool => $r['status'] === 'declined'));

// Distinct offices for filter
$offices = [];
foreach ($requests as $r) {
    $off = trim((string) ($r['office_name'] ?? ''));
    if ($off !== '' && !in_array($off, $offices, true)) {
        $offices[] = $off;
    }
}
sort($offices);

$currentTerm = sams_current_term($pdo);
$termLabel = trim((string) ($currentTerm['term_name'] ?? '') . ' ' . (string) ($currentTerm['term_year'] ?? ''));

function h(?string $str): string {
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Temporary Duty Requests | SAMS Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/admin-shell.css?v=20260922" />
    <style>
        :root {
            --color-brand: #003087;
            --color-brand-light: #155dfc;
            --color-bg-app: #f8fafc;
            --color-card-bg: #ffffff;
            --color-border: #e2e8f0;
            --color-heading: #0f172a;
            --color-body: #334155;
            --color-muted: #64748b;
            --radius-card: 16px;
        }

        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--color-bg-app); color: var(--color-body); }

        .page-content {
            padding: 28px 32px 48px;
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* Banner Hero */
        .hero-banner {
            background: linear-gradient(135deg, #003087 0%, #155dfc 65%, #3b82f6 100%);
            border-radius: 20px;
            padding: 28px 32px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: 0 12px 28px rgba(0, 48, 135, 0.18);
            position: relative;
            overflow: hidden;
        }

        .hero-banner::after {
            content: '';
            position: absolute;
            right: -20px;
            bottom: -30px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-title {
            margin: 0 0 6px;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .hero-sub {
            margin: 0;
            color: #dbeafe;
            font-size: 14px;
            max-width: 650px;
            line-height: 1.5;
        }

        .hero-badge {
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* Metric Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .metric-card {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
        }

        .metric-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .metric-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-muted);
        }

        .metric-val {
            font-size: 28px;
            font-weight: 900;
            color: var(--color-heading);
            line-height: 1.1;
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .metric-icon--pending { background: #fef3c7; color: #d97706; }
        .metric-icon--approved { background: #dcfce7; color: #16a34a; }
        .metric-icon--declined { background: #fee2e2; color: #dc2626; }
        .metric-icon--total { background: #eff6ff; color: #2563eb; }

        /* Filter Controls */
        .controls-card {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 18px 22px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .tabs-group {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
        }

        .tab-btn {
            border: none;
            background: transparent;
            padding: 8px 16px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            transition: all 0.16s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover {
            color: #0f172a;
        }

        .tab-btn.active {
            background: #ffffff;
            color: #003087;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .tab-count {
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            background: #e2e8f0;
            color: #475569;
        }

        .tab-btn.active .tab-count {
            background: #dbeafe;
            color: #003087;
        }

        .tab-count--pending {
            background: #fef3c7 !important;
            color: #b45309 !important;
        }

        .search-filter-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            justify-content: flex-end;
            min-width: 300px;
        }

        .search-input-wrap {
            position: relative;
            flex: 1;
            max-width: 340px;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #94a3b8;
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            padding: 9px 12px 9px 38px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .search-input:focus {
            border-color: #155dfc;
            box-shadow: 0 0 0 3px rgba(21, 93, 252, 0.12);
        }

        .filter-select {
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13.5px;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            cursor: pointer;
        }

        /* Table Card */
        .table-card {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .custom-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .custom-table th {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            border-bottom: 1px solid var(--color-border);
            white-space: nowrap;
        }

        .custom-table td {
            padding: 16px 18px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px;
            vertical-align: middle;
        }

        .custom-table tr:hover td {
            background: #fbfdff;
        }

        /* Student Cell */
        .student-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 200px;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1.5px solid #e2e8f0;
            flex-shrink: 0;
        }

        .student-avatar-fallback {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #003087 0%, #155dfc 100%);
            color: #ffffff;
            font-weight: 800;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .student-meta {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .student-name {
            font-weight: 700;
            color: #0f172a;
            line-height: 1.25;
        }

        .student-code {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }

        /* Date & Time formatting */
        .schedule-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 170px;
        }

        .schedule-date {
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .schedule-time {
            font-size: 12px;
            color: #475569;
            font-weight: 600;
        }

        .schedule-hours {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            color: #0369a1;
            background: #e0f2fe;
            padding: 1px 6px;
            border-radius: 4px;
            width: fit-content;
            margin-top: 2px;
        }

        /* Office Badge */
        .office-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #1e293b;
            font-size: 12.5px;
            font-weight: 700;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        /* Reason Cell */
        .reason-box {
            max-width: 220px;
            font-size: 13px;
            color: #334155;
            line-height: 1.45;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        /* Proof Button */
        .proof-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .proof-btn:hover {
            background: #dbeafe;
            color: #1e40af;
            transform: translateY(-1px);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .status-badge--pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .status-badge--approved { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .status-badge--declined { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .review-meta {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }

        .review-note-preview {
            margin-top: 4px;
            font-size: 11.5px;
            color: #475569;
            font-style: italic;
            background: #f8fafc;
            padding: 4px 8px;
            border-radius: 6px;
            border-left: 2px solid #cbd5e1;
            max-width: 180px;
        }

        /* Action Buttons */
        .action-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-btn {
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }

        .action-btn--approve {
            background: #16a34a;
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(22, 163, 74, 0.2);
        }

        .action-btn--approve:hover {
            background: #15803d;
            transform: translateY(-1px);
        }

        .action-btn--decline {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
        }

        .action-btn--decline:hover {
            background: #fecaca;
            color: #b91c1c;
            transform: translateY(-1px);
        }

        /* Alerts */
        .alert-banner {
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .alert-banner--success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-banner--error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* Empty State */
        .empty-state {
            padding: 56px 24px;
            text-align: center;
            color: #64748b;
        }

        .empty-state-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto 14px;
            background: #f1f5f9;
            color: #94a3b8;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            animation: modalPop 0.2s ease-out;
        }

        .modal-card--lg {
            max-width: 840px;
            height: 85vh;
            display: flex;
            flex-direction: column;
        }

        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }

        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 22px;
            color: #94a3b8;
            cursor: pointer;
            line-height: 1;
            padding: 4px;
        }

        .modal-close-btn:hover {
            color: #0f172a;
        }

        .modal-body {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            flex: 1;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-btn {
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            border: none;
        }

        .modal-btn--cancel {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
        }

        .modal-btn--confirm-approve {
            background: #16a34a;
            color: #ffffff;
        }

        .modal-btn--confirm-decline {
            background: #dc2626;
            color: #ffffff;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            display: block;
            margin-bottom: 6px;
        }

        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13.5px;
            resize: vertical;
            min-height: 80px;
            outline: none;
        }

        .form-textarea:focus {
            border-color: #155dfc;
            box-shadow: 0 0 0 3px rgba(21, 93, 252, 0.12);
        }

        @media (max-width: 1024px) {
            .metrics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .page-content { padding: 20px 16px; }
        }

        @media (max-width: 640px) {
            .metrics-grid { grid-template-columns: 1fr; }
            .hero-banner { flex-direction: column; align-items: flex-start; }
            .controls-card { flex-direction: column; align-items: stretch; }
            .search-filter-group { flex-direction: column; min-width: 100%; }
            .search-input-wrap { max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="shell">
    <?php 
        $activeAdminNav = 'temporary_duty'; 
        require_once __DIR__ . '/_sidebar.php'; 
    ?>

    <div class="main">
        <header class="topbar" role="banner">
            <div class="topbar__left-wrap">
                <div>
                    <div class="topbar__title">Temporary Duty Requests</div>
                    <div class="topbar__sub"><?= h($termLabel ?: 'Academic Term') ?> · Manage student shift requests & proofs</div>
                </div>
            </div>
            <div class="topbar__right">
                <div class="topbar__user-info">
                    <div class="topbar__user-name"><?= h($adminName) ?></div>
                    <div class="topbar__user-role">Administrator</div>
                </div>
                <div class="topbar__avatar" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" style="width:20px;height:20px;"><circle cx="10" cy="7" r="4" fill="white" opacity=".9"/><path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/></svg>
                </div>
            </div>
        </header>

        <main class="page-content">
            <!-- Flash & Error Alerts -->
            <?php if ($flash !== ''): ?>
                <div class="alert-banner alert-banner--success">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    <span><?= h($flash) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert-banner alert-banner--error">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    <span><?= h($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Hero Banner -->
            <div class="hero-banner">
                <div>
                    <h1 class="hero-title">Temporary Duty Management</h1>
                    <p class="hero-sub">Review and verify student assistant requests for extra duty shifts during vacant or cancelled classes. Ensure attached proofs meet department requirements prior to approval.</p>
                </div>
                <div class="hero-badge">
                    <?= $pendingCount ?> Pending Verification
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-info">
                        <span class="metric-label">Pending Review</span>
                        <span class="metric-val" style="color:#d97706;"><?= $pendingCount ?></span>
                    </div>
                    <div class="metric-icon metric-icon--pending">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-info">
                        <span class="metric-label">Approved Shifts</span>
                        <span class="metric-val" style="color:#16a34a;"><?= $approvedCount ?></span>
                    </div>
                    <div class="metric-icon metric-icon--approved">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-info">
                        <span class="metric-label">Declined Requests</span>
                        <span class="metric-val" style="color:#dc2626;"><?= $declinedCount ?></span>
                    </div>
                    <div class="metric-icon metric-icon--declined">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-info">
                        <span class="metric-label">Total Submissions</span>
                        <span class="metric-val"><?= $totalCount ?></span>
                    </div>
                    <div class="metric-icon metric-icon--total">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                </div>
            </div>

            <!-- Controls (Tabs + Search + Office filter) -->
            <div class="controls-card">
                <div class="tabs-group" role="tablist">
                    <button class="tab-btn active" data-status="all" type="button">
                        All <span class="tab-count"><?= $totalCount ?></span>
                    </button>
                    <button class="tab-btn" data-status="pending" type="button">
                        Pending <span class="tab-count tab-count--pending"><?= $pendingCount ?></span>
                    </button>
                    <button class="tab-btn" data-status="approved" type="button">
                        Approved <span class="tab-count"><?= $approvedCount ?></span>
                    </button>
                    <button class="tab-btn" data-status="declined" type="button">
                        Declined <span class="tab-count"><?= $declinedCount ?></span>
                    </button>
                </div>

                <div class="search-filter-group">
                    <div class="search-input-wrap">
                        <svg class="search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="9" r="7"/><path d="M14 14l4 4"/></svg>
                        <input type="text" id="searchInput" class="search-input" placeholder="Search student, ID, reason, or office..." />
                    </div>

                    <?php if (!empty($offices)): ?>
                        <select id="officeFilter" class="filter-select">
                            <option value="all">All Offices</option>
                            <?php foreach ($offices as $off): ?>
                                <option value="<?= h($off) ?>"><?= h($off) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Data Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="custom-table" id="requestsTable">
                        <thead>
                            <tr>
                                <th>Student Assistant</th>
                                <th>Requested Schedule</th>
                                <th>Assigned Office</th>
                                <th>Reason</th>
                                <th>Proof Document</th>
                                <th>Status & Review</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <div class="empty-state-icon">
                                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
                                            </div>
                                            <h3 style="margin:0 0 6px;color:#0f172a;font-size:16px;">No Temporary Duty Requests</h3>
                                            <p style="margin:0;font-size:13px;">There are no temporary duty submissions recorded yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $r): ?>
                                    <?php 
                                        $fullName = trim((string)$r['first_name'] . ' ' . (string)$r['last_name']);
                                        $studentCode = (string)($r['student_id_number'] ?? '');
                                        $avatarUrl = sams_user_avatar_url($r['profile_image'] ?? null, '../');
                                        $status = (string)$r['status'];
                                        $dutyDate = (string)$r['duty_date'];
                                        $dayOfWeek = date('l', strtotime($dutyDate));
                                        $startFmt = date('g:i A', strtotime((string)$r['start_time']));
                                        $endFmt = date('g:i A', strtotime((string)$r['end_time']));
                                        $hours = max(0, (strtotime((string)$r['end_time']) - strtotime((string)$r['start_time'])) / 3600);
                                        
                                        // Proof file path
                                        $proofPath = (string)($r['proof_path'] ?? '');
                                        $proofName = (string)($r['proof_original_name'] ?? 'View Proof');
                                        $proofMime = strtolower((string)($r['proof_mime'] ?? ''));
                                        $isPdf = str_contains($proofMime, 'pdf') || str_ends_with(strtolower($proofPath), '.pdf');
                                    ?>
                                    <tr class="request-row" 
                                        data-status="<?= h($status) ?>" 
                                        data-office="<?= h((string)$r['office_name']) ?>"
                                        data-search="<?= h(strtolower($fullName . ' ' . $studentCode . ' ' . (string)$r['office_name'] . ' ' . (string)$r['reason'])) ?>">
                                        
                                        <!-- Student Info -->
                                        <td>
                                            <div class="student-cell">
                                                <?php if ($avatarUrl): ?>
                                                    <img src="<?= h($avatarUrl) ?>" alt="" class="student-avatar" />
                                                <?php else: ?>
                                                    <div class="student-avatar-fallback">
                                                        <?= h(strtoupper(substr($r['first_name'] ?? 'S', 0, 1) . substr($r['last_name'] ?? 'A', 0, 1))) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="student-meta">
                                                    <span class="student-name"><?= h($fullName) ?></span>
                                                    <span class="student-code">ID: <?= h($studentCode ?: '-') ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Requested Schedule -->
                                        <td>
                                            <div class="schedule-info">
                                                <span class="schedule-date">
                                                    📅 <?= date('M d, Y', strtotime($dutyDate)) ?>
                                                    <span style="font-weight:600;font-size:11.5px;color:#64748b;">(<?= h($dayOfWeek) ?>)</span>
                                                </span>
                                                <span class="schedule-time">⏱️ <?= h($startFmt) ?> – <?= h($endFmt) ?></span>
                                                <span class="schedule-hours"><?= number_format($hours, 1) ?> Hours</span>
                                            </div>
                                        </td>

                                        <!-- Office -->
                                        <td>
                                            <span class="office-pill">
                                                🏢 <?= h((string)($r['office_name'] ?? 'Unassigned')) ?>
                                            </span>
                                        </td>

                                        <!-- Reason -->
                                        <td>
                                            <div class="reason-box" title="<?= h((string)$r['reason']) ?>">
                                                <?= h((string)$r['reason']) ?>
                                            </div>
                                        </td>

                                        <!-- Proof Document -->
                                        <td>
                                            <?php if ($proofPath !== ''): ?>
                                                <button type="button" 
                                                        class="proof-btn" 
                                                        onclick="openProofModal('<?= h('../' . ltrim($proofPath, '/')) ?>', '<?= $isPdf ? 'pdf' : 'image' ?>', '<?= h($proofName) ?>')">
                                                    <?= $isPdf ? '📄 PDF Document' : '🖼️ Image Proof' ?>
                                                </button>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;font-size:12px;">No proof attached</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status & Review -->
                                        <td>
                                            <span class="status-badge status-badge--<?= h($status) ?>">
                                                <?php if ($status === 'approved'): ?>
                                                    ✓ Approved
                                                <?php elseif ($status === 'declined'): ?>
                                                    ✕ Declined
                                                <?php else: ?>
                                                    ⏳ Pending Review
                                                <?php endif; ?>
                                            </span>

                                            <?php if ($status !== 'pending'): ?>
                                                <?php if (!empty($r['reviewer_name'])): ?>
                                                    <div class="review-meta">By: <?= h((string)$r['reviewer_name']) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($r['review_note'])): ?>
                                                    <div class="review-note-preview" title="Admin Note: <?= h((string)$r['review_note']) ?>">
                                                        "<?= h((string)$r['review_note']) ?>"
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td style="text-align:right;">
                                            <?php if ($status === 'pending'): ?>
                                                <div class="action-group" style="justify-content:flex-end;">
                                                    <button type="button" 
                                                            class="action-btn action-btn--approve" 
                                                            onclick="openReviewModal(<?= (int)$r['request_id'] ?>, 'approve', '<?= h($fullName) ?>', '<?= h($dutyDate) ?>', '<?= h($startFmt . ' – ' . $endFmt) ?>')">
                                                        Approve
                                                    </button>
                                                    <button type="button" 
                                                            class="action-btn action-btn--decline" 
                                                            onclick="openReviewModal(<?= (int)$r['request_id'] ?>, 'decline', '<?= h($fullName) ?>', '<?= h($dutyDate) ?>', '<?= h($startFmt . ' – ' . $endFmt) ?>')">
                                                        Decline
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size:12px;color:#94a3b8;font-weight:600;">Reviewed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Review Modal (Approve / Decline) -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-card">
        <form method="post" id="reviewForm">
            <input type="hidden" name="_csrf" value="<?= h(sams_csrf_token()) ?>">
            <input type="hidden" name="request_id" id="modalRequestId" value="0">
            <input type="hidden" name="request_action" id="modalRequestAction" value="">

            <div class="modal-header">
                <h3 class="modal-title" id="modalHeaderTitle">Review Request</h3>
                <button type="button" class="modal-close-btn" onclick="closeReviewModal()">&times;</button>
            </div>

            <div class="modal-body">
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;">
                    <div style="font-weight:700;color:#0f172a;font-size:14.5px;" id="modalStudentName">-</div>
                    <div style="font-size:13px;color:#475569;margin-top:4px;" id="modalScheduleDetail">-</div>
                </div>

                <div>
                    <label class="form-label" for="modalReviewNote">
                        Admin Review Note <span style="font-weight:400;color:#64748b;">(Optional for approve, recommended for decline)</span>:
                    </label>
                    <textarea name="review_note" id="modalReviewNote" class="form-textarea" placeholder="Enter notes or feedback for the student assistant..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn--cancel" onclick="closeReviewModal()">Cancel</button>
                <button type="submit" class="modal-btn" id="modalSubmitBtn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Proof Viewer Modal -->
<div class="modal-overlay" id="proofModal">
    <div class="modal-card modal-card--lg">
        <div class="modal-header">
            <h3 class="modal-title" id="proofModalTitle">Proof Attachment</h3>
            <button type="button" class="modal-close-btn" onclick="closeProofModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:0;display:flex;align-items:center;justify-content:center;background:#0f172a;" id="proofContainer">
            <!-- Dynamic Content (iframe or img) -->
        </div>
        <div class="modal-footer" style="background:#ffffff;">
            <a href="#" id="proofDownloadLink" target="_blank" class="modal-btn modal-btn--cancel" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                <span>Open in New Tab</span>
            </a>
            <button type="button" class="modal-btn modal-btn--cancel" onclick="closeProofModal()">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('searchInput');
    const officeFilter = document.getElementById('officeFilter');
    const tabButtons = document.querySelectorAll('.tab-btn');
    const rows = document.querySelectorAll('.request-row');

    let currentStatus = 'all';

    function filterRows() {
        const query = (searchInput.value || '').trim().toLowerCase();
        const selectedOffice = officeFilter ? officeFilter.value : 'all';

        rows.forEach(row => {
            const rowStatus = row.getAttribute('data-status');
            const rowOffice = row.getAttribute('data-office');
            const rowSearch = row.getAttribute('data-search') || '';

            const matchesStatus = (currentStatus === 'all' || rowStatus === currentStatus);
            const matchesOffice = (selectedOffice === 'all' || rowOffice === selectedOffice);
            const matchesSearch = (!query || rowSearch.includes(query));

            if (matchesStatus && matchesOffice && matchesSearch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterRows);
    }

    if (officeFilter) {
        officeFilter.addEventListener('change', filterRows);
    }

    tabButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            tabButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStatus = this.getAttribute('data-status');
            filterRows();
        });
    });
});

function openReviewModal(requestId, action, studentName, dutyDate, schedule) {
    document.getElementById('modalRequestId').value = requestId;
    document.getElementById('modalRequestAction').value = action;
    document.getElementById('modalStudentName').textContent = studentName;
    document.getElementById('modalScheduleDetail').textContent = 'Shift Date: ' + dutyDate + ' (' + schedule + ')';
    
    const submitBtn = document.getElementById('modalSubmitBtn');
    const headerTitle = document.getElementById('modalHeaderTitle');
    const reviewNote = document.getElementById('modalReviewNote');
    reviewNote.value = '';

    if (action === 'approve') {
        headerTitle.textContent = 'Approve Temporary Duty Shift';
        submitBtn.textContent = 'Confirm Approval';
        submitBtn.className = 'modal-btn modal-btn--confirm-approve';
    } else {
        headerTitle.textContent = 'Decline Temporary Duty Shift';
        submitBtn.textContent = 'Confirm Decline';
        submitBtn.className = 'modal-btn modal-btn--confirm-decline';
    }

    document.getElementById('reviewModal').classList.add('active');
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('active');
}

function openProofModal(fileUrl, type, originalName) {
    const container = document.getElementById('proofContainer');
    const title = document.getElementById('proofModalTitle');
    const downloadLink = document.getElementById('proofDownloadLink');

    title.textContent = originalName || 'Proof Document';
    downloadLink.href = fileUrl;
    container.innerHTML = '';

    if (type === 'pdf') {
        const iframe = document.createElement('iframe');
        iframe.src = fileUrl;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        container.appendChild(iframe);
    } else {
        const img = document.createElement('img');
        img.src = fileUrl;
        img.alt = originalName;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '100%';
        img.style.objectFit = 'contain';
        container.appendChild(img);
    }

    document.getElementById('proofModal').classList.add('active');
}

function closeProofModal() {
    const modal = document.getElementById('proofModal');
    modal.classList.remove('active');
    document.getElementById('proofContainer').innerHTML = '';
}

// Close modals when clicking outside
window.addEventListener('click', function (e) {
    const reviewModal = document.getElementById('reviewModal');
    const proofModal = document.getElementById('proofModal');
    if (e.target === reviewModal) {
        closeReviewModal();
    }
    if (e.target === proofModal) {
        closeProofModal();
    }
});
</script>

</body>
</html>
