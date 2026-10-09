<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/reshuffle.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_reshuffle_ensure_schema($pdo);

$shuffleFlash = (string) ($_SESSION['supervisor_shuffle_flash'] ?? '');
$shuffleFlashError = (string) ($_SESSION['supervisor_shuffle_flash_error'] ?? '');
unset($_SESSION['supervisor_shuffle_flash'], $_SESSION['supervisor_shuffle_flash_error']);

$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = trim((string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? '')));
$supervisorName = trim((string) ($user['name'] ?? 'Supervisor'));

$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);
$termLabel = trim((string) ($activeTerm['term_name'] ?? '') . ' ' . (string) ($activeTerm['term_year'] ?? ''));
if ($termLabel === '') {
    $termLabel = 'Current Term';
}

$search = trim((string) ($_GET['q'] ?? ''));

$replacementStmt = $pdo->prepare(
    'SELECT DISTINCT s.student_id, s.student_id_number, u.first_name, u.last_name
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.term_id = :term_id AND a.status IN ("approved", "deployed")
     ORDER BY u.last_name, u.first_name'
);
$replacementStmt->execute(['term_id' => $activeTermId]);
$replacementStudents = $replacementStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$where = [
    'ds.status IN ("assigned", "pending", "accepted", "deployed")',
    '(ds.office_name = :office_ds OR a.preferred_office = :office_app)',
];
$params = [
    'office_ds' => $supervisorOffice,
    'office_app' => $supervisorOffice,
];

$termFilterForLogs = '';
$termFilterForEval1 = '';
$termFilterForEval2 = '';
if ($activeTermId > 0) {
    $where[] = 'ds.term_id = :term_id_ds';
    $params['term_id_ds'] = $activeTermId;
    $termFilterForLogs = ' AND l.term_id = :term_id_logs';
    $termFilterForEval1 = ' AND e1.term_id = :term_id_eval1';
    $termFilterForEval2 = ' AND e2.term_id = :term_id_eval2';
    $params['term_id_logs'] = $activeTermId;
    $params['term_id_eval1'] = $activeTermId;
    $params['term_id_eval2'] = $activeTermId;
}

if ($search !== '') {
    $where[] = '(u.first_name LIKE :search_first_name OR u.last_name LIKE :search_last_name OR s.student_id_number LIKE :search_student_id OR s.program LIKE :search_program)';
    $searchLike = '%' . $search . '%';
    $params['search_first_name'] = $searchLike;
    $params['search_last_name'] = $searchLike;
    $params['search_student_id'] = $searchLike;
    $params['search_program'] = $searchLike;
}

$sql =
    'SELECT
        a.application_id,
        s.student_id,
        s.student_id_number,
        s.program,
        s.year_level,
        COALESCE(s.reshuffle_count, 0) AS reshuffle_count,
        COALESCE(u.first_name, "") AS first_name,
        COALESCE(u.last_name, "") AS last_name,
        COALESCE(NULLIF(TRIM(a.preferred_office), ""), NULLIF(TRIM(ds.office_name), ""), "Unassigned") AS office_name,
        COUNT(DISTINCT ds.duty_id) AS deployed_schedule_count,
        COALESCE((
            SELECT SUM(TIMESTAMPDIFF(SECOND, l.clock_in_time, l.clock_out_time) / 3600)
            FROM attendance_logs l
            WHERE l.application_id = a.application_id
              AND l.clock_in_time IS NOT NULL
              AND l.clock_out_time IS NOT NULL' . $termFilterForLogs . '
        ), 0) AS rendered_hours,
        COALESCE((
            SELECT AVG((e1.performance_rating + e1.reliability_rating + e1.professionalism_rating) / 3)
            FROM evaluations e1
            WHERE e1.application_id = a.application_id' . $termFilterForEval1 . '
        ), 0) AS avg_rating,
        (
            SELECT COUNT(*) FROM evaluations e2
            WHERE e2.application_id = a.application_id' . $termFilterForEval2 . '
        ) AS has_evaluation,
        (
            SELECT sr.status FROM shuffle_requests sr
            WHERE sr.from_student_id = s.student_id AND sr.term_id = :term_id_sr
            ORDER BY sr.created_at DESC LIMIT 1
        ) AS shuffle_request_status
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE ' . implode(' AND ', $where) . '
     GROUP BY a.application_id, s.student_id, s.student_id_number, s.program, s.year_level, s.reshuffle_count, u.first_name, u.last_name, office_name
     ORDER BY u.last_name ASC, u.first_name ASC';

if ($activeTermId > 0) {
    $params['term_id_sr'] = $activeTermId;
} else {
    $params['term_id_sr'] = 0;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalStudents = count($students);
$ratingSum = 0.0;
$ratingCount = 0;
foreach ($students as $studentRow) {
    $rating = (float) ($studentRow['avg_rating'] ?? 0.0);
    if ($rating > 0) {
        $ratingSum += $rating;
        $ratingCount++;
    }
}
$avgRating = $ratingCount > 0 ? $ratingSum / $ratingCount : 0.0;

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
    <title>Assigned Students | Supervisor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/supervisor-notifications.css" />
    <link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
    <style>
        :root {
            --primary: #155dfc;
            --primary-dark: #1048c7;
            --bg-page: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --radius-card: 16px;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body { font-family: 'Inter', sans-serif; background: var(--bg-page); color: var(--text-main); min-height: 100vh; display:flex;}
        a { text-decoration:none; color:inherit;}
        .shell { display:flex; width:100%; min-height:100vh;}
        .sidebar { width:256px; min-height:100vh; background:#fff; border-right:1px solid var(--border); display:flex; flex-direction:column;}
        .sidebar__brand { display:flex; align-items:center; gap:12px; padding:24px 24px 20px; border-bottom:1px solid var(--border);}
        .sidebar__logo { width:40px; height:40px; background:linear-gradient(135deg, #155dfc 0%, #9810fa 100%); border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px;}
        .sidebar__brand-name { font-size:16px; font-weight:700;}
        .sidebar__brand-sub { font-size:12px; color:var(--text-muted);}
        .sidebar__nav { flex:1; padding:16px; display:flex; flex-direction:column; gap:4px; overflow-y:auto;}
        .sidebar__nav-link { display:flex; align-items:center; gap:12px; height:46px; padding:0 16px; border-radius:10px; font-size:15px; color:#334155; font-weight:500; transition:background .15s;}
        .sidebar__nav-link:hover { background:#f1f5f9;}
        .sidebar__nav-link--active { background:var(--primary); color:#fff; font-weight:600;}
        .sidebar__nav-icon { width:20px; height:20px; flex-shrink:0;}
        .sidebar__footer { border-top:1px solid var(--border); padding:16px; display:flex; flex-direction:column; gap:4px;}
        .main { flex:1; min-width:0; display:flex; flex-direction:column;}
        .topbar { background:#fff; border-bottom:1px solid var(--border); height:80px; padding:0 32px; display:flex; align-items:center; justify-content:space-between; gap:16px;}
        .topbar__title { font-size:20px; font-weight:800; color:var(--text-main);}
        .topbar__sub { font-size:13px; color:var(--text-muted);}
        .page { flex:1; padding:32px;}
        .card { background:#fff; border:1px solid var(--border); border-radius:var(--radius-card); padding:24px; box-shadow:0 1px 3px rgba(0,0,0,.04); margin-bottom:24px;}
        .grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:14px; margin-bottom:20px;}
        .tile { border:1px solid var(--border); border-radius:12px; padding:16px; background:#f8fafc;}
        .tile span { font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:4px;}
        .tile strong { font-size:18px; font-weight:800; color:var(--text-main);}
        .table-wrap { overflow-x:auto; border-radius:12px; border:1px solid var(--border);}
        table { width:100%; border-collapse:collapse; font-size:13px; text-align:left;}
        th { background:#f1f5f9; padding:12px 16px; color:#475569; font-weight:700; border-bottom:1px solid var(--border); white-space:nowrap;}
        td { padding:14px 16px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle;}
        tr:last-child td { border-bottom:0;}
        tr:hover td { background:#fafafa;}
        .btn { display:inline-flex; align-items:center; justify-content:center; height:34px; padding:0 12px; border-radius:8px; background:var(--primary); color:#fff; border:0; cursor:pointer; font-weight:600; font-size:13px; text-decoration:none; transition:all .15s;}
        .btn:hover { opacity:.92; transform:translateY(-1px);}
        .btn--sec { background:#fff; color:#334155; border:1px solid #cbd5e1;}
        .btn--sec:hover { border-color:var(--primary); color:var(--primary); background:#f8fafc;}
        .btn--amber { background:#d97706;}
        .btn--amber:hover { background:#b45309;}
        .pill { display:inline-flex; align-items:center; padding:3px 8px; border-radius:9999px; font-size:11px; font-weight:700; background:#f1f5f9; color:#475569;}
        .pill--active { background:#dcfce7; color:#166534;}
        .pill--warn { background:#fef3c7; color:#92400e;}
        .pill--max { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; font-weight:800;}
        .flash { padding:12px 16px; border-radius:10px; margin-bottom:16px; font-size:14px; font-weight:600; }
        .flash--succ { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;}
        .flash--err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b;}
        .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.65); backdrop-filter:blur(4px); z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px;}
        .modal-box { background:#fff; border-radius:16px; width:100%; max-width:540px; padding:28px; box-shadow:0 20px 25px -5px rgba(0,0,0,.2);}
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar__brand">
            <div class="sidebar__logo"><span>NU</span></div>
            <div>
                <div class="sidebar__brand-name">SA System</div>
                <div class="sidebar__brand-sub">Supervisor</div>
            </div>
        </div>
        <nav class="sidebar__nav">
            <a href="dashboard.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none"><path d="M2.5 7.5L10 2.5L17.5 7.5V17.5H12.5V12.5H7.5V17.5H2.5V7.5Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Dashboard
            </a>
            <a href="attendance.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none"><path d="M17 5L8 14.5L3.5 10" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Attendance
            </a>
            <a href="evaluation.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none"><path d="M10 2l2 5.5H17l-4 3 1.5 5.5L10 13l-4.5 3L7 11 3 8h5L10 2Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Evaluation
            </a>
            <a href="duty_excuses.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Duty Excuses
            </a>
            <a href="reports.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="2.5" width="15" height="15" rx="2" stroke="#364153" stroke-width="1.5"/><path d="M6 14V10M10 14V7M14 14V11" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                Reports
            </a>
            <a href="students.php" class="sidebar__nav-link sidebar__nav-link--active">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="6.5" r="3" stroke="white" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
                Students
            </a>
        </nav>
        <div class="sidebar__footer">
            <a href="logout.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none"><path d="M13 15l5-5-5-5M18 10H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 17.5H3.5a.5.5 0 0 1-.5-.5V3a.5.5 0 0 1 .5-.5H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                Sign Out
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div>
                <div class="topbar__title">Assigned Student Assistants</div>
                <div class="topbar__sub"><?php echo h($supervisorOffice); ?> • <?php echo h($termLabel); ?></div>
            </div>
            <div class="topbar__right">
                <div class="topbar__notif" aria-label="Notifications">
                    <svg class="topbar__icon" viewBox="0 0 20 20" fill="none"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#364153"/></svg>
                    <span class="topbar__notif-dot" style="display:none"></span>
                </div>
                <a href="profile.php" class="btn btn--sec"><?php echo h($supervisorName); ?></a>
            </div>
        </header>

        <main class="page">
            <?php if ($shuffleFlash !== ''): ?><div class="flash flash--succ">✓ <?php echo h($shuffleFlash); ?></div><?php endif; ?>
            <?php if ($shuffleFlashError !== ''): ?><div class="flash flash--err">⚠️ <?php echo h($shuffleFlashError); ?></div><?php endif; ?>

            <div class="grid">
                <div class="tile"><span>Assigned Students</span><strong><?php echo (int) $totalStudents; ?></strong></div>
                <div class="tile"><span>Average Rating</span><strong><?php echo $avgRating > 0 ? number_format($avgRating, 1) . ' / 5.0' : 'N/A'; ?></strong></div>
                <div class="tile"><span>Active Office</span><strong><?php echo h($supervisorOffice); ?></strong></div>
            </div>

            <section class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
                    <div>
                        <h2 style="font-size:18px;font-weight:800;color:var(--text-main);">Student Roster</h2>
                        <p style="font-size:13px;color:var(--text-muted);margin-top:2px;">Performance evaluation must be completed before an office reshuffle request can be submitted.</p>
                    </div>
                    <form method="get" style="display:flex;gap:8px;">
                        <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search student..." style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;width:220px;" />
                        <button class="btn btn--sec" type="submit">Search</button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Program</th>
                                <th>Hours Rendered</th>
                                <th>Evaluation</th>
                                <th>Reshuffles</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $studentRow): ?>
                                <?php
                                    $fullName = trim((string) ($studentRow['first_name'] ?? '') . ' ' . (string) ($studentRow['last_name'] ?? ''));
                                    $rating = (float) ($studentRow['avg_rating'] ?? 0.0);
                                    $hasEval = (int) ($studentRow['has_evaluation'] ?? 0) > 0;
                                    $shuffCount = (int) ($studentRow['reshuffle_count'] ?? 0);
                                    $shuffStatus = (string) ($studentRow['shuffle_request_status'] ?? '');
                                    $studentId = (int) ($studentRow['student_id'] ?? 0);
                                    $appId = (int) ($studentRow['application_id'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h($fullName !== '' ? $fullName : 'Student Assistant'); ?></strong>
                                        <div style="font-size:12px;color:var(--text-muted);"><?php echo h((string) ($studentRow['year_level'] ?? '')); ?></div>
                                    </td>
                                    <td><code><?php echo h((string) ($studentRow['student_id_number'] ?? '')); ?></code></td>
                                    <td><?php echo h((string) ($studentRow['program'] ?? '-')); ?></td>
                                    <td><?php echo number_format((float) ($studentRow['rendered_hours'] ?? 0), 1); ?> hrs</td>
                                    <td>
                                        <?php if ($hasEval): ?>
                                            <span class="pill pill--active">Evaluated (<?php echo number_format($rating, 1); ?>/5)</span>
                                        <?php else: ?>
                                            <span class="pill pill--warn">Pending Evaluation</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($shuffCount >= 3): ?>
                                            <span class="pill pill--max">3/3 Max Reached</span>
                                        <?php elseif ($shuffCount > 0): ?>
                                            <span class="pill pill--warn"><?php echo $shuffCount; ?> / 3 Transfers</span>
                                        <?php else: ?>
                                            <span class="pill">0 / 3 Transfers</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($shuffStatus === 'pending'): ?>
                                            <span class="pill pill--warn">Reshuffle Pending</span>
                                        <?php else: ?>
                                            <span class="pill pill--active">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <a class="btn btn--sec" href="student_profile.php?application_id=<?php echo $appId; ?>" style="height:32px;font-size:12px;padding:0 10px;">View</a>
                                            <?php if (!$hasEval): ?>
                                                <a class="btn" href="evaluation.php?application_id=<?php echo $appId; ?>" style="height:32px;font-size:12px;padding:0 10px;">Evaluate First</a>
                                            <?php elseif ($shuffCount >= 3): ?>
                                                <button class="btn btn--sec" type="button" disabled style="height:32px;font-size:12px;padding:0 10px;opacity:.5;cursor:not-allowed;" title="Maximum 3 reshuffles reached">Max Reshuffles</button>
                                            <?php else: ?>
                                                <button class="btn btn--amber shuffle-open"
                                                        type="button"
                                                        style="height:32px;font-size:12px;padding:0 10px;"
                                                        data-student-id="<?php echo $studentId; ?>"
                                                        data-student-name="<?php echo h($fullName); ?>"
                                                        data-reshuffle-count="<?php echo $shuffCount; ?>">
                                                    Reshuffle
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted);">No assigned students found for this office.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- SHUFFLE REQUEST MODAL -->
<div id="shuffle-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
            <div>
                <h2 style="font-size:20px;font-weight:800;color:var(--text-main);">Request Student Reshuffle</h2>
                <div id="shuffle-modal-meta" style="font-size:13px;color:var(--text-muted);margin-top:2px;"></div>
            </div>
            <button type="button" id="shuffle-cancel" style="background:none;border:0;font-size:22px;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.4;">
            Explain the assignment or operational reason for requesting a student transfer. This request will be submitted to Admin for review. (Maximum of 3 reshuffles per student).
        </p>

        <form method="post" action="shuffle_request.php">
            <?php echo sams_csrf_input_field(); ?>
            <input type="hidden" name="from_student_id" id="shuffle-from-student">
            <input type="hidden" name="return_to" value="students.php">

            <div style="margin-bottom:12px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;" for="shuffle-to-student">Proposed replacement student (optional)</label>
                <select name="to_student_id" id="shuffle-to-student" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;font-size:13px;">
                    <option value="0">Let Admin choose replacement</option>
                    <?php foreach ($replacementStudents as $replacement): ?>
                        <option value="<?php echo (int) $replacement['student_id']; ?>">
                            <?php echo h(trim($replacement['first_name'] . ' ' . $replacement['last_name']) . ' - ' . $replacement['student_id_number']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;" for="shuffle-reason">Reason for Reshuffle *</label>
                <textarea name="reason" id="shuffle-reason" required rows="4" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;font-size:13px;" placeholder="Describe the office schedule requirements, skills re-matching, or support rationale..."></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn--sec" type="button" id="shuffle-close-btn">Cancel</button>
                <button class="btn btn--amber" type="submit">Submit Reshuffle Request</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/admin-notifications.js?v=20260922"></script>
<script>
document.querySelectorAll('.shuffle-open').forEach(function (button) {
    button.addEventListener('click', function () {
        const studentId = this.dataset.studentId;
        const studentName = this.dataset.studentName;
        const count = parseInt(this.dataset.reshuffleCount, 10) || 0;

        document.getElementById('shuffle-from-student').value = studentId;
        document.getElementById('shuffle-modal-meta').textContent = studentName + ' • Current Transfers: ' + count + ' of 3';
        document.getElementById('shuffle-modal').style.display = 'flex';
    });
});
const closeModal = () => { document.getElementById('shuffle-modal').style.display = 'none'; };
document.getElementById('shuffle-cancel').addEventListener('click', closeModal);
document.getElementById('shuffle-close-btn').addEventListener('click', closeModal);
</script>
</body>
</html>
