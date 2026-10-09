<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/duty_excuses.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_duty_excuses_ensure_schema($pdo);

$userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
$supervisorOffice = '';
if ($userId > 0) {
    $officeStmt = $pdo->prepare('SELECT office_name FROM supervisors WHERE user_id = :user_id LIMIT 1');
    $officeStmt->execute(['user_id' => $userId]);
    $officeRow = $officeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $supervisorOffice = trim((string) ($officeRow['office_name'] ?? ($user['office_name'] ?? '')));
}
$supervisorName = trim((string) ($user['name'] ?? 'Supervisor'));

// Mark duty excuses as read for this supervisor
if ($userId > 0 && $supervisorOffice !== '') {
    try {
        $markStmt = $pdo->prepare(
            'INSERT IGNORE INTO duty_excuse_reads (excuse_id, user_id, read_at)
             SELECT de.excuse_id, :user_id, NOW()
             FROM duty_excuses de
             LEFT JOIN applications a ON a.application_id = de.application_id
             WHERE COALESCE(NULLIF(TRIM(de.office_name), ""), NULLIF(TRIM(a.preferred_office), "")) = :office'
        );
        $markStmt->execute([
            'user_id' => $userId,
            'office' => $supervisorOffice,
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}

// Filter parameters
$search = trim((string) ($_GET['q'] ?? ''));
$filterPeriod = trim((string) ($_GET['period'] ?? 'all'));

$whereClauses = [
    'COALESCE(NULLIF(TRIM(de.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") = :office'
];
$params = ['office' => $supervisorOffice];

if ($search !== '') {
    $whereClauses[] = '(u.first_name LIKE :search OR u.last_name LIKE :search OR s.student_id_number LIKE :search OR de.reason LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($filterPeriod === 'today') {
    $whereClauses[] = 'de.duty_date = CURDATE()';
} elseif ($filterPeriod === 'week') {
    $whereClauses[] = 'de.duty_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
} elseif ($filterPeriod === 'month') {
    $whereClauses[] = 'de.duty_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")';
}

$whereSql = implode(' AND ', $whereClauses);

$query = "
    SELECT de.excuse_id, de.duty_date, de.day_of_week, de.excuse_type, de.reason,
           de.proof_path, de.proof_original_name, de.submitted_at, de.status,
           s.student_id_number, u.first_name, u.last_name
    FROM duty_excuses de
    INNER JOIN students s ON s.student_id = de.student_id
    INNER JOIN users u ON u.user_id = s.user_id
    LEFT JOIN applications a ON a.application_id = de.application_id
    WHERE {$whereSql}
    ORDER BY de.duty_date DESC, de.excuse_id DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$excuses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Metrics for supervisor's office
$totalOfficeExcuses = count($excuses);
$todayOfficeExcuses = 0;
$weekOfficeExcuses = 0;
$curDate = date('Y-m-d');
$weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
foreach ($excuses as $e) {
    $d = (string) $e['duty_date'];
    if ($d === $curDate) $todayOfficeExcuses++;
    if ($d >= $weekStart) $weekOfficeExcuses++;
}

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
    <title>Duty Excuses | Supervisor Portal | SAMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/supervisor-notifications.css?v=20260922">
    <style>
        :root {
            --font-family: 'Inter', sans-serif;
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

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font-family);
            background: var(--color-bg-app);
            color: var(--color-heading);
            display: flex;
            min-height: 100vh;
        }

        .shell { display: flex; width: 100%; min-height: 100vh; }

        /* Supervisor sidebar */
        .sidebar {
            width: 260px;
            background: #ffffff;
            border-right: 1px solid var(--color-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar__brand {
            padding: 24px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--color-border);
        }
        .sidebar__logo {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--color-primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
        }
        .sidebar__brand-name { font-size: 16px; font-weight: 800; color: var(--color-heading); }
        .sidebar__brand-sub { font-size: 12px; color: var(--color-body); }
        .sidebar__nav { padding: 16px 12px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
        .sidebar__nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 44px;
            padding: 0 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--color-body);
            text-decoration: none;
            transition: all .15s ease;
        }
        .sidebar__nav-link:hover { background: #f8fafc; color: var(--color-heading); }
        .sidebar__nav-link--active { background: var(--color-primary); color: #fff !important; }
        .sidebar__footer { padding: 16px; border-top: 1px solid var(--color-border); }

        /* Main area */
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .topbar {
            height: 70px;
            background: #fff;
            border-bottom: 1px solid var(--color-border);
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar__title { font-size: 18px; font-weight: 800; color: var(--color-heading); }
        .topbar__sub { font-size: 13px; color: var(--color-body); }

        .page-content { padding: 32px; max-width: 1300px; flex: 1; }

        /* Cards and metrics */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .metric-card {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            padding: 20px;
        }
        .metric-label { font-size: 12px; font-weight: 700; color: var(--color-body); text-transform: uppercase; }
        .metric-val { font-size: 28px; font-weight: 800; color: var(--color-heading); margin-top: 4px; }

        /* Filter bar */
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .search-box {
            flex: 1;
            min-width: 240px;
            padding: 10px 14px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font: inherit;
        }
        .filter-select {
            padding: 10px 14px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font: inherit;
            background: #fff;
        }
        .btn-filter {
            background: var(--color-primary);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }

        /* Table */
        .table-card {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            overflow: hidden;
        }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th {
            background: #f8fafc;
            text-align: left;
            padding: 14px 18px;
            color: #475467;
            font-weight: 700;
            border-bottom: 1px solid var(--color-border);
        }
        td {
            padding: 16px 18px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: middle;
        }
        tr:hover td { background: #fafafa; }

        .badge-excused {
            background: var(--color-excused-bg);
            color: var(--color-excused-text);
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .link-proof {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--color-primary);
            text-decoration: none;
            font-weight: 700;
            padding: 5px 10px;
            background: #eff6ff;
            border-radius: 6px;
        }
        .link-proof:hover { background: #dbeafe; }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar__brand">
            <div class="sidebar__logo">NU</div>
            <div>
                <div class="sidebar__brand-name">SA System</div>
                <div class="sidebar__brand-sub">Supervisor Portal</div>
            </div>
        </div>
        <nav class="sidebar__nav" aria-label="Supervisor navigation">
            <a href="dashboard.php" class="sidebar__nav-link">Dashboard</a>
            <a href="attendance.php" class="sidebar__nav-link">Attendance</a>
            <a href="duty_excuses.php" class="sidebar__nav-link sidebar__nav-link--active">Duty Excuses</a>
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
            <div>
                <div class="topbar__title">Duty Excuses</div>
                <div class="topbar__sub"><?= h($supervisorOffice !== '' ? $supervisorOffice : 'Assigned Office'); ?> · <?= h($supervisorName); ?></div>
            </div>
            <div>
                <a href="attendance.php" class="btn-filter" style="text-decoration:none;">View Attendance Log</a>
            </div>
        </header>

        <main class="page-content">
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-label">Total Filed for Your Office</div>
                    <div class="metric-val"><?= (int) $totalOfficeExcuses ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Excused Today</div>
                    <div class="metric-val"><?= (int) $todayOfficeExcuses ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Excused This Week</div>
                    <div class="metric-val"><?= (int) $weekOfficeExcuses ?></div>
                </div>
            </div>

            <form method="GET" action="duty_excuses.php" class="filter-bar">
                <input type="text" name="q" class="search-box" placeholder="Search student name or ID..." value="<?= h($search) ?>">
                <select name="period" class="filter-select">
                    <option value="all" <?= $filterPeriod === 'all' ? 'selected' : '' ?>>All Dates</option>
                    <option value="today" <?= $filterPeriod === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $filterPeriod === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $filterPeriod === 'month' ? 'selected' : '' ?>>This Month</option>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
                <a href="duty_excuses.php" class="btn-filter" style="background:#e5e7eb; color:#374151; text-decoration:none;">Reset</a>
            </form>

            <div class="table-card">
                <div class="table-responsive">
                    <?php if (!empty($excuses)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Student Assistant</th>
                                    <th>Excused Date</th>
                                    <th>Day</th>
                                    <th>Reason Category</th>
                                    <th>Detailed Reason</th>
                                    <th>Proof Document</th>
                                    <th>Status</th>
                                    <th>Submitted At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($excuses as $item): ?>
                                    <?php $sName = trim((string) ($item['first_name'] ?? '') . ' ' . (string) ($item['last_name'] ?? '')); ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($sName !== '' ? $sName : 'Student') ?></strong>
                                            <div style="font-size:12px; color:#64748b;">ID: <?= h($item['student_id_number']) ?></div>
                                        </td>
                                        <td><strong><?= date('M d, Y', strtotime((string) $item['duty_date'])) ?></strong></td>
                                        <td><?= h($item['day_of_week']) ?></td>
                                        <td><strong><?= h($item['excuse_type']) ?></strong></td>
                                        <td style="max-width:300px;"><?= nl2br(h($item['reason'])) ?></td>
                                        <td>
                                            <?php if (!empty($item['proof_path'])): ?>
                                                <a href="../<?= h($item['proof_path']) ?>" target="_blank" class="link-proof">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                                            <span class="badge-excused">✓ Excused</span>
                                        </td>
                                        <td style="color:#64748b; font-size:12px; white-space:nowrap;">
                                            <?= date('M d, Y g:i A', strtotime((string) $item['submitted_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="padding:40px; text-align:center; color:#64748b;">
                            No duty excuse records found for your office.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>
