<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();

$admin_name  = (string) ($currentUser['name'] ?? 'SAMS Admin');
$admin_role  = 'SDAO Head';
$department  = 'NU Lipa - Student Development and Activities Office';
$termOptions = ['1st Term', '2nd Term', '3rd Term'];
$termSettingsMessage = '';
$termSettingsError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_active_term') {
    $selectedTermName = trim((string) ($_POST['term_name'] ?? ''));
    if (!in_array($selectedTermName, $termOptions, true)) {
        $termSettingsError = 'Please choose a valid academic term.';
    } else {
        try {
            $pdo->beginTransaction();
            $activeTermStatement = $pdo->query(
                'SELECT term_id FROM terms WHERE is_active = 1 ORDER BY term_id DESC LIMIT 1'
            );
            $activeTermId = (int) ($activeTermStatement->fetchColumn() ?: 0);
            if ($activeTermId <= 0) {
                $activeTermId = (int) $pdo->query('SELECT term_id FROM terms ORDER BY term_id DESC LIMIT 1')->fetchColumn();
            }
            if ($activeTermId <= 0) {
                throw new RuntimeException('No term record is available to update.');
            }

            $pdo->exec('UPDATE terms SET is_active = 0');
            $updateTermStatement = $pdo->prepare(
                'UPDATE terms SET term_name = :term_name, is_active = 1 WHERE term_id = :term_id'
            );
            $updateTermStatement->execute([
                'term_name' => $selectedTermName,
                'term_id' => $activeTermId,
            ]);
            $pdo->commit();
            $termSettingsMessage = $selectedTermName . ' is now the active registration term.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $termSettingsError = 'Unable to update the active term: ' . $exception->getMessage();
        }
    }
}

$activeTerm = sams_current_term($pdo);
$activeTermName = trim((string) ($activeTerm['term_name'] ?? ''));
$activeTermYear = trim((string) ($activeTerm['term_year'] ?? ''));
$activeTermDisplay = trim($activeTermName . ' ' . $activeTermYear);

$activeStudents = (int) $pdo->query('SELECT COUNT(*) FROM students WHERE is_enrolled = 1')->fetchColumn();
$pendingApplicationsStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE status = :status');
$pendingApplicationsStmt->execute(['status' => 'pending']);
$pendingApplications = (int) $pendingApplicationsStmt->fetchColumn();

$monthlyHoursStmt = $pdo->query(
    'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in_time, clock_out_time) / 3600), 0)
     FROM attendance_logs
     WHERE YEAR(created_at) = YEAR(CURDATE())
       AND MONTH(created_at) = MONTH(CURDATE())
       AND clock_in_time IS NOT NULL
       AND clock_out_time IS NOT NULL'
);
$monthlyHours = (float) $monthlyHoursStmt->fetchColumn();

$avgRatingStmt = $pdo->query(
    'SELECT COALESCE(AVG((performance_rating + reliability_rating + professionalism_rating) / 3), 0)
     FROM evaluations
     WHERE performance_rating IS NOT NULL'
);
$avgRating = (float) $avgRatingStmt->fetchColumn();

$recentApplicationsStmt = $pdo->query(
    "SELECT a.application_id,
            a.status,
            a.submitted_at,
            s.student_id_number,
            s.program,
            u.first_name,
            u.last_name
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     ORDER BY a.submitted_at DESC
     LIMIT 4"
);
$recentApplications = $recentApplicationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$todayAttendanceStmt = $pdo->query(
    "SELECT u.first_name,
            u.last_name,
            COALESCE(a.preferred_office, 'Unassigned') AS office_name,
            al.clock_in_time AS time_in,
            al.status
     FROM attendance_logs al
     LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
     LEFT JOIN applications a ON a.application_id = ds.application_id
     LEFT JOIN students s ON s.student_id = a.student_id
     LEFT JOIN users u ON u.user_id = s.user_id
     WHERE DATE(al.created_at) = CURDATE()
     ORDER BY al.clock_in_time DESC
     LIMIT 4"
);
$todayAttendance = $todayAttendanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$adminUserId = (int) ($currentUser['user_id'] ?? 0);
sams_admin_meetings_generate_notifications($pdo, $adminUserId);
$upcomingMeetings = sams_admin_meetings_fetch_upcoming($pdo, $adminUserId, 4);

$alerts = [];
if ($pendingApplications > 0) {
    $alerts[] = [
        'tone' => 'warn',
        'title' => $pendingApplications . ' applications awaiting review',
        'body' => 'Review pending applications from this week',
    ];
}

$heroLatestApplication = $recentApplications[0] ?? null;
$heroLatestApplicant = trim((string) ($heroLatestApplication['first_name'] ?? '') . ' ' . (string) ($heroLatestApplication['last_name'] ?? ''));
$heroLatestApplicant = $heroLatestApplicant !== '' ? $heroLatestApplicant : 'No recent applicant';
$heroLatestStatus = (string) ($heroLatestApplication['status'] ?? 'pending');
$heroLatestSubmitted = !empty($heroLatestApplication['submitted_at'])
    ? date('M j, Y', strtotime((string) $heroLatestApplication['submitted_at']))
    : 'Waiting for new submissions';
if ($monthlyHours > 0) {
    $alerts[] = [
        'tone' => 'succ',
        'title' => 'Live monthly hours updated',
        'body' => 'Current month total is ' . number_format($monthlyHours, 1) . ' hours',
    ];
}
if ($avgRating > 0) {
    $alerts[] = [
        'tone' => 'info',
        'title' => 'Current evaluation average ' . number_format($avgRating, 1) . '/5',
        'body' => 'Supervisor ratings are reflected from live records',
    ];
}

function sams_admin_dashboard_initials(?string $firstName, ?string $lastName): string
{
    $a = strtoupper(substr(trim((string) $firstName), 0, 1));
    $b = strtoupper(substr(trim((string) $lastName), 0, 1));
    $initials = trim($a . $b);
    return $initials !== '' ? $initials : 'SA';
}

function sams_admin_dashboard_badge_class(string $status): string
{
    return match (strtolower($status)) {
        'approved' => 'app-row__badge--approved',
        'interview' => 'app-row__badge--interview',
        default => 'app-row__badge--pending',
    };
}

function sams_admin_dashboard_attendance_dot(string $status): string
{
    return match (strtolower($status)) {
        'present', 'completed' => 'att-row__dot--present',
        'late', 'active' => 'att-row__dot--present',
        default => 'att-row__dot--absent',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NU SA System – Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        /* =============================================
           CSS VARIABLES – Design System
        ============================================= */
        :root {
            /* Brand */
            --color-primary:         #155dfc;
            --color-primary-dark:    #1447e6;
            --color-purple:          #9810fa;
            --gradient-brand:        linear-gradient(135deg, #155dfc 0%, #9810fa 100%);

            /* Neutral */
            --color-heading:         #101828;
            --color-body:            #374151;
            --color-label:           #364153;
            --color-muted:           #4b5563;
            --color-border:          #e5e7eb;
            --color-bg-app:          #f9fafb;
            --color-white:           #ffffff;

            /* Status badges */
            --color-pending-bg:      #fef9c2;
            --color-pending-text:    #111827;
            --color-interview-bg:    #dbeafe;
            --color-interview-text:  #111827;
            --color-approved-bg:     #dcfce7;
            --color-approved-text:   #111827;

            /* Presence dots */
            --color-present:         #00c950;
            --color-absent:          #d1d5dc;

            /* Alert colours */
            --color-alert-warn-bg:   #fff7ed;
            --color-alert-warn-bd:   #ffd6a8;
            --color-alert-succ-bg:   #f0fdf4;
            --color-alert-succ-bd:   #b9f8cf;
            --color-alert-info-bg:   #eff6ff;
            --color-alert-info-bd:   #bedbff;
            --color-red-dot:         #fb2c36;
            --color-green-up:        #166534;

            /* Shadows */
            --shadow-card: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);

            /* Dimensions */
            --sidebar-width: 256px;
            --topbar-height: 89px;
            --radius-card:   16.4px;
            --radius-nav:    10px;
            --radius-badge:  9999px;
            --radius-icon:   10px;

            /* Typography */
            --font-xs:   12px;
            --font-sm:   14px;
            --font-base: 16px;
            --font-md:   18px;
            --font-lg:   24px;
            --font-xl:   30px;

            --lh-xs:   16px;
            --lh-sm:   20px;
            --lh-base: 24px;
            --lh-md:   28px;
            --lh-lg:   32px;
            --lh-xl:   36px;
        }

        /* =============================================
           RESET & BASE
        ============================================= */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--color-bg-app);
            color: var(--color-heading);
            min-height: 100vh;
            display: flex;
        }
        a { text-decoration: none; color: inherit; }
        img { display: block; max-width: 100%; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; }

        /* =============================================
           APP SHELL
        ============================================= */
        .shell { display: flex; width: 100%; min-height: 100vh; }

        /* =============================================
           SIDEBAR
        ============================================= */
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--color-white);
            border-right: 1px solid var(--color-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar__brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 24px 24px 20px;
            border-bottom: 1px solid var(--color-border);
            flex-shrink: 0;
        }
        .sidebar__logo {
            width: 40px;
            height: 40px;
            background: var(--gradient-brand);
            border-radius: var(--radius-icon);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .sidebar__logo-text { font-size: 18px; font-weight: 700; color: var(--color-white); line-height: 1; }
        .sidebar__brand-name { font-size: var(--font-base); font-weight: 700; color: var(--color-heading); line-height: var(--lh-base); }
        .sidebar__brand-sub  { font-size: var(--font-xs); font-weight: 400; color: var(--color-body); line-height: var(--lh-xs); }

        .sidebar__nav {
            flex: 1;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            overflow-y: auto;
        }
        .sidebar__nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 48px;
            padding: 0 16px;
            border-radius: var(--radius-nav);
            font-size: var(--font-base);
            font-weight: 400;
            color: var(--color-label);
            transition: background .15s;
            white-space: nowrap;
        }
        .sidebar__nav-link:hover { background: var(--color-bg-app); }
        .sidebar__nav-link--active { background: var(--color-primary); color: var(--color-white); }
        .sidebar__nav-link--active:hover { opacity: .92; }
        .sidebar__nav-icon { width: 20px; height: 20px; flex-shrink: 0; }
        .sidebar__nav-badge {
            background: #dbeafe;
            color: #111827;
            font-size: var(--font-xs);
            font-weight: 700;
            line-height: var(--lh-xs);
            padding: 2px 8px;
            border-radius: var(--radius-badge);
            margin-left: auto;
        }

        .sidebar__footer {
            border-top: 1px solid var(--color-border);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        /* =============================================
           MAIN
        ============================================= */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .topbar {
            background: var(--color-white);
            border-bottom: 1px solid var(--color-border);
            height: var(--topbar-height);
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .topbar__left-wrap { display: flex; align-items: center; }
        .topbar__title { font-size: var(--font-lg); font-weight: 700; line-height: var(--lh-lg); color: var(--color-heading); }
        .topbar__sub   { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); }

        .topbar__right { display: flex; align-items: center; gap: 12px; }
        .topbar__notif-btn {
            width: 36px; height: 36px;
            border-radius: var(--radius-badge);
            display: flex; align-items: center; justify-content: center;
            position: relative; cursor: pointer;
            transition: background .15s;
        }
        .topbar__notif-btn:hover { background: var(--color-bg-app); }
        .topbar__notif-btn svg { width: 20px; height: 20px; }
        .topbar__notif-dot {
            position: absolute; top: 4px; right: 4px;
            width: 8px; height: 8px;
            background: var(--color-red-dot);
            border-radius: var(--radius-badge);
        }
        .topbar__user-info { text-align: right; }
        .topbar__user-name { font-size: var(--font-sm); font-weight: 400; color: var(--color-heading); line-height: var(--lh-sm); }
        .topbar__user-role { font-size: var(--font-xs); font-weight: 400; color: var(--color-body); line-height: var(--lh-xs); }
        .topbar__avatar {
            width: 40px; height: 40px;
            background: var(--gradient-brand);
            border-radius: var(--radius-badge);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .topbar__avatar svg { width: 20px; height: 20px; }

        /* Hamburger */
        .topbar__hamburger {
            display: none;
            flex-direction: column; gap: 5px;
            width: 32px; height: 32px;
            justify-content: center; align-items: center;
            padding: 0; margin-right: 16px; cursor: pointer;
        }
        .topbar__hamburger-bar {
            display: block; width: 22px; height: 2px;
            background: var(--color-heading); border-radius: 2px;
            transition: transform .3s, opacity .3s;
        }
        .topbar__hamburger[aria-expanded="true"] .topbar__hamburger-bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .topbar__hamburger[aria-expanded="true"] .topbar__hamburger-bar:nth-child(2) { opacity: 0; }
        .topbar__hamburger[aria-expanded="true"] .topbar__hamburger-bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* =============================================
           DASHBOARD CONTENT
        ============================================= */
        .dashboard {
            padding: 28px 32px 32px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            flex: 1;
            position: relative;
            isolation: isolate;
        }

        .dashboard::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 18% 8%, rgba(21, 93, 252, 0.08), transparent 0 22%),
                radial-gradient(circle at 92% 12%, rgba(152, 16, 250, 0.07), transparent 0 20%);
            pointer-events: none;
            z-index: -1;
        }

        .hero {
            background: linear-gradient(135deg, #0f4cd6 0%, #205ee6 54%, #3c7dff 100%);
            color: #fff;
            border-radius: 22px;
            padding: 28px;
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(320px, .85fr);
            gap: 20px;
            box-shadow: 0 20px 40px rgba(15, 76, 214, 0.18);
            overflow: hidden;
            position: relative;
            animation: dashboardFadeIn .45s ease both;
        }

        .hero::before,
        .hero::after {
            content: '';
            position: absolute;
            border-radius: 999px;
            pointer-events: none;
            background: rgba(255, 255, 255, 0.12);
        }

        .hero::before {
            width: 220px;
            height: 220px;
            right: -96px;
            top: -72px;
            filter: blur(6px);
        }

        .hero::after {
            width: 160px;
            height: 160px;
            left: 48%;
            bottom: -72px;
            opacity: 0.55;
        }

        .hero__content,
        .hero__snapshot {
            position: relative;
            z-index: 1;
        }

        .hero__eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .hero__eyebrow::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #9ef0b8;
            box-shadow: 0 0 0 4px rgba(158, 240, 184, 0.18);
        }

        .hero h1 {
            margin: 14px 0 0;
            max-width: 13ch;
            font-size: clamp(2rem, 3vw, 3.25rem);
            line-height: 1.02;
            letter-spacing: -0.04em;
        }

        .hero p {
            margin: 14px 0 0;
            max-width: 60ch;
            font-size: 15px;
            line-height: 1.65;
            color: rgba(236, 243, 255, 0.92);
        }

        .hero__metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 22px;
        }

        .hero__metric {
            background: rgba(9, 32, 89, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 16px;
            padding: 14px;
            backdrop-filter: blur(8px);
        }

        .hero__metric-label {
            font-size: 12px;
            color: #d8e6ff;
            letter-spacing: 0.01em;
        }

        .hero__metric-value {
            margin-top: 8px;
            font-size: 28px;
            line-height: 1;
            font-weight: 800;
        }

        .hero__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        .hero__action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            border: 1px solid transparent;
            transition: transform .18s ease, background .18s ease, border-color .18s ease, opacity .18s ease;
        }

        .hero__action:hover {
            transform: translateY(-1px);
        }

        .hero__action--solid {
            background: #ffffff;
            color: #0f3fb8;
        }

        .hero__action--ghost {
            color: #fff;
            border-color: rgba(255, 255, 255, 0.22);
            background: rgba(255, 255, 255, 0.08);
        }

        .hero__snapshot {
            background: rgba(7, 21, 56, 0.34);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 20px;
            padding: 18px;
            align-self: stretch;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 16px;
            backdrop-filter: blur(10px);
        }

        .hero__snapshot-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .hero__snapshot-label {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #cfe1ff;
        }

        .hero__live-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 12px;
            font-weight: 700;
        }

        .hero__live-pill::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #9ef0b8;
        }

        .hero__snapshot-title {
            font-size: 24px;
            line-height: 1.1;
            font-weight: 800;
            margin: 0;
        }

        .hero__snapshot-sub {
            margin: 8px 0 0;
            color: rgba(236, 243, 255, 0.88);
            font-size: 14px;
            line-height: 1.55;
        }

        .hero__snapshot-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .hero__snapshot-card {
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 14px;
        }

        .hero__snapshot-card-label {
            font-size: 12px;
            color: #d8e6ff;
        }

        .hero__snapshot-card-value {
            margin-top: 6px;
            font-size: 22px;
            line-height: 1.1;
            font-weight: 800;
        }

        .hero__snapshot-card-meta {
            margin-top: 6px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.78);
            line-height: 1.45;
        }

        .hero__snapshot-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.14);
            padding-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .hero__snapshot-footer-label {
            font-size: 12px;
            color: #cfe1ff;
            font-weight: 700;
        }

        .hero__snapshot-footer-value {
            font-size: 16px;
            font-weight: 800;
        }

        .hero__snapshot-footer-sub {
            color: rgba(255, 255, 255, 0.82);
            font-size: 13px;
            line-height: 1.5;
        }

        /* =============================================
           STAT CARDS
        ============================================= */
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .stat-card {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: 18px;
            box-shadow: 0 10px 22px rgba(16, 24, 40, .05);
            padding: 22px;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 28px rgba(16, 24, 40, .08);
            border-color: #d6def0;
        }
        .stat-card__top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
        .stat-card__icon-wrap {
            width: 48px; height: 48px;
            background: linear-gradient(180deg, #eff4ff 0%, #f8fbff 100%);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid #dbe6ff;
        }
        .stat-card__icon-wrap svg { width: 24px; height: 24px; }
        .stat-card__trend { font-size: var(--font-sm); font-weight: 400; color: var(--color-green-up); line-height: var(--lh-sm); }
        .stat-card__value { font-size: var(--font-xl); font-weight: 700; line-height: var(--lh-xl); color: var(--color-heading); margin-bottom: 4px; }
        .stat-card__label { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); margin-bottom: 4px; }
        .stat-card__sub   { font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs); color: var(--color-muted); }

        /* =============================================
           MID ROW
        ============================================= */
        .mid-row { display: grid; grid-template-columns: 1fr 431px; gap: 24px; }

        /* =============================================
           CARD BASE
        ============================================= */
        .card {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: 18px;
            box-shadow: 0 10px 22px rgba(16, 24, 40, .05);
            padding: 25px;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 28px rgba(16, 24, 40, .08);
            border-color: #d6def0;
        }
        .card__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .card__title  { font-size: var(--font-md); font-weight: 700; line-height: var(--lh-md); color: var(--color-heading); }
        .card__link   { font-size: var(--font-sm); font-weight: 400; color: var(--color-primary); line-height: var(--lh-sm); transition: opacity .15s; }
        .card__link:hover { opacity: .75; }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--color-muted);
        }

        .section-kicker::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: linear-gradient(135deg, #155dfc 0%, #9810fa 100%);
        }

        .section-note {
            margin-top: 6px;
            color: var(--color-body);
            font-size: 14px;
            line-height: 1.6;
            max-width: 68ch;
        }

        /* =============================================
           APPLICATION ROWS
        ============================================= */
        .app-list { display: flex; flex-direction: column; gap: 12px; }
        .app-row {
            display: flex; align-items: center; gap: 16px;
            height: 76px; padding: 0 16px;
            border-radius: var(--radius-nav);
            transition: background .12s;
        }
        .app-row:hover { background: var(--color-bg-app); }
        .app-row__avatar {
            width: 40px; height: 40px;
            background: var(--gradient-brand);
            border-radius: var(--radius-badge);
            display: flex; align-items: center; justify-content: center;
            font-size: var(--font-base); font-weight: 700; color: var(--color-white);
            flex-shrink: 0;
        }
        .app-row__info { flex: 1; min-width: 0; }
        .app-row__name   { font-size: var(--font-base); font-weight: 700; line-height: var(--lh-base); color: var(--color-heading); }
        .app-row__detail { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); }
        .app-row__meta   { text-align: right; flex-shrink: 0; }
        .app-row__badge {
            display: inline-block; height: 24px;
            padding: 4px 12px;
            border-radius: var(--radius-badge);
            font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs);
            margin-bottom: 3px;
        }
        .app-row__badge--pending   { background: var(--color-pending-bg);   color: var(--color-pending-text); }
        .app-row__badge--interview { background: var(--color-interview-bg); color: var(--color-interview-text); }
        .app-row__badge--approved  { background: var(--color-approved-bg);  color: var(--color-approved-text); }
        .app-row__date { font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs); color: var(--color-muted); }

        /* =============================================
           QUICK ACTIONS
        ============================================= */
        .qa-list { display: flex; flex-direction: column; gap: 12px; }
        .qa-btn {
            display: flex; align-items: center; gap: 12px;
            height: 64px; padding: 0 16px;
            background: var(--color-bg-app);
            border-radius: var(--radius-nav);
            font-size: var(--font-base); font-weight: 400; color: var(--color-heading);
            text-decoration: none; transition: background .15s; cursor: pointer;
        }
        .qa-btn:hover { background: var(--color-border); }
        .qa-btn__emoji { font-size: 24px; line-height: 1; flex-shrink: 0; width: 33px; }

        .meeting-list { display: flex; flex-direction: column; gap: 10px; margin-top: 18px; }
        .meeting-item {
            border: 1px solid var(--color-border);
            border-radius: var(--radius-nav);
            padding: 10px 12px;
            background: #f8fafc;
        }
        .meeting-item__title {
            font-size: 14px;
            font-weight: 700;
            color: var(--color-heading);
            line-height: 20px;
        }
        .meeting-item__meta {
            font-size: 12px;
            color: var(--color-body);
            line-height: 16px;
            margin-top: 4px;
        }

        /* =============================================
           BOTTOM ROW
        ============================================= */
        .bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

        /* =============================================
           ATTENDANCE ROWS
        ============================================= */
        .att-list { display: flex; flex-direction: column; gap: 12px; }
        .att-row {
            display: flex; align-items: center; gap: 16px;
            height: 76px; padding: 0 16px;
            background: var(--color-bg-app);
            border-radius: var(--radius-nav);
        }
        .att-row__dot { width: 12px; height: 12px; border-radius: var(--radius-badge); flex-shrink: 0; }
        .att-row__dot--present { background: var(--color-present); }
        .att-row__dot--absent  { background: var(--color-absent); }
        .att-row__info { flex: 1; }
        .att-row__name     { font-size: var(--font-base); font-weight: 700; line-height: var(--lh-base); color: var(--color-heading); }
        .att-row__location { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); }
        .att-row__time     { font-size: var(--font-base); font-weight: 400; line-height: var(--lh-base); color: var(--color-heading); text-align: right; flex-shrink: 0; }

        /* =============================================
           ALERTS
        ============================================= */
        .alert-list { display: flex; flex-direction: column; gap: 12px; }
        .alert-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 17px; border-radius: var(--radius-nav); border: 1px solid;
        }
        .alert-item--warn { background: var(--color-alert-warn-bg); border-color: var(--color-alert-warn-bd); }
        .alert-item--succ { background: var(--color-alert-succ-bg); border-color: var(--color-alert-succ-bd); }
        .alert-item--info { background: var(--color-alert-info-bg); border-color: var(--color-alert-info-bd); }
        .alert-item__icon  { width: 20px; height: 20px; flex-shrink: 0; }
        .alert-item__title { font-size: var(--font-sm); font-weight: 700; line-height: var(--lh-sm); color: var(--color-heading); margin-bottom: 4px; }
        .alert-item__body  { font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs); color: var(--color-body); }

        @keyframes dashboardFadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* =============================================
           OVERLAY
        ============================================= */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 90; }
        .sidebar-overlay--visible { display: block; }

        /* =============================================
           RESPONSIVE – TABLET (≤1024px)
        ============================================= */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed; left: 0; top: 0; height: 100%; z-index: 100;
                transform: translateX(-100%); transition: transform .3s ease;
            }
            .sidebar--open { transform: translateX(0); }
            .topbar__hamburger { display: flex; }
            .topbar { padding: 0 24px; }
            .dashboard { padding: 24px; }
            .hero { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .mid-row { grid-template-columns: 1fr; }
            .bottom-row { grid-template-columns: 1fr; }
        }

        /* =============================================
           RESPONSIVE – MOBILE (≤768px)
        ============================================= */
        @media (max-width: 768px) {
            .topbar { padding: 0 16px; }
            .dashboard { padding: 16px; gap: 16px; }
            .hero { padding: 20px; }
            .hero__metrics { grid-template-columns: 1fr; }
            .hero__snapshot-grid { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 16px; }
            .topbar__user-info { display: none; }
            .card { padding: 16px; }
            .card__title { font-size: var(--font-base); }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

<div class="shell">
    <?php $activeAdminNav = 'dashboard'; include __DIR__ . '/_sidebar.php'; ?>

    <!-- ══════════════════ MAIN ══════════════════ -->
    <div class="main">

        <header class="topbar" role="banner">
            <div class="topbar__left-wrap">
                <button class="topbar__hamburger" id="hamburger-btn" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
                    <span class="topbar__hamburger-bar"></span>
                    <span class="topbar__hamburger-bar"></span>
                    <span class="topbar__hamburger-bar"></span>
                </button>
                <div class="topbar__left">
                    <div class="topbar__title">Dashboard</div>
                    <div class="topbar__sub"><?= htmlspecialchars($department) ?></div>
                </div>
            </div>
            <div class="topbar__right">
                <span id="connectivity-badge" style="font-size:12px;font-weight:700;padding:6px 10px;border-radius:9999px;background:#fef3c6;color:#a65f00;">Checking...</span>
                <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M15 6.67A5 5 0 0 0 5 6.67C5 12.5 2.5 14.17 2.5 14.17h15S15 12.5 15 6.67Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M11.44 17.5a1.67 1.67 0 0 1-2.88 0" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <span class="topbar__notif-dot" aria-hidden="true" style="display:none"></span>
                </div>
                <div class="topbar__user-info" aria-label="Logged in user">
                    <div class="topbar__user-name"><?= htmlspecialchars($admin_name) ?></div>
                    <div class="topbar__user-role"><?= htmlspecialchars($admin_role) ?></div>
                </div>
                <div class="topbar__avatar" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <path d="M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17.5 17.5c0-4.14-3.36-7.5-7.5-7.5S2.5 13.36 2.5 17.5" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>
        </header>

        <main class="dashboard" id="main-content">

            <section class="hero" aria-labelledby="admin-hero-title">
                <div class="hero__content">
                    <div class="hero__eyebrow">Live command center</div>
                    <h1 id="admin-hero-title">Keep admissions, attendance, and meetings moving in one place.</h1>
                    <p>
                        This view stays in sync with live records, giving you a fast read on <?php echo (int) $activeStudents; ?> active student assistants,
                        <?php echo (int) $pendingApplications; ?> pending applications, and <?php echo number_format($monthlyHours, 1); ?> logged hours this month.
                    </p>

                    <div class="hero__metrics" aria-label="Live summary metrics">
                        <div class="hero__metric">
                            <div class="hero__metric-label">Active students</div>
                            <div class="hero__metric-value" id="hero-metric-active-students"><?php echo (int) $activeStudents; ?></div>
                        </div>
                        <div class="hero__metric">
                            <div class="hero__metric-label">Pending applications</div>
                            <div class="hero__metric-value" id="hero-metric-pending-applications"><?php echo (int) $pendingApplications; ?></div>
                        </div>
                        <div class="hero__metric">
                            <div class="hero__metric-label">Monthly hours</div>
                            <div class="hero__metric-value" id="hero-metric-monthly-hours"><?php echo number_format($monthlyHours, 0); ?></div>
                        </div>
                    </div>

                    <div class="hero__actions">
                        <a href="applications.php" class="hero__action hero__action--solid">Review applications</a>
                        <a href="attendance.php" class="hero__action hero__action--ghost">Open attendance</a>
                    </div>
                </div>

                <div class="hero__snapshot">
                    <div class="hero__snapshot-head">
                        <div class="hero__snapshot-label">Live snapshot</div>
                        <div class="hero__live-pill">Refreshing every 20s</div>
                    </div>
                    <div>
                        <h2 class="hero__snapshot-title">Latest activity at a glance</h2>
                        <p class="hero__snapshot-sub">The dashboard highlights the newest application, the current review pressure, and the month’s performance momentum.</p>
                    </div>
                    <div class="hero__snapshot-grid">
                        <div class="hero__snapshot-card">
                            <div class="hero__snapshot-card-label">Latest application</div>
                            <div class="hero__snapshot-card-value"><?php echo htmlspecialchars($heroLatestApplicant); ?></div>
                            <div class="hero__snapshot-card-meta"><?php echo htmlspecialchars($heroLatestStatus); ?> · <?php echo htmlspecialchars($heroLatestSubmitted); ?></div>
                        </div>
                        <div class="hero__snapshot-card">
                            <div class="hero__snapshot-card-label">Evaluation average</div>
                            <div class="hero__snapshot-card-value" id="hero-metric-avg-rating"><?php echo number_format($avgRating, 1); ?>/5</div>
                            <div class="hero__snapshot-card-meta">Based on live supervisor evaluations</div>
                        </div>
                    </div>
                    <div class="hero__snapshot-footer">
                        <div class="hero__snapshot-footer-label">Next move</div>
                        <div class="hero__snapshot-footer-value"><?php echo (int) $pendingApplications > 0 ? 'Review pending applicants' : 'Dashboard is clear'; ?></div>
                        <div class="hero__snapshot-footer-sub"><?php echo (int) $pendingApplications > 0 ? 'Open the applications queue to clear the current review stack.' : 'No urgent application queue right now. Use the quick actions below.'; ?></div>
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="term-settings-title">
                <div class="card__header">
                    <div>
                        <div class="section-kicker">Registration settings</div>
                        <h2 class="card__title" id="term-settings-title">Active Academic Term</h2>
                        <p class="section-note">Choose the term that will appear on new registrations, requirements, applications, and reports.</p>
                    </div>
                    <strong style="color:#111827;"><?= htmlspecialchars($activeTermDisplay !== '' ? $activeTermDisplay : 'Not configured') ?></strong>
                </div>
                <?php if ($termSettingsMessage !== ''): ?>
                    <div style="margin-bottom:14px;padding:12px 14px;border:1px solid #b9f8cf;border-radius:10px;background:#f0fdf4;color:#166534;font-size:14px;font-weight:700;" role="status">
                        <?= htmlspecialchars($termSettingsMessage) ?>
                    </div>
                <?php endif; ?>
                <?php if ($termSettingsError !== ''): ?>
                    <div style="margin-bottom:14px;padding:12px 14px;border:1px solid #fecaca;border-radius:10px;background:#fef2f2;color:#991b1b;font-size:14px;font-weight:700;" role="alert">
                        <?= htmlspecialchars($termSettingsError) ?>
                    </div>
                <?php endif; ?>
                <form method="post" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="update_active_term">
                    <label style="display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:700;color:#364153;">
                        Registration term
                        <select name="term_name" required style="min-width:190px;padding:10px 12px;border:1px solid #d1d5dc;border-radius:10px;background:#fff;font:inherit;">
                            <?php foreach ($termOptions as $termOption): ?>
                                <option value="<?= htmlspecialchars($termOption) ?>" <?= $activeTermName === $termOption ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($termOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" style="min-height:42px;padding:0 16px;border:0;border-radius:10px;background:#155dfc;color:#fff;font:inherit;font-weight:700;cursor:pointer;">
                        Save Active Term
                    </button>
                </form>
            </section>

            <!-- STAT CARDS -->
            <div class="stat-grid" role="list" aria-label="Key metrics">

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="9" cy="7" r="4" stroke="#4a5565" stroke-width="1.5"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__trend" aria-label="Trending up">↑</span>
                    </div>
                    <div class="stat-card__value" id="metric-active-students"><?= (int) $activeStudents ?></div>
                    <div class="stat-card__label">Active Student Assistants</div>
                    <div class="stat-card__sub">+3 this month</div>
                </div>

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2Z" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </div>
                    <div class="stat-card__value" id="metric-pending-applications"><?= (int) $pendingApplications ?></div>
                    <div class="stat-card__label">Pending Applications</div>
                    <div class="stat-card__sub">Needs review</div>
                </div>

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="9" stroke="#4a5565" stroke-width="1.5"/>
                                <path d="M12 7v5l3 3" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__trend" aria-label="Trending up">↑</span>
                    </div>
                    <div class="stat-card__value" id="metric-monthly-hours"><?= number_format($monthlyHours, 0) ?></div>
                    <div class="stat-card__label">Total Hours (This Month)</div>
                    <div class="stat-card__sub">+12% from last month</div>
                </div>

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M22 7L13.5 15.5L8.5 10.5L2 17" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 7h6v6" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__trend" aria-label="Trending up">↑</span>
                    </div>
                    <div class="stat-card__value" id="metric-avg-rating"><?= number_format($avgRating, 1) ?>/5</div>
                    <div class="stat-card__label">Avg. Performance Rating</div>
                    <div class="stat-card__sub">+0.2 improvement</div>
                </div>

            </div>

            <!-- MID ROW -->
            <div class="mid-row">

                <section class="card" aria-labelledby="recent-apps-heading">
                    <div class="card__header">
                        <h2 class="card__title" id="recent-apps-heading">Recent Applications</h2>
                        <a href="applications.php" class="card__link">View All →</a>
                    </div>
                    <div class="app-list" id="recent-apps-list">
                        <?php foreach ($recentApplications as $application): ?>
                        <?php $fullName = trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? '')); ?>
                        <div class="app-row">
                            <div class="app-row__avatar" aria-hidden="true"><?= htmlspecialchars(sams_admin_dashboard_initials((string) ($application['first_name'] ?? ''), (string) ($application['last_name'] ?? ''))) ?></div>
                            <div class="app-row__info">
                                <div class="app-row__name"><?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Student') ?></div>
                                <div class="app-row__detail"><?= htmlspecialchars((string) ($application['student_id_number'] ?? '')) ?> • <?= htmlspecialchars((string) ($application['program'] ?? '')) ?></div>
                            </div>
                            <div class="app-row__meta">
                                <span class="app-row__badge <?= htmlspecialchars(sams_admin_dashboard_badge_class((string) ($application['status'] ?? 'pending'))) ?>"><?= htmlspecialchars((string) ($application['status'] ?? 'pending')) ?></span>
                                <div class="app-row__date"><?= htmlspecialchars(!empty($application['submitted_at']) ? date('M j, Y', strtotime((string) $application['submitted_at'])) : 'No date') ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="card" aria-labelledby="qa-heading">
                    <div class="section-kicker">Quick access</div>
                    <h2 class="card__title" id="qa-heading" style="margin-bottom:8px;">Quick Actions</h2>
                    <p class="section-note">Jump straight into the highest-value admin tasks without hunting through the sidebar.</p>
                    <div class="qa-list">
                        <a href="applications.php" class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">📋</span> Review Applications</a>
                        <a href="scheduling.php"   class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">📅</span> Create Schedule</a>
                        <a href="meetings.php"     class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">🗓️</span> Manage Meetings</a>
                        <a href="supervisors.php"  class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">👤</span> Manage Supervisors</a>
                        <a href="reports.php"      class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">📄</span> Generate Reports</a>
                        <a href="evaluation.php"   class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">✍️</span> Evaluate Students</a>
                    </div>

                    <h3 style="margin:20px 0 10px;font-size:14px;color:#111827;">Upcoming Meetings</h3>
                    <div class="meeting-list" id="upcoming-meetings-list">
                        <?php if (empty($upcomingMeetings)): ?>
                            <div class="meeting-item">
                                <div class="meeting-item__title">No upcoming meetings</div>
                                <div class="meeting-item__meta">Create one in the Meetings module.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcomingMeetings as $meeting): ?>
                                <div class="meeting-item">
                                    <div class="meeting-item__title"><?= htmlspecialchars((string) ($meeting['title'] ?? 'Meeting')) ?></div>
                                    <div class="meeting-item__meta">
                                        <?= htmlspecialchars(date('M j, Y', strtotime((string) ($meeting['meeting_date'] ?? 'now')))) ?>
                                        at <?= htmlspecialchars(date('g:i A', strtotime((string) ($meeting['start_time'] ?? '00:00:00')))) ?>
                                        <?php if (!empty($meeting['location'])): ?>
                                            • <?= htmlspecialchars((string) $meeting['location']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            </div>

            <!-- BOTTOM ROW -->
            <div class="bottom-row">

                <section class="card" aria-labelledby="attendance-heading">
                    <div class="card__header">
                        <h2 class="card__title" id="attendance-heading">Today's Attendance</h2>
                        <a href="attendance.php" class="card__link">View All →</a>
                    </div>
                    <div class="att-list" id="today-attendance-list">
                        <?php foreach ($todayAttendance as $attendance): ?>
                        <div class="att-row">
                            <span class="att-row__dot <?= htmlspecialchars(sams_admin_dashboard_attendance_dot((string) ($attendance['status'] ?? ''))) ?>" aria-label="<?= htmlspecialchars((string) ($attendance['status'] ?? 'Scheduled')) ?>"></span>
                            <div class="att-row__info">
                                <div class="att-row__name"><?= htmlspecialchars(trim((string) ($attendance['first_name'] ?? '') . ' ' . (string) ($attendance['last_name'] ?? '')) ?: 'Unassigned Student') ?></div>
                                <div class="att-row__location"><?= htmlspecialchars((string) ($attendance['office_name'] ?? '-')) ?></div>
                            </div>
                            <div class="att-row__time"><?= htmlspecialchars(!empty($attendance['time_in']) ? date('g:i A', strtotime((string) $attendance['time_in'])) : 'Not yet') ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="card" aria-labelledby="alerts-heading">
                    <div class="section-kicker">Attention feed</div>
                    <h2 class="card__title" id="alerts-heading" style="margin-bottom:8px;">Alerts &amp; Notifications</h2>
                    <p class="section-note">These alerts surface operational changes from the live database so you can respond faster.</p>
                    <div class="alert-list">
                        <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item alert-item--<?= htmlspecialchars((string) ($alert['tone'] ?? 'info')) ?>" role="status">
                            <svg class="alert-item__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <div>
                                <div class="alert-item__title"><?= htmlspecialchars((string) ($alert['title'] ?? 'Live alert')) ?></div>
                                <div class="alert-item__body"><?= htmlspecialchars((string) ($alert['body'] ?? '')) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

            </div>

        </main>
    </div>

</div>

<script>
(function () {
    'use strict';
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    var hamburger = document.getElementById('hamburger-btn');

    function open() {
        sidebar.classList.add('sidebar--open');
        overlay.classList.add('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'true');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function close() {
        sidebar.classList.remove('sidebar--open');
        overlay.classList.remove('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'false');
        overlay.setAttribute('aria-hidden', 'true');
    }

    if (hamburger) {
        hamburger.addEventListener('click', function () {
            sidebar.classList.contains('sidebar--open') ? close() : open();
        });
    }
    if (overlay) {
        overlay.addEventListener('click', close);
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            close();
        }
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 1024) {
            close();
        }
    });

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch] || ch;
        });
    }

    function statusClass(status) {
        var normalized = String(status || '').toLowerCase();
        if (normalized === 'approved') {
            return 'app-row__badge--approved';
        }
        if (normalized === 'interview') {
            return 'app-row__badge--interview';
        }
        return 'app-row__badge--pending';
    }

    function initials(firstName, lastName) {
        var a = (firstName || '').trim().charAt(0).toUpperCase();
        var b = (lastName || '').trim().charAt(0).toUpperCase();
        return (a + b) || 'SA';
    }

    function formatHours(value) {
        return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 1 });
    }

    function formatRating(value) {
        var rating = Number(value || 0);
        return rating > 0 ? rating.toFixed(1) + '/5' : 'N/A';
    }

    function formatDate(value) {
        if (!value) {
            return 'No date';
        }
        var dt = new Date(value);
        if (isNaN(dt.getTime())) {
            return String(value);
        }
        return dt.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatTime(value) {
        if (!value) {
            return 'Not yet';
        }
        var dt = new Date('1970-01-01T' + String(value));
        if (isNaN(dt.getTime())) {
            return String(value);
        }
        return dt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function setConnectivity(state, text) {
        var badge = document.getElementById('connectivity-badge');
        if (!badge) {
            return;
        }

        if (state === 'online') {
            badge.style.background = '#dcfce7';
            badge.style.color = '#166534';
        } else if (state === 'offline') {
            badge.style.background = '#fee2e2';
            badge.style.color = '#991b1b';
        } else {
            badge.style.background = '#fef3c6';
            badge.style.color = '#a65f00';
        }

        badge.textContent = text;
    }

    function renderRecentApplications(list) {
        var root = document.getElementById('recent-apps-list');
        if (!root) {
            return;
        }

        if (!Array.isArray(list) || list.length === 0) {
            root.innerHTML = '<div class="app-row"><div class="app-row__info"><div class="app-row__name">No recent applications</div><div class="app-row__detail">No data available</div></div></div>';
            return;
        }

        root.innerHTML = list.map(function (item) {
            var first = item.first_name || '';
            var last = item.last_name || '';
            var fullName = (first + ' ' + last).trim() || 'Student Assistant';
            var studentCode = item.student_id || 'N/A';
            var program = item.program || 'N/A';
            var status = String(item.status || 'pending').toLowerCase();
            var dateText = formatDate(item.submitted_at);

            return '<div class="app-row">'
                + '<div class="app-row__avatar" aria-hidden="true">' + esc(initials(first, last)) + '</div>'
                + '<div class="app-row__info">'
                + '<div class="app-row__name">' + esc(fullName) + '</div>'
                + '<div class="app-row__detail">' + esc(studentCode) + ' • ' + esc(program) + '</div>'
                + '</div>'
                + '<div class="app-row__meta">'
                + '<span class="app-row__badge ' + statusClass(status) + '">' + esc(status) + '</span>'
                + '<div class="app-row__date">' + esc(dateText) + '</div>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    function renderTodayAttendance(list) {
        var root = document.getElementById('today-attendance-list');
        if (!root) {
            return;
        }

        if (!Array.isArray(list) || list.length === 0) {
            root.innerHTML = '<div class="att-row"><div class="att-row__info"><div class="att-row__name">No schedules for today</div><div class="att-row__location">Try again later</div></div><div class="att-row__time">-</div></div>';
            return;
        }

        root.innerHTML = list.map(function (item) {
            var first = item.first_name || '';
            var last = item.last_name || '';
            var fullName = (first + ' ' + last).trim() || 'Student Assistant';
            var office = item.office_name || 'Assigned Office';
            var status = String(item.status || '').toLowerCase();
            var checkedIn = status === 'present' || status === 'late' || status === 'incomplete' || !!item.time_in;
            var dotClass = checkedIn ? 'att-row__dot--present' : 'att-row__dot--absent';

            return '<div class="att-row">'
                + '<span class="att-row__dot ' + dotClass + '" aria-label="Attendance status"></span>'
                + '<div class="att-row__info">'
                + '<div class="att-row__name">' + esc(fullName) + '</div>'
                + '<div class="att-row__location">' + esc(office) + '</div>'
                + '</div>'
                + '<div class="att-row__time">' + esc(formatTime(item.time_in)) + '</div>'
                + '</div>';
        }).join('');
    }

    function renderUpcomingMeetings(list) {
        var root = document.getElementById('upcoming-meetings-list');
        if (!root) {
            return;
        }

        if (!Array.isArray(list) || list.length === 0) {
            root.innerHTML = '<div class="meeting-item"><div class="meeting-item__title">No upcoming meetings</div><div class="meeting-item__meta">Create one in the Meetings module.</div></div>';
            return;
        }

        root.innerHTML = list.map(function (item) {
            var title = esc(item.title || 'Meeting');
            var datePart = formatDate(item.meeting_date || item.starts_at);
            var timePart = formatTime(item.start_time || '');
            var location = item.location ? (' • ' + esc(item.location)) : '';

            return '<div class="meeting-item">'
                + '<div class="meeting-item__title">' + title + '</div>'
                + '<div class="meeting-item__meta">' + esc(datePart) + ' at ' + esc(timePart) + location + '</div>'
                + '</div>';
        }).join('');
    }

    function updateMetrics(payload) {
        var metrics = payload.metrics || {};

        var activeEl = document.getElementById('metric-active-students');
        var pendingEl = document.getElementById('metric-pending-applications');
        var hoursEl = document.getElementById('metric-monthly-hours');
        var ratingEl = document.getElementById('metric-avg-rating');
        var heroActiveEl = document.getElementById('hero-metric-active-students');
        var heroPendingEl = document.getElementById('hero-metric-pending-applications');
        var heroHoursEl = document.getElementById('hero-metric-monthly-hours');
        var heroRatingEl = document.getElementById('hero-metric-avg-rating');
        var navPendingEl = document.getElementById('nav-pending-count');

        if (activeEl) {
            activeEl.textContent = String(metrics.active_students ?? 0);
        }
        if (heroActiveEl) {
            heroActiveEl.textContent = String(metrics.active_students ?? 0);
        }
        if (pendingEl) {
            pendingEl.textContent = String(metrics.pending_applications ?? 0);
        }
        if (heroPendingEl) {
            heroPendingEl.textContent = String(metrics.pending_applications ?? 0);
        }
        if (hoursEl) {
            hoursEl.textContent = formatHours(metrics.monthly_hours);
        }
        if (heroHoursEl) {
            heroHoursEl.textContent = formatHours(metrics.monthly_hours);
        }
        if (ratingEl) {
            ratingEl.textContent = formatRating(metrics.avg_rating);
        }
        if (heroRatingEl) {
            heroRatingEl.textContent = formatRating(metrics.avg_rating);
        }
        if (navPendingEl) {
            navPendingEl.textContent = String(metrics.pending_applications ?? 0);
        }

        renderRecentApplications(payload.recent_applications || []);
        renderTodayAttendance(payload.today_attendance || []);
        renderUpcomingMeetings(payload.upcoming_meetings || []);
    }

    function refreshDashboard() {
        setConnectivity('checking', 'Checking...');
        fetch('dashboard_data.php?t=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) {
                    throw new Error((payload && payload.message) || 'Invalid response');
                }
                updateMetrics(payload);
                setConnectivity('online', 'Live');
            })
            .catch(function () {
                setConnectivity('offline', 'Offline');
            });
    }

    refreshDashboard();
    window.setInterval(refreshDashboard, 20000);
})();
</script>
<script src="../assets/js/admin-notifications.js?v=20260922"></script>

</body>
</html>