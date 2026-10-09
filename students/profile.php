<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function sams_profile_first_existing_column(PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        if ((int) $statement->fetchColumn() > 0) {
            return $column;
        }
    }

    return null;
}

function sams_profile_status_label(array $student): string
{
    if ((int) ($student['is_enrolled'] ?? 0) !== 1) {
        return 'Inactive Student';
    }

    if ((int) ($student['is_good_standing'] ?? 0) !== 1) {
        return 'Needs Review';
    }

    return 'Active Student Assistant';
}

function sams_profile_status_class(array $student): string
{
    if ((int) ($student['is_enrolled'] ?? 0) !== 1 || (int) ($student['is_good_standing'] ?? 0) !== 1) {
        return 'field__status-pill--red';
    }

    return 'field__status-pill--green';
}

function sams_profile_time_label(string $time): string
{
    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}

function sams_profile_skill_tags(?string $skills): array
{
    if ($skills === null || trim($skills) === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,;]+/', $skills) ?: [];
    $tags = [];

    foreach ($parts as $part) {
        $tag = trim($part);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return array_values(array_unique($tags));
}

function sams_profile_application_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
        default => 'Pending Review',
    };
}

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$userPhoneColumn = sams_column_exists($pdo, 'users', 'phone_number') ? 'u.phone_number' : 'NULL AS phone_number';
$studentStmt = $pdo->prepare(
    'SELECT s.student_id AS student_db_id, s.student_id_number, s.program, s.year_level, s.current_gpa, s.is_enrolled, s.is_good_standing, s.created_at AS student_created_at, s.nfc_uid, u.user_id AS user_db_id, u.email, u.first_name, u.last_name, ' . $userPhoneColumn . ', NULL AS profile_photo, u.created_at AS user_created_at
     FROM students s
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$studentStmt->execute(['user_id' => (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: ../login.php');
    exit;
}

// No need to fetch phone_number separately; now included in main query

$studentName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
if ($studentName === '') {
    $studentName = (string) ($currentUser['name'] ?? 'Student');
}

$studentCode = (string) ($student['student_id_number'] ?? '');
$studentEmail = (string) ($student['email'] ?? ($currentUser['email'] ?? ''));
$studentPhone = trim((string) ($student['phone_number'] ?? ''));
$studentProgram = trim((string) ($student['program'] ?? ''));
$studentYearLevel = trim((string) ($student['year_level'] ?? ''));
$studentGpa = $student['current_gpa'] !== null ? number_format((float) $student['current_gpa'], 2) : 'N/A';
$studentJoined = !empty($student['student_created_at']) ? date('F Y', strtotime((string) $student['student_created_at'])) : 'N/A';
$studentStatusLabel = sams_profile_status_label($student);
$studentStatusClass = sams_profile_status_class($student);
$studentDbId = (int) ($student['student_db_id'] ?? 0);
$applicationIdColumn = sams_profile_first_existing_column($pdo, 'applications', ['id', 'application_id']);
$latestApplication = null;
$skillTags = [];
$applicationOffice = '';
$applicationHoursPerWeek = null;
$applicationStatusLabel = 'No application on file';
$applicationStatusClass = 'field__status-pill--red';

if ($studentDbId > 0) {
    $applicationSql =
        'SELECT preferred_office, skills, available_hours_per_week, status, submitted_at
         FROM applications
         WHERE student_id = :student_id
         ORDER BY submitted_at DESC';

    if ($applicationIdColumn !== null) {
        $applicationSql .= ', ' . $applicationIdColumn . ' DESC';
    }

    $applicationSql .= ' LIMIT 1';

    $applicationStmt = $pdo->prepare(
        $applicationSql
    );
    $applicationStmt->execute(['student_id' => $studentDbId]);
    $latestApplication = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($latestApplication) {
        $skillTags = sams_profile_skill_tags((string) ($latestApplication['skills'] ?? ''));
        $applicationOffice = trim((string) ($latestApplication['preferred_office'] ?? ''));
        $applicationHoursPerWeek = $latestApplication['available_hours_per_week'] !== null ? (int) $latestApplication['available_hours_per_week'] : null;
        $applicationStatusLabel = sams_profile_application_status_label((string) ($latestApplication['status'] ?? 'pending'));
        $applicationStatusClass = stripos($applicationStatusLabel, 'approved') !== false
            ? 'field__status-pill--green'
            : 'field__status-pill--red';
    }
}
$scheduleRows = [];
$attendanceTotals = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'incomplete' => 0,
    'total' => 0,
];
$latestAttendance = null;

if ($studentDbId > 0) {
    $scheduleStmt = $pdo->prepare(
        'SELECT ds.duty_id AS id,
            COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name,
            ds.day_of_week, ds.start_time AS time_start, ds.end_time AS time_end, ds.status
         FROM duty_schedules ds
         LEFT JOIN applications a ON a.application_id = ds.application_id
         WHERE a.student_id = :student_id
         ORDER BY FIELD(ds.day_of_week, "Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"), ds.start_time ASC'
    );
    $scheduleStmt->execute(['student_id' => $studentDbId]);
    $scheduleRows = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalSchedules = count($scheduleRows);
    $totalDutyHours = 0.0;
    foreach ($scheduleRows as $scheduleRow) {
        $start = strtotime((string) ($scheduleRow['time_start'] ?? ''));
        $end = strtotime((string) ($scheduleRow['time_end'] ?? ''));
        if ($start && $end && $end > $start) {
            $totalDutyHours += ($end - $start) / 3600;
        }
    }

    $attendanceStmt = $pdo->prepare(
        'SELECT al.status, COUNT(*) AS total_count
         FROM attendance_logs al
         INNER JOIN applications a ON a.application_id = al.application_id
         WHERE a.student_id = :student_id
         GROUP BY al.status'
    );
    $attendanceStmt->execute(['student_id' => $studentDbId]);

    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendanceRow) {
        $status = (string) ($attendanceRow['status'] ?? '');
        $attendanceTotals[$status] = (int) ($attendanceRow['total_count'] ?? 0);
        $attendanceTotals['total'] += (int) ($attendanceRow['total_count'] ?? 0);
    }

    $latestAttendanceStmt = $pdo->prepare(
        'SELECT al.status, al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.late_minutes, al.notes AS remarks, al.created_at
         FROM attendance_logs al
         INNER JOIN applications a ON a.application_id = al.application_id
         WHERE a.student_id = :student_id
         ORDER BY al.log_id DESC
         LIMIT 1'
    );
    $latestAttendanceStmt->execute(['student_id' => $studentDbId]);
    $latestAttendance = $latestAttendanceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$acceptedSchedulesStmt = $pdo->prepare('SELECT COUNT(*) FROM duty_schedules ds INNER JOIN applications a ON a.application_id = ds.application_id WHERE a.student_id = :student_id AND ds.status = :status');
$acceptedSchedulesStmt->execute(['student_id' => $studentDbId, 'status' => 'accepted']);
$acceptedSchedules = (int) $acceptedSchedulesStmt->fetchColumn();

$recentAttendanceStmt = $pdo->prepare(
    'SELECT al.status, al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.late_minutes, al.notes AS remarks, al.created_at
     FROM attendance_logs al
     INNER JOIN applications a ON a.application_id = al.application_id
     WHERE a.student_id = :student_id
     ORDER BY al.log_id DESC
     LIMIT 5'
);
$recentAttendanceStmt->execute(['student_id' => $studentDbId]);
$recentAttendance = $recentAttendanceStmt->fetchAll(PDO::FETCH_ASSOC);

$attendanceRate = $attendanceTotals['total'] > 0 ? (int) round((($attendanceTotals['present'] + $attendanceTotals['late']) / $attendanceTotals['total']) * 100) : 0;
$attendanceCompleteRate = $attendanceTotals['total'] > 0 ? (int) round(($attendanceTotals['present'] / $attendanceTotals['total']) * 100) : 0;

$dashboardTitle = $studentName !== '' ? $studentName . ' | Profile' : 'My Profile | NU SAMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dashboardTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        /* ============================================================
           CSS VARIABLES — Design System
        ============================================================ */
        :root {
            /* Brand Blue */
            --clr-navy:             #003087;
            --clr-white:            #FFFFFF;
            --clr-text-primary:     #101828;
            --clr-text-body:        #364153;
            --clr-text-muted:       #4A5565;
            --clr-text-subtle:      #6A7282;
            --clr-text-light:       #BEDBFF;
            --clr-border:           #E5E7EB;
            --clr-border-card:      #F3F4F6;
            --clr-bg-page-s:        #EFF6FF;
            --clr-bg-page-e:        #FFFBEB;
            --clr-bg-muted:         #F9FAFB;
            --clr-bg-grey:          #F3F4F6;

            /* Accent */
            --clr-gold:             #FFB81C;
            --clr-green:            #00C950;
            --clr-green-dark:       #008236;
            --clr-green-bg:         #DCFCE7;
            --clr-blue-stat-s:      #EFF6FF;
            --clr-blue-stat-e:      #EEF2FF;
            --clr-gold-stat-s:      #FFFBEB;
            --clr-gold-stat-e:      #FFF7ED;
            --clr-red:              #E7000B;
            --clr-red-bg:           #FEF2F2;
            --clr-alert:            #FB2C36;
            --clr-blue-light:       #DBEAFE;

            /* Gradients */
            --grad-navy-v:          linear-gradient(180deg,
                                        #003087 0%,#00328B 10%,#00358E 20%,#003792 30%,
                                        #003995 40%,#003B99 50%,#003E9C 60%,#0040A0 70%,
                                        #0042A4 80%,#0045A7 90%,#0047AB 100%);
            --grad-navy-h:          linear-gradient(90deg,
                                        #003087 0%,#00328B 10%,#00358E 20%,#003792 30%,
                                        #003995 40%,#003B99 50%,#003E9C 60%,#0040A0 70%,
                                        #0042A4 80%,#0045A7 90%,#0047AB 100%);
            --grad-navy-135:        linear-gradient(135deg,
                                        #003087 0%,#00328B 10%,#00358E 20%,#003792 30%,
                                        #003995 40%,#003B99 50%,#003E9C 60%,#0040A0 70%,
                                        #0042A4 80%,#0045A7 90%,#0047AB 100%);
            --grad-navy-diag:       linear-gradient(155.57deg,
                                        #003087 0%,#00328B 10%,#00358E 20%,#003792 30%,
                                        #003995 40%,#003B99 50%,#003E9C 60%,#0040A0 70%,
                                        #0042A4 80%,#0045A7 90%,#0047AB 100%);

            /* Shadows */
            --shadow-sm:    0 1px 3px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.10);
            --shadow-md:    0 10px 15px rgba(0,0,0,.10), 0 4px 6px rgba(0,0,0,.10);

            /* Sidebar */
            --sidebar-width: 288px;

            /* Typography */
            --fs-xs:   12px;
            --fs-sm:   14px;
            --fs-base: 16px;
            --fs-md:   18px;
            --fs-lg:   20px;
            --fs-xl:   24px;
            --fs-2xl:  30px;
            --fs-3xl:  36px;

            /* Spacing */
            --sp-4:    4px;
            --sp-8:    8px;
            --sp-12:   12px;
            --sp-16:   16px;
            --sp-24:   24px;
            --sp-32:   32px;

            /* Radius */
            --radius-sm:   10px;
            --radius-md:   14px;
            --radius-lg:   16px;
            --radius-pill: 9999px;
        }

        /* ============================================================
           RESET & BASE
        ============================================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; }
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(136.4deg, #EFF6FF 0%, #FFFFFF 50%, #FFFBEB 100%);
            min-height: 100vh;
            display: flex;
        }
        a { text-decoration: none; color: inherit; }
        button { cursor: pointer; font-family: inherit; border: none; background: none; }
        img { display: block; max-width: 100%; }
        ul { list-style: none; }

        /* ============================================================
           APP LAYOUT
        ============================================================ */
        .app { display: flex; width: 100%; min-height: 100vh; }

        /* ============================================================
           SIDEBAR
        ============================================================ */
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--grad-navy-v);
            box-shadow: 0 25px 50px rgba(0,0,0,.25);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        .sidebar__header {
            border-bottom: 1px solid rgba(255,255,255,.20);
            padding: var(--sp-24) var(--sp-24) 0;
            height: 105px;
            flex-shrink: 0;
        }

        .sidebar__brand {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 48px;
        }

        .sidebar__logo {
            width: 48px;
            height: 48px;
            background: var(--clr-white);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar__logo-text {
            font-size: var(--fs-xl);
            font-weight: 900;
            color: var(--clr-navy);
            line-height: 32px;
        }

        .sidebar__brand-info { display: flex; flex-direction: column; }

        .sidebar__app-name {
            font-size: var(--fs-lg);
            font-weight: 900;
            color: var(--clr-white);
            line-height: 28px;
        }

        .sidebar__app-sub {
            font-size: var(--fs-xs);
            font-weight: 400;
            color: var(--clr-text-light);
            line-height: 16px;
            white-space: nowrap;
        }

        .sidebar__nav {
            flex: 1;
            padding: var(--sp-24) var(--sp-16) 0;
        }

        .nav__list { display: flex; flex-direction: column; gap: var(--sp-8); }
        .nav__item { display: block; }

        .nav__link {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 48px;
            padding-left: var(--sp-16);
            border-radius: var(--radius-md);
            transition: background 0.15s;
        }
        .nav__link:hover { background: rgba(255,255,255,.10); }
        .nav__link--active {
            background: var(--clr-white);
            box-shadow: var(--shadow-md);
        }

        .nav__icon { width: 20px; height: 20px; flex-shrink: 0; }
        .nav__icon svg { width: 100%; height: 100%; }

        .nav__label {
            font-size: var(--fs-base);
            font-weight: 700;
            color: var(--clr-white);
            line-height: 24px;
            white-space: nowrap;
        }
        .nav__link--active .nav__label { color: var(--clr-navy); }

        .sidebar__footer {
            border-top: 1px solid rgba(255,255,255,.20);
            padding: 17px var(--sp-16) var(--sp-16);
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
            flex-shrink: 0;
            background: #003087;
            position: relative;
            z-index: 2;
        }

        .sidebar__user-card {
            background: #0047ab;
            border: 1px solid rgba(255,255,255,.22);
            border-radius: var(--radius-md);
            padding: var(--sp-16) var(--sp-16) var(--sp-8);
            display: flex;
            flex-direction: column;
            gap: 2px;
            height: 92px;
        }

        .sidebar__user-label {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: #dbeafe;
            line-height: 20px;
        }

        .sidebar__user-name {
            font-size: var(--fs-base);
            font-weight: 900;
            color: var(--clr-white);
            line-height: 24px;
        }

        .sidebar__user-id {
            font-size: var(--fs-xs);
            font-weight: 400;
            color: #dbeafe;
            line-height: 16px;
        }

        .sidebar__logout {
            display: flex;
            align-items: center;
            gap: var(--sp-8);
            height: 48px;
            padding-left: var(--sp-16);
            border-radius: var(--radius-md);
            background: #0047ab;
            border: 1px solid rgba(255,255,255,.22);
            width: 100%;
            transition: background 0.15s;
        }
        .sidebar__logout:hover { background: rgba(255,255,255,.18); }
        .sidebar__logout-icon { width: 20px; height: 20px; flex-shrink: 0; }
        .sidebar__logout-icon svg { width: 100%; height: 100%; }
        .sidebar__logout-label {
            font-size: var(--fs-base);
            font-weight: 700;
            color: var(--clr-white);
            line-height: 24px;
        }

        /* Hamburger */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
            flex-shrink: 0;
        }
        .hamburger:hover { background: rgba(0,0,0,.06); }
        .hamburger__bar {
            width: 20px;
            height: 2px;
            background: var(--clr-text-muted);
            border-radius: 2px;
            transition: transform 0.25s, opacity 0.25s;
        }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(2) { opacity: 0; }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 99;
        }
        .sidebar-overlay--hidden  { display: none; }
        .sidebar-overlay--visible { display: block; }

        /* ============================================================
           MAIN
        ============================================================ */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; height: 100vh; min-height: 0; overflow-y: auto; overflow-x: hidden; }

        /* ============================================================
           TOP BAR
        ============================================================ */
        .topbar {
            background: var(--clr-white);
            border-bottom: 1px solid var(--clr-border);
            box-shadow: var(--shadow-sm);
            height: 85px;
            padding: var(--sp-16) var(--sp-32);
            display: flex;
            align-items: center;
            flex-shrink: 0;
            position: relative;
            z-index: 100;
        }

        .topbar__inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 52px;
        }

        .topbar__left { display: flex; align-items: center; gap: var(--sp-12); }

        .topbar__heading { display: flex; flex-direction: column; }

        .topbar__title {
            font-size: var(--fs-xl);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 32px;
        }

        .topbar__subtitle {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-muted);
            line-height: 20px;
            white-space: nowrap;
        }

        .topbar__actions { display: flex; align-items: center; gap: var(--sp-8); }

        .topbar__action-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: background 0.15s;
        }
        .topbar__action-btn:hover { background: var(--clr-bg-muted); }
        .topbar__action-btn svg { width: 24px; height: 24px; }

        .topbar__badge {
            position: absolute;
            top: 0;
            right: 0;
            width: 12px;
            height: 12px;
            background: var(--clr-alert);
            border: 2px solid var(--clr-white);
            border-radius: 50%;
        }

        /* ============================================================
           PAGE CONTENT
        ============================================================ */
        .page-content {
            flex: 1;
            padding: var(--sp-32) 74.5px var(--sp-32) 59.5px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-32);
        }

        /* ============================================================
           PAGE HEADER
        ============================================================ */
        .page-header { display: flex; flex-direction: column; gap: var(--sp-8); }

        .page-header__title {
            font-size: var(--fs-2xl);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 36px;
        }

        .page-header__subtitle {
            font-size: var(--fs-base);
            font-weight: 500;
            color: var(--clr-text-muted);
            line-height: 24px;
        }

        /* ============================================================
           PROFILE LAYOUT GRID
        ============================================================ */
        .profile-grid {
            display: grid;
            grid-template-columns: 362.656px 1fr;
            gap: 32px;
            align-items: start;
        }

        /* ============================================================
           LEFT COLUMN — Avatar Card
        ============================================================ */
        .avatar-card {
            background: var(--clr-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: var(--sp-32);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
        }

        /* Avatar circle */
        .avatar-card__avatar {
            width: 128px;
            height: 128px;
            border-radius: 50%;
            border: 4px solid var(--clr-gold);
            background: var(--grad-navy-135);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-bottom: 16px;
        }

        .avatar-card__avatar svg { width: 64px; height: 64px; }

        .avatar-card__name {
            font-size: var(--fs-xl);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 32px;
            text-align: center;
            margin-bottom: 8px;
        }

        .avatar-card__id {
            font-size: var(--fs-base);
            font-weight: 700;
            color: var(--clr-text-muted);
            line-height: 24px;
            text-align: center;
            margin-bottom: 8px;
        }

        /* Active badge */
        .avatar-card__status-badge {
            background: var(--clr-green-bg);
            color: var(--clr-green-dark);
            font-size: var(--fs-sm);
            font-weight: 900;
            line-height: 20px;
            padding: 8px 16px;
            border-radius: var(--radius-pill);
            height: 36px;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            margin-bottom: var(--sp-24);
        }

        /* Stat mini-cards */
        .avatar-card__stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            width: 100%;
            margin-bottom: var(--sp-24);
        }

        .avatar-stat {
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-4);
            align-items: center;
        }

        .avatar-stat--blue { background: linear-gradient(148.09deg, #EFF6FF 0%, #EEF2FF 100%); }
        .avatar-stat--gold { background: linear-gradient(148.09deg, #FFFBEB 0%, #FFF7ED 100%); }

        .avatar-stat__value {
            font-size: var(--fs-2xl);
            font-weight: 900;
            line-height: 36px;
            text-align: center;
        }

        .avatar-stat--blue .avatar-stat__value { color: var(--clr-navy); }
        .avatar-stat--gold .avatar-stat__value { color: var(--clr-gold); }

        .avatar-stat__label {
            font-size: var(--fs-xs);
            font-weight: 700;
            color: var(--clr-text-muted);
            line-height: 16px;
            text-align: center;
        }

        /* CTA Buttons */
        .avatar-card__actions {
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
            width: 100%;
        }

        .btn-edit-profile {
            height: 48px;
            border-radius: var(--radius-md);
            background: var(--grad-navy-h);
            color: var(--clr-white);
            font-size: var(--fs-base);
            font-weight: 700;
            line-height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--sp-8);
            width: 100%;
            transition: opacity 0.15s;
        }
        .btn-edit-profile:hover { opacity: .88; }
        .btn-edit-profile svg { width: 20px; height: 20px; flex-shrink: 0; }

        .btn-change-pin {
            height: 52px;
            border-radius: var(--radius-md);
            border: 2px solid var(--clr-navy);
            background: var(--clr-white);
            color: var(--clr-navy);
            font-size: var(--fs-base);
            font-weight: 700;
            line-height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--sp-8);
            width: 100%;
            transition: background 0.15s;
        }
        .btn-change-pin:hover { background: #F0F4FF; }
        .btn-change-pin svg { width: 20px; height: 20px; flex-shrink: 0; }

        /* ============================================================
           RIGHT COLUMN — Info Cards
        ============================================================ */
        .right-col {
            display: flex;
            flex-direction: column;
            gap: var(--sp-24);
        }

        /* Shared card base */
        .info-card {
            background: var(--clr-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: var(--sp-32);
            display: flex;
            flex-direction: column;
            gap: var(--sp-24);
        }

        /* Card heading row */
        .info-card__heading {
            display: flex;
            align-items: center;
            gap: var(--sp-8);
            height: 32px;
        }

        .info-card__heading-icon { width: 28px; height: 28px; flex-shrink: 0; }
        .info-card__heading-icon svg { width: 100%; height: 100%; }

        .info-card__title {
            font-size: var(--fs-xl);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 32px;
            white-space: nowrap;
        }

        .info-card__title--white { color: var(--clr-white); }

        /* Fields grid */
        .fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px 24px;
        }

        .field { display: flex; flex-direction: column; gap: var(--sp-8); }

        .field__label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: var(--fs-sm);
            font-weight: 700;
            color: var(--clr-text-muted);
            line-height: 20px;
        }

        .field__label svg { width: 16px; height: 16px; flex-shrink: 0; }

        .field__value {
            font-size: var(--fs-md);
            font-weight: 700;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        .field__hint {
            font-size: var(--fs-xs);
            font-weight: 400;
            color: var(--clr-text-subtle);
            line-height: 16px;
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            padding: 0 12px;
            border-radius: var(--radius-pill);
            background: #f3f4f6;
            color: var(--clr-text-body);
            font-size: var(--fs-sm);
            font-weight: 700;
            line-height: 20px;
        }

        .qual-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .qual-summary__item {
            background: var(--clr-bg-muted);
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .qual-summary__label {
            font-size: var(--fs-xs);
            font-weight: 700;
            color: var(--clr-text-muted);
            line-height: 16px;
        }

        .qual-summary__value {
            font-size: var(--fs-base);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 24px;
        }

        .qual-note {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-subtle);
            line-height: 20px;
        }

        /* Status pill inside a field */
        .field__status-pill {
            display: inline-flex;
            align-items: center;
            height: 28px;
            padding: 4px 12px;
            border-radius: var(--radius-pill);
            font-size: var(--fs-sm);
            font-weight: 900;
            white-space: nowrap;
        }

        .field__status-pill--green { background: var(--clr-green-bg); color: var(--clr-green-dark); }
        .field__status-pill--red { background: var(--clr-red-bg); color: var(--clr-red); }

        /* ============================================================
           PERFORMANCE CARD (navy gradient)
        ============================================================ */
        .perf-card {
            background: var(--grad-navy-diag);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: var(--sp-32);
            display: flex;
            flex-direction: column;
            gap: var(--sp-24);
        }

        .perf-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .perf-stat {
            background: rgba(255,255,255,.10);
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-4);
            height: 92px;
        }

        .perf-stat__label {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-light);
            line-height: 20px;
        }

        .perf-stat__value {
            font-size: var(--fs-2xl);
            font-weight: 900;
            color: var(--clr-white);
            line-height: 36px;
        }

        /* Achievement row */
        .achievement {
            background: rgba(255,255,255,.10);
            border-radius: var(--radius-md);
            padding: var(--sp-16);
            display: flex;
            flex-direction: column;
            gap: var(--sp-8);
        }

        .achievement__label {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-light);
            line-height: 20px;
        }

        .achievement__row {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 48px;
        }

        .achievement__icon-wrap {
            width: 48px;
            height: 48px;
            background: var(--clr-gold);
            border-radius: var(--radius-pill);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .achievement__icon-wrap svg { width: 24px; height: 24px; }

        .achievement__info { display: flex; flex-direction: column; }

        .achievement__title {
            font-size: var(--fs-md);
            font-weight: 900;
            color: var(--clr-white);
            line-height: 28px;
        }

        .achievement__subtitle {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-blue-light);
            line-height: 20px;
            white-space: nowrap;
        }

        /* ============================================================
           ACCOUNT ACTIONS CARD
        ============================================================ */
        .actions-card {
            background: var(--clr-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: var(--sp-32);
            display: flex;
            flex-direction: column;
            gap: var(--sp-16);
        }

        .actions-card__title {
            font-size: var(--fs-lg);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        .actions-card__buttons {
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
        }

        .btn-action {
            height: 48px;
            border-radius: var(--radius-md);
            font-size: var(--fs-base);
            font-weight: 700;
            line-height: 24px;
            padding: 0 var(--sp-16);
            text-align: left;
            display: flex;
            align-items: center;
            gap: var(--sp-8);
            transition: background 0.15s;
        }

        .btn-action--grey {
            background: var(--clr-bg-muted);
            color: var(--clr-text-body);
        }
        .btn-action--grey:hover { background: #F3F4F6; }

        .btn-action--red {
            background: var(--clr-red-bg);
            color: var(--clr-red);
        }
        .btn-action--red:hover { background: #FEE2E2; }
        .btn-action--red svg { width: 20px; height: 20px; flex-shrink: 0; }

        /* ============================================================
           RESPONSIVE — TABLET (≤1024px)
        ============================================================ */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed;
                left: -100%;
                top: 0;
                height: 100vh;
                transition: left 0.28s ease;
                z-index: 200;
            }
            .sidebar--open { left: 0; }
            .hamburger { display: flex; }
            .topbar { padding: var(--sp-16); }
            .page-content { padding: var(--sp-16); }
            .profile-grid { grid-template-columns: 1fr; }
            .avatar-card { max-width: 400px; margin: 0 auto; }
            .perf-stats { grid-template-columns: repeat(3, 1fr); }
        }

        /* ============================================================
           RESPONSIVE — MOBILE (≤768px)
        ============================================================ */
        @media (max-width: 768px) {
            .topbar__title { font-size: 18px; }
            .topbar__subtitle { font-size: var(--fs-xs); }
            .page-content { padding: var(--sp-12); gap: var(--sp-16); }
            .topbar { height: 72px; }
            .page-header__title { font-size: var(--fs-xl); }
            .profile-grid { grid-template-columns: 1fr; gap: var(--sp-16); }
            .avatar-card { max-width: 100%; }
            .fields-grid { grid-template-columns: 1fr; gap: var(--sp-16); }
            .perf-stats { grid-template-columns: 1fr; gap: var(--sp-12); }
            .info-card { padding: var(--sp-16); gap: var(--sp-16); }
            .perf-card { padding: var(--sp-16); gap: var(--sp-16); }
            .actions-card { padding: var(--sp-16); }
        }
    </style>
<link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
</head>
<body>

<div class="sidebar-overlay sidebar-overlay--hidden" id="sidebarOverlay"></div>

<div class="app">

 
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Student portal navigation">

        <div class="sidebar__header">
            <div class="sidebar__brand">
                <div class="sidebar__logo" aria-hidden="true">
                    <span class="sidebar__logo-text">NU</span>
                </div>
                <div class="sidebar__brand-info">
                    <span class="sidebar__app-name">SAMS</span>
                    <span class="sidebar__app-sub">Student Assistant Management</span>
                </div>
            </div>
        </div>

        <nav class="sidebar__nav" aria-label="Main menu">
            <ul class="nav__list">
                <li class="nav__item">
                    <a href="dashboard.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 11.5L12 4l9 7.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff"/>
                                <path d="M5 10.5V20h5v-5h4v5h5v-9.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff"/>
                            </svg>
                        </span>
                        <span class="nav__label">Dashboard</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="schedule.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="4" y="5" width="16" height="15" rx="2" stroke="#101828" stroke-width="1.8" fill="#ffffff"/>
                                <path d="M8 3v4M16 3v4M4 9h16" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="none"/>
                            </svg>
                        </span>
                        <span class="nav__label">My Schedule</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="temporary_duty_request.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 4v16M4 12h16" stroke="#101828" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span class="nav__label">Temporary Duty Request</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="duty_excuse.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 12h6M9 16h4M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="#ffffff"/>
                                <path d="M9 7h2" stroke="#101828" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span class="nav__label">Duty Excuse</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="attendance_history.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M5 4h10l4 4v12H5z" stroke="#101828" stroke-width="1.8" stroke-linejoin="round" fill="#ffffff"/>
                                <path d="M15 4v4h4" stroke="#101828" stroke-width="1.8" stroke-linejoin="round" fill="#ffffff"/>
                                <path d="M8 11h8M8 15h8" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="none"/>
                            </svg>
                        </span>
                        <span class="nav__label">Duty-Hour Report</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="profile.php" class="nav__link nav__link--active" aria-current="page">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="8" r="3.2" stroke="#101828" stroke-width="1.8" fill="#ffffff"/>
                                <path d="M6.5 19c1.4-3.1 4-4.8 5.5-4.8S15.6 15.9 17 19" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff"/>
                            </svg>
                        </span>
                        <span class="nav__label">Profile</span>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="sidebar__footer">
            <div class="sidebar__user-card">
                <span class="sidebar__user-label">Logged in as</span>
                <span class="sidebar__user-name"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="sidebar__user-id">Student ID: <?php echo htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <button class="sidebar__logout" type="button" onclick="window.location.href='logout.php'">
                <span class="sidebar__logout-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3" stroke="rgba(255,255,255,0.85)" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M13 14l3-4-3-4M16 10H7" stroke="rgba(255,255,255,0.85)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="sidebar__logout-label">Logout</span>
            </button>
        </div>
    </aside>

    <!-- ================================================================
         MAIN
    ================================================================ -->
    <main class="main">

        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar__inner">
                <div class="topbar__left">
                    <button class="hamburger" id="hamburgerBtn" type="button" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
                        <span class="hamburger__bar"></span>
                        <span class="hamburger__bar"></span>
                        <span class="hamburger__bar"></span>
                    </button>
                    <div class="topbar__heading">
                        <span class="topbar__title">Student Portal</span>
                        <span class="topbar__subtitle">National University - Lipa Campus</span>
                    </div>
                </div>
                <div class="topbar__actions">
                    <a href="#" class="topbar__action-btn" aria-label="Notifications">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" stroke="#4A5565" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="topbar__badge" style="display:none;" aria-label="New notifications"></span>
                    </a>
                    <script>window.SAMS_CSRF = '<?php echo addslashes(sams_csrf_token()); ?>';</script>
                    <a href="#" class="topbar__action-btn" aria-label="Settings">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37a1.724 1.724 0 002.572-1.065z" stroke="#4A5565" stroke-width="1.8"/>
                            <circle cx="12" cy="12" r="3" stroke="#4A5565" stroke-width="1.8"/>
                        </svg>
                    </a>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <section class="page-content" aria-label="My Profile">

            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-header__title">My Profile</h1>
                <p class="page-header__subtitle">Manage your personal information and account settings</p>
            </div>

            <!-- Profile Grid -->
            <div class="profile-grid">

                <!-- ── LEFT: Avatar Card ── -->
                <div class="avatar-card" role="region" aria-label="Profile overview">

                    <!-- Avatar -->
                    <div class="avatar-card__avatar" aria-label="Profile avatar">
                        <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="32" cy="22" r="14" fill="rgba(255,255,255,0.85)"/>
                            <path d="M6 58c0-14.359 11.640-26 26-26s26 11.641 26 26" fill="rgba(255,255,255,0.85)"/>
                        </svg>
                    </div>

                    <!-- Name & ID -->
                    <h2 class="avatar-card__name"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="avatar-card__id"><?php echo htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8'); ?></p>

                    <!-- Status Badge -->
                    <span class="avatar-card__status-badge" role="status"><?php echo htmlspecialchars($studentStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>

                    <!-- Stats -->
                    <div class="avatar-card__stats">
                        <div class="avatar-stat avatar-stat--blue">
                            <span class="avatar-stat__value"><?php echo htmlspecialchars(number_format($totalDutyHours, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="avatar-stat__label">Total Duty Hours</span>
                        </div>
                        <div class="avatar-stat avatar-stat--gold">
                            <span class="avatar-stat__value"><?php echo (int) $acceptedSchedules; ?></span>
                            <span class="avatar-stat__label">Accepted Duties</span>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="avatar-card__actions">
                        <button class="btn-edit-profile" type="button" onclick="document.getElementById('personal-information-card').scrollIntoView({behavior:'smooth', block:'start'});">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" fill="white"/>
                            </svg>
                            View Profile
                        </button>
                        <button class="btn-change-pin" type="button" onclick="window.location.href='../change_password.php'">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="4" y="9" width="12" height="9" rx="2" stroke="#003087" stroke-width="1.5"/>
                                <path d="M7 9V6a3 3 0 016 0v3" stroke="#003087" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="10" cy="13.5" r="1.5" fill="#003087"/>
                            </svg>
                            Change Password
                        </button>
                    </div>
                </div>

                <!-- ── RIGHT: Info Cards ── -->
                <div class="right-col">

                    <!-- Personal Information -->
                    <div class="info-card" id="personal-information-card" role="region" aria-label="Personal Information">
                        <div class="info-card__heading">
                            <span class="info-card__heading-icon" aria-hidden="true">
                                <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="14" cy="9" r="6" stroke="#003087" stroke-width="1.8"/>
                                    <path d="M2 25c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#003087" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </span>
                            <h2 class="info-card__title">Personal Information</h2>
                        </div>

                        <div class="fields-grid">
                            <!-- Full Name -->
                            <div class="field">
                                <span class="field__label">Full Name</span>
                                <span class="field__value"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <!-- Student ID -->
                            <div class="field">
                                <span class="field__label">Student ID</span>
                                <span class="field__value"><?php echo htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="field__hint">Cannot be changed</span>
                            </div>
                            <!-- Email -->
                            <div class="field">
                                <span class="field__label">
                                    <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect x="1" y="3" width="14" height="10" rx="2" stroke="#4A5565" stroke-width="1.3"/>
                                        <path d="M1 5l7 5 7-5" stroke="#4A5565" stroke-width="1.3" stroke-linecap="round"/>
                                    </svg>
                                    Email Address
                                </span>
                                <span class="field__value"><?php echo htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <!-- Contact -->
                            <div class="field">
                                <span class="field__label">
                                    <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3 2h3l1 3-1.5 1.5a11 11 0 004 4L11 9l3 1v3a1 1 0 01-1 1A12 12 0 012 3a1 1 0 011-1z" stroke="#4A5565" stroke-width="1.2"/>
                                    </svg>
                                    Contact Number
                                </span>
                                <span class="field__value"><?php echo htmlspecialchars($studentPhone !== '' ? $studentPhone : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Academic Information -->
                    <div class="info-card" role="region" aria-label="Academic Information">
                        <div class="info-card__heading">
                            <span class="info-card__heading-icon" aria-hidden="true">
                                <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M14 3L2 9l12 6 12-6-12-6z" stroke="#003087" stroke-width="1.8" stroke-linejoin="round"/>
                                    <path d="M6 12v6c0 2.21 3.582 4 8 4s8-1.79 8-4v-6" stroke="#003087" stroke-width="1.8" stroke-linecap="round"/>
                                    <path d="M24 9v6" stroke="#003087" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </span>
                            <h2 class="info-card__title">Academic Information</h2>
                        </div>

                        <div class="fields-grid">
                            <!-- Course -->
                            <div class="field">
                                <span class="field__label">Course/Program</span>
                                <span class="field__value"><?php echo htmlspecialchars($studentProgram !== '' ? $studentProgram : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <!-- Year -->
                            <div class="field">
                                <span class="field__label">Year Level</span>
                                <span class="field__value"><?php echo htmlspecialchars($studentYearLevel !== '' ? $studentYearLevel . ' Year' : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <!-- Date Joined -->
                            <div class="field">
                                <span class="field__label">
                                    <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect x="1" y="3" width="14" height="12" rx="2" stroke="#4A5565" stroke-width="1.2"/>
                                        <path d="M5 1v4M11 1v4" stroke="#4A5565" stroke-width="1.2" stroke-linecap="round"/>
                                        <path d="M1 7h14" stroke="#4A5565" stroke-width="1.1"/>
                                    </svg>
                                    Date Joined SAMS
                                </span>
                                <span class="field__value"><?php echo htmlspecialchars($studentJoined, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <!-- Status -->
                            <div class="field">
                                <span class="field__label">
                                    <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <circle cx="8" cy="8" r="6" stroke="#4A5565" stroke-width="1.2"/>
                                        <path d="M5 8.5l2 2 4-4" stroke="#4A5565" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Status
                                </span>
                                <span class="field__status-pill <?php echo htmlspecialchars($studentStatusClass, ENT_QUOTES, 'UTF-8'); ?>" role="status"><?php echo htmlspecialchars($studentStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>



                    <!-- Skills & Qualifications -->
                    <div class="info-card" role="region" aria-label="Skills and Qualifications">
                        <div class="info-card__heading">
                            <span class="info-card__heading-icon" aria-hidden="true">
                                <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M14 3l3.5 7.1 7.8 1.1-5.6 5.5 1.3 7.8L14 20.7 7 24.5l1.3-7.8-5.6-5.5 7.8-1.1L14 3z" stroke="#003087" stroke-width="1.8" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <h2 class="info-card__title">Skills &amp; Qualifications</h2>
                        </div>

                        <div class="fields-grid">
                            <div class="field">
                                <span class="field__label">Assigned Role / Office</span>
                                <span class="field__value"><?php echo htmlspecialchars($applicationOffice !== '' ? $applicationOffice : 'Not assigned yet', ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="field__hint">Based on your latest application and current schedule</span>
                            </div>
                            <div class="field">
                                <span class="field__label">Application Status</span>
                                <span class="field__status-pill <?php echo htmlspecialchars($applicationStatusClass, ENT_QUOTES, 'UTF-8'); ?>" role="status"><?php echo htmlspecialchars($applicationStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="field__hint">Reviewed by the student affairs team</span>
                            </div>
                        </div>

                        <div class="qual-summary">
                            <div class="qual-summary__item">
                                <span class="qual-summary__label">Availability per Week</span>
                                <span class="qual-summary__value"><?php echo $applicationHoursPerWeek !== null ? (int) $applicationHoursPerWeek . ' hrs' : 'Not set'; ?></span>
                            </div>
                            <div class="qual-summary__item">
                                <span class="qual-summary__label">Profile Approval</span>
                                <span class="qual-summary__value"><?php echo htmlspecialchars($applicationStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="qual-summary__item">
                                <span class="qual-summary__label">Assignment Readiness</span>
                                <span class="qual-summary__value"><?php echo empty($skillTags) ? 'Incomplete' : 'Ready'; ?></span>
                            </div>
                        </div>

                        <div class="field">
                            <span class="field__label">Skills</span>
                            <?php if (empty($skillTags)): ?>
                                <span class="qual-note">No skills have been recorded yet.</span>
                            <?php else: ?>
                                <div class="chips">
                                    <?php foreach ($skillTags as $skillTag): ?>
                                        <span class="chip"><?php echo htmlspecialchars($skillTag, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <p class="qual-note">Profile changes stay under admin oversight. If your skills or assignment details are outdated, ask the SDAO office to review your record.</p>
                    </div>

                    <!-- Performance Summary -->
                    <div class="perf-card" role="region" aria-label="Performance Summary">
                        <div class="info-card__heading">
                            <span class="info-card__heading-icon" aria-hidden="true">
                                <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="14" cy="10" r="6" stroke="#FFB81C" stroke-width="1.8"/>
                                    <path d="M14 4v1M14 15v1M8 10H7M21 10h-1M9.172 5.172l-.707.707M19.535 15.535l-.707.707M9.172 14.828l-.707-.707M19.535 4.465l-.707-.707" stroke="#FFB81C" stroke-width="1.5" stroke-linecap="round"/>
                                    <path d="M10 20l-2 6h12l-2-6" stroke="#FFB81C" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <h2 class="info-card__title info-card__title--white">Performance Summary</h2>
                        </div>

                        <div class="perf-stats">
                            <div class="perf-stat">
                                <span class="perf-stat__label">Total Duty Hours</span>
                                <span class="perf-stat__value"><?php echo htmlspecialchars(number_format($totalDutyHours, 1), ENT_QUOTES, 'UTF-8'); ?> hrs</span>
                            </div>
                            <div class="perf-stat">
                                <span class="perf-stat__label">Assigned Duties</span>
                                <span class="perf-stat__value"><?php echo (int) $totalSchedules; ?></span>
                            </div>
                            <div class="perf-stat">
                                <span class="perf-stat__label">Attendance Rate</span>
                                <span class="perf-stat__value"><?php echo (int) $attendanceRate; ?>%</span>
                            </div>
                        </div>

                        <div class="achievement">
                            <span class="achievement__label">Recent Achievement</span>
                            <div class="achievement__row">
                                <div class="achievement__icon-wrap" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M12 2l2.09 4.26L19 7.27l-3.5 3.41.83 4.82L12 13.27l-4.33 2.23.83-4.82L5 7.27l4.91-.71L12 2z" fill="white"/>
                                    </svg>
                                </div>
                                <div class="achievement__info">
                                    <span class="achievement__title"><?php echo $attendanceTotals['absent'] === 0 ? 'Perfect Attendance!' : 'Keep Improving!'; ?></span>
                                    <span class="achievement__subtitle"><?php echo $attendanceTotals['absent'] === 0 ? 'No missed duties recorded yet 🎉' : 'You have ' . (int) $attendanceTotals['absent'] . ' missed duty log(s)'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Actions -->
                    <div class="actions-card" role="region" aria-label="Account Actions">
                        <h2 class="actions-card__title">Account Actions</h2>
                        <div class="actions-card__buttons">
                            <button class="btn-action btn-action--grey" type="button">Download My Data</button>
                            <button class="btn-action btn-action--grey" type="button">Privacy Settings</button>
                            <button class="btn-action btn-action--red" type="button" onclick="window.location.href='logout.php'">
                                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3" stroke="#E7000B" stroke-width="1.5" stroke-linecap="round"/>
                                    <path d="M13 14l3-4-3-4M16 10H7" stroke="#E7000B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Logout from SAMS
                            </button>
                        </div>
                    </div>

                </div><!-- /right-col -->
            </div><!-- /profile-grid -->

        </section>
    </main>
</div><!-- /app -->

<script>
(function () {
    'use strict';
    var originalNfcUid = <?php echo json_encode((string) ($student['nfc_uid'] ?? '')); ?>;

    var hamburger = document.getElementById('hamburgerBtn');
    var sidebar   = document.getElementById('sidebar');
    var overlay   = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('sidebar--open');
        overlay.classList.remove('sidebar-overlay--hidden');
        overlay.classList.add('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('sidebar--open');
        overlay.classList.add('sidebar-overlay--hidden');
        overlay.classList.remove('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    if (hamburger) hamburger.addEventListener('click', function () {
        sidebar.classList.contains('sidebar--open') ? closeSidebar() : openSidebar();
    });

    if (overlay) overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('sidebar--open')) {
            closeSidebar();
            hamburger && hamburger.focus();
        }
    });

    // Handle Page Auto-Reload
    var reloadTimer = window.setInterval(function () {
        window.location.reload();
    }, 30000);



})();
</script>
<script src="../assets/js/student-notifications.js?v=20260922"></script>

</body>
</html>