<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/duty_excuses.php';
require_once __DIR__ . '/../config/audit.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_duty_excuses_ensure_schema($pdo);

$adminName = trim((string) ($user['name'] ?? 'Administrator'));
$adminRole = 'System Administrator';

// Mark duty excuse notifications as read for this admin user
$adminUserId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
if ($adminUserId > 0) {
    try {
        $markStmt = $pdo->prepare(
            'INSERT IGNORE INTO duty_excuse_reads (excuse_id, user_id, read_at)
             SELECT excuse_id, :user_id, NOW()
             FROM duty_excuses'
        );
        $markStmt->execute(['user_id' => $adminUserId]);
    } catch (Throwable $e) {
        // ignore
    }
}

// Handle admin note update
$updateMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_note') {
    try {
        if (!hash_equals(sams_csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid request token.');
        }
        $excuseId = (int) ($_POST['excuse_id'] ?? 0);
        $adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));
        if ($excuseId > 0) {
            $upd = $pdo->prepare('UPDATE duty_excuses SET admin_notes = :notes WHERE excuse_id = :id');
            $upd->execute(['notes' => $adminNotes, 'id' => $excuseId]);
            $updateMessage = 'Admin note updated successfully.';

            sams_log_audit(
                'DUTY_EXCUSE_NOTE',
                'DUTY_EXCUSE',
                "Admin {$adminName} updated note on Duty Excuse #{$excuseId}.",
                'duty_excuse',
                $excuseId,
                ['notes' => $adminNotes],
                $user
            );
        }
    } catch (Throwable $e) {
        // error
    }
}

// Filter parameters
$search = trim((string) ($_GET['q'] ?? ''));
$filterOffice = trim((string) ($_GET['office'] ?? 'all'));
$filterType = trim((string) ($_GET['type'] ?? 'all'));
$filterPeriod = trim((string) ($_GET['period'] ?? 'all'));

$whereClauses = ['1=1'];
$params = [];

if ($search !== '') {
    $whereClauses[] = '(u.first_name LIKE :search OR u.last_name LIKE :search OR s.student_id_number LIKE :search OR de.reason LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($filterOffice !== 'all' && $filterOffice !== '') {
    $whereClauses[] = 'COALESCE(NULLIF(TRIM(de.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") = :office';
    $params['office'] = $filterOffice;
}

if ($filterType !== 'all' && $filterType !== '') {
    $whereClauses[] = 'de.excuse_type = :type';
    $params['type'] = $filterType;
}

if ($filterPeriod === 'today') {
    $whereClauses[] = 'de.duty_date = CURDATE()';
} elseif ($filterPeriod === 'week') {
    $whereClauses[] = 'de.duty_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
} elseif ($filterPeriod === 'month') {
    $whereClauses[] = 'de.duty_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")';
}

$whereSql = implode(' AND ', $whereClauses);

// Fetch Excuses
$query = "
    SELECT de.excuse_id, de.student_id, de.application_id, de.term_id, de.duty_date, de.day_of_week,
           COALESCE(NULLIF(TRIM(de.office_name), ''), NULLIF(TRIM(a.preferred_office), ''), 'Unassigned') AS office_name,
           de.excuse_type, de.reason, de.proof_path, de.proof_original_name, de.proof_mime, de.proof_size,
           de.status, de.submitted_at, de.admin_notes,
           s.student_id_number, u.first_name, u.last_name, u.email
    FROM duty_excuses de
    INNER JOIN students s ON s.student_id = de.student_id
    INNER JOIN users u ON u.user_id = s.user_id
    LEFT JOIN applications a ON a.application_id = de.application_id
    WHERE {$whereSql}
    ORDER BY de.duty_date DESC, de.excuse_id DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$excuseRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Fetch Stats
$totalExcuses = (int) $pdo->query('SELECT COUNT(*) FROM duty_excuses')->fetchColumn();
$todayExcuses = (int) $pdo->query('SELECT COUNT(*) FROM duty_excuses WHERE duty_date = CURDATE()')->fetchColumn();
$weekExcuses = (int) $pdo->query('SELECT COUNT(*) FROM duty_excuses WHERE duty_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)')->fetchColumn();
$topReasonStmt = $pdo->query('SELECT excuse_type, COUNT(*) as cnt FROM duty_excuses GROUP BY excuse_type ORDER BY cnt DESC LIMIT 1');
$topReasonRow = $topReasonStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$topReason = !empty($topReasonRow['excuse_type']) ? (string) $topReasonRow['excuse_type'] : 'None yet';

// Fetch Offices for filter dropdown
$officeOptions = array_merge(sams_office_options(), ['Unassigned']);

function h(?string $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Duty Excuses Management | SAMS Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-shell.css?v=20260922">
    <link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922">
    <style>
        :root {
            --font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            --color-primary: #155dfc;
            --color-bg-app: #f4f6fa;
            --color-surface: #ffffff;
            --color-border: #e5e7eb;
            --color-heading: #101828;
            --color-body: #475467;
            --color-excused-bg: #fef3c7;
            --color-excused-text: #b45309;
            --radius-card: 16px;
        }

        body {
            font-family: var(--font-family);
            background: var(--color-bg-app);
            color: var(--color-heading);
            margin: 0;
            padding: 0;
        }

        .shell {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        .main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .page-container {
            padding: 28px 32px;
            max-width: 1400px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-header h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 4px 0;
            letter-spacing: -0.02em;
        }

        .page-header p {
            margin: 0;
            color: var(--color-body);
            font-size: 14px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        .stat-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            padding: 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .stat-card__label {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-body);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .stat-card__val {
            font-size: 30px;
            font-weight: 800;
            color: var(--color-heading);
            line-height: 1.1;
        }
        .stat-card__sub {
            font-size: 12px;
            color: #667085;
        }

        /* Filter Panel */
        .filter-panel {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            padding: 18px 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: center;
        }
        .search-input {
            flex: 1;
            min-width: 220px;
            padding: 10px 14px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
        }
        .filter-select {
            padding: 10px 14px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            background: #fff;
            min-width: 170px;
        }
        .btn-filter {
            background: var(--color-primary);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-filter:hover { background: #124fd4; }
        .btn-reset {
            background: #f2f4f7;
            color: #344054;
            border: 1px solid #d0d5dd;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
        }

        /* Table Card */
        .card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
            overflow: hidden;
        }
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #f8fafc;
            color: #475467;
            font-weight: 700;
            text-align: left;
            padding: 14px 18px;
            border-bottom: 1px solid var(--color-border);
            white-space: nowrap;
        }
        td {
            padding: 16px 18px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #1e293b;
        }
        tr:hover td { background: #f9fafb; }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .avatar-circle {
            width: 36px;
            height: 36px;
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }
        .student-name {
            font-weight: 700;
            color: #0f172a;
        }
        .student-id {
            font-size: 12px;
            color: #64748b;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge--excused {
            background: var(--color-excused-bg);
            color: var(--color-excused-text);
            border: 1px solid #fde68a;
        }
        .badge--type {
            background: #eef2f6;
            color: #334155;
            font-weight: 600;
        }

        .link-proof {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--color-primary);
            text-decoration: none;
            font-weight: 700;
            padding: 6px 12px;
            background: #eff6ff;
            border-radius: 6px;
            border: 1px solid #dbeafe;
            transition: all .15s;
        }
        .link-proof:hover {
            background: #dbeafe;
            color: #1e40af;
        }

        .reason-box {
            max-width: 280px;
            line-height: 1.45;
            color: #334155;
        }

        .empty-state {
            padding: 48px 16px;
            text-align: center;
            color: #64748b;
        }
    </style>
</head>
<body>
<div class="shell">
    <?php $activeAdminNav = 'duty_excuses'; include __DIR__ . '/_sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar__left-wrap">
                <button class="topbar__hamburger" id="hamburger-btn" aria-label="Toggle navigation">
                    <span class="topbar__hamburger-bar"></span>
                    <span class="topbar__hamburger-bar"></span>
                    <span class="topbar__hamburger-bar"></span>
                </button>
                <div class="topbar__left">
                    <div class="topbar__title">Duty Excuses</div>
                    <div class="topbar__sub">Student Assistant Management System</div>
                </div>
            </div>
            <div class="topbar__right">
                <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M15 6.67A5 5 0 0 0 5 6.67C5 12.5 2.5 14.17 2.5 14.17h15S15 12.5 15 6.67Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M11.44 17.5a1.67 1.67 0 0 1-2.88 0" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <span class="topbar__notif-dot" aria-hidden="true" style="display:none"></span>
                </div>
                <div class="topbar__user-info">
                    <div class="topbar__user-name"><?= htmlspecialchars($adminName) ?></div>
                    <div class="topbar__user-role"><?= htmlspecialchars($adminRole) ?></div>
                </div>
            </div>
        </header>

        <main class="page-container" id="main-content">
            <div class="page-header">
                <div>
                    <h1>Official Duty Excuses</h1>
                    <p>Submissions of absence notices filed by Student Assistants with attached verification proof.</p>
                </div>
            </div>

            <?php if ($updateMessage !== ''): ?>
                <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-weight:600;">
                    ✓ <?= htmlspecialchars($updateMessage) ?>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card__label">Total Excuses Filed</div>
                    <div class="stat-card__val"><?= number_format($totalExcuses) ?></div>
                    <div class="stat-card__sub">Across all student assistants</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Excused Today</div>
                    <div class="stat-card__val"><?= number_format($todayExcuses) ?></div>
                    <div class="stat-card__sub">For <?= date('M d, Y') ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Excused This Week</div>
                    <div class="stat-card__val"><?= number_format($weekExcuses) ?></div>
                    <div class="stat-card__sub">Current academic week</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Top Reason</div>
                    <div class="stat-card__val" style="font-size:20px; padding-top:4px;"><?= htmlspecialchars($topReason) ?></div>
                    <div class="stat-card__sub">Most frequent category</div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" action="duty_excuses.php" class="filter-panel">
                <input type="text" name="q" class="search-input" placeholder="Search student name, ID, reason..." value="<?= h($search) ?>">

                <select name="office" class="filter-select">
                    <option value="all">All Assigned Offices</option>
                    <?php foreach ($officeOptions as $off): ?>
                        <option value="<?= h($off) ?>" <?= $filterOffice === $off ? 'selected' : '' ?>><?= h($off) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="type" class="filter-select">
                    <option value="all">All Reason Categories</option>
                    <?php foreach (sams_duty_excuse_types() as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= $filterType === $val ? 'selected' : '' ?>><?= h($val) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="period" class="filter-select" style="min-width:140px;">
                    <option value="all" <?= $filterPeriod === 'all' ? 'selected' : '' ?>>All Dates</option>
                    <option value="today" <?= $filterPeriod === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $filterPeriod === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $filterPeriod === 'month' ? 'selected' : '' ?>>This Month</option>
                </select>

                <button type="submit" class="btn-filter">Filter</button>
                <a href="duty_excuses.php" class="btn-reset">Reset</a>
            </form>

            <!-- Table Card -->
            <div class="card">
                <div class="table-responsive">
                    <?php if (!empty($excuseRows)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Student Assistant</th>
                                    <th>Assigned Office</th>
                                    <th>Excused Date</th>
                                    <th>Day</th>
                                    <th>Category</th>
                                    <th>Reason / Explanation</th>
                                    <th>Proof Document</th>
                                    <th>Status</th>
                                    <th>Submitted At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($excuseRows as $row): ?>
                                    <?php
                                    $initials = strtoupper(substr((string) ($row['first_name'] ?? 'S'), 0, 1) . substr((string) ($row['last_name'] ?? 'A'), 0, 1));
                                    $studentFullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="student-cell">
                                                <div class="avatar-circle"><?= h($initials) ?></div>
                                                <div>
                                                    <div class="student-name"><?= h($studentFullName) ?></div>
                                                    <div class="student-id">ID: <?= h($row['student_id_number']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><strong><?= h($row['office_name']) ?></strong></td>
                                        <td><strong><?= date('M d, Y', strtotime((string) $row['duty_date'])) ?></strong></td>
                                        <td><?= h($row['day_of_week']) ?></td>
                                        <td><span class="badge badge--type"><?= h($row['excuse_type']) ?></span></td>
                                        <td>
                                            <div class="reason-box"><?= nl2br(h($row['reason'])) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['proof_path'])): ?>
                                                <a href="../<?= h($row['proof_path']) ?>" target="_blank" class="link-proof">
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                        <polyline points="14 2 14 8 20 8"/>
                                                    </svg>
                                                    View Proof
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge--excused">✓ Excused</span>
                                        </td>
                                        <td style="color:#64748b; font-size:12px; white-space:nowrap;">
                                            <?= date('M d, Y g:i A', strtotime((string) $row['submitted_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <h3>No Duty Excuses Found</h3>
                            <p>There are no filed duty excuses matching your selected search or filter criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>
