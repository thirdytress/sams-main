<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'supervisor')) {
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
$supervisorStatement->execute(['user_id' => (int) ($currentUser['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = trim((string) ($supervisorRow['office_name'] ?? ($currentUser['office_name'] ?? '')));
$supervisorName = trim((string) ($currentUser['name'] ?? 'Supervisor'));

$currentDay = date('l');
$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);
$termLabel = trim((string) ($activeTerm['term_name'] ?? '') . ' ' . (string) ($activeTerm['term_year'] ?? ''));
if ($termLabel === '') {
    $termLabel = 'Current Term';
}

$todayRows = [];
if ($supervisorOffice !== '' && $activeTermId > 0) {
    $stmt = $pdo->prepare(
    'SELECT u.first_name, u.last_name, s.student_id AS student_code, a.application_id, ds.duty_id, COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name,
                al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, ds.start_time, ds.end_time,
                de.excuse_id, de.excuse_type
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         LEFT JOIN duty_excuses de ON (de.application_id = a.application_id AND de.duty_date = CURDATE())
         LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT al2.log_id
             FROM attendance_logs al2
             WHERE al2.application_id = ds.application_id
               AND al2.duty_id = ds.duty_id
             ORDER BY al2.log_id DESC
             LIMIT 1
         )
         WHERE ds.day_of_week = :day
                     AND ds.status = "deployed"
           AND ds.term_id = :term_id
                     AND COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") = :office
         ORDER BY ds.start_time ASC, al.log_id DESC'
    );
    $stmt->execute([
        'day' => $currentDay,
        'term_id' => $activeTermId,
        'office' => $supervisorOffice,
    ]);
    $todayRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$metrics = [
    'present' => 0,
    'late' => 0,
    'excused' => 0,
    'absent' => 0,
    'total' => 0,
    'rendered_seconds' => 0,
];

$rows = [];
foreach ($todayRows as $row) {
    $status = sams_attendance_display_status((string) ($row['status'] ?? ''));
    if ($status === '') {
        $status = !empty($row['excuse_id']) ? 'excused' : 'absent';
    }

    $timeInRaw = $row['time_in'] ?? null;
    $timeOutRaw = $row['time_out'] ?? null;
    $duration = sams_attendance_duration_label(
        !empty($timeInRaw) ? (string) $timeInRaw : null,
        !empty($timeOutRaw) ? (string) $timeOutRaw : null
    );
    $timeIn = $timeInRaw ? date('g:i A', strtotime((string) $timeInRaw)) : '-';
    $timeOut = $timeOutRaw ? date('g:i A', strtotime((string) $timeOutRaw)) : ($timeInRaw ? 'In Progress' : '-');

    if ($status === 'present' || $status === 'completed') {
        $metrics['present']++;
    } elseif ($status === 'late') {
        $metrics['late']++;
    } elseif ($status === 'excused') {
        $metrics['excused']++;
    } else {
        $metrics['absent']++;
    }
    $metrics['total']++;

    if (!empty($row['time_in']) && !empty($row['time_out'])) {
        $inTs = strtotime((string) $row['time_in']);
        $outTs = strtotime((string) $row['time_out']);
        if ($inTs !== false && $outTs !== false && $outTs > $inTs) {
            $metrics['rendered_seconds'] += $outTs - $inTs;
        }
    }

    $rows[] = [
        'name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?: 'Unassigned Student',
        'student_code' => (string) ($row['student_code'] ?? ''),
        'office' => (string) ($row['office_name'] ?? '-'),
        'time_in' => $timeIn,
        'time_out' => $timeOut,
        'duration' => $duration,
        'status' => match ($status) {
            'present', 'completed' => 'Present',
            'late' => 'Late',
            'excused' => 'Excused',
            default => 'Absent',
        },
        'dot' => match ($status) {
            'present', 'completed' => 'green',
            'late' => 'blue',
            'excused' => 'amber',
            default => 'grey',
        },
    ];
}

$attendanceRate = $metrics['total'] > 0 ? (int) round((($metrics['present'] + $metrics['late']) / $metrics['total']) * 100) : 0;
$currentDateLabel = date('l, F j, Y');
$pageTitle = 'Attendance Monitoring | Supervisor Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <link rel="stylesheet" href="../assets/css/supervisor-notifications.css" />
    <link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Inter,Arial,Helvetica,sans-serif;background:var(--color-bg-app);color:var(--color-heading);min-height:100vh;display:flex}
        a{text-decoration:none;color:inherit}
        .shell{display:flex;width:100%;min-height:100vh}
        .sidebar{width:var(--sidebar-width);min-height:100vh;background:var(--color-white);border-right:1px solid var(--color-border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
        .sidebar__brand{display:flex;align-items:center;gap:12px;padding:24px 24px 20px;border-bottom:1px solid var(--color-border)}
        .sidebar__logo{width:40px;height:40px;background:var(--gradient-brand);border-radius:var(--radius-icon);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sidebar__logo-text{font-size:18px;font-weight:700;color:var(--color-white)}
        .sidebar__brand-name{font-size:var(--font-base);font-weight:700;color:var(--color-heading)}
        .sidebar__brand-sub{font-size:var(--font-xs);color:var(--color-body)}
        .sidebar__nav{flex:1;padding:16px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
        .sidebar__nav-link{display:flex;align-items:center;gap:12px;height:48px;padding:0 16px;border-radius:var(--radius-nav);font-size:var(--font-base);color:var(--color-label);transition:background .15s;white-space:nowrap}
        .sidebar__nav-link:hover{background:var(--color-bg-app)}
        .sidebar__nav-link--active{background:var(--color-primary);color:#fff}
        .sidebar__nav-link--active:hover{opacity:.92}
        .sidebar__nav-icon{width:20px;height:20px;flex-shrink:0}
        .sidebar__footer{border-top:1px solid var(--color-border);padding:16px;display:flex;flex-direction:column;gap:4px;flex-shrink:0}
        .main{flex:1;min-width:0;display:flex;flex-direction:column}
        .topbar{background:var(--color-white);border-bottom:1px solid var(--color-border);height:var(--topbar-height);padding:0 32px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-shrink:0;position:sticky;top:0;z-index:50}
        .topbar__title{font-size:var(--font-lg);font-weight:700;color:var(--color-heading)}
        .topbar__sub{font-size:var(--font-sm);color:var(--color-body)}
        .button{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 16px;border-radius:10px;font-weight:700;font-size:var(--font-sm);border:0;cursor:pointer;transition:all .2s ease}
        .button--primary{background:var(--gradient-brand);color:#fff;box-shadow:0 8px 16px rgba(21,93,252,.2)}
        .button--primary:hover{background:var(--color-primary-dark)}
        .button--neutral{background:#6b7280;color:#fff}
        .page-content{flex:1;padding:36px;display:flex;flex-direction:column;gap:22px}
        .header-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid var(--color-border);border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
        .header-row__title{font-size:24px;font-weight:700;color:var(--color-heading)}
        .header-row__subtitle{font-size:var(--font-sm);color:var(--color-body);margin-top:4px}
        .metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px}
        .metric{position:relative;background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--radius-card);padding:20px;min-height:120px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
        .metric::before{content:'';position:absolute;left:0;top:0;width:100%;height:4px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#155dfc,#9810fa)}
        .metric__label{font-size:var(--font-sm);color:var(--color-body);margin-top:8px}
        .metric__value{font-size:32px;font-weight:800;color:var(--color-heading);line-height:1}
        .card{background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--radius-card);overflow:hidden}
        .card__header{padding:20px;border-bottom:1px solid var(--color-border)}
        .card__title{font-size:18px;font-weight:700;color:var(--color-heading)}
        .card__meta{margin-top:6px;font-size:13px;color:var(--color-body)}
        .card__legend{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
        .legend-chip{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 10px;border-radius:999px;font-size:12px;font-weight:700}
        .legend-chip--present{background:#dcfce7;color:#008236}
        .legend-chip--late{background:#dbeafe;color:#1447e6}
        .legend-chip--excused{background:#fef3c7;color:#b45309}
        .legend-chip--absent{background:#f3f4f6;color:#4a5565}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:900px}
        thead th{background:#f9fafb;text-align:left;padding:14px 16px;border-bottom:1px solid var(--color-border);font-size:13px;color:var(--color-heading)}
        tbody td{padding:14px 16px;border-bottom:1px solid var(--color-border);font-size:14px;color:var(--color-heading)}
        tbody tr:hover td{background:#f8faff}
        .dot{display:inline-block;width:10px;height:10px;border-radius:9999px;margin-right:8px;vertical-align:middle}
        .dot--green{background:#00c950}
        .dot--blue{background:#2b7fff}
        .dot--amber{background:#f59e0b}
        .dot--grey{background:#d1d5dc}
        .badge{display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:9999px;font-size:12px;font-weight:700}
        .badge--present{background:#dcfce7;color:#008236}
        .badge--late{background:#dbeafe;color:#1447e6}
        .badge--excused{background:#fef3c7;color:#b45309}
        .badge--absent{background:#f3f4f6;color:#4a5565}
        .empty{padding:24px;text-align:center;color:var(--color-body)}
        .toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .select{height:40px;padding:0 12px;border:1px solid var(--color-border);border-radius:8px;background:#fff;font-size:14px;color:var(--color-heading)}
        .select:focus{outline:none;border-color:#9fc0ff;box-shadow:0 0 0 3px rgba(21,93,252,.12)}
        .switch{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--color-body)}
        @media (max-width: 1200px){.metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media (max-width: 900px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width: 780px){.shell{flex-direction:column}.sidebar{width:100%;height:auto;position:relative}.page-content{padding:16px}.metrics{grid-template-columns:1fr}.topbar{padding:0 16px;height:auto;min-height:89px;align-items:flex-start;padding-top:16px;padding-bottom:16px;flex-wrap:wrap}}
    </style>
</head>
<body>
<div class="shell">
<?php 
    $activeSupervisorNav = 'attendance';
    require_once __DIR__ . '/_sidebar.php'; 
?>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="topbar__title">Attendance Monitoring</div>
                <div class="topbar__sub"><?php echo htmlspecialchars($supervisorOffice !== '' ? $supervisorOffice : 'Assigned Office'); ?> · <?php echo htmlspecialchars($termLabel); ?></div>
            </div>
            <div class="topbar__right" style="display:flex;align-items:center;gap:12px;">
                <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/></svg>
                    <span class="topbar__notif-dot" aria-hidden="true" style="display:none"></span>
                </div>
                <a href="profile.php" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:#101828;font-weight:700;font-size:14px;" title="My Profile">
                    <?php $supAv = sams_user_avatar_url($user['profile_image'] ?? null, '../'); ?>
                    <?php if ($supAv): ?>
                        <img src="<?php echo htmlspecialchars($supAv); ?>?v=<?php echo time(); ?>" alt="" style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:1.5px solid #003087;">
                    <?php else: ?>
                        <div style="width:34px;height:34px;border-radius:50%;background:#003087;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;">
                            <?php echo htmlspecialchars(strtoupper(substr($supervisorName, 0, 1))); ?>
                        </div>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($supervisorName, ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
                <form method="get" class="inline-form">
                    <select class="select" name="refresh" aria-label="Auto refresh">
                        <option value="0">Manual</option>
                        <option value="1" selected>Auto refresh</option>
                    </select>
                </form>
                <button class="button button--neutral" type="button" onclick="location.reload()">Refresh</button>
                <a href="logout.php" class="button button--primary">Logout</a>
            </div>
        </header>

        <section class="page-content">
            <div class="header-row">
                <div>
                    <h1 class="header-row__title">Today's Attendance Log</h1>
                    <div class="header-row__subtitle"><?php echo htmlspecialchars($currentDateLabel); ?></div>
                </div>
                <div class="toolbar">
                    <label class="switch"><input type="checkbox" id="autoRefresh" checked> Auto-refresh every 20s</label>
                </div>
            </div>

            <div class="metrics">
                <div class="metric">
                    <div class="metric__value"><?php echo (int) $metrics['present']; ?></div>
                    <div class="metric__label">Present</div>
                </div>
                <div class="metric">
                    <div class="metric__value"><?php echo (int) $metrics['late']; ?></div>
                    <div class="metric__label">Late</div>
                </div>
                <div class="metric">
                    <div class="metric__value"><?php echo (int) $metrics['absent']; ?></div>
                    <div class="metric__label">Absent</div>
                </div>
                <div class="metric">
                    <div class="metric__value"><?php echo (int) $attendanceRate; ?>%</div>
                    <div class="metric__label">Attendance Rate</div>
                </div>
                <div class="metric">
                    <div class="metric__value"><?php echo htmlspecialchars(sams_attendance_format_duration((int) $metrics['rendered_seconds'])); ?></div>
                    <div class="metric__label">Rendered Time</div>
                </div>
            </div>

            <div class="card">
                <div class="card__header">
                    <div class="card__title">Accepted schedules for today</div>
                    <div class="card__meta">Showing <?php echo (int) $metrics['total']; ?> schedule<?php echo (int) $metrics['total'] === 1 ? '' : 's'; ?> in <?php echo htmlspecialchars($supervisorOffice !== '' ? $supervisorOffice : 'your office'); ?>. Missing logs are treated as absent.</div>
                    <div class="card__legend">
                        <span class="legend-chip legend-chip--present">Present</span>
                        <span class="legend-chip legend-chip--late">Late</span>
                        <span class="legend-chip legend-chip--excused">Excused</span>
                        <span class="legend-chip legend-chip--absent">Absent</span>
                    </div>
                </div>
                <div class="table-wrap">
                    <table aria-label="Supervisor attendance log">
                        <thead>
                            <tr>
                                <th>Student Assistant</th>
                                <th>Office</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceBody">
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td>
                                        <span class="dot dot--<?php echo htmlspecialchars($row['dot']); ?>"></span>
                                        <?php echo htmlspecialchars($row['name']); ?>
                                        <?php if (!empty($row['student_code'])): ?>
                                            <div class="meta-small">Student ID: <?php echo htmlspecialchars($row['student_code']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['office']); ?></td>
                                    <td><?php echo htmlspecialchars($row['time_in']); ?></td>
                                    <td><?php echo htmlspecialchars($row['time_out']); ?></td>
                                    <td><span class="badge badge--<?php echo strtolower(htmlspecialchars($row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['duration']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty">No deployed schedules found for this office today.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
(function () {
    var autoRefresh = document.getElementById('autoRefresh');
    var timer = null;

    function start() {
        if (timer) clearInterval(timer);
        timer = setInterval(function () {
            location.reload();
        }, 20000);
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    autoRefresh.addEventListener('change', function () {
        if (autoRefresh.checked) start(); else stop();
    });

    if (autoRefresh.checked) {
        start();
    }
})();
</script>
<script src="../assets/js/admin-notifications.js?v=20260922"></script>
</body>
</html>
