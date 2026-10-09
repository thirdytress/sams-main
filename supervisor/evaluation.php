<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/reshuffle.php';
require_once __DIR__ . '/../config/audit.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'supervisor') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_reshuffle_ensure_schema($pdo);

$flashMessage = (string) ($_SESSION['supervisor_shuffle_flash'] ?? '');
$flashError = (string) ($_SESSION['supervisor_shuffle_flash_error'] ?? '');
unset($_SESSION['supervisor_shuffle_flash'], $_SESSION['supervisor_shuffle_flash_error']);

// Check if evaluations are enabled (admin control)
$evaluationsEnabled = true;
try {
    $flagStmt = $pdo->prepare('SELECT enabled FROM system_flags WHERE flag_key = :k LIMIT 1');
    $flagStmt->execute(['k' => 'evaluations_enabled']);
    $fv = $flagStmt->fetchColumn();
    if ($fv !== false) {
        $evaluationsEnabled = (int) $fv === 1;
    } else {
        $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $colStmt->execute(['table' => 'system_settings']);
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $valueCol = null;
        foreach (['value', 'setting_value', 'val', 'option_value', 'v'] as $cand) {
            if (in_array($cand, $cols, true)) {
                $valueCol = $cand;
                break;
            }
        }
        if ($valueCol !== null) {
            $sql = sprintf('SELECT `%s` AS val FROM system_settings WHERE `key` = :k LIMIT 1', $valueCol);
            $s = $pdo->prepare($sql);
            $s->execute(['k' => 'evaluations_enabled']);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $evaluationsEnabled = isset($r['val']) ? ((int) $r['val'] === 1) : true;
        }
    }
} catch (Throwable $e) {
    $evaluationsEnabled = true;
}

$supervisorStatement = $pdo->prepare(
    'SELECT s.supervisor_id, s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorId = (int) ($supervisorRow['supervisor_id'] ?? 0);
$officeName = (string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? 'Assigned Office'));

$termStatement = $pdo->query(
    'SELECT term_id, term_name, term_year
     FROM terms
     WHERE is_active = 1
     ORDER BY term_id DESC
     LIMIT 1'
);
$activeTerm = $termStatement->fetch(PDO::FETCH_ASSOC) ?: null;
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);

$postEvalStudent = null;

// Handle Retention Decision POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_retention') {
    try {
        if (!sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid request token.');
        }
        $evalId = (int) ($_POST['evaluation_id'] ?? 0);
        $studentName = trim((string) ($_POST['student_name'] ?? 'Student'));
        if ($evalId > 0) {
            $upd = $pdo->prepare('UPDATE evaluations SET retention_decision = "retain" WHERE evaluation_id = :id');
            $upd->execute(['id' => $evalId]);

            sams_log_audit(
                $pdo,
                'UPDATE',
                'Evaluations',
                "Supervisor confirmed retention of {$studentName} in {$officeName}.",
                ['evaluation_id' => $evalId, 'decision' => 'retain', 'office' => $officeName],
                $evalId,
                'evaluation'
            );

            $flashMessage = 'Retention confirmed: ' . $studentName . ' will remain assigned to ' . $officeName . ' for the next period.';
        }
    } catch (Throwable $exception) {
        $flashError = $exception->getMessage();
    }
}

// Handle Evaluation Submission POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_evaluation') {
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $termId = (int) ($_POST['term_id'] ?? 0);
    $performanceRating = (int) ($_POST['performance_rating'] ?? 0);
    $reliabilityRating = (int) ($_POST['reliability_rating'] ?? 0);
    $professionalismRating = (int) ($_POST['professionalism_rating'] ?? 0);
    $comments = trim((string) ($_POST['comments'] ?? ''));

    if ($supervisorId <= 0 || $applicationId <= 0 || $termId <= 0) {
        $flashError = 'Invalid evaluation request.';
    } elseif ($performanceRating < 1 || $performanceRating > 5 || $reliabilityRating < 1 || $reliabilityRating > 5 || $professionalismRating < 1 || $professionalismRating > 5) {
        $flashError = 'All ratings must be between 1 and 5.';
    } else {
        $applicationStatement = $pdo->prepare(
            'SELECT a.application_id, a.student_id, u.first_name, u.last_name, s.student_id_number,
                    COALESCE(s.reshuffle_count, 0) AS reshuffle_count
             FROM applications a
             INNER JOIN students s ON s.student_id = a.student_id
             INNER JOIN users u ON u.user_id = s.user_id
             WHERE a.application_id = :application_id
               AND a.term_id = :term_id
               AND a.preferred_office = :office_name
               AND a.status IN ("approved", "deployed")
             LIMIT 1'
        );
        $applicationStatement->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
            'office_name' => $officeName,
        ]);
        $appRow = $applicationStatement->fetch(PDO::FETCH_ASSOC);

        if (!$appRow) {
            $flashError = 'The selected student is not available for this office or term.';
        } else {
            try {
                $insertStatement = $pdo->prepare(
                    'INSERT INTO evaluations (
                        application_id,
                        term_id,
                        supervisor_id,
                        performance_rating,
                        reliability_rating,
                        professionalism_rating,
                        comments,
                        retention_decision,
                        submitted_at
                    ) VALUES (
                        :application_id,
                        :term_id,
                        :supervisor_id,
                        :performance_rating,
                        :reliability_rating,
                        :professionalism_rating,
                        :comments,
                        "pending",
                        NOW()
                    )'
                );
                $insertStatement->execute([
                    'application_id' => $applicationId,
                    'term_id' => $termId,
                    'supervisor_id' => $supervisorId,
                    'performance_rating' => $performanceRating,
                    'reliability_rating' => $reliabilityRating,
                    'professionalism_rating' => $professionalismRating,
                    'comments' => $comments,
                ]);

                $newEvalId = (int) $pdo->lastInsertId();
                $avgScore = round(($performanceRating + $reliabilityRating + $professionalismRating) / 3, 1);
                $studentFullName = trim((string) ($appRow['first_name'] ?? '') . ' ' . (string) ($appRow['last_name'] ?? ''));
                $studentId = (int) ($appRow['student_id'] ?? 0);
                $reshuffleCount = sams_student_reshuffle_count($pdo, $studentId);

                sams_log_audit(
                    $pdo,
                    'EVALUATE',
                    'Evaluations',
                    "Supervisor submitted performance evaluation for {$studentFullName} ({$avgScore}/5.0).",
                    [
                        'student_id' => $studentId,
                        'performance' => $performanceRating,
                        'reliability' => $reliabilityRating,
                        'professionalism' => $professionalismRating,
                        'comments' => $comments,
                        'office' => $officeName,
                    ],
                    $newEvalId,
                    'evaluation'
                );

                $flashMessage = 'Evaluation submitted successfully for ' . $studentFullName . ' (' . $avgScore . '/5).';

                // Setup post evaluation modal data
                $postEvalStudent = [
                    'eval_id' => $newEvalId,
                    'student_id' => $studentId,
                    'application_id' => $applicationId,
                    'name' => $studentFullName,
                    'student_code' => (string) ($appRow['student_id_number'] ?? ''),
                    'avg_rating' => $avgScore,
                    'reshuffle_count' => $reshuffleCount,
                    'can_reshuffle' => $reshuffleCount < 3,
                ];
            } catch (Throwable $exception) {
                $flashError = 'Unable to submit evaluation: ' . $exception->getMessage();
            }
        }
    }
}

// Fetch all students assigned to supervisor's office with their evaluation & reshuffle status
$eligibleStudents = [];
$replacementStudents = [];
if ($activeTermId > 0 && $officeName !== '') {
    $studentStatement = $pdo->prepare(
        'SELECT
            a.application_id,
            a.student_id,
            a.preferred_office,
            a.term_id,
            u.first_name,
            u.last_name,
            s.student_id_number,
            s.program,
            COALESCE(s.reshuffle_count, 0) AS reshuffle_count,
            e.evaluation_id,
            e.retention_decision,
            e.submitted_at AS evaluated_at,
            e.comments AS eval_comments,
            sr.status AS shuffle_request_status,
            ROUND((COALESCE(e.performance_rating,0) + COALESCE(e.reliability_rating,0) + COALESCE(e.professionalism_rating,0)) / 3, 1) AS latest_rating
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         LEFT JOIN evaluations e ON e.evaluation_id = (
             SELECT e2.evaluation_id FROM evaluations e2
             WHERE e2.application_id = a.application_id AND e2.term_id = :term_id1
             ORDER BY e2.submitted_at DESC LIMIT 1
         )
         LEFT JOIN shuffle_requests sr ON sr.request_id = (
             SELECT sr2.request_id FROM shuffle_requests sr2
             WHERE sr2.from_student_id = a.student_id AND sr2.term_id = :term_id2
             ORDER BY sr2.created_at DESC LIMIT 1
         )
         WHERE a.term_id = :term_id3
           AND a.preferred_office = :office_name
           AND a.status IN ("approved", "deployed")
         ORDER BY u.last_name, u.first_name'
    );
    $studentStatement->execute([
        'term_id1' => $activeTermId,
        'term_id2' => $activeTermId,
        'term_id3' => $activeTermId,
        'office_name' => $officeName,
    ]);
    $eligibleStudents = $studentStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fetch potential replacement students across other applications in term
    $repStmt = $pdo->prepare(
        'SELECT a.student_id, a.application_id, s.student_id_number, u.first_name, u.last_name, a.preferred_office
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.term_id = :term_id AND a.status IN ("approved", "deployed")
         ORDER BY u.last_name, u.first_name'
    );
    $repStmt->execute(['term_id' => $activeTermId]);
    $replacementStudents = $repStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$eligibleCount = count($eligibleStudents);
$canSubmitEvaluation = $activeTerm && $eligibleCount > 0 && $evaluationsEnabled;
$selectedAppId = (int) ($_GET['application_id'] ?? 0);
$openShuffleStudentId = (int) ($_GET['shuffle_student_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Supervisor Evaluation & Reshuffle Management – SAMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
  <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
  <link rel="stylesheet" href="../assets/css/supervisor-notifications.css" />
  <link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #0f172a; min-height: 100vh; display: flex; }
    a { text-decoration: none; color: inherit; }
    .shell { display: flex; width: 100%; min-height: 100vh; }
    .sidebar { width: 256px; min-height: 100vh; background: #ffffff; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; }
    .sidebar__brand { display:flex; align-items:center; gap:12px; padding:24px 24px 20px; border-bottom:1px solid #e2e8f0; }
    .sidebar__logo { width:40px; height:40px; background:linear-gradient(135deg, #155dfc 0%, #9810fa 100%); border-radius:10px; display:flex; align-items:center; justify-content:center; }
    .sidebar__logo-text{font-size:18px;font-weight:700;color:#ffffff;} .sidebar__brand-name{font-size:16px;font-weight:700}
    .sidebar__brand-sub{font-size:12px;color:#64748b}
    .sidebar__nav{flex:1;padding:16px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
    .sidebar__nav-link{display:flex;align-items:center;gap:12px;height:46px;padding:0 16px;border-radius:10px;font-size:15px;color:#334155;transition:background .15s;font-weight:500;}
    .sidebar__nav-link:hover{background:#f1f5f9}
    .sidebar__nav-link--active{background:#155dfc;color:#fff;font-weight:600;}
    .sidebar__nav-link--active:hover{opacity:.92}
    .sidebar__nav-icon{width:20px;height:20px;flex-shrink:0}
    .sidebar__footer{border-top:1px solid #e2e8f0;padding:16px;display:flex;flex-direction:column;gap:4px;flex-shrink:0}
    .main{flex:1; min-width:0; display:flex; flex-direction:column;}
    .topbar{background:#ffffff; border-bottom:1px solid #e2e8f0; height:80px; padding:0 32px; display:flex; align-items:center; justify-content:space-between; gap:16px}
    .topbar__heading{display:flex;flex-direction:column;gap:2px}
    .topbar__title{font-size:20px;font-weight:800;color:#0f172a}
    .topbar__subtitle{font-size:13px;color:#64748b}
    .logout-btn{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 14px;border-radius:8px;background:#fee2e2;color:#991b1b;font-weight:700;font-size:13px;}
    .logout-btn:hover{background:#fecaca}
    .page{flex:1; padding:32px;}
    .card{position:relative;background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; padding:26px; box-shadow:0 1px 3px rgba(0,0,0,.04); margin-bottom:24px;}
    .card::before{content:'';position:absolute;left:0;top:0;width:100%;height:4px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#155dfc,#9810fa)}
    .eyebrow{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6366f1;margin-bottom:6px}
    .card h1{font-size:26px;font-weight:800;line-height:1.2;margin-bottom:8px;color:#0f172a}
    .card p{color:#475569;line-height:1.5;max-width:780px;font-size:14px;}
    .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:20px}
    .tile{border:1px solid #e2e8f0;border-radius:12px;padding:16px;background:#f8fafc;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 1px 2px rgba(0,0,0,.02)}
    .tile span{font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px}
    .tile strong{font-size:17px;color:#0f172a;display:block;font-weight:800}
    .form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:20px}
    .field{display:flex;flex-direction:column;gap:6px}
    .field label{font-weight:600;color:#334155;font-size:13px}
    .field select,.field textarea,.field input{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;font:inherit;color:#0f172a;transition:border-color .15s, box-shadow .15s;font-size:14px;}
    .field select:focus,.field textarea:focus,.field input:focus{outline:none;border-color:#155dfc;box-shadow:0 0 0 3px rgba(21,93,252,.12)}
    .field textarea{min-height:95px;resize:vertical}
    .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;align-items:center}
    .btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;border-radius:10px;background:#155dfc;color:#fff;border:0;cursor:pointer;font-weight:700;font-size:14px;transition:all .15s ease;}
    .btn:hover{opacity:.92;transform:translateY(-1px)}
    .btn--primary{background:linear-gradient(135deg, #155dfc 0%, #9810fa 100%);}
    .btn--secondary{background:#ffffff;color:#334155;border:1px solid #cbd5e1}
    .btn--secondary:hover{border-color:#155dfc;color:#155dfc;background:#f8fafc}
    .btn--amber{background:#d97706;color:#fff;}
    .btn--amber:hover{background:#b45309;}
    .btn--green{background:#059669;color:#fff;}
    .btn--green:hover{background:#047857;}
    .flash{padding:14px 18px;border-radius:12px;margin-top:16px;border:1px solid #e2e8f0;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;}
    .flash--success{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .flash--error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .guide{margin-top:20px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .guide__item{padding:14px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;}
    .guide__title{font-size:13px;font-weight:700;color:#0f172a}
    .guide__text{margin-top:4px;font-size:12px;color:#64748b;line-height:1.4}
    .table-container{overflow-x:auto;border-radius:12px;border:1px solid #e2e8f0;margin-top:20px;}
    table{width:100%;border-collapse:collapse;text-align:left;font-size:13px;}
    th{background:#f1f5f9;padding:12px 16px;color:#475569;font-weight:700;border-bottom:1px solid #e2e8f0;white-space:nowrap;}
    td{padding:14px 16px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle;}
    tr:last-child td{border-bottom:0}
    tr:hover td{background:#fafafa}
    .badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:9999px;font-size:12px;font-weight:700;}
    .badge--success{background:#dcfce7;color:#166534}
    .badge--warning{background:#fef3c7;color:#92400e}
    .badge--danger{background:#fee2e2;color:#991b1b}
    .badge--info{background:#e0e7ff;color:#3730a3}
    .badge--neutral{background:#f1f5f9;color:#475569}
    .badge--max{background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5;}
    .shuffle-pill{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#f1f5f9;color:#475569;}
    .shuffle-pill--warn{background:#fffbeb;color:#b45309;border:1px solid #fde68a;}
    .shuffle-pill--max{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-weight:800;}
    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn .2s ease;}
    .modal-card{background:#ffffff;border-radius:20px;width:100%;max-width:580px;padding:32px;box-shadow:0 20px 25px -5px rgba(0,0,0,.2), 0 10px 10px -5px rgba(0,0,0,.08);position:relative;animation:slideUp .25s ease;}
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    @keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
    @media(max-width:960px){.grid,.form-grid,.guide{grid-template-columns:1fr}.page{padding:16px}}
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
      <a href="dashboard.php" class="sidebar__nav-link">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M2.5 7.5L10 2.5L17.5 7.5V17.5H12.5V12.5H7.5V17.5H2.5V7.5Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Dashboard
      </a>
      <a href="attendance.php" class="sidebar__nav-link">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M17 5L8 14.5L3.5 10" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Attendance
      </a>
      <a href="evaluation.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2l2 5.5H17l-4 3 1.5 5.5L10 13l-4.5 3L7 11 3 8h5L10 2Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Evaluation
      </a>
      <a href="duty_excuses.php" class="sidebar__nav-link">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Duty Excuses
      </a>
      <a href="reports.php" class="sidebar__nav-link">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="2.5" width="15" height="15" rx="2" stroke="#364153" stroke-width="1.5"/><path d="M6 14V10M10 14V7M14 14V11" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
        Reports
      </a>
      <a href="students.php" class="sidebar__nav-link">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="6.5" r="3" stroke="#364153" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
        Students
      </a>
    </nav>

    <div class="sidebar__footer">
      <a href="logout.php" class="sidebar__nav-link">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13 15l5-5-5-5M18 10H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 17.5H3.5a.5.5 0 0 1-.5-.5V3a.5.5 0 0 1 .5-.5H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="topbar__heading">
        <div class="topbar__title">Performance Evaluation & Reshuffle Management</div>
        <div class="topbar__subtitle">Evaluate student assistants and manage retention / office transfers (Max 3 reshuffles per student)</div>
      </div>
      <div class="topbar__right">
        <div class="topbar__notif" aria-label="Notifications">
          <svg class="topbar__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#364153"/></svg>
          <span class="topbar__notif-dot" aria-label="New notifications" style="display:none"></span>
        </div>
        <a href="profile.php" class="logout-btn"><?php echo htmlspecialchars((string) ($user['name'] ?? 'Supervisor'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a href="logout.php" class="logout-btn">Logout</a>
      </div>
    </header>

    <main class="page">
      <section class="card">
        <div class="eyebrow">Supervisor Evaluation Portal</div>
        <h1><?php echo htmlspecialchars((string) ($user['name'] ?? 'Supervisor'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Submit per-term performance feedback for approved student assistants in <strong><?php echo htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8'); ?></strong>. Upon submitting an evaluation, you can choose to retain the student or submit a reshuffle request (maximum of 3 office transfers per student).</p>

        <?php if ($flashMessage !== ''): ?><div class="flash flash--success"><span>✓</span> <div><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div></div><?php endif; ?>
        <?php if ($flashError !== ''): ?><div class="flash flash--error"><span>⚠️</span> <div><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div></div><?php endif; ?>

        <div class="grid">
          <div class="tile"><span>Active Term</span><strong><?php echo $activeTerm ? htmlspecialchars((string) $activeTerm['term_name'] . ' ' . (string) $activeTerm['term_year'], ENT_QUOTES, 'UTF-8') : 'No active term'; ?></strong></div>
          <div class="tile"><span>Assigned Office</span><strong><?php echo htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
          <div class="tile"><span>Assigned Students</span><strong><?php echo (int) $eligibleCount; ?> Active</strong></div>
        </div>

        <div class="guide" aria-label="Evaluation guide">
          <div class="guide__item"><div class="guide__title">1. Performance</div><div class="guide__text">Quality of work output, accuracy, and task completion speed.</div></div>
          <div class="guide__item"><div class="guide__title">2. Reliability</div><div class="guide__text">Consistency, punctuality, duty adherence, and dependability.</div></div>
          <div class="guide__item"><div class="guide__title">3. Professionalism</div><div class="guide__text">Office conduct, respectful communication, and team cooperation.</div></div>
        </div>

        <form method="post" style="margin-top:24px;">
          <?php echo sams_csrf_input_field(); ?>
          <input type="hidden" name="action" value="submit_evaluation" />

          <div class="form-grid">
            <div class="field">
              <label for="term_id">Term *</label>
              <select id="term_id" name="term_id" required>
                <?php if ($activeTerm): ?>
                  <option value="<?php echo (int) $activeTerm['term_id']; ?>" selected><?php echo htmlspecialchars((string) $activeTerm['term_name'] . ' ' . (string) $activeTerm['term_year'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php else: ?>
                  <option value="">No active term</option>
                <?php endif; ?>
              </select>
            </div>

            <div class="field">
              <label for="application_id">Select Student Assistant *</label>
              <select id="application_id" name="application_id" required>
                <option value="">-- Choose Student --</option>
                <?php foreach ($eligibleStudents as $student): ?>
                  <?php
                    $sName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
                    $sCode = (string) ($student['student_id_number'] ?? '');
                    $evalTag = !empty($student['evaluation_id']) ? ' (Evaluated)' : ' (Pending Evaluation)';
                    $isSel = ($selectedAppId > 0 && (int) $student['application_id'] === $selectedAppId);
                  ?>
                  <option value="<?php echo (int) $student['application_id']; ?>" <?php echo $isSel ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sName . ' - ' . $sCode . $evalTag, ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="performance_rating">Performance (1 - 5) *</label>
              <select id="performance_rating" name="performance_rating" required>
                <option value="5">5 - Excellent (Exceeds expectations)</option>
                <option value="4">4 - Very Good (High quality)</option>
                <option value="3" selected>3 - Satisfactory (Meets standards)</option>
                <option value="2">2 - Needs Improvement (Inconsistent)</option>
                <option value="1">1 - Poor (Unsatisfactory)</option>
              </select>
            </div>

            <div class="field">
              <label for="reliability_rating">Reliability (1 - 5) *</label>
              <select id="reliability_rating" name="reliability_rating" required>
                <option value="5">5 - Excellent (Always punctual & dependable)</option>
                <option value="4">4 - Very Good (Consistent)</option>
                <option value="3" selected>3 - Satisfactory (Regular attendance)</option>
                <option value="2">2 - Needs Improvement (Occasional tardiness)</option>
                <option value="1">1 - Poor (Frequently absent/late)</option>
              </select>
            </div>

            <div class="field">
              <label for="professionalism_rating">Professionalism (1 - 5) *</label>
              <select id="professionalism_rating" name="professionalism_rating" required>
                <option value="5">5 - Excellent (Exemplary conduct & demeanor)</option>
                <option value="4">4 - Very Good (Respectful & courteous)</option>
                <option value="3" selected>3 - Satisfactory (Appropriate conduct)</option>
                <option value="2">2 - Needs Improvement (Lacks initiative/focus)</option>
                <option value="1">1 - Poor (Unprofessional behavior)</option>
              </select>
            </div>

            <div class="field">
              <label for="comments">Supervisor Remarks & Recommendation</label>
              <input type="text" id="comments" name="comments" placeholder="Summary notes on student performance..." />
            </div>
          </div>

          <div class="actions">
            <button class="btn btn--primary" type="submit" <?php echo $canSubmitEvaluation ? '' : 'disabled'; ?>>
              Submit Performance Evaluation
            </button>
            <a class="btn btn--secondary" href="dashboard.php">Back to Dashboard</a>
          </div>
        </form>
      </section>

      <!-- Student Assistants Evaluation & Retention Status Table -->
      <section class="card">
        <div class="eyebrow">Office Roster & Assignment Decisions</div>
        <h1>Assigned Students Status</h1>
        <p>Overview of student assistants in <?php echo htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8'); ?>, their evaluations, retention decisions, and cumulative reshuffle count (maximum 3 reshuffles).</p>

        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Student Assistant</th>
                <th>Student ID</th>
                <th>Program</th>
                <th>Evaluation Status</th>
                <th>Average Score</th>
                <th>Reshuffle History</th>
                <th>Assignment Decision</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($eligibleStudents)): ?>
                <tr>
                  <td colspan="8" style="text-align:center;padding:24px;color:#64748b;">No student assistants currently assigned to this office.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($eligibleStudents as $student): ?>
                  <?php
                    $sName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
                    $sCode = (string) ($student['student_id_number'] ?? '');
                    $sProg = (string) ($student['program'] ?? '-');
                    $evalId = (int) ($student['evaluation_id'] ?? 0);
                    $hasEval = $evalId > 0;
                    $rating = (float) ($student['latest_rating'] ?? 0);
                    $retDecision = (string) ($student['retention_decision'] ?? 'pending');
                    $shuffCount = (int) ($student['reshuffle_count'] ?? 0);
                    $shuffStatus = (string) ($student['shuffle_request_status'] ?? '');
                  ?>
                  <tr>
                    <td>
                      <strong><?php echo htmlspecialchars($sName !== '' ? $sName : 'Student Assistant', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </td>
                    <td><code><?php echo htmlspecialchars($sCode, ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo htmlspecialchars($sProg, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                      <?php if ($hasEval): ?>
                        <span class="badge badge--success">Evaluated</span>
                        <div style="font-size:11px;color:#64748b;margin-top:3px;"><?php echo date('M d, Y', strtotime((string) $student['evaluated_at'])); ?></div>
                      <?php else: ?>
                        <span class="badge badge--warning">Pending Evaluation</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($hasEval && $rating > 0): ?>
                        <strong><?php echo number_format($rating, 1); ?> / 5.0</strong>
                      <?php else: ?>
                        <span style="color:#94a3b8;">--</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($shuffCount >= 3): ?>
                        <span class="shuffle-pill shuffle-pill--max">3 / 3 (Max Reached)</span>
                      <?php elseif ($shuffCount > 0): ?>
                        <span class="shuffle-pill shuffle-pill--warn"><?php echo $shuffCount; ?> / 3 Transfers</span>
                      <?php else: ?>
                        <span class="shuffle-pill">0 / 3 Transfers</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($shuffStatus === 'pending'): ?>
                        <span class="badge badge--warning">Reshuffle Pending Admin</span>
                      <?php elseif ($retDecision === 'retain'): ?>
                        <span class="badge badge--success">Retained in Office</span>
                      <?php elseif ($retDecision === 'reshuffle'): ?>
                        <span class="badge badge--info">Reshuffle Requested</span>
                      <?php elseif ($hasEval): ?>
                        <span class="badge badge--neutral">Awaiting Recommendation</span>
                      <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">Complete Evaluation First</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if (!$hasEval): ?>
                          <a class="btn btn--secondary" href="evaluation.php?application_id=<?php echo (int) $student['application_id']; ?>" style="height:32px;font-size:12px;padding:0 10px;">Evaluate</a>
                        <?php else: ?>
                          <button class="btn btn--secondary btn-decision-trigger"
                                  type="button"
                                  style="height:32px;font-size:12px;padding:0 10px;"
                                  data-eval-id="<?php echo $evalId; ?>"
                                  data-student-id="<?php echo (int) $student['student_id']; ?>"
                                  data-student-name="<?php echo htmlspecialchars($sName, ENT_QUOTES, 'UTF-8'); ?>"
                                  data-student-code="<?php echo htmlspecialchars($sCode, ENT_QUOTES, 'UTF-8'); ?>"
                                  data-avg-rating="<?php echo $rating; ?>"
                                  data-reshuffle-count="<?php echo $shuffCount; ?>">
                            Decision
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- POST-EVALUATION / RETENTION VS RESHUFFLE MODAL -->
<div id="post-eval-modal" class="modal-overlay" <?php echo ($postEvalStudent !== null || $openShuffleStudentId > 0) ? '' : 'style="display:none;"'; ?>>
  <div class="modal-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
      <div>
        <div class="eyebrow">Performance Evaluation Saved</div>
        <h2 id="modal-student-title" style="font-size:22px;font-weight:800;color:#0f172a;">
          <?php echo htmlspecialchars((string) ($postEvalStudent['name'] ?? 'Student Assistant'), ENT_QUOTES, 'UTF-8'); ?>
        </h2>
        <div id="modal-student-meta" style="font-size:13px;color:#64748b;margin-top:2px;">
          ID: <?php echo htmlspecialchars((string) ($postEvalStudent['student_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> • Rating: <?php echo htmlspecialchars((string) ($postEvalStudent['avg_rating'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>/5.0
        </div>
      </div>
      <button type="button" id="close-post-eval-btn" style="background:none;border:0;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1;">&times;</button>
    </div>

    <!-- Reshuffle Count Status Banner -->
    <div id="modal-reshuffle-status" style="margin:16px 0;padding:12px 14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;font-weight:600;color:#334155;">Student Reshuffle History:</span>
        <span id="modal-reshuffle-pill" class="shuffle-pill <?php echo (($postEvalStudent['reshuffle_count'] ?? 0) >= 3) ? 'shuffle-pill--max' : ''; ?>">
          <?php echo (int) ($postEvalStudent['reshuffle_count'] ?? 0); ?> of 3 Transfers
        </span>
      </div>
    </div>

    <!-- Step 1: Decision Choice -->
    <div id="decision-choice-view">
      <p style="font-size:14px;color:#475569;margin-bottom:20px;line-height:1.5;">
        Now that you have completed this student's evaluation, please choose whether you want to <strong>Retain</strong> the student in <strong><?php echo htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8'); ?></strong> or <strong>Request a Reshuffle / Office Transfer</strong>.
      </p>

      <?php if (($postEvalStudent['reshuffle_count'] ?? 0) >= 3): ?>
        <div style="padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;margin-bottom:18px;">
          <div style="font-weight:700;color:#991b1b;font-size:13px;display:flex;align-items:center;gap:6px;">
            <span>⚠️</span> Maximum Reshuffle Limit Reached (3 of 3)
          </div>
          <div style="font-size:12px;color:#7f1d1d;margin-top:4px;line-height:1.4;">
            This student has already been transferred 3 times across university offices. Under SAMS policy, no further reshuffling is permitted. The student will remain with your office.
          </div>
        </div>
      <?php endif; ?>

      <div style="display:flex;flex-direction:column;gap:12px;">
        <!-- Retain Option -->
        <form method="post">
          <?php echo sams_csrf_input_field(); ?>
          <input type="hidden" name="action" value="confirm_retention" />
          <input type="hidden" name="evaluation_id" id="retain-eval-id" value="<?php echo (int) ($postEvalStudent['eval_id'] ?? 0); ?>" />
          <input type="hidden" name="student_name" id="retain-student-name" value="<?php echo htmlspecialchars((string) ($postEvalStudent['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
          <button class="btn btn--green" type="submit" style="width:100%;height:46px;justify-content:center;gap:8px;">
            <span>✓</span> Retain Student in <?php echo htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8'); ?>
          </button>
        </form>

        <!-- Reshuffle Option -->
        <button id="show-reshuffle-form-btn"
                class="btn btn--amber"
                type="button"
                style="width:100%;height:46px;justify-content:center;gap:8px;"
                <?php echo (($postEvalStudent['reshuffle_count'] ?? 0) >= 3) ? 'disabled style="opacity:.45;cursor:not-allowed;"' : ''; ?>>
          <span>⇄</span> Request Office Reshuffle / Transfer
        </button>
      </div>
    </div>

    <!-- Step 2: Reshuffle Request Form (Revealed upon clicking reshuffle) -->
    <div id="reshuffle-form-view" style="display:none;margin-top:8px;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;color:#b45309;font-weight:700;font-size:14px;">
        <span>⇄</span> File Reshuffle Request for Admin Review
      </div>

      <form method="post" action="shuffle_request.php">
        <?php echo sams_csrf_input_field(); ?>
        <input type="hidden" name="from_student_id" id="shuffle-from-student-id" value="<?php echo (int) ($postEvalStudent['student_id'] ?? 0); ?>" />
        <input type="hidden" name="return_to" value="evaluation.php" />

        <div class="field" style="margin-bottom:12px;">
          <label for="to_student_id">Proposed Replacement Student (Optional)</label>
          <select name="to_student_id" id="to_student_id">
            <option value="0">Let Admin choose replacement student</option>
            <?php foreach ($replacementStudents as $rep): ?>
              <?php if ((int) $rep['student_id'] !== (int) ($postEvalStudent['student_id'] ?? 0)): ?>
                <option value="<?php echo (int) $rep['student_id']; ?>">
                  <?php echo htmlspecialchars(trim((string) $rep['first_name'] . ' ' . (string) $rep['last_name']) . ' (' . (string) $rep['student_id_number'] . ') - ' . (string) ($rep['preferred_office'] ?? 'General'), ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field" style="margin-bottom:16px;">
          <label for="shuffle_reason">Reason for Reshuffle Request *</label>
          <textarea name="reason" id="shuffle_reason" required placeholder="Describe the office operational needs, student schedule constraints, or skills matching rationale..."></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" id="back-to-decision-btn" class="btn btn--secondary">Back</button>
          <button type="submit" class="btn btn--amber">Submit Reshuffle to Admin</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="../assets/js/admin-notifications.js?v=20260922"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('post-eval-modal');
  const closeBtn = document.getElementById('close-post-eval-btn');
  const decisionView = document.getElementById('decision-choice-view');
  const reshuffleView = document.getElementById('reshuffle-form-view');
  const showReshuffleBtn = document.getElementById('show-reshuffle-form-btn');
  const backToDecisionBtn = document.getElementById('back-to-decision-btn');

  // Trigger modal when clicking Decision button in table
  document.querySelectorAll('.btn-decision-trigger').forEach(btn => {
    btn.addEventListener('click', function () {
      const evalId = this.dataset.evalId;
      const studentId = this.dataset.studentId;
      const studentName = this.dataset.studentName;
      const studentCode = this.dataset.studentCode;
      const avgRating = this.dataset.avgRating;
      const reshuffleCount = parseInt(this.dataset.reshuffleCount, 10) || 0;

      document.getElementById('modal-student-title').textContent = studentName;
      document.getElementById('modal-student-meta').textContent = 'ID: ' + studentCode + ' • Rating: ' + avgRating + '/5.0';

      const pill = document.getElementById('modal-reshuffle-pill');
      pill.textContent = reshuffleCount + ' of 3 Transfers';
      if (reshuffleCount >= 3) {
        pill.className = 'shuffle-pill shuffle-pill--max';
        showReshuffleBtn.disabled = true;
        showReshuffleBtn.style.opacity = '0.45';
        showReshuffleBtn.style.cursor = 'not-allowed';
      } else {
        pill.className = reshuffleCount > 0 ? 'shuffle-pill shuffle-pill--warn' : 'shuffle-pill';
        showReshuffleBtn.disabled = false;
        showReshuffleBtn.style.opacity = '1';
        showReshuffleBtn.style.cursor = 'pointer';
      }

      document.getElementById('retain-eval-id').value = evalId;
      document.getElementById('retain-student-name').value = studentName;
      document.getElementById('shuffle-from-student-id').value = studentId;

      decisionView.style.display = 'block';
      reshuffleView.style.display = 'none';
      modal.style.display = 'flex';
    });
  });

  if (showReshuffleBtn) {
    showReshuffleBtn.addEventListener('click', function () {
      decisionView.style.display = 'none';
      reshuffleView.style.display = 'block';
    });
  }

  if (backToDecisionBtn) {
    backToDecisionBtn.addEventListener('click', function () {
      reshuffleView.style.display = 'none';
      decisionView.style.display = 'block';
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      modal.style.display = 'none';
    });
  }

  modal.addEventListener('click', function (e) {
    if (e.target === modal) {
      modal.style.display = 'none';
    }
  });
});
</script>
</body>
</html>