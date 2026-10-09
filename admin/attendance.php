<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}
$admin_name = (string) ($currentUser['name'] ?? 'SAMS Admin');

$pdo = sams_pdo();
$attendanceOfficeOptions = array_merge(sams_office_options(), ['Unassigned']);

$applicationBadgeCount = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'")->fetchColumn();

$currentDay = date('l');
$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);

function sams_admin_attendance_display_name(array $row): string
{
    $fullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return $fullName !== '' ? $fullName : 'Unassigned Student';
}

function sams_admin_attendance_dot(string $status): string
{
    $status = strtolower($status);
    return match ($status) {
        'completed', 'present' => 'green',
        'active', 'late' => 'blue',
        'excused' => 'amber',
        default => 'grey',
    };
}

$todayRows = [];
if ($activeTermId > 0) {
    $todayRowsStmt = $pdo->prepare(
        'SELECT u.first_name, u.last_name, s.student_id AS student_code,
                COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name,
                al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, ds.start_time,
                de.excuse_id, de.excuse_type
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         LEFT JOIN duty_excuses de ON (de.application_id = ds.application_id AND de.duty_date = CURDATE())
         LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT al2.log_id
             FROM attendance_logs al2
             WHERE al2.application_id = ds.application_id
               AND al2.duty_id = ds.duty_id
               AND DATE(al2.created_at) = CURDATE()
             ORDER BY al2.log_id DESC
             LIMIT 1
         )
         WHERE ds.day_of_week = :day
           AND ds.status = "deployed"
           AND ds.term_id = :term_id
         ORDER BY ds.start_time ASC'
    );
    $todayRowsStmt->execute([
        'day' => $currentDay,
        'term_id' => $activeTermId,
    ]);
    $todayRows = $todayRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$attendance_rows = [];
$activeNow = 0;
$completedToday = 0;
$recordedSeconds = 0;
$totalSchedules = count($todayRows);
$officeSummary = [];

foreach ($todayRows as $row) {
    $status = sams_attendance_display_status((string) ($row['status'] ?? ''));
    if ($status === '') {
        $status = !empty($row['excuse_id']) ? 'excused' : 'absent';
    }

    $timeIn = !empty($row['time_in']) ? (string) $row['time_in'] : null;
    $timeOut = !empty($row['time_out']) ? (string) $row['time_out'] : null;

    if (!sams_attendance_clocking_enabled()) {
        $timeIn = null;
        $timeOut = null;
        if (empty($row['excuse_id'])) {
            $status = 'absent';
        }
    }

    if ($timeIn !== null && $timeOut === null) {
        $activeNow++;
    }
    if ($timeIn !== null && $timeOut !== null) {
        $completedToday++;
        $startTimestamp = strtotime($timeIn);
        $endTimestamp = strtotime($timeOut);
        if ($startTimestamp !== false && $endTimestamp !== false && $endTimestamp > $startTimestamp) {
            $recordedSeconds += $endTimestamp - $startTimestamp;
        }
    }

    $officeName = (string) ($row['office_name'] ?? 'Unassigned');
    if (!isset($officeSummary[$officeName])) {
        $officeSummary[$officeName] = [
            'name' => $officeName,
            'count' => 0,
            'active' => 0,
        ];
    }
    $officeSummary[$officeName]['count']++;
    if ($timeIn !== null && $timeOut === null) {
        $officeSummary[$officeName]['active']++;
    }

    $attendance_rows[] = [
        'dot' => sams_admin_attendance_dot($status),
        'name' => sams_admin_attendance_display_name($row),
        'office' => $officeName,
        'date_label' => $currentDateLabel ?? date('D, M j, Y'),
        'schedule_start' => (string) ($row['start_time'] ?? ''),
        'time_in' => $timeIn ? date('g:i A', strtotime($timeIn)) : '-',
        'time_out' => $timeOut ? date('g:i A', strtotime($timeOut)) : ($timeIn ? 'In Progress' : '-'),
        'duration' => sams_attendance_duration_label($timeIn, $timeOut),
        'method' => !empty($timeIn) ? 'Live DB' : '-',
        'status' => match ($status) {
            'present', 'completed' => 'Present',
            'late' => 'Late',
            'excused' => 'Excused',
            'active' => 'Active',
            default => 'Absent',
        },
    ];
}

$offices = [];
foreach ($officeSummary as $office) {
    $name = (string) ($office['name'] ?? 'Unassigned');
    $count = (int) ($office['count'] ?? 0);
    $active = (int) ($office['active'] ?? 0);
    $pct = $totalSchedules > 0 ? (int) round(($count / $totalSchedules) * 100) : 0;

    $color = 'grey';
    $lower = strtolower($name);
    if (str_contains($lower, 'sdao')) {
        $color = 'blue';
    } elseif (str_contains($lower, 'library')) {
        $color = 'green';
    } elseif (str_contains($lower, 'computer')) {
        $color = 'purple';
    } elseif (str_contains($lower, 'registrar')) {
        $color = 'orange';
    }

    $offices[] = [
        'name' => $name,
        'count' => $count,
        'pct' => $pct,
        'color' => $color,
        'active' => $active,
    ];
}

$verifiedCount = $completedToday + $activeNow;
$attendanceRate = $totalSchedules > 0 ? (int) round(($verifiedCount / $totalSchedules) * 100) : 0;

$currentDateLabel = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Monitoring | NU SA System</title>
    <style>
        /* ============================================================
           CSS VARIABLES — Design System
        ============================================================ */
        :root {
            --clr-white:          #FFFFFF;
            --clr-bg:             #F9FAFB;
            --clr-border:         #E5E7EB;
            --clr-border-input:   #D1D5DC;
            --clr-border-track:   #E5E7EB;

            --clr-text-primary:   #101828;
            --clr-text-body:      #364153;
            --clr-text-muted:     #4A5565;
            --clr-text-placeholder: rgba(10,10,10,0.5);
            --clr-text-dark:      #0A0A0A;

            /* Brand / Blue */
            --clr-blue:           #155DFC;
            --clr-blue-dark:      #1447E6;
            --clr-blue-bg:        #DBEAFE;

            /* Green */
            --clr-green:          #008236;
            --clr-green-bright:   #00A63E;
            --clr-green-vivid:    #00C950;
            --clr-green-bg:       #DCFCE7;
            --clr-green-light:    #DCFCE7;

            /* Purple / Orange / Grey */
            --clr-purple:         #9810FA;
            --clr-purple-bg:      #F3E8FF;
            --clr-orange:         #F54900;
            --clr-orange-bg:      #FFEDD4;
            --clr-grey-dot:       #99A1AF;
            --clr-grey-bg:        #F3F4F6;
            --clr-blue-dot:       #2B7FFF;

            /* Gradients */
            --grad-brand:         linear-gradient(135deg, #155DFC 0%, #9810FA 100%);
            --grad-green-panel:   linear-gradient(155.38deg, #00A63E 0%, #008236 100%);

            /* Status badge colors */
            --clr-status-completed-bg:   #DCFCE7;
            --clr-status-completed-text: #008236;
            --clr-status-active-bg:      #DBEAFE;
            --clr-status-active-text:    #1447E6;
            --clr-status-scheduled-bg:   #F3F4F6;
            --clr-status-scheduled-text: #364153;

            /* Sidebar */
            --sidebar-width:      256px;

            /* Typography */
            --fs-xs:   12px;
            --fs-sm:   14px;
            --fs-base: 16px;
            --fs-md:   18px;
            --fs-lg:   24px;

            /* Spacing */
            --sp-4:   4px;
            --sp-8:   8px;
            --sp-12:  12px;
            --sp-16:  16px;
            --sp-24:  24px;
            --sp-32:  32px;

            /* Radius */
            --radius-xs:   4px;
            --radius-sm:   10px;
            --radius-md:   16.4px;
            --radius-pill: 9999px;
        }

        /* ============================================================
           RESET & BASE
        ============================================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; }
        body {
            font-family: Arial, sans-serif;
            background: var(--clr-bg);
            color: var(--clr-text-primary);
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
        .app {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* ============================================================
           SIDEBAR
        ============================================================ */
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--clr-white);
            border-right: 1px solid var(--clr-border);
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
            height: 89px;
            border-bottom: none;
            padding: 0;
            flex-shrink: 0;
        }

        .sidebar__brand {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            padding: var(--sp-24) var(--sp-24) 20px;
            border-bottom: 1px solid var(--clr-border);
        }

        .sidebar__logo {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: var(--grad-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar__logo-text {
            font-size: 18px;
            font-weight: bold;
            color: var(--clr-white);
            line-height: 28px;
        }

        .sidebar__brand-info { display: flex; flex-direction: column; }

        .sidebar__app-name {
            font-size: var(--fs-base);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 24px;
        }

        .sidebar__app-sub {
            font-size: var(--fs-xs);
            color: var(--clr-text-muted);
            line-height: 16px;
        }

        .sidebar__nav {
            flex: 1;
            padding: var(--sp-16) var(--sp-16) 0;
        }

        .nav__list { display: flex; flex-direction: column; gap: var(--sp-4); }
        .nav__item { display: block; }

        .nav__link {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 48px;
            padding: 0 var(--sp-16);
            border-radius: var(--radius-sm);
            transition: background 0.15s;
        }

        .nav__link:hover { background: var(--clr-bg); }
        .nav__link--active { background: var(--clr-blue); }
        .nav__link--active .nav__label { color: var(--clr-white); }

        .nav__icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }
        .nav__icon svg { width: 100%; height: 100%; }

        .nav__label {
            font-size: var(--fs-base);
            color: var(--clr-text-body);
            line-height: 24px;
            flex: 1;
            white-space: nowrap;
        }

        .nav__badge {
            background: var(--clr-blue-bg);
            color: var(--clr-blue);
            font-size: var(--fs-xs);
            font-weight: bold;
            line-height: 16px;
            padding: 2px 8px;
            border-radius: var(--radius-pill);
            height: 20px;
            display: flex;
            align-items: center;
        }

        .sidebar__footer {
            border-top: 1px solid var(--clr-border);
            padding: 17px var(--sp-16) var(--sp-16);
            flex-shrink: 0;
        }

        /* ============================================================
           MAIN
        ============================================================ */
        .main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        /* ============================================================
           TOP BAR
        ============================================================ */
        .topbar {
            background: var(--clr-white);
            border-bottom: 1px solid var(--clr-border);
            height: 89px;
            padding: 0 var(--sp-32);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar__left { display: flex; align-items: center; gap: var(--sp-12); }

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
        .hamburger:hover { background: var(--clr-bg); }
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

        .topbar__heading { display: flex; flex-direction: column; gap: var(--sp-4); }

        .topbar__title {
            font-size: var(--fs-lg);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 32px;
        }

        .topbar__subtitle {
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            line-height: 20px;
        }

        .topbar__right { display: flex; align-items: center; gap: var(--sp-12); }

        .topbar__notif {
            position: relative;
            width: 36px;
            height: 36px;
            border-radius: var(--radius-pill);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }
        .topbar__notif:hover { background: var(--clr-bg); }
        .topbar__notif svg { width: 20px; height: 20px; }
        .topbar__notif-dot {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 8px;
            height: 8px;
            background: #FB2C36;
            border-radius: 50%;
        }

        .topbar__user { display: flex; align-items: center; gap: var(--sp-12); }

        .topbar__user-info { text-align: right; display: flex; flex-direction: column; }
        .topbar__user-name { font-size: var(--fs-sm); color: var(--clr-text-primary); line-height: 20px; }
        .topbar__user-role { font-size: var(--fs-xs); color: var(--clr-text-muted); line-height: 16px; }

        .topbar__avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-pill);
            background: var(--grad-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .topbar__avatar svg { width: 20px; height: 20px; }

        /* ============================================================
           PAGE CONTENT
        ============================================================ */
        .page-content {
            flex: 1;
            padding: var(--sp-32);
            display: flex;
            flex-direction: column;
            gap: var(--sp-24);
            overflow-x: hidden;
        }

        /* ============================================================
           CONTROLS ROW (Search + Filter + Export)
        ============================================================ */
        .controls-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--sp-12);
            flex-wrap: wrap;
            min-height: 42px;
        }

        .controls-row__left {
            display: flex;
            align-items: center;
            gap: var(--sp-16);
            flex-wrap: wrap;
        }

        /* Search Input */
        .search-wrap {
            position: relative;
            width: 320px;
        }

        .search-wrap__icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            pointer-events: none;
        }
        .search-wrap__icon svg { width: 100%; height: 100%; }

        .search-wrap__input {
            width: 100%;
            height: 42px;
            border: 1px solid var(--clr-border-input);
            border-radius: var(--radius-sm);
            padding: 8px 16px 8px 40px;
            font-family: Arial, sans-serif;
            font-size: var(--fs-base);
            color: var(--clr-text-primary);
            background: var(--clr-white);
            outline: none;
            transition: border-color 0.15s;
        }
        .search-wrap__input::placeholder { color: var(--clr-text-placeholder); }
        .search-wrap__input:focus { border-color: var(--clr-blue); }

        /* Filter Dropdown */
        .filter-select {
            height: 42px;
            width: 134px;
            border: 1px solid var(--clr-border-input);
            border-radius: var(--radius-sm);
            padding: 0 var(--sp-12);
            font-family: Arial, sans-serif;
            font-size: var(--fs-base);
            color: var(--clr-text-dark);
            background: var(--clr-white);
            outline: none;
            cursor: pointer;
            transition: border-color 0.15s;
        }
        .filter-select.office-filter { width: 280px; }
        .filter-select:focus { border-color: var(--clr-blue); }

        /* Export Button */
        .btn-export {
            height: 40px;
            padding: 0 var(--sp-16);
            background: var(--clr-blue);
            color: var(--clr-white);
            border-radius: var(--radius-sm);
            font-size: var(--fs-base);
            line-height: 24px;
            display: flex;
            align-items: center;
            gap: var(--sp-8);
            white-space: nowrap;
            transition: opacity 0.15s;
        }
        .btn-export:hover { opacity: .88; }
        .btn-export svg { width: 16px; height: 16px; }

        /* ============================================================
           STAT CARDS
        ============================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: var(--sp-16);
        }

        .stat-card {
            background: var(--clr-white);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: 17px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
            min-height: 142px;
        }

        .stat-card__top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
        }

        .stat-card__icon-wrap {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card__icon-wrap svg { width: 20px; height: 20px; }

        .stat-card__icon-wrap--green  { background: var(--clr-green-light); }
        .stat-card__icon-wrap--blue   { background: var(--clr-blue-bg); }
        .stat-card__icon-wrap--purple { background: var(--clr-purple-bg); }
        .stat-card__icon-wrap--orange { background: var(--clr-orange-bg); }

        .stat-card__pct {
            font-size: var(--fs-sm);
            color: var(--clr-green-bright);
            line-height: 20px;
        }

        .stat-card__value {
            font-size: var(--fs-lg);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 32px;
        }

        .stat-card__label {
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            line-height: 20px;
        }

        /* ============================================================
           ATTENDANCE TABLE CARD
        ============================================================ */
        .table-card {
            background: var(--clr-white);
            border: 1px solid var(--clr-border);
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(16, 24, 40, .06);
            overflow: hidden;
        }

        .table-card__header {
            border-bottom: 1px solid var(--clr-border);
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 76px;
            background: #fff;
        }

        .table-card__title {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 1.3;
            letter-spacing: 0;
        }

        .table-card__date {
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            line-height: 1.4;
            padding: 6px 10px;
            border: 1px solid var(--clr-border);
            border-radius: 7px;
            background: #f8fafc;
            white-space: nowrap;
        }

        /* Table */
        .att-table-wrap { max-height: min(68vh, 720px); overflow: auto; }

        .att-table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .att-table col.col-sa       { width: 18%; }
        .att-table col.col-date     { width: 12%; }
        .att-table col.col-office   { width: 16%; }
        .att-table col.col-timein   { width: 12%; }
        .att-table col.col-timeout  { width: 12%; }
        .att-table col.col-duration { width: 10%; }
        .att-table col.col-method   { width: 10%; }
        .att-table col.col-status   { width: 10%; }

        .att-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            border-bottom: 1px solid var(--clr-border);
            padding: 13px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--clr-text-primary);
            text-transform: uppercase;
            height: 44px;
        }

        .att-table tbody tr {
            border-bottom: 1px solid var(--clr-border);
            transition: background .15s ease;
        }
        .att-table tbody tr:last-child { border-bottom: none; }
        .att-table tbody tr:hover { background: #f8fafc; }

        .att-table tbody td {
            padding: 12px 16px;
            min-height: 56px;
            vertical-align: middle;
            font-size: 13px;
            color: var(--clr-text-primary);
            line-height: 1.45;
        }

        .att-date { color: var(--clr-text-muted) !important; white-space: nowrap; }
        .att-office { font-weight: 600; overflow-wrap: anywhere; }
        .att-table__empty { padding: 36px 20px !important; text-align: center; color: var(--clr-text-muted) !important; }

        /* Name cell with dot */
        .att-name {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            font-weight: 600;
        }

        .att-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .att-dot--green  { background: var(--clr-green-vivid); }
        .att-dot--blue   { background: var(--clr-blue-dot); }
        .att-dot--grey   { background: var(--clr-grey-dot); }

        /* Duration bold */
        .att-duration { font-weight: bold; }

        /* Method badge */
        .att-method {
            display: inline-flex;
            align-items: center;
            height: 24px;
            padding: 4px 8px;
            background: var(--clr-grey-bg);
            border-radius: var(--radius-xs);
            font-size: var(--fs-xs);
            color: var(--clr-text-body);
            white-space: nowrap;
        }

        /* Status badge */
        .att-status {
            display: inline-flex;
            align-items: center;
            min-height: 26px;
            padding: 4px 10px;
            border-radius: var(--radius-pill);
            font-size: var(--fs-xs);
            font-weight: 700;
            white-space: nowrap;
        }
        .att-dot--amber  { background: #f59e0b; }
        .att-status--completed { background: var(--clr-status-completed-bg); color: var(--clr-status-completed-text); }
        .att-status--active    { background: var(--clr-status-active-bg);    color: var(--clr-status-active-text); }
        .att-status--excused   { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .att-status--scheduled { background: var(--clr-status-scheduled-bg); color: var(--clr-status-scheduled-text); }

        /* ============================================================
           BOTTOM PANELS ROW
        ============================================================ */
        .bottom-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--sp-24);
        }

        /* ---- Green Security Panel ---- */
        .security-panel {
            background: var(--grad-green-panel);
            border-radius: var(--radius-md);
            padding: var(--sp-24);
            display: flex;
            flex-direction: column;
            gap: var(--sp-16);
            min-height: 302px;
        }

        .security-panel__title {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-white);
            line-height: 28px;
        }

        .security-panel__desc {
            font-size: var(--fs-sm);
            color: var(--clr-green-light);
            line-height: 20px;
        }

        .security-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--sp-16);
        }

        .security-stat {
            background: rgba(255,255,255,.10);
            border-radius: var(--radius-sm);
            padding: var(--sp-12);
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .security-stat__value {
            font-size: var(--fs-lg);
            font-weight: bold;
            color: var(--clr-white);
            line-height: 32px;
        }

        .security-stat__label {
            font-size: var(--fs-sm);
            color: var(--clr-green-light);
            line-height: 20px;
        }

        /* ---- Office Distribution Panel ---- */
        .dist-panel {
            background: var(--clr-white);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-16);
            min-height: 302px;
        }

        .dist-panel__title {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        .office-bars {
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
        }

        .office-bar { display: flex; flex-direction: column; gap: var(--sp-4); }

        .office-bar__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 20px;
        }

        .office-bar__name {
            font-size: var(--fs-sm);
            color: var(--clr-text-primary);
            line-height: 20px;
        }

        .office-bar__count {
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            line-height: 20px;
        }

        .office-bar__track {
            height: 8px;
            background: var(--clr-border-track);
            border-radius: var(--radius-pill);
            overflow: hidden;
        }

        .office-bar__fill {
            height: 100%;
            border-radius: var(--radius-pill);
        }

        .office-bar__fill--blue   { background: var(--clr-blue); }
        .office-bar__fill--green  { background: var(--clr-green-bright); }
        .office-bar__fill--purple { background: var(--clr-purple); }
        .office-bar__fill--orange { background: var(--clr-orange); }
        .office-bar__fill--grey   { background: var(--clr-text-muted); }

        /* ============================================================
           SIDEBAR OVERLAY
        ============================================================ */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 99;
        }
        .sidebar-overlay--hidden  { display: none; }
        .sidebar-overlay--visible { display: block; }

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
            .topbar { padding: 0 var(--sp-16); }
            .page-content { padding: var(--sp-16); }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .bottom-row { grid-template-columns: 1fr; }
            .controls-row { flex-direction: column; align-items: flex-start; }
            .topbar__user-info { display: none; }
        }

        /* ============================================================
           RESPONSIVE — MOBILE (≤768px)
        ============================================================ */
        @media (max-width: 768px) {
            .topbar__title { font-size: 18px; }
            .topbar__subtitle { font-size: var(--fs-xs); }
            .page-content { padding: var(--sp-12); gap: var(--sp-16); }
            .stats-row { grid-template-columns: 1fr 1fr; gap: var(--sp-12); }
            .stat-card { min-height: auto; }
            .search-wrap { width: 100%; }
            .filter-select { width: 120px; }
            .filter-select.office-filter { width: 100%; }
            .table-card__header { align-items: flex-start; flex-direction: column; padding: 16px; }
            .btn-export { font-size: var(--fs-sm); height: 38px; }
            .security-stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
</head>
<body>

<div class="sidebar-overlay sidebar-overlay--hidden" id="sidebarOverlay"></div>

<div class="app">
    <?php $activeAdminNav = 'attendance'; $pendingApplications = (int) $applicationBadgeCount; include __DIR__ . '/_sidebar.php'; ?>

    <!-- ================================================================
         MAIN
    ================================================================ -->
    <main class="main">

        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar__left">
                <button class="hamburger" id="hamburgerBtn" type="button" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
                    <span class="hamburger__bar"></span>
                    <span class="hamburger__bar"></span>
                    <span class="hamburger__bar"></span>
                </button>
                <div class="topbar__heading">
                    <h1 class="topbar__title">Attendance Monitoring</h1>
                    <p class="topbar__subtitle">NU Lipa - Student Development and Activities Office</p>
                </div>
            </div>
            <div class="topbar__right">
                <a href="#" class="topbar__notif" aria-label="Notifications">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/>
                    </svg>
                    <span class="topbar__notif-dot" aria-label="New notifications"></span>
                </a>
                <div class="topbar__user">
                    <div class="topbar__user-info">
                        <span class="topbar__user-name"><?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="topbar__user-role">SDAO Head</span>
                    </div>
                    <div class="topbar__avatar" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="10" cy="7" r="4" fill="white" opacity=".9"/>
                            <path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/>
                        </svg>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <section class="page-content" aria-label="Attendance Monitoring content">

            <!-- Controls Row -->
            <div class="controls-row">
                <div class="controls-row__left">
                    <!-- Search -->
                    <div class="search-wrap">
                        <span class="search-wrap__icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="9" cy="9" r="6" stroke="#99A1AF" stroke-width="1.5"/>
                                <path d="M13.5 13.5L17 17" stroke="#99A1AF" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <input
                            class="search-wrap__input"
                            type="search"
                            placeholder="Search student assistant..."
                            aria-label="Search student assistant"
                            id="searchInput"
                        >
                    </div>
                    <!-- Filter -->
                    <select class="filter-select" aria-label="Filter by period" id="filterSelect">
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                    </select>
                    <select class="filter-select office-filter" aria-label="Filter by office" id="officeFilter">
                        <option value="all">All Offices</option>
                        <?php foreach ($attendanceOfficeOptions as $officeOption): ?>
                            <option value="<?= htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" aria-label="Filter by scheduled shift" id="scheduleShiftFilter">
                        <option value="all">All schedules</option>
                        <option value="morning">Morning</option>
                        <option value="afternoon">Afternoon</option>
                    </select>
                </div>
                <!-- Export -->
                <button class="btn-export" type="button">
                    <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 2v8M8 10L5 7M8 10l3-3" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 11v2a1 1 0 001 1h10a1 1 0 001-1v-2" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Export Report
                </button>
            </div>

            <!-- Stat Cards -->
            <div class="stats-row">
                <!-- On Time Today -->
                <div class="stat-card">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap stat-card__icon-wrap--green" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="10" cy="10" r="8" stroke="#00A63E" stroke-width="1.5"/>
                                <path d="M10 6v4l2.5 2.5" stroke="#00A63E" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__pct" id="metric-attendance-rate"><?= $attendanceRate ?>%</span>
                    </div>
                    <div class="stat-card__value" id="metric-on-time"><?= $verifiedCount ?>/<?= $totalSchedules ?></div>
                    <div class="stat-card__label">Attendance Recorded</div>
                </div>
                <!-- Avg Hours/Day -->
                <div class="stat-card">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap stat-card__icon-wrap--blue" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 17l4-8 4 5 3-3 3 2" stroke="#155DFC" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__pct">Live</span>
                    </div>
                    <div class="stat-card__value" id="metric-scheduled-shifts"><?= $totalSchedules ?></div>
                    <div class="stat-card__label">Scheduled Shifts</div>
                </div>
                <!-- Active Now -->
                <div class="stat-card">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap stat-card__icon-wrap--purple" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="10" cy="7" r="4" stroke="#9810FA" stroke-width="1.5"/>
                                <path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" stroke="#9810FA" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__pct">Live</span>
                    </div>
                    <div class="stat-card__value" id="metric-active-now">24</div>
                    <div class="stat-card__label">In Progress</div>
                </div>
                <!-- This Month -->
                <div class="stat-card">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap stat-card__icon-wrap--orange" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="2" y="4" width="16" height="14" rx="2" stroke="#F54900" stroke-width="1.5"/>
                                <path d="M6 2v4M14 2v4" stroke="#F54900" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M2 9h16" stroke="#F54900" stroke-width="1.2"/>
                            </svg>
                        </div>
                        <span class="stat-card__pct">Live</span>
                    </div>
                    <div class="stat-card__value" id="metric-total-hours"><?= htmlspecialchars(sams_attendance_format_duration($recordedSeconds), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="stat-card__label">Hours Recorded</div>
                </div>
            </div>

            <!-- Attendance Table -->
            <div class="table-card">
                <div class="table-card__header">
                    <h2 class="table-card__title" id="attendance-table-title">Today's Attendance Log</h2>
                    <p class="table-card__date" id="attendance-period-label"><?php echo htmlspecialchars($currentDateLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="att-table-wrap">
                    <table class="att-table" aria-label="Today's attendance log">
                        <colgroup>
                            <col class="col-sa">
                            <col class="col-date">
                            <col class="col-office">
                            <col class="col-timein">
                            <col class="col-timeout">
                            <col class="col-duration">
                            <col class="col-method">
                            <col class="col-status">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Student Assistant</th>
                                <th scope="col">Date</th>
                                <th scope="col">Office</th>
                                <th scope="col">Time In</th>
                                <th scope="col">Time Out</th>
                                <th scope="col">Duration</th>
                                <th scope="col">Method</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <?php if (empty($attendance_rows)): ?>
                            <tr><td class="att-table__empty" colspan="8">No attendance records for today.</td></tr>
                            <?php else: ?>
                            <?php foreach ($attendance_rows as $row): ?>
                            <tr data-schedule-start="<?= htmlspecialchars((string) ($row['schedule_start'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <td>
                                    <div class="att-name">
                                        <span class="att-dot att-dot--<?= htmlspecialchars($row['dot']) ?>" aria-hidden="true"></span>
                                        <?= htmlspecialchars($row['name']) ?>
                                    </div>
                                </td>
                                <td class="att-date"><?= htmlspecialchars($row['date_label']) ?></td>
                                <td class="att-office"><?= htmlspecialchars($row['office']) ?></td>
                                <td><?= htmlspecialchars($row['time_in']) ?></td>
                                <td><?= htmlspecialchars($row['time_out']) ?></td>
                                <td><span class="att-duration"><?= htmlspecialchars($row['duration']) ?></span></td>
                                <td><span class="att-method"><?= htmlspecialchars($row['method']) ?></span></td>
                                <td>
                                    <?php
                                    $st = strtolower($row['status']);
                                    $cls = 'att-status--scheduled';
                                    if ($st === 'present' || $st === 'completed') $cls = 'att-status--completed';
                                    elseif ($st === 'late' || $st === 'active' || $st === 'in progress') $cls = 'att-status--active';
                                    elseif ($st === 'excused') $cls = 'att-status--excused';
                                    ?>
                                    <span class="att-status <?= $cls ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bottom Row: Security Panel + Office Distribution -->
            <div class="bottom-row">

                <!-- Dual-Layer Security Panel -->
                <div class="security-panel" role="region" aria-label="Dual-Layer Security">
                    <h2 class="security-panel__title">🔒 Dual-Layer Security</h2>
                    <p class="security-panel__desc">All attendance logs verified with QR Code + PIN/OTP validation</p>
                    <div class="security-stats">
                        <div class="security-stat">
                            <span class="security-stat__value" id="security-verified"><?= (int) $verifiedCount ?></span>
                            <span class="security-stat__label">Verified Logs</span>
                        </div>
                        <div class="security-stat">
                            <span class="security-stat__value" id="security-accuracy"><?= $attendanceRate ?>%</span>
                            <span class="security-stat__label">Attendance Rate</span>
                        </div>
                    </div>
                </div>

                <!-- Office Distribution Panel -->
                <div class="dist-panel" role="region" aria-label="Office Distribution">
                    <h2 class="dist-panel__title">Office Distribution</h2>
                            <div class="office-bars" id="officeBars">
                                <?php foreach ($offices as $office): ?>
                                <div class="office-bar">
                                    <div class="office-bar__header">
                                        <span class="office-bar__name"><?= htmlspecialchars($office['name']) ?></span>
                                        <span class="office-bar__count"><?= (int)$office['count'] ?> SAs</span>
                                    </div>
                                    <div class="office-bar__track" role="progressbar" aria-valuenow="<?= (int)$office['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= htmlspecialchars($office['name']) ?> distribution">
                                        <div class="office-bar__fill office-bar__fill--<?= htmlspecialchars($office['color']) ?>" style="width:<?= (int)$office['pct'] ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                </div>

            </div>

        </section>
    </main>
</div><!-- /app -->

<script>
(function () {
    'use strict';

    /* ---- Hamburger / Sidebar ---- */
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

    /* ---- Live search filter ---- */
    var searchInput = document.getElementById('searchInput');
    var tableBody   = document.getElementById('attendanceTableBody');
    var shiftFilter = document.getElementById('scheduleShiftFilter');

    function schedulePeriod(startTime) {
        var match = String(startTime || '').trim().match(/^(\d{1,2}):\d{2}(?::\d{2})?\s*(AM|PM)?$/i);
        if (!match) return 'unknown';

        var hour = parseInt(match[1], 10);
        var meridiem = (match[2] || '').toUpperCase();
        if (meridiem === 'PM' && hour < 12) hour += 12;
        if (meridiem === 'AM' && hour === 12) hour = 0;
        return hour < 12 ? 'morning' : 'afternoon';
    }

    function applyAttendanceFilters() {
        if (!tableBody) return;

        var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var selectedShift = shiftFilter ? shiftFilter.value : 'all';
        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr'));
        var dataRows = rows.filter(function (row) { return !row.querySelector('td[colspan]'); });
        var visibleCount = 0;

        tableBody.querySelectorAll('[data-filter-empty]').forEach(function (row) { row.remove(); });
        dataRows.forEach(function (row) {
            var name = row.querySelector('.att-name');
            var matchesSearch = !query || (name && name.textContent.toLowerCase().indexOf(query) !== -1);
            var matchesShift = selectedShift === 'all' || schedulePeriod(row.getAttribute('data-schedule-start')) === selectedShift;
            row.style.display = matchesSearch && matchesShift ? '' : 'none';
            if (matchesSearch && matchesShift) visibleCount++;
        });

        if (dataRows.length > 0 && visibleCount === 0) {
            var emptyRow = document.createElement('tr');
            emptyRow.setAttribute('data-filter-empty', 'true');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 8;
            emptyCell.textContent = 'No schedules match this shift and search.';
            emptyRow.appendChild(emptyCell);
            tableBody.appendChild(emptyRow);
        }
    }

    if (searchInput && tableBody) {
        searchInput.addEventListener('input', function () {
            applyAttendanceFilters();
        });
    }
    if (shiftFilter) shiftFilter.addEventListener('change', applyAttendanceFilters);
    window.applyAttendanceFilters = applyAttendanceFilters;

})();
</script>

<script>
// Poll attendance_data.php every 5 seconds and update UI
(function () {
    'use strict';

    var endpoint = 'attendance_data.php';
    var tableBody = document.getElementById('attendanceTableBody');
    var metricOnTime = document.getElementById('metric-on-time');
    var metricAttendanceRate = document.getElementById('metric-attendance-rate');
    var metricScheduledShifts = document.getElementById('metric-scheduled-shifts');
    var metricActive = document.getElementById('metric-active-now');
    var metricTotalHours = document.getElementById('metric-total-hours');
    var officeBars = document.getElementById('officeBars');
    var securityVerified = document.getElementById('security-verified');
    var securityAccuracy = document.getElementById('security-accuracy');
    var periodFilter = document.getElementById('filterSelect');
    var officeFilter = document.getElementById('officeFilter');
    var tableTitle = document.getElementById('attendance-table-title');
    var periodLabel = document.getElementById('attendance-period-label');

    function dotClassForStatus(st) {
        st = (st || '').toLowerCase();
        if (st === 'completed' || st === 'present') return 'att-dot--green';
        if (st === 'active' || st === 'late' || st === 'in progress') return 'att-dot--blue';
        if (st === 'excused') return 'att-dot--amber';
        return 'att-dot--grey';
    }

    function renderRow(r) {
        var name = (r.first_name || '') + ' ' + (r.last_name || '');
        var office = r.office_name || '-';
        var timeIn = r.time_in || '-';
        var timeOut = r.time_out || (r.time_in ? 'In Progress' : '-');
        var scheduleStart = r.start_time || r.schedule_start || '';
        var status = r.status || 'Scheduled';
        var dotCls = dotClassForStatus(status);

        var statusCls = 'att-status--scheduled';
        if ((status || '').toLowerCase() === 'completed') statusCls = 'att-status--completed';
        else if ((status || '').toLowerCase() === 'active' || (status || '').toLowerCase() === 'late' || (status || '').toLowerCase() === 'in progress') statusCls = 'att-status--active';
        else if ((status || '').toLowerCase() === 'excused') statusCls = 'att-status--excused';

        var html = '<tr data-schedule-start="' + escapeHtml(scheduleStart) + '">' +
            '<td><div class="att-name"><span class="att-dot ' + dotCls + '" aria-hidden="true"></span>' + escapeHtml(name) + '</div></td>' +
            '<td class="att-date">' + escapeHtml(r.date_label || '-') + '</td>' +
            '<td class="att-office">' + escapeHtml(office) + '</td>' +
            '<td>' + escapeHtml(timeIn) + '</td>' +
            '<td>' + escapeHtml(timeOut) + '</td>' +
            '<td><span class="att-duration">' + escapeHtml(r.duration || '-') + '</span></td>' +
            '<td><span class="att-method">' + escapeHtml(r.method || '-') + '</span></td>' +
            '<td><span class="att-status ' + statusCls + '">' + escapeHtml(status) + '</span></td>' +
            '</tr>';
        return html;
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function updateOfficeBars(offices) {
        if (!officeBars) return;
        officeBars.innerHTML = '';
        if (!Array.isArray(offices) || offices.length === 0) {
            officeBars.innerHTML = '<p style="color:var(--clr-text-muted);font-size:var(--fs-sm);">No office attendance in this period.</p>';
            return;
        }
        offices.forEach(function (o) {
            var name = o.office_name || o.name || '-';
            var total = o.total || o.count || 0;
            var pct = parseInt(o.pct || 0, 10) || 0;
            var fillColor = 'office-bar__fill--blue';
            var color = (o.color || '').toLowerCase();
            if (color === 'green') fillColor = 'office-bar__fill--green';
            else if (color === 'purple') fillColor = 'office-bar__fill--purple';
            else if (color === 'orange') fillColor = 'office-bar__fill--orange';
            else if (color === 'grey') fillColor = 'office-bar__fill--grey';

            var node = document.createElement('div');
            node.className = 'office-bar';
            node.innerHTML = '<div class="office-bar__header"><span class="office-bar__name">' + escapeHtml(name) + '</span><span class="office-bar__count">' + (total) + ' SAs</span></div>' +
                '<div class="office-bar__track" role="progressbar" aria-valuenow="' + (pct) + '" aria-valuemin="0" aria-valuemax="100" aria-label="' + escapeHtml(name) + ' distribution">' +
                '<div class="office-bar__fill ' + fillColor + '" style="width:' + (pct) + '%"></div></div>';
            officeBars.appendChild(node);
        });
    }

    function poll() {
        var selectedPeriod = periodFilter ? periodFilter.value : 'today';
        var selectedOffice = officeFilter ? officeFilter.value : 'all';
        var url = endpoint + '?period=' + encodeURIComponent(selectedPeriod) +
            '&office=' + encodeURIComponent(selectedOffice) + '&t=' + Date.now();
        fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) throw new Error('Network response not ok');
            return r.json();
        }).then(function (data) {
            if (!data || !data.success) return;

            // Update metrics
            try {
                var metrics = data.metrics || {};
                var recordedCount = Number(metrics.recorded_count || 0);
                var totalSchedules = Number(metrics.total_schedules || 0);

                if (metricActive) metricActive.textContent = String(metrics.active_now || 0);
                if (metricOnTime) metricOnTime.textContent = recordedCount + '/' + totalSchedules;
                if (metricAttendanceRate) metricAttendanceRate.textContent = String(metrics.attendance_rate || 0) + '%';
                if (metricScheduledShifts) metricScheduledShifts.textContent = String(totalSchedules);
                if (metricTotalHours) metricTotalHours.textContent = metrics.total_hours || '0 min';
                if (tableTitle && periodFilter) {
                    var periodTitles = { today: "Today's Attendance Log", yesterday: "Yesterday's Attendance Log", week: "This Week's Attendance Log", month: "This Month's Attendance Log" };
                    tableTitle.textContent = periodTitles[selectedPeriod] || 'Attendance Log';
                }
                if (periodLabel) periodLabel.textContent = data.period_label || '';

                // Table rows
                if (tableBody && Array.isArray(data.today_rows)) {
                    var rowsHtml = data.today_rows.map(renderRow).join('');
                    tableBody.innerHTML = rowsHtml || '<tr><td class="att-table__empty" colspan="8">No attendance records for this period and office.</td></tr>';
                    if (window.applyAttendanceFilters) window.applyAttendanceFilters();
                }

                // Offices
                if (Array.isArray(data.offices)) updateOfficeBars(data.offices);

                // Security
                if (securityVerified && data.security) securityVerified.textContent = String(data.security.verified || '0');
                if (securityAccuracy && data.security) securityAccuracy.textContent = String(data.security.accuracy || 0) + '%';
            } catch (err) {
                console.error('Update error', err);
            }
        }).catch(function (err) {
            console.warn('Attendance poll failed', err);
        });
    }

    // Start polling
    // Start polling
    poll();
    setInterval(poll, 5000);
    if (periodFilter) periodFilter.addEventListener('change', poll);
    if (officeFilter) officeFilter.addEventListener('change', poll);

    // SSE stream: prefer push updates for Live UI
    if (window.EventSource) {
        try {
            var adminES = new EventSource('../api/attendance_stream.php');
            adminES.addEventListener('attendance', function (e) {
                try {
                    var payload = JSON.parse(e.data);
                    if (!payload || !payload.success) return;
                    poll();
                } catch (err) {
                    console.error('SSE admin parse error', err);
                }
            });
            adminES.addEventListener('error', function () { try { adminES.close(); } catch (e) {} });
        } catch (ex) {
            console.error('Admin SSE setup failed:', ex);
        }
    }

})();
</script>

<script src="../assets/js/admin-notifications.js?v=20260922"></script>

</body>
</html>