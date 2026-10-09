<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/duty_excuses.php';
require_once __DIR__ . '/../config/audit.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'student') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_duty_excuses_ensure_schema($pdo);

$userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
$studentStmt = $pdo->prepare(
    'SELECT s.student_id, s.student_id_number, u.first_name, u.last_name, u.email
     FROM students s
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE s.user_id = :user_id LIMIT 1'
);
$studentStmt->execute(['user_id' => $userId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$studentId = (int) ($student['student_id'] ?? 0);
$studentName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
if ($studentName === '') {
    $studentName = (string) ($user['name'] ?? 'Student Assistant');
}
$studentCode = (string) ($student['student_id_number'] ?? '');

$applicationStmt = $pdo->prepare(
    "SELECT a.application_id, a.term_id, COALESCE(NULLIF(TRIM(a.preferred_office), ''), 'STUDENT DEVELOPMENT AND ACTIVITIES OFFICE') AS office_name
     FROM applications a
     WHERE a.student_id = :student_id AND a.status IN ('approved', 'deployed')
     ORDER BY a.application_id DESC LIMIT 1"
);
$applicationStmt->execute(['student_id' => $studentId]);
$application = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$applicationId = (int) ($application['application_id'] ?? 0);
$termId = (int) ($application['term_id'] ?? 0);
$officeName = trim((string) ($application['office_name'] ?? ''));

// Fetch student's deployed duty schedules for reference
$schedulesStmt = $pdo->prepare(
    "SELECT ds.duty_id, ds.day_of_week, ds.start_time, ds.end_time,
            COALESCE(NULLIF(TRIM(ds.office_name), ''), :office_name) AS duty_office
     FROM duty_schedules ds
     WHERE ds.application_id = :application_id
       AND ds.term_id = :term_id
       AND ds.status IN ('deployed', 'accepted', 'assigned')
     ORDER BY FIELD(ds.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), ds.start_time ASC"
);
$schedulesStmt->execute([
    'application_id' => $applicationId,
    'term_id' => $termId,
    'office_name' => $officeName !== '' ? $officeName : 'Assigned Office',
]);
$activeSchedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(sams_csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid security token. Please refresh the page and try again.');
        }

        if ($studentId <= 0 || $applicationId <= 0 || $termId <= 0) {
            throw new RuntimeException('You must have an active and deployed Student Assistant application to file a Duty Excuse.');
        }

        $dutyDate = trim((string) ($_POST['duty_date'] ?? ''));
        $excuseType = trim((string) ($_POST['excuse_type'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $proof = $_FILES['proof'] ?? null;

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $dutyDate);
        if (!$date || $date->format('Y-m-d') !== $dutyDate) {
            throw new RuntimeException('Please choose a valid duty date.');
        }

        // Allow filing today, future dates, or up to 7 days retroactively (e.g. unexpected medical emergency)
        $minDate = (new DateTimeImmutable('today'))->modify('-7 days')->format('Y-m-d');
        if ($dutyDate < $minDate) {
            throw new RuntimeException('Duty excuse can only be filed up to 7 days retroactively.');
        }

        $validTypes = sams_duty_excuse_types();
        if (!isset($validTypes[$excuseType])) {
            throw new RuntimeException('Please select a valid reason category.');
        }

        if ($reason === '' || mb_strlen($reason) < 10) {
            throw new RuntimeException('Please provide a detailed explanation of at least 10 characters.');
        }

        if (!$proof || (int) ($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Proof file is required (e.g. Medical Certificate, Excuse Slip, Scholarship Slip, or Formal Letter).');
        }

        if ((int) $proof['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('The proof file size must not exceed 5 MB.');
        }

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $proof['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('Only PDF, JPG, or PNG files are supported for proof.');
        }

        // Check if an excuse has already been filed for this date
        $checkDup = $pdo->prepare(
            'SELECT excuse_id FROM duty_excuses WHERE student_id = :student_id AND duty_date = :duty_date LIMIT 1'
        );
        $checkDup->execute(['student_id' => $studentId, 'duty_date' => $dutyDate]);
        if ($checkDup->fetchColumn()) {
            throw new RuntimeException('You have already filed a Duty Excuse for ' . date('F j, Y', strtotime($dutyDate)) . '.');
        }

        $dayOfWeek = $date->format('l');

        // Store file
        $ext = match (strtolower($mime)) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            default => 'jpg',
        };
        $originalName = basename((string) $proof['name']);
        $storedName = 'excuse_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $relativeDir = 'uploads/duty_excuse/student_' . $studentId;
        $absoluteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Failed to create destination folder for proof upload.');
        }

        $destinationPath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string) $proof['tmp_name'], $destinationPath)) {
            throw new RuntimeException('Failed to store the uploaded proof file.');
        }

        // Insert into duty_excuses
        $insertExcuse = $pdo->prepare(
            'INSERT INTO duty_excuses
                (student_id, application_id, term_id, duty_date, day_of_week, office_name,
                 excuse_type, reason, proof_original_name, proof_stored_name, proof_path,
                 proof_mime, proof_size, status, submitted_at)
             VALUES
                (:student_id, :application_id, :term_id, :duty_date, :day_of_week, :office_name,
                 :excuse_type, :reason, :proof_original_name, :proof_stored_name, :proof_path,
                 :proof_mime, :proof_size, "excused", NOW())'
        );
        $insertExcuse->execute([
            'student_id' => $studentId,
            'application_id' => $applicationId,
            'term_id' => $termId,
            'duty_date' => $dutyDate,
            'day_of_week' => $dayOfWeek,
            'office_name' => $officeName,
            'excuse_type' => $excuseType,
            'reason' => $reason,
            'proof_original_name' => $originalName,
            'proof_stored_name' => $storedName,
            'proof_path' => $relativeDir . '/' . $storedName,
            'proof_mime' => $mime,
            'proof_size' => (int) $proof['size'],
        ]);

        // Auto-sync into attendance_logs so the student is directly recorded as "excused"
        sams_duty_excuse_sync_attendance_logs(
            $pdo,
            $applicationId,
            $termId,
            $dutyDate,
            $dayOfWeek,
            $excuseType,
            $reason
        );

        $excuseId = (int) $pdo->lastInsertId();

        sams_log_audit(
            $pdo,
            'DUTY_EXCUSE',
            'Duty Excuses',
            "Student {$studentName} filed a Duty Excuse for {$dutyDate} ({$excuseType}).",
            [
                'duty_date' => $dutyDate,
                'day_of_week' => $dayOfWeek,
                'excuse_type' => $excuseType,
                'reason' => $reason,
                'proof_file' => $originalName,
                'office_name' => $officeName,
            ],
            $excuseId,
            'duty_excuse'
        );

        $message = 'Your Duty Excuse for ' . date('F j, Y (l)', strtotime($dutyDate)) . ' has been recorded as Excused and notified to your Supervisor and Admin.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Fetch past duty excuses
$historyStmt = $pdo->prepare(
    'SELECT excuse_id, duty_date, day_of_week, excuse_type, reason, proof_path, proof_original_name, status, submitted_at, admin_notes
     FROM duty_excuses
     WHERE student_id = :student_id
     ORDER BY duty_date DESC, excuse_id DESC'
);
$historyStmt->execute(['student_id' => $studentId]);
$excuses = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function h(?string $str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Duty Excuse | Student Portal | SAMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/sams-shell.css">
  <style>
    :root {
      --font-family-base: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      --color-primary: #155dfc;
      --color-primary-hover: #124fd4;
      --color-primary-subtle: #f0f5ff;
      --color-dark: #101828;
      --color-text-subtle: #475467;
      --color-border: #e4e7ec;
      --color-bg-light: #f8fafc;
      --color-card-bg: #ffffff;
      --color-excused: #b45309;
      --color-excused-bg: #fef3c7;
      --color-excused-bd: #fde68a;
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 16px;
      --radius-pill: 9999px;
      --shadow-sm: 0 1px 3px rgba(16, 24, 40, .05);
      --shadow-md: 0 4px 12px rgba(16, 24, 40, .07);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--font-family-base);
      background-color: var(--color-bg-light);
      color: var(--color-dark);
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar - matches standard student pages */
    .sidebar {
      width: 260px;
      background: #111827;
      color: #fff;
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      min-height: 100vh;
      border-right: 1px solid rgba(255,255,255,.08);
    }
    .sidebar__brand {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 24px 20px;
      border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .sidebar__logo {
      width: 38px;
      height: 38px;
      background: var(--color-primary);
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      color: #fff;
      font-size: 16px;
    }
    .sidebar__brand-name { font-size: 17px; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
    .sidebar__brand-sub { font-size: 11px; color: #9ca3af; }
    .sidebar__nav { padding: 18px 12px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      height: 44px;
      padding: 0 14px;
      border-radius: var(--radius-sm);
      font-size: 14px;
      font-weight: 600;
      color: #9ca3af;
      text-decoration: none;
      transition: all .15s ease;
    }
    .nav-item:hover { color: #fff; background: rgba(255,255,255,.06); }
    .nav-item--active { background: var(--color-primary); color: #fff !important; }
    .nav-item__icon { width: 20px; height: 20px; flex-shrink: 0; }
    .sidebar__footer {
      padding: 16px;
      border-top: 1px solid rgba(255,255,255,.08);
      background: rgba(0,0,0,.15);
    }
    .sidebar__user { display: flex; flex-direction: column; gap: 2px; }
    .sidebar__user-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
    .sidebar__user-name { font-size: 13px; font-weight: 700; color: #e5e7eb; }
    .sidebar__user-id { font-size: 12px; color: #9ca3af; }

    /* Main layout */
    .content-area {
      flex: 1;
      padding: 32px 40px;
      max-width: 1200px;
      overflow-y: auto;
    }
    .header-bar {
      margin-bottom: 24px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 16px;
    }
    .header-bar h1 {
      font-size: 26px;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: #0f172a;
    }
    .header-bar p {
      color: var(--color-text-subtle);
      font-size: 14px;
      margin-top: 4px;
    }

    /* Notice banner */
    .notice-box {
      background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
      border: 1px solid #bfdbfe;
      border-radius: var(--radius-md);
      padding: 18px 20px;
      margin-bottom: 24px;
      display: flex;
      gap: 14px;
      align-items: flex-start;
    }
    .notice-box__icon {
      color: var(--color-primary);
      flex-shrink: 0;
      margin-top: 2px;
    }
    .notice-box__title {
      font-size: 14px;
      font-weight: 700;
      color: #1e3a8a;
      margin-bottom: 4px;
    }
    .notice-box__desc {
      font-size: 13px;
      color: #334155;
      line-height: 1.5;
    }

    /* Messages */
    .alert {
      padding: 14px 18px;
      border-radius: var(--radius-sm);
      font-size: 14px;
      font-weight: 500;
      margin-bottom: 20px;
    }
    .alert--success {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }
    .alert--error {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    /* Grid layout */
    .excuse-grid {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 24px;
      align-items: start;
    }
    @media (max-width: 900px) {
      .excuse-grid { grid-template-columns: 1fr; }
    }

    /* Card styling */
    .card {
      background: var(--color-card-bg);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: var(--shadow-sm);
    }
    .card__header {
      margin-bottom: 20px;
      padding-bottom: 14px;
      border-bottom: 1px solid var(--color-border);
    }
    .card__title {
      font-size: 17px;
      font-weight: 700;
      color: #0f172a;
    }
    .card__sub {
      font-size: 13px;
      color: var(--color-text-subtle);
      margin-top: 2px;
    }

    /* Form elements */
    .form-group {
      margin-bottom: 18px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .form-label {
      font-size: 13px;
      font-weight: 700;
      color: #334155;
    }
    .form-label .req {
      color: #ef4444;
      margin-left: 2px;
    }
    .form-input, .form-select, .form-textarea {
      width: 100%;
      padding: 11px 14px;
      border: 1px solid #cbd5e1;
      border-radius: var(--radius-sm);
      font-family: inherit;
      font-size: 14px;
      color: #0f172a;
      background: #fff;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
      outline: none;
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(21, 93, 252, .12);
    }
    .form-textarea {
      min-height: 100px;
      resize: vertical;
    }
    .form-hint {
      font-size: 12px;
      color: #64748b;
    }

    /* File dropzone */
    .file-dropzone {
      border: 2px dashed #cbd5e1;
      border-radius: var(--radius-md);
      padding: 24px 16px;
      text-align: center;
      background: #f8fafc;
      cursor: pointer;
      transition: all .2s;
    }
    .file-dropzone:hover {
      border-color: var(--color-primary);
      background: #f0f5ff;
    }
    .file-dropzone input { display: none; }
    .file-dropzone svg {
      width: 36px;
      height: 36px;
      stroke: #64748b;
      margin-bottom: 8px;
    }
    .file-dropzone .dropzone-title {
      font-size: 13px;
      font-weight: 600;
      color: #334155;
    }
    .file-dropzone .dropzone-sub {
      font-size: 11px;
      color: #94a3b8;
      margin-top: 4px;
    }
    .selected-filename {
      margin-top: 10px;
      font-size: 12px;
      font-weight: 700;
      color: var(--color-primary);
      display: none;
    }

    /* Submit button */
    .btn-submit {
      width: 100%;
      background: var(--color-primary);
      color: #fff;
      padding: 13px 20px;
      border: none;
      border-radius: var(--radius-sm);
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 2px 6px rgba(21, 93, 252, .25);
      transition: background .15s, transform .05s;
    }
    .btn-submit:hover { background: var(--color-primary-hover); }
    .btn-submit:active { transform: scale(0.99); }

    /* Schedule pills widget */
    .sched-card {
      background: #ffffff;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      padding: 16px;
      margin-bottom: 20px;
    }
    .sched-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 12px;
      background: #f8fafc;
      border-radius: var(--radius-sm);
      margin-bottom: 8px;
      border-left: 3px solid var(--color-primary);
      font-size: 13px;
    }
    .sched-item:last-child { margin-bottom: 0; }
    .sched-day { font-weight: 700; color: #1e293b; }
    .sched-time { color: #475467; font-weight: 500; }
    .sched-empty { font-size: 13px; color: #94a3b8; font-style: italic; }

    /* History table */
    .history-card {
      margin-top: 24px;
      grid-column: 1 / -1;
    }
    .table-responsive {
      overflow-x: auto;
      margin-top: 12px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    th {
      text-align: left;
      padding: 12px 14px;
      background: #f8fafc;
      color: #475467;
      font-weight: 700;
      border-bottom: 1px solid var(--color-border);
      white-space: nowrap;
    }
    td {
      padding: 14px;
      border-bottom: 1px solid #f1f5f9;
      color: #1e293b;
      vertical-align: top;
    }
    tr:hover td { background: #fafafa; }

    /* Badges */
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: var(--radius-pill);
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }
    .badge--excused {
      background: var(--color-excused-bg);
      color: var(--color-excused);
      border: 1px solid var(--color-excused-bd);
    }
    .link-proof {
      color: var(--color-primary);
      text-decoration: none;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .link-proof:hover { text-decoration: underline; }
  </style>
</head>
<body>

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar__brand">
      <div class="sidebar__logo">NU</div>
      <div>
        <div class="sidebar__brand-name">SAMS</div>
        <div class="sidebar__brand-sub">Student Portal</div>
      </div>
    </div>

    <nav class="sidebar__nav" aria-label="Main navigation">
      <a class="nav-item" href="dashboard.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 11.5L12 4l9 7.5M5 10.5V20h5v-5h4v5h5v-9.5"/>
        </svg>
        Dashboard
      </a>
      <a class="nav-item" href="schedule.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="4" y="5" width="16" height="15" rx="2"/>
          <path d="M8 3v4M16 3v4M4 9h16"/>
        </svg>
        My Schedule
      </a>
      <a class="nav-item" href="temporary_duty_request.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 4v16M4 12h16"/>
        </svg>
        Temporary Duty Request
      </a>
      <a class="nav-item nav-item--active" href="duty_excuse.php" aria-current="page">
        <svg class="nav-item__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 12h6M9 16h4M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
          <path d="M9 7h2"/>
        </svg>
        Duty Excuse
      </a>
      <a class="nav-item" href="attendance_history.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M5 4h10l4 4v12H5zM15 4v4h4M8 11h8M8 15h8"/>
        </svg>
        Duty-Hour Report
      </a>
      <a class="nav-item" href="profile.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="8" r="4"/>
          <path d="M6 20c1.5-3.5 4-5 6-5s4.5 1.5 6 5"/>
        </svg>
        Profile
      </a>
    </nav>

    <div class="sidebar__footer">
      <div class="sidebar__user">
        <span class="sidebar__user-label">Student Assistant</span>
        <span class="sidebar__user-name"><?php echo h($studentName); ?></span>
        <span class="sidebar__user-id"><?php echo h($studentCode); ?></span>
      </div>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="content-area">
    <div class="header-bar">
      <div>
        <h1>Filing of Duty Excuse</h1>
        <p>Submit a notice of absence along with official proof for your scheduled duty shift.</p>
      </div>
    </div>

    <!-- Informational Banner -->
    <div class="notice-box">
      <div class="notice-box__icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <path d="M12 16v-4M12 8h.01"/>
        </svg>
      </div>
      <div>
        <div class="notice-box__title">Notice Regarding Duty Excuses</div>
        <div class="notice-box__desc">
          Submitting a Duty Excuse is <strong>automatically recorded as "Excused"</strong> in your attendance log so you will not be marked as <em>Absent</em>. It does not require separate admin approval, but will immediately notify your <strong>Supervisor</strong> and <strong>Admin</strong> along with your attached proof document.
        </div>
      </div>
    </div>

    <?php if ($message !== ''): ?>
      <div class="alert alert--success">
        ✓ <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="alert alert--error">
        ✕ <?php echo h($error); ?>
      </div>
    <?php endif; ?>

    <div class="excuse-grid">
      <!-- Form Card -->
      <div class="card">
        <div class="card__header">
          <h2 class="card__title">Duty Excuse Notice Form</h2>
          <p class="card__sub">Fill in the details below and upload your official supporting proof.</p>
        </div>

        <form action="duty_excuse.php" method="POST" enctype="multipart/form-data" id="excuseForm">
          <input type="hidden" name="_csrf" value="<?php echo h(sams_csrf_token()); ?>">

          <div class="form-group">
            <label class="form-label" for="duty_date">
              Duty Date to Excuse <span class="req">*</span>
            </label>
            <input type="date" id="duty_date" name="duty_date" class="form-input" required
                   value="<?php echo h($_POST['duty_date'] ?? date('Y-m-d')); ?>"
                   min="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
            <span class="form-hint" id="dateHint">Select the date of the duty shift you cannot attend.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="excuse_type">
              Reason Category <span class="req">*</span>
            </label>
            <select id="excuse_type" name="excuse_type" class="form-select" required>
              <option value="">-- Select a Reason Category --</option>
              <?php foreach (sams_duty_excuse_types() as $val => $label): ?>
                <option value="<?php echo h($val); ?>" <?php echo (($_POST['excuse_type'] ?? '') === $val) ? 'selected' : ''; ?>>
                  <?php echo h($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="reason">
              Detailed Explanation <span class="req">*</span>
            </label>
            <textarea id="reason" name="reason" class="form-textarea" required
                      placeholder="Provide a clear explanation of your situation (e.g. ill with fever and advised to rest, processing scholarship documents at the scholarship office, academic defense)..."><?php echo h($_POST['reason'] ?? ''); ?></textarea>
            <span class="form-hint">Must be at least 10 characters.</span>
          </div>

          <div class="form-group">
            <label class="form-label">
              Verification Proof (File Upload) <span class="req">*</span>
            </label>
            <label class="file-dropzone" for="proof">
              <input type="file" id="proof" name="proof" accept=".pdf,.png,.jpg,.jpeg" required onchange="handleFileChange(this)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
              </svg>
              <div class="dropzone-title">Click to upload Proof document</div>
              <div class="dropzone-sub">Medical Certificate, Excuse Letter, Scholarship Slip (PDF, JPG, PNG up to 5MB)</div>
              <div class="selected-filename" id="selectedFilename"></div>
            </label>
          </div>

          <button type="submit" class="btn-submit">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
            </svg>
            Submit Duty Excuse
          </button>
        </form>
      </div>

      <!-- Right Column: Current Schedules and Tips -->
      <div>
        <div class="card" style="margin-bottom: 20px;">
          <div class="card__header">
            <h3 class="card__title">Your Assigned Duty Schedule</h3>
            <p class="card__sub"><?php echo h($officeName !== '' ? $officeName : 'Office Assignment'); ?></p>
          </div>
          <?php if (!empty($activeSchedules)): ?>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <?php foreach ($activeSchedules as $sched): ?>
                <div class="sched-item">
                  <span class="sched-day"><?php echo h($sched['day_of_week']); ?></span>
                  <span class="sched-time">
                    <?php echo date('g:i A', strtotime((string) $sched['start_time'])); ?> -
                    <?php echo date('g:i A', strtotime((string) $sched['end_time'])); ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="sched-empty">No active duty schedule assigned for the current term.</div>
          <?php endif; ?>
        </div>

        <div class="card" style="background:#f8fafc;">
          <h4 style="font-size:14px; font-weight:700; color:#1e293b; margin-bottom:8px;">Important Reminders:</h4>
          <ul style="font-size:13px; color:#475467; padding-left:18px; line-height:1.6;">
            <li>Whenever possible, submit your notice before your scheduled shift start time or cutoff.</li>
            <li>Ensure the attached verification proof is clear, valid, and legible.</li>
            <li>Being excused means your absence is justified and will not count as an unexcused Absence penalty in your duty report.</li>
          </ul>
        </div>
      </div>

      <!-- History Table -->
      <div class="card history-card">
        <div class="card__header">
          <h2 class="card__title">Your Duty Excuse History</h2>
          <p class="card__sub">Complete log of all your submitted notices of absence.</p>
        </div>

        <div class="table-responsive">
          <?php if (!empty($excuses)): ?>
            <table>
              <thead>
                <tr>
                  <th>Excuse Date</th>
                  <th>Day</th>
                  <th>Category</th>
                  <th>Reason / Explanation</th>
                  <th>Proof Document</th>
                  <th>Status</th>
                  <th>Date Submitted</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($excuses as $item): ?>
                  <tr>
                    <td><strong><?php echo date('M d, Y', strtotime((string) $item['duty_date'])); ?></strong></td>
                    <td><?php echo h($item['day_of_week']); ?></td>
                    <td><span style="font-weight:600;"><?php echo h($item['excuse_type']); ?></span></td>
                    <td style="max-width:320px;"><?php echo nl2br(h($item['reason'])); ?></td>
                    <td>
                      <?php if (!empty($item['proof_path'])): ?>
                        <a href="../<?php echo h($item['proof_path']); ?>" target="_blank" class="link-proof">
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                          </svg>
                          View Proof
                        </a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge badge--excused">
                        ✓ Excused
                      </span>
                    </td>
                    <td style="color:#64748b; font-size:12px;">
                      <?php echo date('M d, Y g:i A', strtotime((string) $item['submitted_at'])); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div style="text-align:center; padding:32px 16px; color:#64748b;">
              You have not submitted any Duty Excuses yet.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <script>
    function handleFileChange(input) {
      const display = document.getElementById('selectedFilename');
      if (input.files && input.files[0]) {
        display.textContent = 'Selected file: ' + input.files[0].name;
        display.style.display = 'block';
      } else {
        display.style.display = 'none';
      }
    }
  </script>
</body>
</html>
