<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function sams_student_day_rank(string $day): int
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

function sams_student_next_date(string $day): string
{
  $targetMap = [
    'Monday' => 1,
    'Tuesday' => 2,
    'Wednesday' => 3,
    'Thursday' => 4,
    'Friday' => 5,
    'Saturday' => 6,
  ];

  if (!isset($targetMap[$day])) {
    return date('Y-m-d');
  }

  $today = (int) date('N');
  $target = $targetMap[$day];
  $diff = $target - $today;
  if ($diff < 0) {
    $diff += 7;
  }

  $date = new DateTimeImmutable('today');
  if ($diff > 0) {
    $date = $date->modify('+' . $diff . ' days');
  }

  return $date->format('Y-m-d');
}

function sams_student_time_label(string $time): string
{
  $timestamp = strtotime($time);
  return $timestamp ? date('g:i A', $timestamp) : $time;
}

function sams_student_schedule_status_label(string $status): string
{
  return match ($status) {
    'deployed' => 'Deployed',
    'accepted' => 'Accepted',
    'declined' => 'Declined',
    default => 'Pending',
  };
}

function sams_student_attendance_status_label(string $status): string
{
  return match ($status) {
    'present', 'active', 'completed' => 'Present',
    'late' => 'Late',
    'absent', 'incomplete' => 'Absent',
    default => ucfirst((string) $status),
  };
}

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
  header('Location: ../login.php');
  exit;
}

$pendingApplicationStatus = strtolower((string) ($currentUser['application_status'] ?? ''));
if (in_array($pendingApplicationStatus, ['pending', 'rejected'], true)) {
  header('Location: ../status.php');
  exit;
}

$pdo = sams_pdo();
$studentInfoStmt = $pdo->prepare(
  'SELECT s.student_id AS student_db_id, s.student_id_number AS student_code, u.first_name, u.last_name
   FROM students s
   INNER JOIN users u ON u.user_id = s.user_id
   WHERE s.user_id = :user_id
   LIMIT 1'
);
$studentInfoStmt->execute(['user_id' => (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
$studentInfo = $studentInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$studentDbId = (int) ($studentInfo['student_db_id'] ?? 0);
$studentName = trim((string) ($studentInfo['first_name'] ?? '') . ' ' . (string) ($studentInfo['last_name'] ?? ''));
if ($studentName === '') {
  $studentName = (string) ($currentUser['name'] ?? 'Student');
}
$studentCode = (string) ($studentInfo['student_code'] ?? ($currentUser['student_id'] ?? ''));

$applicationId = 0;
$applicationStmt = $pdo->prepare(
  'SELECT application_id, term_id
   FROM applications
   WHERE student_id = :student_id
   ORDER BY application_id DESC
   LIMIT 1'
);
$applicationStmt->execute(['student_id' => $studentDbId]);
$applicationRow = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$applicationId = (int) ($applicationRow['application_id'] ?? 0);
$applicationTermId = (int) ($applicationRow['term_id'] ?? 0);

$studentSchedules = [];
$attendanceTotals = [
  'present' => 0,
  'late' => 0,
  'absent' => 0,
  'incomplete' => 0,
  'total' => 0,
];
$renderedHours = 0.0;
$lastAttendance = null;
$attendanceHistory = [];
$allAttendanceLogs = [];

if ($studentDbId > 0) {
  $scheduleStmt = $pdo->prepare(
        "SELECT ds.duty_id AS id,
          COALESCE(NULLIF(TRIM(ds.office_name), ''), NULLIF(TRIM(a.preferred_office), ''), 'Unassigned') AS office_name,
          ds.day_of_week, ds.start_time AS time_start, ds.end_time AS time_end, ds.status,
            ds.term_id, t.term_name, t.term_year AS school_year
     FROM duty_schedules ds
     LEFT JOIN applications a ON a.application_id = ds.application_id
     LEFT JOIN terms t ON t.term_id = ds.term_id
     WHERE a.student_id = :student_id
     ORDER BY FIELD(ds.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), ds.start_time ASC"
  );
  $scheduleStmt->execute(['student_id' => $studentDbId]);
  $studentSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

  $renderedHoursStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in_time, clock_out_time) / 3600), 0)
     FROM attendance_logs
     WHERE application_id = :application_id AND clock_in_time IS NOT NULL AND clock_out_time IS NOT NULL'
  );
  $renderedHoursStmt->execute(['application_id' => $applicationId]);
  $renderedHours = (float) $renderedHoursStmt->fetchColumn();

  $allAttendanceLogs = sams_attendance_normalize_student_logs($pdo, $applicationId, $applicationTermId > 0 ? $applicationTermId : null);
  $attendanceHistory = array_slice($allAttendanceLogs, 0, 5);
  $lastAttendance = $attendanceHistory[0] ?? null;

  foreach ($allAttendanceLogs as $attendanceRow) {
    $status = sams_attendance_display_status((string) ($attendanceRow['status'] ?? ''));
    if ($status === 'present' || $status === 'active' || $status === 'completed') {
      $attendanceTotals['present']++;
    } elseif ($status === 'late') {
      $attendanceTotals['late']++;
    } elseif ($status === 'absent' || $status === 'incomplete') {
      $attendanceTotals['absent']++;
    }
    $attendanceTotals['total']++;
  }

  $attendanceTotals['incomplete'] = 0;
}

$todayName = date('l');
$todaySchedules = array_values(array_filter($studentSchedules, static function (array $schedule) use ($todayName): bool {
  return (string) ($schedule['day_of_week'] ?? '') === $todayName;
}));

$futureSchedules = $studentSchedules;

$totalSchedules = count($studentSchedules);
$acceptedSchedules = 0;
$pendingSchedules = 0;
$declinedSchedules = 0;
$totalHours = 0.0;
$respondedSchedules = 0;

foreach ($studentSchedules as $schedule) {
  $status = (string) ($schedule['status'] ?? 'pending');
  if ($status === 'accepted' || $status === 'deployed') {
    $acceptedSchedules++;
    $respondedSchedules++;
  } elseif ($status === 'declined') {
    $declinedSchedules++;
    $respondedSchedules++;
  } else {
    $pendingSchedules++;
  }

  $startTime = strtotime((string) ($schedule['time_start'] ?? ''));
  $endTime = strtotime((string) ($schedule['time_end'] ?? ''));
  if ($startTime && $endTime && $endTime > $startTime) {
    $totalHours += ($endTime - $startTime) / 3600;
  }
}

$responseRate = $totalSchedules > 0 ? (int) round(($respondedSchedules / $totalSchedules) * 100) : 0;
$acceptedRate = $totalSchedules > 0 ? (int) round(($acceptedSchedules / $totalSchedules) * 100) : 0;
$pendingRate = $totalSchedules > 0 ? (int) round(($pendingSchedules / $totalSchedules) * 100) : 0;
$attendanceRate = $attendanceTotals['total'] > 0 ? (int) round((($attendanceTotals['present'] + $attendanceTotals['late']) / $attendanceTotals['total']) * 100) : 0;
$attendanceLateRate = $attendanceTotals['total'] > 0 ? (int) round(($attendanceTotals['late'] / $attendanceTotals['total']) * 100) : 0;

$currentAssignment = null;
$nextDuty = null;
if (!empty($studentSchedules)) {
  $todayRank = (int) date('N');
  $sortedSchedules = $studentSchedules;
  usort($sortedSchedules, static function (array $left, array $right) use ($todayRank): int {
    $leftOffset = sams_student_day_rank((string) ($left['day_of_week'] ?? '')) - $todayRank;
    if ($leftOffset < 0) {
      $leftOffset += 7;
    }

    $rightOffset = sams_student_day_rank((string) ($right['day_of_week'] ?? '')) - $todayRank;
    if ($rightOffset < 0) {
      $rightOffset += 7;
    }

    $offsetCompare = $leftOffset <=> $rightOffset;
    if ($offsetCompare !== 0) {
      return $offsetCompare;
    }

    return strcmp((string) ($left['time_start'] ?? ''), (string) ($right['time_start'] ?? ''));
  });

  $nextDuty = $sortedSchedules[0] ?? null;

  foreach ($sortedSchedules as $schedule) {
    $status = (string) ($schedule['status'] ?? '');
    if ($status === 'accepted' || $status === 'deployed') {
      $currentAssignment = $schedule;
      break;
    }
  }

  if ($currentAssignment === null) {
    $currentAssignment = $nextDuty;
  }
}

$todayScheduleLabel = !empty($todaySchedules) ? 'Today' : 'No Duty Today';
$todayScheduleCount = count($todaySchedules);

// Fallback notifications (used when announcements table is not available)
$notifications = [];
if ($pendingSchedules > 0) {
  $notifications[] = [
    'label' => 'Schedule confirmations',
    'message' => $pendingSchedules . ' pending assignment' . ($pendingSchedules > 1 ? 's' : '') . ' need your response.',
    'tone' => 'yellow',
  ];
}
if ($todayScheduleCount > 0) {
  $notifications[] = [
    'label' => 'Today',
    'message' => 'You have ' . $todayScheduleCount . ' duty' . ($todayScheduleCount > 1 ? 'ies' : '') . ' scheduled for today.',
    'tone' => 'blue',
  ];
}
if ($lastAttendance && in_array((string) ($lastAttendance['status'] ?? ''), ['late'], true)) {
  $notifications[] = [
    'label' => 'Attendance review',
    'message' => 'Your latest attendance log was marked ' . sams_student_attendance_status_label((string) $lastAttendance['status']) . '.',
    'tone' => 'purple',
  ];
}
if (empty($notifications)) {
  $notifications[] = [
    'label' => 'All clear',
    'message' => 'You have no pending schedule actions right now.',
    'tone' => 'green',
  ];
}

$notificationCount = count($notifications);

// Check for approved application without schedules (waiting for deployment)
$waitingForDeployment = false;
try {
  if ($applicationId > 0) {
    $checkStmt = $pdo->prepare(
      'SELECT COUNT(*) FROM duty_schedules WHERE application_id = :aid'
    );
    $checkStmt->execute(['aid' => $applicationId]);
    $hasSchedules = (int) $checkStmt->fetchColumn() > 0;
    if (($applicationRow['application_id'] ?? 0) > 0) {
      // load latest application status
      $appStatusStmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = :aid LIMIT 1');
      $appStatusStmt->execute(['aid' => $applicationId]);
      $appStatus = (string) $appStatusStmt->fetchColumn();
      if ($appStatus === 'approved' && !$hasSchedules) {
        $waitingForDeployment = true;
      }
    }
  }
} catch (Throwable $e) {
  // ignore DB errors for this non-critical banner
}

// Try loading announcements and unread count from DB; if table missing, keep fallback
$announcements = [];
try {
  $announcementStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.body, a.created_at,
       (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id AND r.user_id = :user_id) AS is_read
       FROM announcements a
       WHERE a.is_active = 1 AND a.audience IN ('students','all')
      ORDER BY a.created_at DESC
      LIMIT 20"
  );
  $announcementStmt->execute(['user_id' => (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
  $announcements = $announcementStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $unreadCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM announcements a LEFT JOIN announcement_reads r ON a.id = r.announcement_id AND r.user_id = :user_id WHERE a.is_active = 1 AND a.audience IN ("students","all") AND r.id IS NULL'
  );
  $unreadCountStmt->execute(['user_id' => (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
  $dbUnread = (int) $unreadCountStmt->fetchColumn();
  // override notificationCount with DB-driven unread count when available
  if ($dbUnread >= 0) {
    $notificationCount = $dbUnread;
  }
} catch (PDOException $e) {
  // Table may not exist or other DB issue; keep fallback notifications
  $announcements = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard – SAMS Student Portal | NU Lipa</title>
  <meta name="description" content="Student Portal Dashboard for NU Lipa Student Assistant Management System." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      /* Brand */
      --color-primary:         #003087;
      --color-primary-100:     #00438b;
      --color-primary-200:     #004aab;
      --color-gold:            #ffb81c;
      --color-white:           #ffffff;

      /* Greys */
      --color-dark:            #101828;
      --color-body:            #364153;
      --color-muted:           #4a5565;
      --color-muted-light:     #99a1af;
      --color-bg:              #f9fafb;
      --color-card-border:     #f3f4f6;
      --color-border:          #e5e7eb;
      --color-input-border:    #d1d5dc;

      /* Accent colours */
      --color-blue-pale:       #bedbff;
      --color-blue-light:      #dbeafe;
      --color-blue:            #155dfc;
      --color-blue-mid:        #2b7fff;
      --color-green:           #00c950;
      --color-green-dark:      #00a63e;
      --color-green-light:     #dcfce7;
      --color-green-pale:      #f0fdf4;
      --color-yellow:          #e17100;
      --color-yellow-bg:       #fffbeb;
      --color-yellow-border:   #ffb81c;
      --color-purple:          #9810fa;
      --color-purple-mid:      #ad46ff;
      --color-purple-light:    #f3e8ff;
      --color-red-dot:         #fb2c36;

      /* Sidebar */
      --sidebar-w:             288px;
      --color-sidebar-start:   #003087;
      --color-sidebar-end:     #0047ab;

      /* Gradients */
      --grad-sidebar:          linear-gradient(180deg, #003087 0%, #0047ab 100%);
      --grad-primary-135:      linear-gradient(135deg, #003087 0%, #0047ab 100%);
      --grad-gold:             linear-gradient(135deg, #ffb81c 0%, #ffa500 100%);
      --grad-green:            linear-gradient(135deg, #00c950 0%, #00a63e 100%);
      --grad-purple:           linear-gradient(135deg, #ad46ff 0%, #9810fa 100%);
      --grad-page:             linear-gradient(135deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);
      --grad-blue-card:        linear-gradient(141deg, #eff6ff 0%, #dbeafe 100%);
      --grad-green-card:       linear-gradient(141deg, #f0fdf4 0%, #dcfce7 100%);
      --grad-purple-card:      linear-gradient(141deg, #faf5ff 0%, #f3e8ff 100%);
      --grad-tips:             linear-gradient(147deg, #003087 0%, #0047ab 100%);

      /* Shadows */
      --shadow-card:           0 10px 15px 0 rgba(0,0,0,.10), 0 4px 6px 0 rgba(0,0,0,.10);
      --shadow-nav:            0 1px 3px 0 rgba(0,0,0,.10), 0 1px 2px 0 rgba(0,0,0,.10);
      --shadow-sidebar:        0 25px 50px 0 rgba(0,0,0,.25);

      /* Radii */
      --radius-sm:   10px;
      --radius-md:   14px;
      --radius-lg:   16px;
      --radius-pill: 9999px;

      /* Font sizes */
      --font-xs:   12px;
      --font-sm:   14px;
      --font-base: 16px;
      --font-lg:   18px;
      --font-xl:   20px;
      --font-2xl:  24px;
      --font-3xl:  30px;
      --font-4xl:  36px;

      /* Spacing */
      --space-1:   4px;
      --space-2:   8px;
      --space-3:   12px;
      --space-4:   16px;
      --space-5:   20px;
      --space-6:   24px;
      --space-8:   32px;
    }

    /* =============================================
       RESET & BASE
    ============================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: 'Inter', Arial, sans-serif;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--grad-page);
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; }
    button { font-family: inherit; cursor: pointer; }

    /* =============================================
       APP SHELL
    ============================================= */
    .app { display: flex; height: 100vh; overflow: hidden; }

    /* =============================================
       SIDEBAR
    ============================================= */
    .sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: var(--grad-sidebar);
      box-shadow: var(--shadow-sidebar);
      display: flex;
      flex-direction: column;
      height: 100%;
      overflow: hidden;
    }

    /* Brand */
    .sidebar__brand {
      border-bottom: 1px solid rgba(255,255,255,.20);
      padding: var(--space-6) var(--space-6) var(--space-5);
      display: flex;
      align-items: center;
      gap: var(--space-3);
      flex-shrink: 0;
    }
    .sidebar__logo {
      width: 48px; height: 48px;
      border-radius: var(--radius-md);
      background: var(--color-white);
      display: flex; align-items: center; justify-content: center;
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-primary);
      flex-shrink: 0;
    }
    .sidebar__brand-name {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-white);
      line-height: 1.4;
    }
    .sidebar__brand-sub {
      font-size: var(--font-xs);
      font-weight: 400;
      color: var(--color-blue-pale);
    }

    /* Nav */
    .sidebar__nav {
      flex: 1;
      overflow-y: auto;
      padding: var(--space-6) var(--space-4) 0;
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .nav-item {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      height: 48px;
      padding-left: var(--space-4);
      border-radius: var(--radius-md);
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      cursor: pointer;
      transition: background .15s;
    }
    .nav-item:hover { background: rgba(255,255,255,.10); }
    .nav-item--active {
      background: var(--color-white);
      color: var(--color-primary);
      box-shadow: var(--shadow-card);
    }
    .nav-item--active:hover { background: var(--color-white); }
    .nav-item__icon { width: 20px; height: 20px; flex-shrink: 0; }

    /* Sidebar footer */
    .sidebar__footer {
      border-top: 1px solid rgba(255,255,255,.20);
      padding: 17px var(--space-4) var(--space-4);
      display: flex;
      flex-direction: column;
      gap: var(--space-3);
      flex-shrink: 0;
      background: #003087;
      position: relative;
      z-index: 2;
    }

    /* User info box */
    .sidebar__user {
      background: #0047ab;
      border: 1px solid rgba(255,255,255,.22);
      border-radius: var(--radius-md);
      padding: var(--space-4);
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .sidebar__user-label { font-size: var(--font-sm); font-weight: 600; color: #dbeafe; }
    .sidebar__user-name  { font-size: var(--font-base); font-weight: 900; color: var(--color-white); }
    .sidebar__user-id    { font-size: var(--font-xs); font-weight: 500; color: #dbeafe; }

    /* Logout */
    .sidebar__logout {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      height: 48px;
      padding-left: var(--space-4);
      border-radius: var(--radius-md);
      background: #0047ab;
      border: 1px solid rgba(255,255,255,.22);
      border: none;
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      cursor: pointer;
      transition: background .15s;
      width: 100%;
      text-align: left;
    }
    .sidebar__logout:hover { background: rgba(255,255,255,.18); }
    .sidebar__logout img { width: 20px; height: 20px; }

    /* Mobile toggle */
    .sidebar-toggle {
      display: none;
      position: fixed;
      top: 16px; left: 16px;
      z-index: 200;
      width: 36px; height: 36px;
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-sm);
      cursor: pointer;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 4px;
    }
    .sidebar-toggle__bar {
      display: block;
      width: 18px; height: 2px;
      background: var(--color-dark);
      border-radius: 2px;
      transition: transform .3s, opacity .3s;
    }

    /* =============================================
       MAIN AREA
    ============================================= */
    .main { flex: 1; display: flex; flex-direction: column; min-height: 0; overflow-y: auto; overflow-x: hidden; }

    /* Top bar */
    .topbar {
      height: 85px;
      flex-shrink: 0;
      background: var(--color-white);
      border-bottom: 1px solid var(--color-border);
      box-shadow: var(--shadow-nav);
      padding: 0 var(--space-8);
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: relative;
      z-index: 50;
    }
    .topbar__title { font-size: var(--font-2xl); font-weight: 900; color: var(--color-dark); line-height: 1.33; }
    .topbar__sub   { font-size: var(--font-sm); font-weight: 500; color: var(--color-muted); }

    .topbar__actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }
    .topbar__icon-btn {
      width: 40px; height: 40px;
      border-radius: var(--radius-sm);
      display: flex; align-items: center; justify-content: center;
      background: none;
      border: none;
      position: relative;
      cursor: pointer;
    }
    .topbar__icon-btn img { width: 24px; height: 24px; }
    .topbar__icon-btn svg { width: 24px; height: 24px; }
    .topbar__notif-dot {
      position: absolute;
      top: -3px; right: -3px;
      min-width: 18px;
      height: 18px;
      padding: 0 4px;
      border-radius: 9999px;
      background: var(--color-red-dot);
      border: 2px solid var(--color-white);
      color: var(--color-white);
      font-size: 11px;
      font-weight: 900;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* =============================================
       DASHBOARD OVERVIEW CARD
    ============================================= */
    .overview-card {
      background: var(--grad-primary-135);
      color: var(--color-white);
      border-radius: var(--radius-lg);
      padding: var(--space-8);
      box-shadow: var(--shadow-card);
    }
    .overview-card__header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: var(--space-6);
      flex-wrap: wrap;
      margin-bottom: var(--space-6);
    }
    .overview-card__eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: rgba(255,255,255,.80);
      margin-bottom: var(--space-2);
    }
    .overview-card__title {
      font-size: var(--font-3xl);
      font-weight: 900;
      line-height: 1.1;
      margin-bottom: var(--space-2);
    }
    .overview-card__desc {
      max-width: 760px;
      font-size: var(--font-base);
      line-height: 1.6;
      color: rgba(255,255,255,.86);
    }
    .overview-card__chips {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
    }
    .overview-card__chip {
      display: inline-flex;
      align-items: center;
      height: 36px;
      padding: 0 14px;
      border-radius: 9999px;
      background: rgba(255,255,255,.10);
      border: 1px solid rgba(255,255,255,.16);
      color: #fff;
      font-size: var(--font-sm);
      font-weight: 700;
      white-space: nowrap;
    }
    .overview-grid {
      display: grid;
      grid-template-columns: 1.05fr .95fr 1fr;
      gap: var(--space-4);
    }
    .overview-panel {
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.16);
      border-radius: var(--radius-lg);
      padding: var(--space-5);
      backdrop-filter: blur(4px);
    }
    .overview-panel__label {
      font-size: 11px;
      font-weight: 900;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: rgba(255,255,255,.72);
      margin-bottom: 10px;
    }
    .overview-panel__value {
      font-size: var(--font-xl);
      font-weight: 900;
      line-height: 1.25;
      margin-bottom: 6px;
    }
    .overview-panel__sub {
      font-size: var(--font-sm);
      line-height: 1.5;
      color: rgba(255,255,255,.82);
    }
    .overview-panel__meta {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
    }
    .overview-panel__meta span {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 0 10px;
      border-radius: 9999px;
      background: rgba(255,255,255,.12);
      font-size: var(--font-xs);
      font-weight: 700;
      color: rgba(255,255,255,.94);
    }
    .overview-panel__list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .overview-panel__item {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: var(--font-sm);
      line-height: 1.5;
      color: rgba(255,255,255,.88);
    }
    .overview-panel__bullet {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      margin-top: 7px;
      flex-shrink: 0;
    }
    .overview-panel__bullet--yellow { background: var(--color-gold); }
    .overview-panel__bullet--blue { background: #8fd3ff; }
    .overview-panel__bullet--purple { background: #d8b4fe; }
    .overview-panel__bullet--green { background: #86efac; }

    /* =============================================
       PAGE CONTENT
    ============================================= */
    .content {
      flex: 1;
      padding: var(--space-8);
      display: flex;
      flex-direction: column;
      gap: var(--space-8);
    }

    /* Page greeting */
    .greeting__title {
      font-size: var(--font-4xl);
      font-weight: 900;
      color: var(--color-dark);
      line-height: 1.1;
      margin-bottom: var(--space-2);
    }
    .greeting__sub {
      font-size: var(--font-lg);
      font-weight: 500;
      color: var(--color-muted);
    }

    /* =============================================
       STAT CARDS ROW
    ============================================= */
    .stat-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-6);
    }
    .stat-card {
      background: var(--color-white);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: var(--space-6);
      display: flex;
      flex-direction: column;
      gap: var(--space-3);
    }
    .stat-card__top {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .stat-card__icon {
      width: 48px; height: 48px;
      border-radius: var(--radius-md);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      color: var(--color-white);
    }
    .stat-card__icon img { width: 24px; height: 24px; }
    .stat-card__icon svg { width: 24px; height: 24px; }
    .stat-card__icon--blue   { background: var(--grad-primary-135); }
    .stat-card__icon--gold   { background: var(--grad-gold); }
    .stat-card__icon--green  { background: var(--grad-green); }
    .stat-card__icon--purple { background: var(--grad-purple); }

    .stat-card__badge {
      font-size: var(--font-xs);
      font-weight: 700;
      padding: var(--space-1) var(--space-3);
      border-radius: var(--radius-pill);
      white-space: nowrap;
    }
    .stat-card__badge--green  { background: var(--color-green-light); color: var(--color-green-dark); }
    .stat-card__badge--blue   { background: var(--color-blue-light);  color: var(--color-blue); }
    .stat-card__badge--purple { background: var(--color-purple-light);color: var(--color-purple); }

    .stat-card__value {
      font-size: var(--font-3xl);
      font-weight: 900;
      color: var(--color-dark);
      line-height: 1.2;
    }
    .stat-card__label {
      font-size: var(--font-sm);
      font-weight: 500;
      color: var(--color-muted);
    }

    /* =============================================
       MAIN CONTENT GRID (2 columns)
    ============================================= */
    .dashboard-grid {
      display: grid;
      grid-template-columns: 1fr 381px;
      gap: var(--space-6);
    }
    .dashboard-col {
      display: flex;
      flex-direction: column;
      gap: var(--space-6);
    }

    /* =============================================
       SHARED CARD
    ============================================= */
    .card {
      background: var(--color-white);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: 26px;
    }
    .card__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: var(--space-6);
    }
    .card__heading {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-dark);
    }
    .card__heading img { width: 28px; height: 28px; }
    .card__link {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-primary);
      white-space: nowrap;
    }
    .card__heading--sm {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
      margin-bottom: var(--space-4);
    }
    .card__heading--sm img { width: 24px; height: 24px; }

    /* =============================================
       TODAY'S SCHEDULE – empty state
    ============================================= */
    .schedule-empty {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: var(--space-8) 0 var(--space-6);
      gap: var(--space-4);
    }
    .schedule-empty__icon-wrap {
      width: 96px; height: 96px;
      border-radius: 50%;
      background: var(--color-card-border);
      display: flex; align-items: center; justify-content: center;
    }
    .schedule-empty__icon-wrap img { width: 48px; height: 48px; }
    .schedule-empty__title {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
      text-align: center;
    }
    .schedule-empty__sub {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted);
      text-align: center;
    }
    .schedule-empty__btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      height: 48px;
      padding: 0 var(--space-6);
      border-radius: var(--radius-md);
      background: var(--color-primary);
      color: var(--color-white);
      font-size: var(--font-base);
      font-weight: 700;
      border: none;
      cursor: pointer;
      transition: opacity .15s;
      text-decoration: none;
    }
    .schedule-empty__btn:hover { opacity: .88; }

    /* =============================================
       QUICK ACTIONS
    ============================================= */
    .quick-actions {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--space-4);
    }
    .quick-action {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: var(--space-3);
      padding: 26px var(--space-2);
      border-radius: var(--radius-lg);
      border: 2px solid;
      text-decoration: none;
      cursor: pointer;
      transition: opacity .15s;
    }
    .quick-action:hover { opacity: .88; }
    .quick-action--green  { background: var(--grad-green-card);  border-color: #b9f8cf; }
    .quick-action--blue   { background: var(--grad-blue-card);   border-color: var(--color-blue-pale); }
    .quick-action--purple { background: var(--grad-purple-card); border-color: #e9d4ff; }
    .quick-action__icon {
      width: 56px; height: 56px;
      border-radius: var(--radius-lg);
      display: flex; align-items: center; justify-content: center;
      font-size: 30px;
    }
    .quick-action__icon img { width: 28px; height: 28px; }
    .quick-action__icon--green  { background: var(--color-green); }
    .quick-action__icon--blue   { background: var(--color-blue-mid); }
    .quick-action__icon--purple { background: var(--color-purple-mid); }
    .quick-action__label {
      font-size: var(--font-sm);
      font-weight: 900;
      color: var(--color-dark);
      text-align: center;
    }

    /* =============================================
       UPCOMING DUTIES
    ============================================= */
    .duties-list {
      display: flex;
      flex-direction: column;
      gap: var(--space-3);
    }
    .duty-item {
      background: var(--color-bg);
      border-radius: var(--radius-md);
      padding: 0 var(--space-4);
      height: 80px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .duty-item__left {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }
    .duty-item__icon {
      width: 48px; height: 48px;
      border-radius: var(--radius-md);
      background: var(--color-primary);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .duty-item__icon img { width: 24px; height: 24px; }
    .duty-item__day  { font-size: var(--font-base); font-weight: 900; color: var(--color-dark); }
    .duty-item__date { font-size: var(--font-sm);   font-weight: 500; color: var(--color-muted); }
    .duty-item__right { text-align: right; }
    .duty-item__time   { font-size: var(--font-base); font-weight: 900; color: var(--color-primary); }
    .duty-item__office { font-size: var(--font-sm);   font-weight: 500; color: var(--color-muted); }

    /* =============================================
       ANNOUNCEMENTS
    ============================================= */
    .announcements-list {
      display: flex;
      flex-direction: column;
      gap: var(--space-4);
    }
    .announcement {
      border-radius: var(--radius-sm);
      padding: var(--space-4);
      border-left: 4px solid;
    }
    .announcement--blue   { background: #eff6ff;          border-color: var(--color-primary); }
    .announcement--yellow { background: var(--color-yellow-bg);    border-color: var(--color-gold); }
    .announcement--green  { background: var(--color-green-pale);   border-color: var(--color-green); }
    .announcement__time {
      font-size: var(--font-xs);
      font-weight: 700;
      margin-bottom: var(--space-2);
    }
    .announcement--blue   .announcement__time { color: var(--color-blue); }
    .announcement--yellow .announcement__time { color: var(--color-yellow); }
    .announcement--green  .announcement__time { color: var(--color-green-dark); }
    .announcement__title {
      font-size: var(--font-base);
      font-weight: 900;
      color: var(--color-dark);
      margin-bottom: var(--space-2);
    }
    .announcement__body {
      font-size: var(--font-sm);
      font-weight: 500;
      color: var(--color-body);
      line-height: 1.43;
    }

    /* =============================================
       TIPS & REMINDERS
    ============================================= */
    .tips-card {
      background: var(--grad-tips);
      border-radius: var(--radius-lg);
      padding: var(--space-6);
    }
    .tips-card__heading {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-white);
      margin-bottom: var(--space-4);
    }
    .tips-card__list {
      display: flex;
      flex-direction: column;
      gap: var(--space-3);
    }
    .tips-card__item {
      display: flex;
      gap: var(--space-2);
      font-size: var(--font-sm);
      font-weight: 500;
      color: var(--color-blue-light);
      line-height: 1.43;
    }
    .tips-card__check { color: var(--color-gold); flex-shrink: 0; }

    /* =============================================
       PERFORMANCE
    ============================================= */
    .perf-list {
      display: flex;
      flex-direction: column;
      gap: var(--space-4);
    }
    .perf-item__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: var(--space-2);
    }
    .perf-item__label { font-size: var(--font-sm); font-weight: 700; color: var(--color-body); }
    .perf-item__value { font-size: var(--font-sm); font-weight: 900; }
    .perf-item__value--green  { color: var(--color-green-dark); }
    .perf-item__value--blue   { color: var(--color-blue); }
    .perf-item__value--purple { color: var(--color-purple); }
    .perf-item__bar-track {
      height: 12px;
      background: var(--color-border);
      border-radius: var(--radius-pill);
      overflow: hidden;
    }
    .perf-item__bar-fill {
      height: 100%;
      border-radius: var(--radius-pill);
    }
    .perf-item__bar-fill--green  { background: var(--color-green);     width: 98%; }
    .perf-item__bar-fill--blue   { background: var(--color-blue-mid);  width: 95%; }
    .perf-item__bar-fill--purple { background: var(--color-purple-mid);width: 92%; }

    /* =============================================
       SIDEBAR OVERLAY
    ============================================= */
    .sidebar-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,.4);
      z-index: 99;
    }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .sidebar {
        position: fixed;
        left: 0; top: 0; bottom: 0;
        z-index: 100;
        transform: translateX(-100%);
        transition: transform .3s;
      }
      .sidebar.is-open { transform: translateX(0); }
      .sidebar-overlay.is-open { display: block; }
      .sidebar-toggle { display: flex; }
      .topbar { padding-left: 64px; }
      .stat-row { grid-template-columns: repeat(2, 1fr); }
      .dashboard-grid { grid-template-columns: 1fr; }
      .overview-grid { grid-template-columns: 1fr; }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .content { padding: var(--space-4); gap: var(--space-6); }
      .topbar { padding: 0 var(--space-4) 0 64px; height: 72px; }
      .topbar__title { font-size: var(--font-lg); }
      .greeting__title { font-size: 26px; }
      .stat-row { grid-template-columns: 1fr 1fr; gap: var(--space-4); }
      .quick-actions { grid-template-columns: repeat(3, 1fr); }
      .overview-card { padding: var(--space-6); }
      .overview-card__title { font-size: 26px; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
<link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
</head>
<body>

<div class="app">

  <!-- ============================================
       SIDEBAR
  ============================================= -->
  <aside class="sidebar" id="sidebar" aria-label="Student navigation">

    <!-- Brand -->
    <div class="sidebar__brand">
      <div class="sidebar__logo" aria-hidden="true">NU</div>
      <div>
        <div class="sidebar__brand-name">SAMS</div>
        <div class="sidebar__brand-sub">Student Assistant Management</div>
      </div>
    </div>

    <!-- Nav -->
    <nav class="sidebar__nav" aria-label="Main navigation">
      <a class="nav-item nav-item--active" href="dashboard.php" aria-current="page">
        <svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M3 11.5L12 4l9 7.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
          <path d="M5 10.5V20h5v-5h4v5h5v-9.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
        </svg>
        Dashboard
      </a>
      <a class="nav-item" href="schedule.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <rect x="4" y="5" width="16" height="15" rx="2" stroke="#101828" stroke-width="1.8" fill="#ffffff" />
          <path d="M8 3v4M16 3v4M4 9h16" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="none" />
        </svg>
        My Schedule
      </a>
      <a class="nav-item" href="attendance_history.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M5 4h10l4 4v12H5z" stroke="#101828" stroke-width="1.8" stroke-linejoin="round" fill="#ffffff" />
          <path d="M15 4v4h4" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
          <path d="M8 11h8M8 15h8" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="none" />
        </svg>
        Duty-Hour Report
      </a>
      <a class="nav-item" href="profile.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <circle cx="12" cy="8" r="3.2" stroke="#101828" stroke-width="1.8" fill="#ffffff" />
          <path d="M6.5 19c1.4-3.1 4-4.8 5.5-4.8S15.6 15.9 17 19" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
        </svg>
        Profile
      </a>
    </nav>

    <!-- Footer: user info + logout -->
    <div class="sidebar__footer">
      <div class="sidebar__user">
        <span class="sidebar__user-label">Logged in as</span>
        <span class="sidebar__user-name"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="sidebar__user-id">Student ID: <?php echo htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <button class="sidebar__logout" type="button" onclick="window.location.href='logout.php'">
        <svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M10 7V5.5A1.5 1.5 0 0 1 11.5 4h6A1.5 1.5 0 0 1 19 5.5v13A1.5 1.5 0 0 1 17.5 20h-6A1.5 1.5 0 0 1 10 18.5V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          <path d="M3 12h10m0 0-3-3m3 3-3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Logout
      </button>
    </div>
  </aside>

  <div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

  <button class="sidebar-toggle" id="sidebar-toggle"
    aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
    <span class="sidebar-toggle__bar"></span>
    <span class="sidebar-toggle__bar"></span>
    <span class="sidebar-toggle__bar"></span>
  </button>

  <!-- ============================================
       MAIN
  ============================================= -->
  <div class="main">

    <!-- Top bar -->
    <header class="topbar" role="banner">
      <div>
        <div class="topbar__title">Student Portal</div>
        <div class="topbar__sub">National University - Lipa Campus</div>
      </div>
      <div class="topbar__actions">
        <!-- Notification bell -->
        <button id="notif-toggle" class="topbar__icon-btn" aria-label="Notifications">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 4a5 5 0 0 0-5 5v2.2c0 .9-.2 1.8-.6 2.6L5.2 15.6A1 1 0 0 0 6 17h12a1 1 0 0 0 .8-1.4l-1.2-1.8c-.4-.8-.6-1.7-.6-2.6V9a5 5 0 0 0-5-5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
            <path d="M9.5 17.5a2.8 2.8 0 0 0 5 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
          <span class="topbar__notif-dot" style="display:none;" aria-label="New notifications"></span>
        </button>
        <?php // expose CSRF token to JS for API calls ?>
        <script>window.SAMS_CSRF = '<?php echo addslashes(sams_csrf_token()); ?>';</script>
        <!-- Settings -->
        <button class="topbar__icon-btn" aria-label="Settings">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6Z" stroke="currentColor" stroke-width="1.8" fill="none" />
            <path d="M4.5 13.2v-2.4l2-.7a6.8 6.8 0 0 1 .8-1.8l-1-1.9 1.7-1.7 1.9 1a6.8 6.8 0 0 1 1.8-.8l.7-2h2.4l.7 2c.6.2 1.2.5 1.8.8l1.9-1 1.7 1.7-1 1.9c.3.6.6 1.2.8 1.8l2 .7v2.4l-2 .7a6.8 6.8 0 0 1-.8 1.8l1 1.9-1.7 1.7-1.9-1c-.6.3-1.2.6-1.8.8l-.7 2h-2.4l-.7-2a6.8 6.8 0 0 1-1.8-.8l-1.9 1-1.7-1.7 1-1.9a6.8 6.8 0 0 1-.8-1.8Z" stroke="currentColor" stroke-width="1.2" fill="none" />
          </svg>
        </button>
      </div>
    </header>

    <!-- Page content -->
    <main class="content" role="main">

      <?php if (!empty($waitingForDeployment)): ?>
        <div style="background:#fff3cd;border:1px solid #ffeeba;padding:14px;border-radius:10px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
          <strong style="color:#856404;">Notice:</strong>
          <div style="color:#856404;">Your application has been <strong>approved</strong>, but schedules are still waiting for deployment by the admin. We will notify you once schedules are published.</div>
          <div style="margin-left:auto"><a href="schedule.php" style="background:#fff;border:1px solid #856404;padding:8px 10px;border-radius:8px;color:#856404;text-decoration:none;font-weight:700">View schedules</a></div>
        </div>
      <?php endif; ?>

      <!-- Greeting -->
      <div>
        <div class="greeting__title">Welcome back, <?php echo htmlspecialchars($studentName !== '' ? $studentName : 'Student', ENT_QUOTES, 'UTF-8'); ?>! 👋</div>
        <div class="greeting__sub">Here's what's happening with your duties today</div>
      </div>

      <section class="overview-card" aria-labelledby="dashboard-overview-title">
        <div class="overview-card__header">
          <div>
            <div class="overview-card__eyebrow">Student dashboard (web)</div>
            <h2 class="overview-card__title" id="dashboard-overview-title">Assignment overview</h2>
            <p class="overview-card__desc">Aggregates key information: current assignment details, rendered duty hours, attendance summaries, and pending notifications so students can act fast.</p>
          </div>
          <div class="overview-card__chips" aria-label="Dashboard categories">
            <span class="overview-card__chip">Assignment overview</span>
            <span class="overview-card__chip">Hours summary</span>
            <span class="overview-card__chip">Notifications</span>
          </div>
        </div>

        <div class="overview-grid">
          <article class="overview-panel" id="current-assignment-card">
            <div class="overview-panel__label">Current assignment</div>
            <?php if ($currentAssignment): ?>
              <div class="overview-panel__value" id="current-assignment-office"><?php echo htmlspecialchars((string) $currentAssignment['office_name'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="overview-panel__sub" id="current-assignment-schedule">
                <?php echo htmlspecialchars((string) $currentAssignment['day_of_week'], ENT_QUOTES, 'UTF-8'); ?> ·
                <?php echo htmlspecialchars(sams_student_time_label((string) $currentAssignment['time_start']) . ' - ' . sams_student_time_label((string) $currentAssignment['time_end']), ENT_QUOTES, 'UTF-8'); ?>
              </div>
              <div class="overview-panel__meta" id="current-assignment-meta">
                <?php if (!empty($currentAssignment['supervisor_first_name']) || !empty($currentAssignment['supervisor_last_name'])): ?>
                  <span id="current-assignment-supervisor"><?php echo htmlspecialchars(trim((string) ($currentAssignment['supervisor_first_name'] ?? '') . ' ' . (string) ($currentAssignment['supervisor_last_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if (!empty($currentAssignment['term_name'])): ?>
                  <span id="current-assignment-term"><?php echo htmlspecialchars((string) $currentAssignment['term_name'] . ' ' . (string) ($currentAssignment['school_year'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <span id="current-assignment-status"><?php echo htmlspecialchars(sams_student_schedule_status_label((string) $currentAssignment['status']), ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
            <?php else: ?>
              <div class="overview-panel__value" id="current-assignment-office">No assignment yet</div>
              <div class="overview-panel__sub" id="current-assignment-schedule">Your assigned office, schedule, and term will appear here once admin creates your duty.</div>
              <div class="overview-panel__meta" id="current-assignment-meta"></div>
            <?php endif; ?>
          </article>

          <article class="overview-panel">
            <div class="overview-panel__label">Hours summary</div>
            <div class="overview-panel__value"><?php echo htmlspecialchars(number_format($renderedHours, 1), ENT_QUOTES, 'UTF-8'); ?> hrs</div>
            <div class="overview-panel__sub">Rendered duty hours logged from your attendance records.</div>
            <div class="overview-panel__meta">
              <span><?php echo htmlspecialchars(number_format($totalHours, 1), ENT_QUOTES, 'UTF-8'); ?> scheduled</span>
              <span><?php echo (int) $attendanceRate; ?>% attendance</span>
            </div>
          </article>

          <article class="overview-panel">
            <div class="overview-panel__label">Notifications</div>
            <div class="overview-panel__value"><?php echo (int) $notificationCount; ?> item<?php echo $notificationCount === 1 ? '' : 's'; ?></div>
            <div class="overview-panel__list">
              <?php foreach (array_slice($notifications, 0, 3) as $notification): ?>
                <div class="overview-panel__item">
                  <span class="overview-panel__bullet overview-panel__bullet--<?php echo htmlspecialchars((string) $notification['tone'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                  <span>
                    <strong><?php echo htmlspecialchars((string) $notification['label'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                    <?php echo htmlspecialchars((string) $notification['message'], ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
        </div>
      </section>

      <!-- ---- Stat cards ---- -->
      <div class="stat-row">
        <!-- Total Hours -->
        <div class="stat-card">
          <div class="stat-card__top">
            <div class="stat-card__icon stat-card__icon--blue">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none" />
                <path d="M12 8.2V12l2.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
              </svg>
            </div>
            <span class="stat-card__badge stat-card__badge--green">Live</span>
          </div>
          <div class="stat-card__value"><?php echo htmlspecialchars(number_format($totalHours, 1), ENT_QUOTES, 'UTF-8'); ?> hrs</div>
          <div class="stat-card__label">Total Hours</div>
        </div>
        <!-- Upcoming Duties -->
        <div class="stat-card">
          <div class="stat-card__top">
            <div class="stat-card__icon stat-card__icon--gold">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
                <path d="M8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
              </svg>
            </div>
            <span class="stat-card__badge stat-card__badge--blue">Assigned</span>
          </div>
          <div class="stat-card__value"><?php echo (int) $totalSchedules; ?></div>
          <div class="stat-card__label">Upcoming Duties</div>
        </div>
        <!-- Duties Completed -->
        <div class="stat-card">
          <div class="stat-card__top">
            <div class="stat-card__icon stat-card__icon--green">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M6.5 12.5l3 3 8-8" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.2" fill="none" opacity=".35" />
              </svg>
            </div>
            <span class="stat-card__badge stat-card__badge--green">Accepted</span>
          </div>
          <div class="stat-card__value"><?php echo (int) $acceptedSchedules; ?></div>
          <div class="stat-card__label">Accepted Duties</div>
        </div>
        <!-- Attendance Rate -->
        <div class="stat-card">
          <div class="stat-card__top">
            <div class="stat-card__icon stat-card__icon--purple">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 3.5v4m0 9v4M5.5 12h4m9 0h-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <circle cx="12" cy="12" r="4.8" stroke="currentColor" stroke-width="1.6" fill="none" />
              </svg>
            </div>
            <span class="stat-card__badge stat-card__badge--purple">Pending</span>
          </div>
          <div class="stat-card__value"><?php echo (int) $pendingSchedules; ?></div>
          <div class="stat-card__label">Pending Responses</div>
        </div>
      </div>

      <!-- ---- Main grid ---- -->
      <div class="dashboard-grid">

        <!-- LEFT COLUMN -->
        <div class="dashboard-col">

          <!-- Today's Schedule -->
          <div class="card">
            <div class="card__header">
              <h2 class="card__heading">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="card__heading-icon">
                    <path d="M3 11.5L12 4l9 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                    <path d="M5 10.5V20h5v-5h4v5h5v-9.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                  </svg>
                Today's Schedule
              </h2>
              <a class="card__link" href="schedule.php">View Full Schedule →</a>
            </div>
            <?php if (!empty($todaySchedules)): ?>
              <div class="duties-list">
                <?php foreach ($todaySchedules as $schedule): ?>
                  <div class="duty-item">
                    <div class="duty-item__left">
                      <div class="duty-item__icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
                          <path d="M8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                      </div>
                      <div>
                        <div class="duty-item__day"><?php echo htmlspecialchars((string) $schedule['day_of_week'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="duty-item__date"><?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?></div>
                      </div>
                    </div>
                    <div class="duty-item__right">
                      <div class="duty-item__time"><?php echo htmlspecialchars(sams_student_time_label((string) $schedule['time_start']) . ' - ' . sams_student_time_label((string) $schedule['time_end']), ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="duty-item__office"><?php echo htmlspecialchars((string) $schedule['office_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="schedule-empty">
                <div class="schedule-empty__icon-wrap">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
                    <path d="M8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    <path d="M8.5 13h7M8.5 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" opacity=".8" />
                  </svg>
                </div>
                <div class="schedule-empty__title">No Duty Today! 🎉</div>
                <div class="schedule-empty__sub">
                  <?php if ($nextDuty): ?>
                    Your next duty is <?php echo htmlspecialchars((string) $nextDuty['day_of_week'], ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(sams_student_time_label((string) $nextDuty['time_start']), ENT_QUOTES, 'UTF-8'); ?>.
                  <?php else: ?>
                    Enjoy your free day. Check your schedule for upcoming duties.
                  <?php endif; ?>
                </div>
                <a class="schedule-empty__btn" href="schedule.php">View Schedule</a>
              </div>
            <?php endif; ?>
          </div>

          <!-- Quick Actions -->
          <div class="card">
            <h2 class="card__heading" style="margin-bottom: var(--space-6);">Quick Actions</h2>
            <div class="quick-actions">
              <a class="quick-action quick-action--green" href="availability.php">
                <div class="quick-action__icon quick-action__icon--green">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
                    <path d="M8 12h8M8 15h5M8 8h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                  </svg>
                </div>
                <span class="quick-action__label">Set Availability</span>
              </a>
              <a class="quick-action quick-action--blue" href="schedule.php">
                <div class="quick-action__icon quick-action__icon--blue">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 4v16M4 12h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.4" fill="none" opacity=".35" />
                  </svg>
                </div>
                <span class="quick-action__label">View Schedule</span>
              </a>
              <a class="quick-action quick-action--purple" href="profile.php">
                <div class="quick-action__icon quick-action__icon--purple">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.8" fill="none" />
                    <path d="M6.5 19c1.4-3.1 4-4.8 5.5-4.8S15.6 15.9 17 19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                  </svg>
                </div>
                <span class="quick-action__label">My Profile</span>
              </a>
            </div>
          </div>

          <!-- Upcoming Duties -->
          <div class="card">
            <h2 class="card__heading" style="margin-bottom: var(--space-6);">Upcoming Duties</h2>
            <div class="duties-list">
              <?php
                $visibleSchedules = array_filter($studentSchedules, fn($s) => in_array($s['status'] ?? 'pending', ['accepted', 'deployed']));
              ?>
              <?php if (empty($visibleSchedules)): ?>
                <div class="schedule-empty" style="padding: 0; box-shadow: none; background: transparent;">
                  <div class="schedule-empty__title">No upcoming duties yet.</div>
                  <div class="schedule-empty__sub">Once you accept a schedule, it will appear here.</div>
                  <a class="schedule-empty__btn" href="schedule.php">Open Schedule</a>
                </div>
              <?php else: ?>
                <?php foreach (array_slice($visibleSchedules, 0, 3) as $schedule): ?>
                  <div class="duty-item">
                    <div class="duty-item__left">
                      <div class="duty-item__icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
                          <path d="M8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                      </div>
                      <div>
                        <div class="duty-item__day"><?php echo htmlspecialchars((string) $schedule['day_of_week'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="duty-item__date"><?php echo htmlspecialchars(sams_student_next_date((string) $schedule['day_of_week']), ENT_QUOTES, 'UTF-8'); ?></div>
                      </div>
                    </div>
                    <div class="duty-item__right">
                      <div class="duty-item__time"><?php echo htmlspecialchars(sams_student_time_label((string) $schedule['time_start']) . ' - ' . sams_student_time_label((string) $schedule['time_end']), ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="duty-item__office"><?php echo htmlspecialchars((string) $schedule['office_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(sams_student_schedule_status_label((string) $schedule['status']), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

        </div><!-- /.dashboard-col left -->

        <!-- RIGHT COLUMN -->
        <div class="dashboard-col">

          <!-- Announcements -->
          <div class="card">
            <h2 class="card__heading--sm">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="card__heading-icon">
                <path d="M4 6h16v10H5.8L4 18V6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none" />
                <path d="M8 9h8M8 12h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
              </svg>
              Announcements
            </h2>
            <div class="announcements-list" id="announcements-container">
              <!-- Announcements loaded here dynamically -->
              <div style="padding:16px;color:#999;text-align:center;">Loading announcements...</div>
            </div>
          </div>

          <!-- Tips & Reminders -->
          <div class="tips-card">
            <div class="tips-card__heading">💡 Tips &amp; Reminders</div>
            <div class="tips-card__list">
              <div class="tips-card__item">
                <span class="tips-card__check">✓</span>
                <span>Always arrive 5-10 minutes before your scheduled time</span>
              </div>
              <div class="tips-card__item">
                <span class="tips-card__check">✓</span>
                <span>Attendance clock-in/out will be enabled once hardware setup is complete</span>
              </div>
              <div class="tips-card__item">
                <span class="tips-card__check">✓</span>
                <span>Check your schedule regularly for updates</span>
              </div>
              <div class="tips-card__item">
                <span class="tips-card__check">✓</span>
                <span>Notify Miss Zai if you need to reschedule</span>
              </div>
            </div>
          </div>

          <!-- Your Performance -->
          <div class="card">
            <h2 class="card__heading--sm" style="margin-bottom: var(--space-4);">Schedule Progress</h2>
            <div class="perf-list">
              <div class="perf-item">
                <div class="perf-item__meta">
                  <span class="perf-item__label">Accepted Rate</span>
                  <span class="perf-item__value perf-item__value--green"><?php echo (int) $acceptedRate; ?>%</span>
                </div>
                <div class="perf-item__bar-track">
                  <div class="perf-item__bar-fill perf-item__bar-fill--green" role="progressbar" aria-valuenow="<?php echo (int) $acceptedRate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
              <div class="perf-item">
                <div class="perf-item__meta">
                  <span class="perf-item__label">Responded Rate</span>
                  <span class="perf-item__value perf-item__value--blue"><?php echo (int) $responseRate; ?>%</span>
                </div>
                <div class="perf-item__bar-track">
                  <div class="perf-item__bar-fill perf-item__bar-fill--blue" role="progressbar" aria-valuenow="<?php echo (int) $responseRate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
              <div class="perf-item">
                <div class="perf-item__meta">
                  <span class="perf-item__label">Pending Load</span>
                  <span class="perf-item__value perf-item__value--purple"><?php echo (int) $pendingSchedules; ?> duty(s)</span>
                </div>
                <div class="perf-item__bar-track">
                  <div class="perf-item__bar-fill perf-item__bar-fill--purple" role="progressbar" aria-valuenow="<?php echo (int) $pendingRate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Attendance Snapshot -->
          <div class="card">
            <h2 class="card__heading--sm" style="margin-bottom: var(--space-4);">Attendance Snapshot</h2>
            <div class="perf-list">
              <div class="perf-item">
                <div class="perf-item__meta">
                  <span class="perf-item__label">Attendance Rate</span>
                  <span id="attendance-rate-value" class="perf-item__value perf-item__value--green"><?php echo (int) $attendanceRate; ?>%</span>
                </div>
                <div class="perf-item__bar-track">
                  <div id="attendance-rate-bar" class="perf-item__bar-fill perf-item__bar-fill--green" role="progressbar" aria-valuenow="<?php echo (int) $attendanceRate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
              <div class="perf-item">
                <div class="perf-item__meta">
                  <span class="perf-item__label">Present / Late</span>
                  <span id="attendance-present-late-value" class="perf-item__value perf-item__value--blue"><?php echo (int) $attendanceTotals['present']; ?> / <?php echo (int) $attendanceTotals['late']; ?></span>
                </div>
                <div class="perf-item__bar-track">
                  <div id="attendance-late-bar" class="perf-item__bar-fill perf-item__bar-fill--blue" role="progressbar" aria-valuenow="<?php echo (int) $attendanceLateRate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
              <div class="perf-item">
                <div class="perf-item__meta">
                  <span class="perf-item__label">Absent / Incomplete</span>
                  <span id="attendance-absent-value" class="perf-item__value perf-item__value--purple"><?php echo (int) $attendanceTotals['absent']; ?> / <?php echo (int) $attendanceTotals['incomplete']; ?></span>
                </div>
                <div class="perf-item__bar-track">
                  <div id="attendance-absent-bar" class="perf-item__bar-fill perf-item__bar-fill--purple" role="progressbar" aria-valuenow="<?php echo (int) $attendanceTotals['absent'] + (int) $attendanceTotals['incomplete']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
            </div>
            <div id="attendance-last-log" style="margin-top:14px;font-size:14px;color:var(--color-body);line-height:1.6;">
              <?php if ($lastAttendance): ?>
                Latest log: <strong><?php echo htmlspecialchars(sams_student_attendance_status_label((string) ($lastAttendance['status'] ?? 'absent')), ENT_QUOTES, 'UTF-8'); ?></strong>
                on <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime((string) ($lastAttendance['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($lastAttendance['notes'])): ?>
                  · <?php echo htmlspecialchars((string) $lastAttendance['notes'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
              <?php else: ?>
                No attendance logs recorded yet.
              <?php endif; ?>
              <div style="margin-top:12px;">
                <a href="attendance_history.php" style="display:inline-flex;align-items:center;height:40px;padding:0 14px;border-radius:9999px;background:#fff;color:var(--color-primary);font-weight:900;box-shadow:0 8px 18px rgba(0,0,0,.10);">Open duty-hour report</a>
              </div>
            </div>
          </div>

            <!-- Attendance History -->
            <section id="attendance" class="card">
              <h2 class="card__heading--sm" style="margin-bottom: var(--space-4);">Attendance History</h2>
              <div id="attendance-history-container">
              <?php if (empty($attendanceHistory)): ?>
                <div class="schedule-empty" style="padding: 0; box-shadow: none; background: transparent;">
                  <div id="attendance-history-empty">
                  <div class="schedule-empty__title">No attendance history yet.</div>
                  <div class="schedule-empty__sub">Once attendance records are captured, your logs will appear here.</div>
                  </div>
                </div>
              <?php else: ?>
                <div id="attendance-history-list" class="duties-list">
                  <?php foreach ($attendanceHistory as $log): ?>
                    <div class="duty-item">
                      <div class="duty-item__left">
                        <div class="duty-item__icon">
                          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none" />
                            <path d="M12 8.2V12l2.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                          </svg>
                        </div>
                        <div>
                          <div class="duty-item__day"><?php echo htmlspecialchars(sams_student_attendance_status_label((string) ($log['status'] ?? 'absent')), ENT_QUOTES, 'UTF-8'); ?></div>
                          <div class="duty-item__date"><?php echo htmlspecialchars(date('M d, Y', strtotime((string) ($log['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                      </div>
                      <div class="duty-item__right">
                        <div class="duty-item__office">
                          Late: <?php echo (int) ($log['late_minutes'] ?? 0); ?> min
                          <?php if (!empty($log['notes'])): ?>
                            · <?php echo htmlspecialchars((string) $log['notes'], ENT_QUOTES, 'UTF-8'); ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              </div>
            </section>

        </div><!-- /.dashboard-col right -->

      </div><!-- /.dashboard-grid -->

    </main>
    <script>
    (function(){
      function escapeHtml(s){
        return String(s).replace(/[&<>\"']/g, function(c){
          return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
      }

      function getRelativeTime(dateStr){
        var date = new Date(dateStr);
        var now = new Date();
        var diffMs = now - date;
        var diffSecs = Math.floor(diffMs / 1000);
        var diffMins = Math.floor(diffSecs / 60);
        var diffHours = Math.floor(diffMins / 60);
        var diffDays = Math.floor(diffHours / 24);
        
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return diffMins + ' minute' + (diffMins !== 1 ? 's' : '') + ' ago';
        if (diffHours < 24) return diffHours + ' hour' + (diffHours !== 1 ? 's' : '') + ' ago';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return diffDays + ' days ago';
        return date.toLocaleDateString();
      }

      function getAnnouncementColor(index){
        var colors = ['blue', 'yellow', 'green'];
        return colors[index % colors.length];
      }

      function renderAnnouncementsInCard(data){
        var container = document.getElementById('announcements-container');
        if (!container) return;
        
        container.innerHTML = '';
        
        if (!data || !Array.isArray(data.announcements) || data.announcements.length === 0){
          container.innerHTML = '<div style="padding:24px;color:#999;text-align:center;font-size:14px;">No announcements yet. Check back soon!</div>';
          return;
        }
        
        data.announcements.forEach(function(a, idx){
          var color = getAnnouncementColor(idx);
          var relTime = getRelativeTime(a.created_at);
          var div = document.createElement('div');
          div.className = 'announcement announcement--' + color;
          div.setAttribute('data-id', String(a.id));
          div.style.cursor = 'pointer';
          div.style.transition = 'opacity 0.3s, background-color 0.3s';
          if (a.is_read && parseInt(a.is_read, 10)) {
            div.style.opacity = '0.6';
          }
          div.innerHTML = '<div class="announcement__time">' + escapeHtml(relTime) + '</div>' +
                          '<div class="announcement__title">' + escapeHtml(a.title) + '</div>' +
                          '<div class="announcement__body">' + escapeHtml(a.body).replace(/\n/g, '<br>') + '</div>';
          
          div.addEventListener('click', function(){
            markAnnouncementRead(a.id, div);
          });
          div.addEventListener('mouseenter', function(){
            div.style.backgroundColor = color === 'blue' ? '#e8f1ff' : (color === 'yellow' ? '#fff8e8' : '#e8f5e8');
          });
          div.addEventListener('mouseleave', function(){
            div.style.backgroundColor = '';
          });
          
          container.appendChild(div);
        });
      }

      function renderAnnouncementsInDropdown(data){
        var list = document.getElementById('notif-list');
        if (!list) return;
        list.innerHTML = '';
        if (!data || !Array.isArray(data.announcements) || data.announcements.length === 0){
          list.innerHTML = '<div style="padding:12px;color:#666">No announcements</div>';
          return;
        }
        data.announcements.forEach(function(a, idx){
          var item = document.createElement('div');
          item.className = 'notif-item';
          item.setAttribute('data-id', String(a.id));
          item.style.padding = '12px';
          item.style.borderBottom = '1px solid #f6f6f6';
          item.style.cursor = 'pointer';
          var rel = getRelativeTime(a.created_at);
          item.innerHTML = '<div style="font-weight:700">'+escapeHtml(a.title)+'</div>' +
                           '<div style="font-size:13px;color:#666;margin-top:8px;">'+escapeHtml(a.body).replace(/\n/g,'<br>')+'</div>' +
                           '<div style="font-size:12px;color:#999;margin-top:8px">'+escapeHtml(rel)+'</div>';
          if (a.is_read && parseInt(a.is_read,10)) item.style.opacity = '0.6';
          item.addEventListener('click', function(){ markAnnouncementRead(a.id, item); });
          list.appendChild(item);
        });
      }

      function markAnnouncementRead(announcementId, element){
        fetch('../api/announcements/mark_read.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.SAMS_CSRF || '' },
          credentials: 'same-origin',
          body: JSON.stringify({ announcement_id: parseInt(announcementId, 10) })
        }).then(function(){
          if (element) {
            element.style.opacity = '0.6';
          }
          refreshAnnouncements();
        }).catch(function(err){
          console.error('Failed to mark as read:', err);
        });
      }

      function updateBell(count){
        var dot = document.querySelector('.topbar__notif-dot');
        if (!dot){
          // create dot if missing
          var btn = document.getElementById('notif-toggle');
          if (!btn) return;
          var span = document.createElement('span');
          span.className = 'topbar__notif-dot';
          span.setAttribute('aria-label','New notifications');
          span.style.position = 'absolute';
          span.style.right = '6px';
          span.style.top = '6px';
          span.style.fontSize = '11px';
          span.style.fontWeight = '700';
          span.style.minWidth = '18px';
          span.style.height = '18px';
          span.style.background = '#fb2c36';
          span.style.color = '#fff';
          span.style.borderRadius = '9999px';
          span.style.display = 'flex';
          span.style.alignItems = 'center';
          span.style.justifyContent = 'center';
          btn.appendChild(span);
          dot = span;
        }
        if (count > 0){
          dot.textContent = String(count);
          dot.style.display = 'flex';
        } else {
          dot.style.display = 'none';
        }
      }

      var pollingTimer = null;
      function refreshAnnouncements(){
        fetch('../api/announcements/list.php', { 
          credentials: 'same-origin'
        })
          .then(function(r){ return r.json(); })
          .then(function(data){
            if (!data) return;
            renderAnnouncementsInCard(data);
            renderAnnouncementsInDropdown(data);
            updateBell(data.unread_count || 0);
          }).catch(function(err){
            console.error('Failed to load announcements:', err);
          });
        if (pollingTimer) clearTimeout(pollingTimer);
        pollingTimer = setTimeout(refreshAnnouncements, 10000);
      }

      // Initial load
      refreshAnnouncements();

      // Prefer SSE (push) if supported; otherwise fall back to polling.
      if (window.EventSource) {
        try {
          var es = new EventSource('../api/announcements/stream.php');
          es.addEventListener('announcements', function(e){
            try {
              var data = JSON.parse(e.data);
              renderAnnouncementsInCard(data);
              renderAnnouncementsInDropdown(data);
              updateBell(data.unread_count || 0);
            } catch (err) {
              console.error('SSE parse error:', err);
            }
          });
          es.addEventListener('error', function(){
            // on error, close and fall back to polling
            try { es.close(); } catch (e) {}
            setTimeout(refreshAnnouncements, 1000);
          });
        } catch (ex) {
          console.error('SSE setup failed:', ex);
          setTimeout(refreshAnnouncements, 1000);
        }
      } else {
        setTimeout(refreshAnnouncements, 1000);
      }

      // Expose for testing
      window.SAMS_refreshAnnouncements = refreshAnnouncements;
    }());
  </script>
  <script>
    (function(){
      'use strict';

      function renderLastLog(container, last){
        if (!container) return;
        if (!last) {
          container.innerHTML = 'No attendance logs recorded yet.';
          return;
        }
        var when = new Date(last.created_at);
        var html = 'Latest log: <strong>' + (last.status ? last.status.charAt(0).toUpperCase() + last.status.slice(1) : 'Absent') + '</strong>';
        html += ' on ' + when.toLocaleString();
        if (last.notes) html += ' · ' + (last.notes);
        container.innerHTML = html;
      }

      function renderHistoryList(container, items){
        if (!container) return;
        container.innerHTML = '';
        if (!items || items.length === 0) {
          var empty = document.getElementById('attendance-history-empty');
          if (empty) container.appendChild(empty.cloneNode(true));
          return;
        }
        items.forEach(function(it){
          var row = document.createElement('div');
          row.className = 'duty-item';
          row.innerHTML = '<div class="duty-item__left">' +
                          '<div class="duty-item__icon"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none" /><path d="M12 8.2V12l2.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" /></svg></div>' +
                          '<div><div class="duty-item__day">' + (it.status ? it.status.charAt(0).toUpperCase() + it.status.slice(1) : 'Absent') + '</div>' +
                          '<div class="duty-item__date">' + (new Date(it.created_at)).toLocaleDateString() + '</div></div></div>' +
                          '<div class="duty-item__right"><div class="duty-item__office">Late: ' + (parseInt(it.late_minutes || 0, 10)) + ' min' + (it.notes ? ' · ' + it.notes : '') + '</div></div>';
          container.appendChild(row);
        });
      }

      function updateAttendanceUI(data){
        if (!data || !data.success) return;
        var sum = data.summary || {};
        var rate = 0;
        if (sum.total && sum.total > 0) rate = Math.round(((sum.present + sum.late) / sum.total) * 100);

        var rateEl = document.getElementById('attendance-rate-value');
        var rateBar = document.getElementById('attendance-rate-bar');
        var presLate = document.getElementById('attendance-present-late-value');
        var absentVal = document.getElementById('attendance-absent-value');
        var lastLog = document.getElementById('attendance-last-log');
        var historyList = document.getElementById('attendance-history-list');

        if (rateEl) rateEl.textContent = rate + '%';
        if (rateBar) { rateBar.style.width = rate + '%'; rateBar.setAttribute('aria-valuenow', String(rate)); }
        if (presLate) presLate.textContent = String((sum.present || 0)) + ' / ' + String((sum.late || 0));
        if (absentVal) absentVal.textContent = String((sum.absent || 0)) + ' / ' + String((sum.incomplete || 0));

        renderLastLog(lastLog, (data.attendance && data.attendance[0]) ? data.attendance[0] : null);

        if (historyList) {
          renderHistoryList(historyList, data.attendance || []);
        } else {
          var container = document.getElementById('attendance-history-container');
          if (container) renderHistoryList(container, data.attendance || []);
        }
      }

      function fetchAttendanceSnapshot(){
        fetch('../api/attendance_snapshot.php', { credentials: 'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(json){ updateAttendanceUI(json); })
          .catch(function(err){ /* silent */ console.error('attendance snapshot failed', err); });
      }

      // Initial fetch and then every 5s
      fetchAttendanceSnapshot();
      setInterval(fetchAttendanceSnapshot, 5000);

      // Prefer SSE for live attendance updates
      if (window.EventSource) {
        try {
          var es = new EventSource('../api/attendance_stream.php');
          es.addEventListener('attendance', function(e){
            try {
              var data = JSON.parse(e.data);
              if (data && data.success) {
                updateAttendanceUI(data);
              }
            } catch (err) { console.error('SSE parse error', err); }
          });
          es.addEventListener('error', function(ev){
            // on error, fallback to polling (already running)
            try { es.close(); } catch (ex) {}
          });
        } catch (ex) {
          console.error('SSE setup failed:', ex);
        }
      }

      window.SAMS_fetchAttendanceSnapshot = fetchAttendanceSnapshot;
    }());
  </script>
  <script>
    (function(){
      'use strict';

      function setText(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = value || '';
        el.hidden = !value;
      }

      function updateCurrentAssignmentUI(data) {
        var officeEl = document.getElementById('current-assignment-office');
        var scheduleEl = document.getElementById('current-assignment-schedule');
        var metaEl = document.getElementById('current-assignment-meta');
        if (!officeEl || !scheduleEl || !metaEl) return;

        var assignment = data && data.current_assignment ? data.current_assignment : null;

        if (!assignment) {
          officeEl.textContent = 'No assignment yet';
          scheduleEl.textContent = 'Your assigned office, schedule, and term will appear here once admin creates your duty.';
          metaEl.innerHTML = '';
          metaEl.hidden = true;
          return;
        }

        officeEl.textContent = assignment.office_name || 'Current assignment';
        scheduleEl.textContent = (assignment.day_of_week || 'Upcoming') + ' · ' + (assignment.time_start_label || assignment.time_start || '') + ' - ' + (assignment.time_end_label || assignment.time_end || '');
        metaEl.hidden = false;

        setText('current-assignment-supervisor', assignment.supervisor_name || '');
        setText('current-assignment-term', assignment.term_label || '');
        setText('current-assignment-status', assignment.status_label || '');
      }

      function refreshCurrentAssignment() {
        return fetch('../api/student_dashboard_snapshot.php', { credentials: 'same-origin' })
          .then(function(response) { return response.json(); })
          .then(function(data) {
            if (data && data.success) {
              updateCurrentAssignmentUI(data);
            }
          })
          .catch(function(error) {
            console.error('current assignment snapshot failed', error);
          });
      }

      refreshCurrentAssignment();
      setInterval(refreshCurrentAssignment, 15000);

      window.SAMS_refreshCurrentAssignment = refreshCurrentAssignment;
    }());
  </script>
  </div><!-- /.main -->

</div><!-- /.app -->

<script>
  (function () {
    'use strict';

    /* ---- Sidebar toggle (mobile/tablet) ---- */
    var toggle  = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');

    function openSidebar() {
      sidebar.classList.add('is-open');
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'true');
    }
    function closeSidebar() {
      sidebar.classList.remove('is-open');
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
      sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
    });
    overlay.addEventListener('click', closeSidebar);

    window.setInterval(function () {
      window.location.reload();
    }, 90000);

  }());
</script>
<script src="../assets/js/student-notifications.js?v=20260922"></script>

</body>
</html>