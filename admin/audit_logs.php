<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/audit.php';

$admin = sams_authenticated_user();
if (!$admin || ($admin['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_audit_ensure_schema($pdo);
sams_audit_seed_initial_history($pdo);

$adminName = (string) ($admin['name'] ?? 'SAMS Admin');

// Filter parameters
$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$periodFilter = trim((string) ($_GET['period'] ?? 'all'));
$monthYearFilter = trim((string) ($_GET['month_year'] ?? ''));
$fromDate = trim((string) ($_GET['from_date'] ?? ''));
$toDate = trim((string) ($_GET['to_date'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? 'all'));
$categoryFilter = trim((string) ($_GET['category'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));

// Handle audit log print/export action logging
if (isset($_GET['exported']) && $_GET['exported'] === '1') {
    sams_log_audit(
        $pdo,
        'EXPORT_PDF',
        'Reports & PDF',
        "Admin {$adminName} exported/printed System Audit Trail Report.",
        ['filters' => ['role' => $roleFilter, 'period' => $periodFilter, 'action' => $actionFilter, 'category' => $categoryFilter, 'q' => $search]],
        null,
        'audit_log'
    );
}

// Build WHERE SQL
$where = ['1=1'];
$params = [];

// 1. Role filter
if ($roleFilter !== 'all' && in_array($roleFilter, ['admin', 'supervisor', 'student', 'system'], true)) {
    $where[] = 'al.user_role = :role';
    $params['role'] = $roleFilter;
}

// 2. Action Type filter
if ($actionFilter !== 'all' && $actionFilter !== '') {
    $where[] = 'al.action_type LIKE :action_type';
    $params['action_type'] = '%' . $actionFilter . '%';
}

// 3. Category filter
if ($categoryFilter !== 'all' && $categoryFilter !== '') {
    $where[] = 'al.category = :category';
    $params['category'] = $categoryFilter;
}

// 4. Period & Date filters
if ($monthYearFilter !== '') {
    $where[] = 'DATE_FORMAT(al.created_at, "%Y-%m") = :month_year';
    $params['month_year'] = $monthYearFilter;
} elseif ($periodFilter === 'today') {
    $where[] = 'DATE(al.created_at) = CURDATE()';
} elseif ($periodFilter === 'week') {
    $where[] = 'al.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
} elseif ($periodFilter === 'month') {
    $where[] = 'al.created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")';
} elseif ($fromDate !== '' && $toDate !== '') {
    $where[] = 'DATE(al.created_at) BETWEEN :from_date AND :to_date';
    $params['from_date'] = $fromDate;
    $params['to_date'] = $toDate;
} elseif ($fromDate !== '') {
    $where[] = 'DATE(al.created_at) >= :from_date';
    $params['from_date'] = $fromDate;
} elseif ($toDate !== '') {
    $where[] = 'DATE(al.created_at) <= :to_date';
    $params['to_date'] = $toDate;
}

// 5. Keyword search
if ($search !== '') {
    $where[] = '(al.user_name LIKE :search OR al.user_email LIKE :search OR al.description LIKE :search OR al.category LIKE :search OR al.action_type LIKE :search OR al.details LIKE :search OR al.ip_address LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);

// Metrics calculation
$metricsStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN user_role = 'admin' THEN 1 ELSE 0 END) AS admin_count,
        SUM(CASE WHEN user_role = 'supervisor' THEN 1 ELSE 0 END) AS supervisor_count,
        SUM(CASE WHEN user_role = 'student' THEN 1 ELSE 0 END) AS student_count,
        SUM(CASE WHEN action_type LIKE '%PDF%' OR action_type LIKE '%EXPORT%' THEN 1 ELSE 0 END) AS export_count
     FROM audit_logs"
);
$metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_count' => 0,
    'admin_count' => 0,
    'supervisor_count' => 0,
    'student_count' => 0,
    'export_count' => 0,
];

// Available categories for filter dropdown
$categories = [
    'Applications',
    'Attendance',
    'Duty Schedules',
    'Evaluations',
    'Duty Excuses',
    'Temporary Duty',
    'Reports & PDF',
    'Authentication',
    'System Settings',
    'Reshuffle',
    'User Management',
];

// Distinct available months for monthly selector
$availableMonths = $pdo->query(
    "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS ym,
            DATE_FORMAT(created_at, '%M %Y') AS month_label
     FROM audit_logs
     ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Pagination
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al WHERE {$whereSql}");
$countStmt->execute($params);
$totalFilteredRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalFilteredRows / $perPage));

$logStmt = $pdo->prepare(
    "SELECT al.*
     FROM audit_logs al
     WHERE {$whereSql}
     ORDER BY al.created_at DESC, al.audit_id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $logStmt->bindValue(':' . $k, $v);
}
$logStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$logStmt->execute();
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function h(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sams_audit_role_badge(string $role): string {
    return match (strtolower($role)) {
        'admin' => '<span class="role-badge role-badge--admin">Admin</span>',
        'supervisor' => '<span class="role-badge role-badge--supervisor">Supervisor</span>',
        'student' => '<span class="role-badge role-badge--student">Student</span>',
        default => '<span class="role-badge role-badge--system">System</span>',
    };
}

function sams_audit_action_badge(string $action): string {
    $act = strtoupper($action);
    if (str_contains($act, 'CREATE') || str_contains($act, 'ADD') || str_contains($act, 'DEPLOY')) {
        return '<span class="act-badge act-badge--create">' . h($act) . '</span>';
    }
    if (str_contains($act, 'UPDATE') || str_contains($act, 'EDIT') || str_contains($act, 'CHANGE')) {
        return '<span class="act-badge act-badge--update">' . h($act) . '</span>';
    }
    if (str_contains($act, 'DELETE') || str_contains($act, 'REJECT') || str_contains($act, 'DECLINE')) {
        return '<span class="act-badge act-badge--delete">' . h($act) . '</span>';
    }
    if (str_contains($act, 'PDF') || str_contains($act, 'EXPORT') || str_contains($act, 'PRINT')) {
        return '<span class="act-badge act-badge--pdf">' . h($act) . '</span>';
    }
    if (str_contains($act, 'EVALUAT')) {
        return '<span class="act-badge act-badge--eval">' . h($act) . '</span>';
    }
    if (str_contains($act, 'EXCUSE')) {
        return '<span class="act-badge act-badge--excuse">' . h($act) . '</span>';
    }
    if (str_contains($act, 'LOGIN') || str_contains($act, 'AUTH')) {
        return '<span class="act-badge act-badge--auth">' . h($act) . '</span>';
    }
    return '<span class="act-badge act-badge--default">' . h($act) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>System Audit Logs | NU SA Management System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/admin-shell.css?v=20260922" />
  <style>
    :root {
      --primary: #155dfc;
      --primary-dark: #1048c7;
      --border: #e2e8f0;
      --surface: #ffffff;
      --bg: #f8fafc;
      --text: #0f172a;
      --muted: #64748b;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
    a { text-decoration: none; color: inherit; }
    .page { padding: 32px; }
    .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.03); margin-bottom: 24px; }
    .topbar { background: #fff; border-bottom: 1px solid var(--border); height: 80px; padding: 0 32px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .topbar__title { font-size: 20px; font-weight: 800; color: var(--text); }
    .topbar__sub { font-size: 13px; color: var(--muted); }
    
    /* Metrics Grid */
    .grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
    .tile { border: 1px solid var(--border); border-radius: 12px; padding: 16px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.02); }
    .tile span { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; display: block; margin-bottom: 4px; }
    .tile strong { font-size: 22px; font-weight: 800; color: var(--text); }

    /* Filter Controls & Tabs */
    .role-tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--border); margin-bottom: 20px; padding-bottom: 12px; flex-wrap: wrap; }
    .tab-btn { display: inline-flex; align-items: center; gap: 8px; height: 38px; padding: 0 16px; border-radius: 9999px; font-size: 13px; font-weight: 700; border: 1px solid var(--border); background: #fff; color: #475569; transition: all .15s; }
    .tab-btn:hover { background: #f1f5f9; color: var(--primary); }
    .tab-btn--active { background: var(--primary); color: #fff; border-color: var(--primary); }
    .tab-btn--active:hover { background: var(--primary-dark); color: #fff; }
    
    .filters-bar { display: grid; grid-template-columns: 2fr 1.2fr 1.2fr 1.2fr auto; gap: 10px; margin-bottom: 20px; }
    .input-ctrl { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font-size: 13px; background: #fff; font-family: inherit; color: var(--text); }
    .input-ctrl:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(21,93,252,.12); }
    
    .btn { display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; border: 0; cursor: pointer; transition: all .15s; }
    .btn--primary { background: var(--primary); color: #fff; }
    .btn--sec { background: #fff; color: #334155; border: 1px solid var(--border); }
    .btn--sec:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn--print { background: #0f172a; color: #fff; }
    .btn--print:hover { background: #1e293b; }

    /* Audit Table */
    .table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
    th { background: #f8fafc; padding: 12px 16px; color: #475569; font-weight: 700; border-bottom: 1px solid var(--border); white-space: nowrap; }
    td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #334155; }
    tr:last-child td { border-bottom: 0; }
    tr:hover td { background: #fbfcfe; }

    /* Role Badges */
    .role-badge { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; }
    .role-badge--admin { background: #dbeafe; color: #1e40af; }
    .role-badge--supervisor { background: #f3e8ff; color: #6b21a8; }
    .role-badge--student { background: #dcfce7; color: #166534; }
    .role-badge--system { background: #f1f5f9; color: #475569; }

    /* Action Badges */
    .act-badge { display: inline-block; padding: 2px 7px; border-radius: 6px; font-size: 11px; font-weight: 800; font-family: 'JetBrains Mono', monospace; }
    .act-badge--create { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .act-badge--update { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .act-badge--delete { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .act-badge--pdf { background: #fdf4ff; color: #86198f; border: 1px solid #f0abfc; }
    .act-badge--eval { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .act-badge--excuse { background: #fefce8; color: #854d0e; border: 1px solid #fef08a; }
    .act-badge--auth { background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe; }
    .act-badge--default { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

    .cat-chip { display: inline-block; font-size: 12px; font-weight: 600; color: #475569; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; margin-top: 3px; }
    .ip-chip { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #64748b; }

    /* Pagination */
    .pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 10px; }
    .page-links { display: flex; gap: 4px; }
    .page-link { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--border); font-size: 13px; font-weight: 600; background: #fff; }
    .page-link--active { background: var(--primary); color: #fff; border-color: var(--primary); }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .modal-card { background: #fff; border-radius: 16px; width: 100%; max-width: 640px; max-height: 85vh; overflow-y: auto; padding: 28px; box-shadow: 0 20px 25px -5px rgba(0,0,0,.2); }
    .code-block { background: #0f172a; color: #e2e8f0; font-family: 'JetBrains Mono', monospace; font-size: 12px; padding: 14px; border-radius: 10px; overflow-x: auto; white-space: pre-wrap; line-height: 1.5; margin-top: 8px; }

    @media print {
      .sidebar, .topbar, .role-tabs, .filters-bar, .pagination, .no-print { display: none !important; }
      .main { margin: 0 !important; width: 100% !important; }
      .page { padding: 0 !important; }
      .card { border: 0 !important; box-shadow: none !important; }
      body { background: #fff !important; }
    }
    @media (max-width: 1024px) {
      .grid { grid-template-columns: repeat(2, 1fr); }
      .filters-bar { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="shell">
  <?php $activeAdminNav = 'audit_logs'; require __DIR__ . '/_sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div>
        <div class="topbar__title">System Audit Logs & Activity Trail</div>
        <div class="topbar__sub">Comprehensive chronological record of all changes, user operations, evaluations, duty excuses, and document exports</div>
      </div>
      <div class="no-print" style="display:flex;gap:10px;align-items:center;">
        <button class="btn btn--print" type="button" onclick="printAuditLogs()">
          <svg style="width:16px;height:16px;margin-right:6px;" viewBox="0 0 20 20" fill="none"><path d="M5 7V3h10v4M5 14H4a2 2 0 01-2-2V8a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2h-1M6 11h8v6H6v-6z" stroke="currentColor" stroke-width="1.5"/></svg>
          Print / Export PDF
        </button>
      </div>
    </header>

    <main class="page">
      <!-- High-Level Metrics Summary -->
      <div class="grid no-print">
        <div class="tile">
          <span>Total Logged Events</span>
          <strong><?php echo number_format((float) ($metrics['total_count'] ?? 0)); ?></strong>
        </div>
        <div class="tile">
          <span>Admin Operations</span>
          <strong style="color:#1d4ed8;"><?php echo number_format((float) ($metrics['admin_count'] ?? 0)); ?></strong>
        </div>
        <div class="tile">
          <span>Supervisor Actions</span>
          <strong style="color:#7e22ce;"><?php echo number_format((float) ($metrics['supervisor_count'] ?? 0)); ?></strong>
        </div>
        <div class="tile">
          <span>Student Submissions</span>
          <strong style="color:#15803d;"><?php echo number_format((float) ($metrics['student_count'] ?? 0)); ?></strong>
        </div>
        <div class="tile">
          <span>PDF & Document Exports</span>
          <strong style="color:#a21caf;"><?php echo number_format((float) ($metrics['export_count'] ?? 0)); ?></strong>
        </div>
      </div>

      <section class="card">
        <!-- Role Filter Tabs -->
        <div class="role-tabs no-print">
          <a class="tab-btn <?php echo $roleFilter === 'all' ? 'tab-btn--active' : ''; ?>"
             href="?<?php echo http_build_query(array_merge($_GET, ['role' => 'all', 'p' => 1])); ?>">
            👥 All Activities (<?php echo (int) $metrics['total_count']; ?>)
          </a>
          <a class="tab-btn <?php echo $roleFilter === 'admin' ? 'tab-btn--active' : ''; ?>"
             href="?<?php echo http_build_query(array_merge($_GET, ['role' => 'admin', 'p' => 1])); ?>">
            🛡️ Admin Only (<?php echo (int) $metrics['admin_count']; ?>)
          </a>
          <a class="tab-btn <?php echo $roleFilter === 'supervisor' ? 'tab-btn--active' : ''; ?>"
             href="?<?php echo http_build_query(array_merge($_GET, ['role' => 'supervisor', 'p' => 1])); ?>">
            👔 Supervisor Only (<?php echo (int) $metrics['supervisor_count']; ?>)
          </a>
          <a class="tab-btn <?php echo $roleFilter === 'student' ? 'tab-btn--active' : ''; ?>"
             href="?<?php echo http_build_query(array_merge($_GET, ['role' => 'student', 'p' => 1])); ?>">
            🎓 Student Only (<?php echo (int) $metrics['student_count']; ?>)
          </a>
        </div>

        <!-- Filter Controls Bar -->
        <form method="get" class="filters-bar no-print">
          <input type="hidden" name="role" value="<?php echo h($roleFilter); ?>" />

          <!-- Search Keyword -->
          <div>
            <input class="input-ctrl" type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search user name, email, action, keywords..." />
          </div>

          <!-- Category Filter -->
          <div>
            <select class="input-ctrl" name="category" onchange="this.form.submit()">
              <option value="all">All Modules / Categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?php echo h($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>><?php echo h($cat); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Action Filter -->
          <div>
            <select class="input-ctrl" name="action" onchange="this.form.submit()">
              <option value="all">All Action Types</option>
              <option value="CREATE" <?php echo $actionFilter === 'CREATE' ? 'selected' : ''; ?>>CREATE / ADD</option>
              <option value="UPDATE" <?php echo $actionFilter === 'UPDATE' ? 'selected' : ''; ?>>UPDATE / EDIT</option>
              <option value="DELETE" <?php echo $actionFilter === 'DELETE' ? 'selected' : ''; ?>>DELETE / REMOVE</option>
              <option value="EXPORT_PDF" <?php echo $actionFilter === 'EXPORT_PDF' ? 'selected' : ''; ?>>EXPORT / PRINT PDF</option>
              <option value="EVALUATE" <?php echo $actionFilter === 'EVALUATE' ? 'selected' : ''; ?>>EVALUATE</option>
              <option value="DUTY_EXCUSE" <?php echo $actionFilter === 'DUTY_EXCUSE' ? 'selected' : ''; ?>>DUTY EXCUSE</option>
              <option value="RESHUFFLE" <?php echo $actionFilter === 'RESHUFFLE' ? 'selected' : ''; ?>>RESHUFFLE</option>
              <option value="LOGIN" <?php echo $actionFilter === 'LOGIN' ? 'selected' : ''; ?>>AUTH / LOGIN</option>
            </select>
          </div>

          <!-- Monthly / Period Filter -->
          <div>
            <select class="input-ctrl" name="period" onchange="this.form.submit()">
              <option value="all" <?php echo $periodFilter === 'all' ? 'selected' : ''; ?>>All Time</option>
              <option value="today" <?php echo $periodFilter === 'today' ? 'selected' : ''; ?>>Today</option>
              <option value="week" <?php echo $periodFilter === 'week' ? 'selected' : ''; ?>>This Week</option>
              <option value="month" <?php echo $periodFilter === 'month' ? 'selected' : ''; ?>>This Month</option>
              <?php foreach ($availableMonths as $am): ?>
                <option value="month_select" <?php echo $monthYearFilter === $am['ym'] ? 'selected' : ''; ?> data-ym="<?php echo h($am['ym']); ?>">
                  Month: <?php echo h($am['month_label']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="display:flex;gap:6px;">
            <button class="btn btn--primary" type="submit">Filter</button>
            <?php if ($search !== '' || $categoryFilter !== 'all' || $actionFilter !== 'all' || $periodFilter !== 'all' || $roleFilter !== 'all'): ?>
              <a class="btn btn--sec" href="audit_logs.php" title="Reset Filters">Reset</a>
            <?php endif; ?>
          </div>
        </form>

        <!-- Audit Trail Table -->
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:170px;">Date & Time</th>
                <th style="width:200px;">Actor / User</th>
                <th style="width:160px;">Action & Module</th>
                <th>Activity Description</th>
                <th style="width:130px;">IP Address</th>
                <th style="width:80px;text-align:right;" class="no-print">Details</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($logs)): ?>
                <tr>
                  <td colspan="6" style="padding:36px;text-align:center;color:var(--muted);">
                    No audit log records found matching the specified filters.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($logs as $log): ?>
                  <?php
                    $uName = trim((string) ($log['user_name'] ?? ''));
                    $uEmail = trim((string) ($log['user_email'] ?? ''));
                    if ($uName === '') {
                        $uName = 'System / Automated';
                    }
                    $timeFormatted = date('M d, Y • h:i A', strtotime((string) $log['created_at']));
                    $detailsRaw = (string) ($log['details'] ?? '');
                  ?>
                  <tr>
                    <td>
                      <div style="font-weight:700;color:var(--text);font-size:12px;"><?php echo $timeFormatted; ?></div>
                      <div style="font-size:11px;color:var(--muted);margin-top:2px;">
                        <?php echo date('D, M j', strtotime((string) $log['created_at'])); ?>
                      </div>
                    </td>
                    <td>
                      <div style="display:flex;align-items:center;gap:6px;">
                        <strong><?php echo h($uName); ?></strong>
                        <?php echo sams_audit_role_badge((string) $log['user_role']); ?>
                      </div>
                      <?php if ($uEmail !== ''): ?>
                        <div style="font-size:11px;color:var(--muted);margin-top:2px;"><?php echo h($uEmail); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div><?php echo sams_audit_action_badge((string) $log['action_type']); ?></div>
                      <div class="cat-chip"><?php echo h((string) $log['category']); ?></div>
                    </td>
                    <td style="line-height:1.45;">
                      <?php echo h((string) $log['description']); ?>
                      <?php if (!empty($log['target_type'])): ?>
                        <div style="font-size:11px;color:var(--muted);margin-top:3px;">
                          Entity: <code><?php echo h($log['target_type']); ?><?php echo !empty($log['target_id']) ? ' #' . (int) $log['target_id'] : ''; ?></code>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="ip-chip"><?php echo h((string) ($log['ip_address'] ?? '127.0.0.1')); ?></span>
                    </td>
                    <td style="text-align:right;" class="no-print">
                      <button class="btn btn--sec btn-view-details"
                              type="button"
                              style="height:28px;font-size:11px;padding:0 8px;"
                              data-id="<?php echo (int) $log['audit_id']; ?>"
                              data-time="<?php echo h($timeFormatted); ?>"
                              data-user="<?php echo h($uName); ?>"
                              data-role="<?php echo h((string) $log['user_role']); ?>"
                              data-email="<?php echo h($uEmail); ?>"
                              data-action="<?php echo h((string) $log['action_type']); ?>"
                              data-cat="<?php echo h((string) $log['category']); ?>"
                              data-desc="<?php echo h((string) $log['description']); ?>"
                              data-ip="<?php echo h((string) ($log['ip_address'] ?? '127.0.0.1')); ?>"
                              data-agent="<?php echo h((string) ($log['user_agent'] ?? '-')); ?>"
                              data-details="<?php echo h($detailsRaw); ?>">
                        View
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
          <div class="pagination no-print">
            <div style="font-size:13px;color:var(--muted);">
              Showing <strong><?php echo number_format($offset + 1); ?></strong> to <strong><?php echo number_format(min($totalFilteredRows, $offset + count($logs))); ?></strong> of <strong><?php echo number_format($totalFilteredRows); ?></strong> events
            </div>
            <div class="page-links">
              <?php if ($page > 1): ?>
                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page - 1])); ?>">&laquo;</a>
              <?php endif; ?>

              <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a class="page-link <?php echo $i === $page ? 'page-link--active' : ''; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['p' => $i])); ?>">
                  <?php echo $i; ?>
                </a>
              <?php endfor; ?>

              <?php if ($page < $totalPages): ?>
                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page + 1])); ?>">&raquo;</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
</div>

<!-- AUDIT DETAILS MODAL -->
<div id="details-modal" class="modal-overlay" style="display:none;">
  <div class="modal-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--primary);text-transform:uppercase;">Audit Event Metadata</div>
        <h2 id="modal-title" style="font-size:18px;font-weight:800;color:var(--text);margin-top:2px;">Event Details</h2>
      </div>
      <button type="button" id="close-modal-btn" style="background:none;border:0;font-size:22px;cursor:pointer;color:#94a3b8;">&times;</button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;font-size:13px;background:#f8fafc;padding:14px;border-radius:10px;border:1px solid var(--border);">
      <div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;">TIMESTAMP</div>
        <div id="modal-time" style="font-weight:700;">-</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;">ACTOR & ROLE</div>
        <div id="modal-actor" style="font-weight:700;">-</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;">ACTION TYPE</div>
        <div id="modal-action" style="font-weight:700;">-</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;">MODULE / CATEGORY</div>
        <div id="modal-cat" style="font-weight:700;">-</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;">CLIENT IP ADDRESS</div>
        <div id="modal-ip" style="font-family:'JetBrains Mono', monospace;">-</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;">USER AGENT / DEVICE</div>
        <div id="modal-agent" style="font-size:11px;color:#475569;max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">-</div>
      </div>
    </div>

    <div style="margin-bottom:14px;">
      <div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:4px;">Event Description:</div>
      <div id="modal-desc" style="font-size:13px;color:#0f172a;line-height:1.45;background:#fff;border:1px solid var(--border);padding:10px;border-radius:8px;"></div>
    </div>

    <div>
      <div style="font-size:12px;font-weight:700;color:#334155;">Payload / Change Details:</div>
      <pre id="modal-payload" class="code-block"></pre>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:18px;">
      <button type="button" class="btn btn--sec" id="modal-close-action">Close</button>
    </div>
  </div>
</div>

<script src="../assets/js/admin-notifications.js?v=20260922"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('details-modal');
  const closeBtn = document.getElementById('close-modal-btn');
  const closeActionBtn = document.getElementById('modal-close-action');

  document.querySelectorAll('.btn-view-details').forEach(btn => {
    btn.addEventListener('click', function () {
      document.getElementById('modal-title').textContent = 'Audit Event #' + this.dataset.id;
      document.getElementById('modal-time').textContent = this.dataset.time;
      document.getElementById('modal-actor').textContent = this.dataset.user + ' (' + this.dataset.role + ')';
      document.getElementById('modal-action').textContent = this.dataset.action;
      document.getElementById('modal-cat').textContent = this.dataset.cat;
      document.getElementById('modal-ip').textContent = this.dataset.ip;
      document.getElementById('modal-agent').textContent = this.dataset.agent;
      document.getElementById('modal-desc').textContent = this.dataset.desc;

      const raw = this.dataset.details;
      let formatted = 'No additional payload';
      if (raw && raw.trim() !== '') {
        try {
          const parsed = JSON.parse(raw);
          formatted = JSON.stringify(parsed, null, 2);
        } catch (e) {
          formatted = raw;
        }
      }
      document.getElementById('modal-payload').textContent = formatted;

      modal.style.display = 'flex';
    });
  });

  const hide = () => { modal.style.display = 'none'; };
  if (closeBtn) closeBtn.addEventListener('click', hide);
  if (closeActionBtn) closeActionBtn.addEventListener('click', hide);
  modal.addEventListener('click', (e) => { if (e.target === modal) hide(); });
});

function printAuditLogs() {
  // Track print event in background
  const urlParams = new URLSearchParams(window.location.search);
  urlParams.set('exported', '1');
  fetch('audit_logs.php?' + urlParams.toString(), { method: 'GET' }).catch(() => {});
  window.print();
}
</script>
</body>
</html>
