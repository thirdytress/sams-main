<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function sams_supervisor_day_rank(string $day): int
{
    return match ($day) {
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
        default => 7,
    };
}

function sams_supervisor_time_label(?string $time): string
{
    if ($time === null || trim($time) === '') {
        return '-';
    }

    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}

function sams_supervisor_relative_days(string $createdAt): string
{
    $ts = strtotime($createdAt);
    if (!$ts) {
        return 'recently';
    }

    $diff = time() - $ts;
    if ($diff < 3600) {
        return max(1, (int) floor($diff / 60)) . ' min ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hours ago';
    }

    return (int) floor($diff / 86400) . ' days ago';
}

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];

$supervisorName = trim((string) ($user['name'] ?? 'Supervisor'));
$officeName = trim((string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? 'Assigned Office')));
$currentDay = date('l');
$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);
$termLabel = trim((string) ($activeTerm['term_name'] ?? '') . ' ' . (string) ($activeTerm['term_year'] ?? ''));
if ($termLabel === '') {
    $termLabel = 'Current Term';
}

$allRows = [];
if ($officeName !== '' && $activeTermId > 0) {
    $rowsStmt = $pdo->prepare(
        'SELECT u.first_name, u.last_name, s.student_id AS student_code, a.application_id, ds.duty_id,
                COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name,
                ds.day_of_week, ds.start_time, ds.end_time,
                al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT al2.log_id
             FROM attendance_logs al2
             WHERE al2.application_id = ds.application_id
               AND al2.duty_id = ds.duty_id
             ORDER BY al2.log_id DESC
             LIMIT 1
         )
         WHERE ds.status = "deployed"
           AND ds.term_id = :term_id
           AND COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") = :office
         ORDER BY FIELD(ds.day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"), ds.start_time ASC'
    );
    $rowsStmt->execute([
        'term_id' => $activeTermId,
        'office' => $officeName,
    ]);
    $allRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$todayRows = [];
$todayMetrics = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0];
$needsAttention = [];
$weeklyLoad = [
    'Monday' => 0,
    'Tuesday' => 0,
    'Wednesday' => 0,
    'Thursday' => 0,
    'Friday' => 0,
    'Saturday' => 0,
];

foreach ($allRows as $row) {
    $day = (string) ($row['day_of_week'] ?? '');
    if (isset($weeklyLoad[$day])) {
        $weeklyLoad[$day]++;
    }

    if ($day !== $currentDay) {
        continue;
    }

    $status = sams_attendance_display_status((string) ($row['status'] ?? ''));
    if ($status === '') {
        $status = 'absent';
    }

    if ($status === 'present' || $status === 'completed') {
        $todayMetrics['present']++;
    } elseif ($status === 'late') {
        $todayMetrics['late']++;
    } else {
        $todayMetrics['absent']++;
    }
    $todayMetrics['total']++;

    $row['resolved_status'] = $status;
    $todayRows[] = $row;

    if ($status === 'late' || $status === 'absent') {
        $needsAttention[] = $row;
    }
}

$nextDuty = null;
$todayRank = (int) date('N');
$nowMinutes = ((int) date('H')) * 60 + (int) date('i');
$candidateRows = $allRows;
usort($candidateRows, static function (array $left, array $right) use ($todayRank, $nowMinutes): int {
    $leftDayRank = sams_supervisor_day_rank((string) ($left['day_of_week'] ?? ''));
    $rightDayRank = sams_supervisor_day_rank((string) ($right['day_of_week'] ?? ''));

    $leftOffset = $leftDayRank - $todayRank;
    if ($leftOffset < 0) {
        $leftOffset += 7;
    }
    $rightOffset = $rightDayRank - $todayRank;
    if ($rightOffset < 0) {
        $rightOffset += 7;
    }

    if ($leftOffset === 0) {
        $leftStart = strtotime((string) ($left['start_time'] ?? '00:00:00'));
        $leftMin = $leftStart ? ((int) date('H', $leftStart)) * 60 + (int) date('i', $leftStart) : 0;
        if ($leftMin < $nowMinutes) {
            $leftOffset = 7;
        }
    }

    if ($rightOffset === 0) {
        $rightStart = strtotime((string) ($right['start_time'] ?? '00:00:00'));
        $rightMin = $rightStart ? ((int) date('H', $rightStart)) * 60 + (int) date('i', $rightStart) : 0;
        if ($rightMin < $nowMinutes) {
            $rightOffset = 7;
        }
    }

    if ($leftOffset !== $rightOffset) {
        return $leftOffset <=> $rightOffset;
    }

    return strcmp((string) ($left['start_time'] ?? ''), (string) ($right['start_time'] ?? ''));
});
$nextDuty = $candidateRows[0] ?? null;

$announcementsStmt = $pdo->prepare(
    "SELECT id, title, body, created_at
     FROM announcements
     WHERE is_active = 1
       AND audience IN ('supervisors', 'all')
     ORDER BY created_at DESC
     LIMIT 3"
);
$announcementsStmt->execute();
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$announcementTone = ['blue', 'yellow', 'green'];

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Supervisor Dashboard - SAMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <link rel="stylesheet" href="../assets/css/supervisor-notifications.css" />
    <link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, Arial, sans-serif; background: #f4f6fa; color: var(--color-heading); }
        .hero {
            background: linear-gradient(125deg, #0f4cd6 0%, #205ee6 58%, #3c7dff 100%);
            border-radius: 18px;
            padding: 22px;
            color: #fff;
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 16px;
        }
        .hero h1 { margin: 0; font-size: 34px; letter-spacing: -0.02em; }
        .hero p { margin: 8px 0 0; color: #dce9ff; }
        .hero__badge { display: inline-flex; align-items: center; gap: 8px; margin-top: 14px; padding: 8px 12px; border-radius: 999px; background: rgba(255,255,255,.16); font-weight: 700; font-size: 13px; }
        .hero__next { background: rgba(9, 32, 89, .35); border: 1px solid rgba(255,255,255,.16); border-radius: 14px; padding: 14px; }
        .hero__next-label { font-size: 12px; color: #c8dbff; }
        .hero__next-main { margin-top: 8px; font-weight: 800; font-size: 18px; }
        .hero__next-sub { margin-top: 6px; color: #e2ecff; font-size: 13px; }

        .layout { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; }
        .stack { display: grid; gap: 14px; }

        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .stat {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        }
        .stat__label { color: var(--color-body); font-size: 13px; }
        .stat__value { margin-top: 8px; font-size: 30px; font-weight: 800; line-height: 1; }
        .stat--present .stat__value { color: #008236; }
        .stat--late .stat__value { color: #155dfc; }
        .stat--absent .stat__value { color: #f54900; }
        .stat--total .stat__value { color: #101828; }

        .panel {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        }
        .panel__head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .panel__title { font-size: 22px; font-weight: 800; margin: 0; }
        .panel__sub { margin: 0; color: var(--color-body); font-size: 14px; }

        .needs-list { display: grid; gap: 10px; }
        .needs-item { border: 1px solid var(--color-border); border-radius: 12px; padding: 12px; display: grid; gap: 8px; }
        .needs-item__top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .needs-item__name { font-weight: 800; }
        .chip { display: inline-flex; align-items: center; height: 24px; padding: 0 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .chip--absent { background: #fff1f2; color: #be123c; }
        .chip--late { background: #eff6ff; color: #1d4ed8; }
        .needs-item__meta { color: var(--color-body); font-size: 13px; }
        .needs-item__actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .btn-sm { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 11px; border: 1px solid var(--color-border); border-radius: 9px; font-size: 12px; font-weight: 700; color: #101828; background: #fff; }
        .btn-sm--brand { border-color: #155dfc; color: #155dfc; }

        .timeline-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; }
        .day-col { border: 1px solid var(--color-border); border-radius: 12px; padding: 10px; min-height: 130px; background: #fbfdff; }
        .day-col h4 { margin: 0 0 8px; font-size: 13px; color: var(--color-muted); text-transform: uppercase; letter-spacing: .03em; }
        .slot { border-left: 3px solid #dbeafe; background: #f8fbff; border-radius: 8px; padding: 7px 8px; margin-bottom: 7px; }
        .slot--today { border-left-color: #155dfc; }
        .slot__time { font-size: 12px; font-weight: 700; color: #1e3a8a; }
        .slot__name { font-size: 12px; color: #334155; margin-top: 2px; }

        .workload { display: grid; gap: 10px; }
        .bar-row { display: grid; grid-template-columns: 82px 1fr 44px; align-items: center; gap: 8px; }
        .bar-track { height: 10px; border-radius: 999px; background: #e7edf8; overflow: hidden; }
        .bar-fill { height: 100%; background: linear-gradient(90deg, #155dfc 0%, #4f8dff 100%); }

        .ann-panel .panel__title-wrap { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .ann-icon {
            width: 68px;
            height: 68px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
        }
        .announcements-list { display: grid; gap: 12px; }
        .announcement { border-left: 3px solid transparent; border-radius: 12px; padding: 12px 14px; }
        .announcement--blue { background: #eaf1ff; border-color: #155dfc; }
        .announcement--yellow { background: #fff8e8; border-color: #f59e0b; }
        .announcement--green { background: #ecfbf1; border-color: #00c950; }
        .announcement__time { font-size: 13px; font-weight: 700; }
        .announcement--blue .announcement__time { color: #155dfc; }
        .announcement--yellow .announcement__time { color: #d97706; }
        .announcement--green .announcement__time { color: #16a34a; }
        .announcement__title { margin-top: 4px; font-weight: 800; font-size: 19px; }
        .announcement__body { margin-top: 6px; color: #334155; line-height: 1.45; }

        .quick-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .quick-actions .secondary, .quick-actions .primary { min-height: 38px; padding: 0 12px; }

        .empty { padding: 14px; border: 1px dashed var(--color-border); border-radius: 12px; color: var(--color-body); font-size: 14px; text-align: center; }

        @media (max-width: 1200px) {
            .layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 980px) {
            .hero { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .timeline-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar__brand">
            <div class="sidebar__logo"><span class="sidebar__logo-text">NU</span></div>
            <div>
                <div class="sidebar__brand-name">SA System</div>
                <div class="sidebar__brand-sub">Supervisor</div>
            </div>
        </div>
        <nav class="sidebar__nav" aria-label="Supervisor navigation">
            <a href="dashboard.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">Dashboard</a>
            <a href="attendance.php" class="sidebar__nav-link">Attendance</a>
            <a href="duty_excuses.php" class="sidebar__nav-link">Duty Excuses</a>
            <a href="evaluation.php" class="sidebar__nav-link">Evaluation</a>
            <a href="reports.php" class="sidebar__nav-link">Reports</a>
            <a href="students.php" class="sidebar__nav-link">Students</a>
            <a href="announcements.php" class="sidebar__nav-link">Announcements</a>
        </nav>
        <div class="sidebar__footer">
            <a href="logout.php" class="sidebar__nav-link">Sign Out</a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar__heading">
                <div class="topbar__title">Supervisor Dashboard</div>
                <div class="topbar__sub"><?php echo h($officeName); ?> · <?php echo h($termLabel); ?></div>
            </div>
            <div class="topbar__right">
                <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/></svg>
                    <span class="topbar__notif-dot" aria-hidden="true" style="display:none"></span>
                </div>
                    <a href="profile.php" class="logout-warning"><?php echo h($supervisorName); ?></a>
                <a href="logout.php" class="logout-warning">Logout</a>
            </div>
        </header>

        <main class="page">
            <section class="hero">
                <div>
                    <div class="eyebrow" style="color:#e6efff">Supervisor Portal</div>
                    <h1><?php echo h($supervisorName); ?></h1>
                    <p>Track attendance in real time, resolve absences quickly, and manage students assigned to <?php echo h($officeName); ?>.</p>
                    <span class="hero__badge">Live Monitoring · Auto-refresh every 10s</span>
                    <div class="quick-actions">
                        <a class="primary" href="attendance.php">Open Attendance</a>
                        <a class="secondary" href="students.php">Open Students</a>
                        <a class="secondary" href="reports.php">Open Reports</a>
                        <a class="secondary" href="evaluation.php">Open Evaluation</a>
                    </div>
                </div>
                <div class="hero__next">
                    <div class="hero__next-label">Next Scheduled Duty</div>
                    <?php if ($nextDuty): ?>
                        <div class="hero__next-main"><?php echo h((string) ($nextDuty['day_of_week'] ?? '')); ?> · <?php echo h(sams_supervisor_time_label((string) ($nextDuty['start_time'] ?? ''))); ?> - <?php echo h(sams_supervisor_time_label((string) ($nextDuty['end_time'] ?? ''))); ?></div>
                        <div class="hero__next-sub"><?php echo h(trim((string) ($nextDuty['first_name'] ?? '') . ' ' . (string) ($nextDuty['last_name'] ?? ''))); ?> · <?php echo h((string) ($nextDuty['student_code'] ?? '')); ?></div>
                    <?php else: ?>
                        <div class="hero__next-main">No upcoming duty</div>
                        <div class="hero__next-sub">All deployed schedules for this office are completed or unavailable.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="stats">
                <article class="stat stat--present"><div class="stat__label">Present Today</div><div id="metric-present" class="stat__value"><?php echo (int) $todayMetrics['present']; ?></div></article>
                <article class="stat stat--late"><div class="stat__label">Late Today</div><div id="metric-late" class="stat__value"><?php echo (int) $todayMetrics['late']; ?></div></article>
                <article class="stat stat--absent"><div class="stat__label">Absent Today</div><div id="metric-absent" class="stat__value"><?php echo (int) $todayMetrics['absent']; ?></div></article>
                <article class="stat stat--total"><div class="stat__label">Total Duties Today</div><div id="metric-total" class="stat__value"><?php echo (int) $todayMetrics['total']; ?></div></article>
            </section>

            <section class="layout">
                <div class="stack">
                    <section class="panel">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Needs Attention</h2>
                                <p class="panel__sub">Students with late or missing logs in today's duties.</p>
                            </div>
                        </div>
                        <div class="needs-list" id="needs-list">
                            <?php if (!empty($needsAttention)): ?>
                                <?php foreach ($needsAttention as $row): ?>
                                    <?php $resolvedStatus = (string) ($row['resolved_status'] ?? 'absent'); ?>
                                    <div class="needs-item">
                                        <div class="needs-item__top">
                                            <div class="needs-item__name"><?php echo h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''))); ?></div>
                                            <span class="chip <?php echo $resolvedStatus === 'late' ? 'chip--late' : 'chip--absent'; ?>"><?php echo h(strtoupper($resolvedStatus)); ?></span>
                                        </div>
                                        <div class="needs-item__meta">Student ID: <?php echo h((string) ($row['student_code'] ?? '')); ?> · Time In: <?php echo h(sams_supervisor_time_label((string) ($row['time_in'] ?? ''))); ?> · Time Out: <?php echo h(sams_supervisor_time_label((string) ($row['time_out'] ?? ''))); ?> · Duty: <?php echo h(sams_supervisor_time_label((string) ($row['start_time'] ?? ''))); ?> - <?php echo h(sams_supervisor_time_label((string) ($row['end_time'] ?? ''))); ?></div>
                                        <div class="needs-item__actions">
                                            <a class="btn-sm btn-sm--brand" href="student_profile.php?application_id=<?php echo (int) ($row['application_id'] ?? 0); ?>">Open Profile</a>
                                            <a class="btn-sm" href="reports.php?application_id=<?php echo (int) ($row['application_id'] ?? 0); ?>">Report</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty">No late or absent alerts right now.</div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Weekly Timeline</h2>
                                <p class="panel__sub">Accepted duty blocks in <?php echo h($officeName); ?> for <?php echo h($termLabel); ?>.</p>
                            </div>
                        </div>
                        <div class="timeline-grid">
                            <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day): ?>
                                <div class="day-col">
                                    <h4><?php echo h($day); ?></h4>
                                    <?php
                                    $hasSlots = false;
                                    foreach ($allRows as $slot):
                                        if ((string) ($slot['day_of_week'] ?? '') !== $day) {
                                            continue;
                                        }
                                        $hasSlots = true;
                                        ?>
                                        <div class="slot <?php echo $day === $currentDay ? 'slot--today' : ''; ?>">
                                            <div class="slot__time"><?php echo h(sams_supervisor_time_label((string) ($slot['start_time'] ?? ''))); ?> - <?php echo h(sams_supervisor_time_label((string) ($slot['end_time'] ?? ''))); ?></div>
                                            <div class="slot__name"><?php echo h(trim((string) ($slot['first_name'] ?? '') . ' ' . (string) ($slot['last_name'] ?? ''))); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$hasSlots): ?>
                                        <div class="slot"><div class="slot__name">No duty</div></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Weekly Workload</h2>
                                <p class="panel__sub">Duty volume per day for this office.</p>
                            </div>
                        </div>
                        <div class="workload">
                            <?php $maxLoad = max($weeklyLoad ?: [1]); if ($maxLoad <= 0) { $maxLoad = 1; } ?>
                            <?php foreach ($weeklyLoad as $day => $count): ?>
                                <?php $pct = (int) round(($count / $maxLoad) * 100); ?>
                                <div class="bar-row">
                                    <div><?php echo h(substr($day, 0, 3)); ?></div>
                                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
                                    <div><?php echo (int) $count; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <div class="stack">
                    <section class="panel ann-panel">
                        <div class="panel__title-wrap">
                            <div class="ann-icon" aria-hidden="true">
                                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M4 6h16v10H7l-3 3V6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    <path d="M8 9h8M8 12h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <h2 class="panel__title" style="margin:0">Announcements</h2>
                        </div>
                        <div class="announcements-list">
                            <?php if (!empty($announcements)): ?>
                                <?php foreach ($announcements as $index => $announcement): ?>
                                    <?php $tone = $announcementTone[$index % count($announcementTone)]; ?>
                                    <article class="announcement announcement--<?php echo h($tone); ?>">
                                        <div class="announcement__time"><?php echo h(sams_supervisor_relative_days((string) ($announcement['created_at'] ?? ''))); ?></div>
                                        <div class="announcement__title"><?php echo h((string) ($announcement['title'] ?? 'Announcement')); ?></div>
                                        <div class="announcement__body"><?php echo nl2br(h((string) ($announcement['body'] ?? ''))); ?></div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty">No announcements yet.</div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>
</div>
<script>
(function () {
    'use strict';

    var metricPresent = document.getElementById('metric-present');
    var metricLate = document.getElementById('metric-late');
    var metricAbsent = document.getElementById('metric-absent');
    var metricTotal = document.getElementById('metric-total');
    var needsList = document.getElementById('needs-list');

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderNeeds(rows) {
        if (!needsList) return;

        var flagged = (rows || []).filter(function (row) {
            var s = String(row.status || '').toLowerCase();
            return s === 'absent' || s === 'late';
        });

        if (!flagged.length) {
            needsList.innerHTML = '<div class="empty">No late or absent alerts right now.</div>';
            return;
        }

        var html = flagged.map(function (row) {
            var status = String(row.status || '').toLowerCase();
            var chipClass = status === 'late' ? 'chip--late' : 'chip--absent';
            var fullName = (row.first_name || '') + ' ' + (row.last_name || '');

            return '<div class="needs-item">' +
                '<div class="needs-item__top">' +
                '<div class="needs-item__name">' + esc(fullName.trim() || 'Student') + '</div>' +
                '<span class="chip ' + chipClass + '">' + esc(status.toUpperCase()) + '</span>' +
                '</div>' +
                '<div class="needs-item__meta">Student ID: ' + esc(row.student_code || '') + ' · Time In: ' + esc(row.time_in || '-') + ' · Time Out: ' + esc(row.time_out || '-') + ' · Duty: ' + esc(row.duty_start || '-') + ' - ' + esc(row.duty_end || '-') + '</div>' +
                '<div class="needs-item__actions">' +
                '<a class="btn-sm btn-sm--brand" href="student_profile.php?application_id=' + encodeURIComponent(row.application_id || 0) + '">Open Profile</a>' +
                '<a class="btn-sm" href="reports.php?application_id=' + encodeURIComponent(row.application_id || 0) + '">Report</a>' +
                '</div>' +
                '</div>';
        }).join('');

        needsList.innerHTML = html;
    }

    function pollMetrics() {
        fetch('attendance_data.php', { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.metrics) {
                    return;
                }

                metricPresent.textContent = data.metrics.present || 0;
                metricLate.textContent = data.metrics.late || 0;
                metricAbsent.textContent = data.metrics.absent || 0;
                metricTotal.textContent = data.metrics.total || 0;
                renderNeeds(data.today_rows || []);
            })
            .catch(function () {
                // Keep last good state.
            });
    }

    pollMetrics();
    setInterval(pollMetrics, 10000);
})();
</script>
<script src="../assets/js/admin-notifications.js?v=20260922"></script>
</body>
</html>
