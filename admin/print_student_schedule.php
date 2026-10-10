<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/attendance.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['admin', 'supervisor'], true)) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$officeFilter = isset($_GET['office']) ? trim((string)$_GET['office']) : '';
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

$currentTerm = sams_current_term($pdo);
$currentTermId = (int)($currentTerm['term_id'] ?? 0);
$termName = trim((string)($currentTerm['term_name'] ?? '1st Term'));
$termYear = trim((string)($currentTerm['term_year'] ?? date('Y') . '-' . (date('Y') + 1)));
$termFullLabel = "{$termName}, AY {$termYear}";

// Fetch admin info
$adminName = $currentUser['name'] ?? 'SDAO Administrator';

// Helper function to format time
function format_time_12hr(?string $timeStr): string {
    if (empty($timeStr)) return '-';
    $ts = strtotime($timeStr);
    return $ts ? date('g:i A', $ts) : $timeStr;
}

function h(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$userPhoneColumn = sams_column_exists($pdo, 'users', 'phone_number') 
    ? 'u.phone_number' 
    : (sams_column_exists($pdo, 'users', 'phone') ? 'u.phone' : "''");

// Build list of students to print
$studentsToPrint = [];

if ($studentId > 0) {
    // Specific single student
    $stmt = $pdo->prepare(
        "SELECT s.student_id, s.student_id_number, s.program, s.year_level, {$userPhoneColumn} AS phone,
                u.first_name, u.last_name, u.email,
                a.application_id, a.preferred_office, a.status AS application_status, a.term_id,
                t.term_name, t.term_year
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         LEFT JOIN applications a ON a.student_id = s.student_id
         LEFT JOIN terms t ON t.term_id = a.term_id
         WHERE s.student_id = :student_id
         ORDER BY a.application_id DESC LIMIT 1"
    );
    $stmt->execute(['student_id' => $studentId]);
    $st = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $studentsToPrint[] = $st;
    }
} elseif ($officeFilter !== '') {
    // All students in specified office
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.student_id, s.student_id_number, s.program, s.year_level, {$userPhoneColumn} AS phone,
                u.first_name, u.last_name, u.email,
                a.application_id, COALESCE(ds.office_name, a.preferred_office) AS preferred_office, 
                a.status AS application_status, a.term_id,
                t.term_name, t.term_year
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         INNER JOIN applications a ON a.student_id = s.student_id
         LEFT JOIN duty_schedules ds ON ds.application_id = a.application_id
         LEFT JOIN terms t ON t.term_id = a.term_id
         WHERE (COALESCE(ds.office_name, a.preferred_office) = :office OR a.preferred_office = :office2)
           AND a.status IN ('approved', 'deployed')
         ORDER BY u.last_name ASC, u.first_name ASC"
    );
    $stmt->execute(['office' => $officeFilter, 'office2' => $officeFilter]);
    $studentsToPrint = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // All approved & deployed students
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.student_id, s.student_id_number, s.program, s.year_level, {$userPhoneColumn} AS phone,
                u.first_name, u.last_name, u.email,
                a.application_id, a.preferred_office, a.status AS application_status, a.term_id,
                t.term_name, t.term_year
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         INNER JOIN applications a ON a.student_id = s.student_id
         WHERE a.status IN ('approved', 'deployed')
         ORDER BY a.preferred_office ASC, u.last_name ASC"
    );
    $stmt->execute();
    $studentsToPrint = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to fetch schedule details for a student
function getStudentScheduleData(PDO $pdo, int $applicationId, int $studentId): array {
    $schedules = [];
    if ($applicationId > 0) {
        $stmt = $pdo->prepare(
            "SELECT ds.duty_id, ds.day_of_week, ds.start_time, ds.end_time, ds.status,
                    COALESCE(ds.office_name, a.preferred_office) AS office_name,
                    ROUND(TIMESTAMPDIFF(MINUTE, ds.start_time, ds.end_time) / 60, 2) AS shift_hours
             FROM duty_schedules ds
             INNER JOIN applications a ON a.application_id = ds.application_id
             WHERE ds.application_id = :app_id
               AND ds.status <> 'declined'
             ORDER BY FIELD(ds.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), ds.start_time ASC"
        );
        $stmt->execute(['app_id' => $applicationId]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Calculate total weekly hours
    $totalWeeklyHours = 0.0;
    $groupedByDay = [];
    foreach ($schedules as $sched) {
        $day = trim((string)$sched['day_of_week']);
        $totalWeeklyHours += (float)$sched['shift_hours'];
        if (!isset($groupedByDay[$day])) {
            $groupedByDay[$day] = [];
        }
        $groupedByDay[$day][] = $sched;
    }

    // Get assigned supervisor if office is known
    $office = $schedules[0]['office_name'] ?? '';
    $supervisor = null;
    if ($office !== '') {
        $supPhoneCol = sams_column_exists($pdo, 'supervisors', 'phone')
            ? 'sup.phone'
            : (sams_column_exists($pdo, 'users', 'phone_number') ? 'u.phone_number AS phone' : "'' AS phone");
        $supStmt = $pdo->prepare(
            "SELECT u.first_name, u.last_name, u.email, {$supPhoneCol}, sup.office_name
             FROM supervisors sup
             INNER JOIN users u ON u.user_id = sup.user_id
             WHERE LOWER(TRIM(sup.office_name)) = LOWER(TRIM(:office))
             LIMIT 1"
        );
        $supStmt->execute(['office' => $office]);
        $supervisor = $supStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Get rendered hours if attendance logs exist
    $renderedHours = 0.0;
    try {
        $attStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in_time, clock_out_time)), 0) / 3600 AS total_rendered
             FROM attendance_logs
             WHERE student_id = :sid AND clock_in_time IS NOT NULL AND clock_out_time IS NOT NULL"
        );
        $attStmt->execute(['sid' => $studentId]);
        $renderedHours = (float)($attStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $renderedHours = 0.0;
    }

    return [
        'schedules' => $schedules,
        'grouped_by_day' => $groupedByDay,
        'total_weekly_hours' => $totalWeeklyHours,
        'supervisor' => $supervisor,
        'rendered_hours' => round($renderedHours, 2),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Assistant Duty Hours & Schedule — National University Lipa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --nu-blue: #00205B;
            --nu-gold: #C59B27;
            --nu-navy: #0B1F44;
            --text-dark: #1F2937;
            --text-muted: #4B5563;
            --border-color: #D1D5DB;
            --bg-light: #F9FAFB;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #E5E7EB;
            color: var(--text-dark);
            line-height: 1.4;
            font-size: 13px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Non-printing Control Toolbar */
        .no-print-toolbar {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            background: #1E293B;
            color: #F8FAFC;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
        }

        .toolbar-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            font-weight: 700;
            color: #FFFFFF;
        }

        .toolbar-badge {
            background: #3B82F6;
            color: #FFFFFF;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 999px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: #2563EB;
            color: #FFFFFF;
        }

        .btn-primary:hover {
            background: #1D4ED8;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.4);
        }

        .btn-secondary {
            background: #475569;
            color: #F1F5F9;
        }

        .btn-secondary:hover {
            background: #334155;
        }

        .btn-gold {
            background: #D97706;
            color: #FFFFFF;
        }

        .btn-gold:hover {
            background: #B45309;
        }

        /* Printable Sheet Container */
        .sheet-container {
            max-width: 860px;
            margin: 24px auto;
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .printable-page {
            background: #FFFFFF;
            width: 100%;
            min-height: 1080px;
            padding: 36px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            position: relative;
            box-sizing: border-box;
            page-break-after: always;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .printable-page:last-child {
            page-break-after: avoid;
        }

        /* Institutional Header */
        .doc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--nu-blue);
            padding-bottom: 14px;
            margin-bottom: 20px;
        }

        .doc-header__logo-area {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .nu-crest {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, var(--nu-blue) 0%, var(--nu-navy) 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-family: 'Cinzel', serif;
            font-size: 26px;
            font-weight: 700;
            border: 2px solid var(--nu-gold);
            box-shadow: 0 2px 6px rgba(0, 32, 91, 0.25);
            flex-shrink: 0;
        }

        .institution-titles h1 {
            font-family: 'Cinzel', serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--nu-blue);
            letter-spacing: 0.8px;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .institution-titles h2 {
            font-size: 12px;
            font-weight: 700;
            color: var(--nu-gold);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .institution-titles p {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .doc-header__meta {
            text-align: right;
            font-size: 10px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .doc-header__meta strong {
            color: var(--text-dark);
            font-size: 11px;
        }

        .doc-badge {
            display: inline-block;
            background: #EFF6FF;
            color: var(--nu-blue);
            border: 1px solid #BFDBFE;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        /* Document Title Ribbon */
        .doc-title-block {
            text-align: center;
            background: linear-gradient(135deg, #F0F4FF 0%, #E6EEFA 100%);
            border: 1px solid #D0E0FC;
            border-left: 5px solid var(--nu-blue);
            border-radius: 6px;
            padding: 10px 16px;
            margin-bottom: 20px;
        }

        .doc-title-block h2 {
            font-size: 15px;
            font-weight: 800;
            color: var(--nu-blue);
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .doc-title-block p {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Student & Assignment Information Box */
        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--nu-blue);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .section-title::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 12px;
            background: var(--nu-gold);
            border-radius: 2px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 16px;
            background: #FAFAFA;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .info-label {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .info-value {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .info-value strong {
            font-weight: 800;
            color: var(--nu-navy);
        }

        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            width: fit-content;
        }

        .status-pill--deployed {
            background: #DEF7EC;
            color: #03543F;
            border: 1px solid #BCF0DA;
        }

        .status-pill--approved {
            background: #E1EFFE;
            color: #1E429F;
            border: 1px solid #C3DDFD;
        }

        .status-pill--pending {
            background: #FEF08A;
            color: #854D0E;
            border: 1px solid #FDE047;
        }

        /* Schedule Timetable Table */
        .table-wrap {
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }

        .sched-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            text-align: left;
        }

        .sched-table thead {
            background: var(--nu-blue);
            color: #FFFFFF;
        }

        .sched-table th {
            padding: 8px 12px;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-right: 1px solid rgba(255, 255, 255, 0.15);
        }

        .sched-table th:last-child {
            border-right: none;
        }

        .sched-table tbody tr {
            border-bottom: 1px solid #E5E7EB;
        }

        .sched-table tbody tr:nth-child(even) {
            background-color: #F9FAFB;
        }

        .sched-table td {
            padding: 8px 12px;
            color: var(--text-dark);
            border-right: 1px solid #E5E7EB;
            vertical-align: middle;
        }

        .sched-table td:last-child {
            border-right: none;
        }

        .sched-table .day-col {
            font-weight: 700;
            color: var(--nu-navy);
            width: 120px;
        }

        .sched-table .time-col {
            font-weight: 600;
            color: #111827;
        }

        .sched-table .hours-col {
            font-weight: 700;
            text-align: center;
            width: 90px;
        }

        .sched-table .office-col {
            color: var(--text-dark);
            font-weight: 500;
        }

        .sched-table .status-col {
            text-align: center;
            width: 100px;
        }

        .sched-table tfoot {
            background: #F3F4F6;
            font-weight: 800;
            border-top: 2px solid var(--nu-blue);
        }

        .sched-table tfoot td {
            padding: 9px 12px;
            color: var(--nu-navy);
            font-size: 11.5px;
        }

        .off-day-text {
            color: #9CA3AF;
            font-style: italic;
            font-weight: 400;
        }

        /* Duty Guidelines & Policies Note */
        .guidelines-box {
            background: #F8FAFC;
            border: 1px dashed #CBD5E1;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 24px;
            font-size: 10px;
            color: #475569;
            line-height: 1.45;
        }

        .guidelines-box strong {
            color: var(--nu-navy);
        }

        .guidelines-box ul {
            margin-left: 16px;
            margin-top: 4px;
        }

        .guidelines-box li {
            margin-bottom: 2px;
        }

        /* Signatures Section */
        .signatures-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid #E5E7EB;
        }

        .signature-block {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            text-align: center;
        }

        .sig-role-label {
            font-size: 9.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 36px;
            text-align: left;
        }

        .sig-line {
            border-bottom: 1.5px solid #1F2937;
            margin-bottom: 5px;
            width: 100%;
        }

        .sig-name {
            font-size: 11px;
            font-weight: 800;
            color: var(--nu-navy);
            text-transform: uppercase;
            line-height: 1.2;
        }

        .sig-title {
            font-size: 9.5px;
            color: var(--text-muted);
            font-weight: 500;
            margin-top: 1px;
        }

        .sig-date {
            font-size: 9px;
            color: #9CA3AF;
            margin-top: 4px;
        }

        /* Document Footer */
        .doc-footer {
            margin-top: 14px;
            padding-top: 8px;
            border-top: 1px solid #F3F4F6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9px;
            color: #9CA3AF;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #FFFFFF !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .no-print,
            .no-print-toolbar {
                display: none !important;
            }

            .sheet-container {
                max-width: 100% !important;
                margin: 0 !important;
                gap: 0 !important;
            }

            .printable-page {
                border-radius: 0 !important;
                box-shadow: none !important;
                padding: 12mm 15mm 12mm 15mm !important;
                min-height: 280mm !important;
                height: auto !important;
                page-break-after: always !important;
                border: none !important;
            }

            .printable-page:last-child {
                page-break-after: avoid !important;
            }

            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

    <!-- Non-printing control toolbar -->
    <div class="no-print-toolbar no-print">
        <div class="toolbar-title">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"></path><line x1="9" y1="9" x2="15" y2="9"></line><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="13" y2="17"></line></svg>
            <span>Student Assistant Duty Schedule & Hours Sheet</span>
            <span class="toolbar-badge">Physical 201 File Ready</span>
        </div>
        <div class="toolbar-actions">
            <a href="scheduling.php<?= $studentId > 0 ? '?student_id=' . $studentId : '' ?>" class="btn btn-secondary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Scheduling
            </a>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print / Save PDF (Ctrl+P)
            </button>
        </div>
    </div>

    <!-- Printable Content Container -->
    <div class="sheet-container">
        <?php if (empty($studentsToPrint)): ?>
            <div class="printable-page" style="min-height:400px;justify-content:center;align-items:center;text-align:center;">
                <h3 style="font-size:18px;color:var(--text-muted);">No student records found matching the specified filter.</h3>
                <p style="margin-top:8px;font-size:13px;color:#6B7280;">Please return to the scheduling page and select an approved student assistant.</p>
                <div style="margin-top:20px;">
                    <a href="scheduling.php" class="btn btn-primary">Return to Scheduling</a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($studentsToPrint as $stData): ?>
                <?php
                    $sId = (int)$stData['student_id'];
                    $appId = (int)($stData['application_id'] ?? 0);
                    $fullName = trim((string)$stData['first_name'] . ' ' . (string)$stData['last_name']);
                    $studentNumber = (string)($stData['student_id_number'] ?? 'N/A');
                    $program = (string)($stData['program'] ?? 'N/A');
                    $yearLevel = (string)($stData['year_level'] ?? 'N/A');
                    $email = (string)($stData['email'] ?? 'N/A');
                    $phone = (string)($stData['phone'] ?? 'N/A');
                    $officeName = (string)($stData['preferred_office'] ?? 'Unassigned');
                    $appStatus = strtolower(trim((string)($stData['application_status'] ?? 'approved')));

                    $schedData = getStudentScheduleData($pdo, $appId, $sId);
                    $totalHours = $schedData['total_weekly_hours'];
                    $grouped = $schedData['grouped_by_day'];
                    $supervisor = $schedData['supervisor'];
                    $renderedHours = $schedData['rendered_hours'];
                    $supervisorName = $supervisor ? trim($supervisor['first_name'] . ' ' . $supervisor['last_name']) : 'Office Head / Supervisor';
                ?>
                <div class="printable-page">
                    <div>
                        <!-- Institutional Header -->
                        <div class="doc-header">
                            <div class="doc-header__logo-area">
                                <div class="nu-crest">NU</div>
                                <div class="institution-titles">
                                    <h1>National University Lipa</h1>
                                    <h2>Student Development & Activities Office (SDAO)</h2>
                                    <p>Student Assistantship & Mentorship Program</p>
                                </div>
                            </div>
                            <div class="doc-header__meta">
                                <span class="doc-badge">Official Document</span><br>
                                <strong>Form No:</strong> SDAO-SA-SCHED-2026<br>
                                <strong>Term:</strong> <?= h($termFullLabel) ?><br>
                                <strong>Date Generated:</strong> <?= date('F d, Y') ?>
                            </div>
                        </div>

                        <!-- Document Title Block -->
                        <div class="doc-title-block">
                            <h2>Student Assistant Duty Hours & Schedule Certification</h2>
                            <p>Official Record for Department / 201 Physical Folder Filing</p>
                        </div>

                        <!-- Student Information Grid -->
                        <div class="section-title">Student Assistant Information</div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Student Assistant Name</span>
                                <span class="info-value"><strong><?= h($fullName) ?></strong></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Student ID Number</span>
                                <span class="info-value"><strong><?= h($studentNumber) ?></strong></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Degree Program & Year</span>
                                <span class="info-value"><?= h($program) ?> · <?= h($yearLevel) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Assigned Office / Department</span>
                                <span class="info-value"><strong><?= h($officeName) ?></strong></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Institutional Email</span>
                                <span class="info-value"><?= h($email) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Designated Office Supervisor</span>
                                <span class="info-value"><?= h($supervisorName) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Contact Number</span>
                                <span class="info-value"><?= h($phone !== '' ? $phone : 'N/A') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Status & Term</span>
                                <span class="info-value">
                                    <?php if ($appStatus === 'deployed'): ?>
                                        <span class="status-pill status-pill--deployed">Deployed</span>
                                    <?php elseif ($appStatus === 'approved'): ?>
                                        <span class="status-pill status-pill--approved">Approved</span>
                                    <?php else: ?>
                                        <span class="status-pill status-pill--pending"><?= ucfirst($appStatus) ?></span>
                                    <?php endif; ?>
                                    &nbsp;<?= h($termFullLabel) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Official Duty Hours Timetable -->
                        <div class="section-title">Weekly Duty Hours Timetable</div>
                        <div class="table-wrap">
                            <table class="sched-table">
                                <thead>
                                    <tr>
                                        <th style="width:130px;">Day of Week</th>
                                        <th>Assigned Duty Hours</th>
                                        <th style="width:110px;text-align:center;">Duration (Hours)</th>
                                        <th>Designated Workstation / Office</th>
                                        <th style="width:100px;text-align:center;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daysOfWeek as $day): ?>
                                        <?php if (isset($grouped[$day]) && !empty($grouped[$day])): ?>
                                            <?php foreach ($grouped[$day] as $index => $slot): ?>
                                                <tr>
                                                    <td class="day-col">
                                                        <?= $index === 0 ? h($day) : '' ?>
                                                    </td>
                                                    <td class="time-col">
                                                        <?= h(format_time_12hr($slot['start_time'])) ?> – <?= h(format_time_12hr($slot['end_time'])) ?>
                                                    </td>
                                                    <td class="hours-col">
                                                        <?= number_format((float)$slot['shift_hours'], 2) ?> hrs
                                                    </td>
                                                    <td class="office-col">
                                                        <?= h((string)$slot['office_name']) ?>
                                                    </td>
                                                    <td class="status-col">
                                                        <span style="font-weight:700;color:#059669;font-size:10px;text-transform:uppercase;">
                                                            <?= h(ucfirst((string)$slot['status'])) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td class="day-col"><?= h($day) ?></td>
                                                <td class="time-col off-day-text">No duty scheduled (Off)</td>
                                                <td class="hours-col off-day-text">0.00 hrs</td>
                                                <td class="office-col off-day-text">—</td>
                                                <td class="status-col off-day-text">—</td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2" style="text-align:right;font-weight:800;letter-spacing:0.3px;">
                                            TOTAL WEEKLY REQUIRED DUTY HOURS:
                                        </td>
                                        <td style="text-align:center;font-weight:900;color:var(--nu-blue);font-size:12.5px;">
                                            <?= number_format($totalHours, 2) ?> hrs
                                        </td>
                                        <td colspan="2" style="font-size:10.5px;color:var(--text-muted);font-weight:600;">
                                            <?= $totalHours >= 20 ? 'Target weekly commitment fulfilled' : 'Weekly duty schedule as designated' ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Duty Policies & Institutional Guidelines -->
                        <div class="guidelines-box">
                            <strong>DUTY RESPONSIBILITIES & PHYSICAL FILING INSTRUCTIONS:</strong>
                            <ul>
                                <li><strong>Attendance Verification:</strong> Student Assistants must record their clock-in and clock-out strictly via the official SAMS NFC Kiosk at their designated office.</li>
                                <li><strong>Schedule Adherence:</strong> Duty hours must strictly follow the approved schedule above. Off-schedule duties or shifts must have prior written approval via Temporary Duty Request.</li>
                                <li><strong>Physical 201 Folder:</strong> This certified copy must be filed inside the Student Assistant's 201 Physical Folder maintained at the office for audit and monitoring.</li>
                                <li><strong>Code of Conduct:</strong> SAs are expected to maintain professional conduct, university dress code, and confidentiality of university records at all times.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Signatures & Verification Section -->
                    <div>
                        <div class="signatures-grid">
                            <div class="signature-block">
                                <div class="sig-role-label">Conformed & Accepted by:</div>
                                <div class="sig-line"></div>
                                <div class="sig-name"><?= h($fullName) ?></div>
                                <div class="sig-title">Student Assistant</div>
                                <div class="sig-date">Date Signed: _______________</div>
                            </div>

                            <div class="signature-block">
                                <div class="sig-role-label">Verified & Endorsed by:</div>
                                <div class="sig-line"></div>
                                <div class="sig-name"><?= h($supervisorName) ?></div>
                                <div class="sig-title">Office Supervisor / Head</div>
                                <div class="sig-date">Date Signed: _______________</div>
                            </div>

                            <div class="signature-block">
                                <div class="sig-role-label">Approved by:</div>
                                <div class="sig-line"></div>
                                <div class="sig-name"><?= h($adminName) ?></div>
                                <div class="sig-title">SDAO Head / SAMS Administrator</div>
                                <div class="sig-date">Date Signed: _______________</div>
                            </div>
                        </div>

                        <!-- Page Footer -->
                        <div class="doc-footer">
                            <span>SDAO Student Assistant Management System (SAMS) • National University Lipa</span>
                            <span>Printed on: <?= date('Y-m-d H:i:s') ?> • Physical Folder Copy</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($autoPrint): ?>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 500);
        });
    </script>
    <?php endif; ?>

</body>
</html>
