<?php
// schedule.php – NU SAMS Student Portal | My Duty Schedule
// Student Assistant Management System | National University – Lipa
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

function sams_schedule_day_rank(string $day): int
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

function sams_schedule_time_label(string $time): string
{
  $timestamp = strtotime($time);
  return $timestamp ? date('g:i A', $timestamp) : $time;
}

function sams_schedule_time_to_minutes(string $time): int
{
  $timestamp = strtotime($time);
  if ($timestamp === false) {
    return 0;
  }

  return ((int) date('H', $timestamp)) * 60 + (int) date('i', $timestamp);
}

function sams_schedule_event_top(string $time, int $startHour = 7, int $hourHeight = 64): int
{
  $minutes = sams_schedule_time_to_minutes($time);
  $offsetMinutes = max(0, $minutes - ($startHour * 60));
  return (int) round(($offsetMinutes / 60) * $hourHeight);
}

function sams_schedule_event_height(string $startTime, string $endTime, int $hourHeight = 64): int
{
  $startMinutes = sams_schedule_time_to_minutes($startTime);
  $endMinutes = sams_schedule_time_to_minutes($endTime);
  $durationMinutes = max(30, $endMinutes - $startMinutes);

  return (int) round(($durationMinutes / 60) * $hourHeight);
}

function sams_monthly_minimum_hours(int $units): int
{
  if ($units <= 14) return 100;
  if ($units <= 18) return 75;
  return 50;
}

function sams_schedule_date_for_day(string $day): string
{
  $map = [
    'Monday' => 1,
    'Tuesday' => 2,
    'Wednesday' => 3,
    'Thursday' => 4,
    'Friday' => 5,
    'Saturday' => 6,
  ];

  if (!isset($map[$day])) {
    return date('Y-m-d');
  }

  $today = (int) date('N');
  $diff = $map[$day] - $today;
  if ($diff < 0) {
    $diff += 7;
  }

  $date = new DateTimeImmutable('today');
  if ($diff > 0) {
    $date = $date->modify('+' . $diff . ' days');
  }

  return $date->format('Y-m-d');
}

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
  header('Location: ../login.php');
  exit;
}

$pdo = sams_pdo();
$studentSchedules = [];
$studentName = (string) ($currentUser['name'] ?? 'Student');
$studentCode = (string) ($currentUser['student_id'] ?? '');
$totalHours = 0.0;
$totalDays = 0;
$acceptedSchedules = 0;
$pendingSchedules = 0;
$declinedSchedules = 0;
$notificationCount = 0;
$studentUnits = 0;
$monthlyMinimumHours = 0;
$estimatedMonthlyHours = 0.0;
$studentApplicationId = 0;
$availabilityChangePending = false;

$userId = (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0);
if ($userId > 0) {
  $studentStmt = $pdo->prepare(
    'SELECT s.student_id AS id, s.student_id_number, u.first_name, u.last_name
     FROM students s
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE s.user_id = :user_id
     LIMIT 1'
  );
  $studentStmt->execute(['user_id' => $userId]);
  $studentRow = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

  if (!empty($studentRow)) {
    $studentName = trim((string) ($studentRow['first_name'] ?? '') . ' ' . (string) ($studentRow['last_name'] ?? '')) ?: $studentName;
    $studentCode = (string) ($studentRow['student_id_number'] ?? $studentCode);
    $studentId = (int) ($studentRow['id'] ?? 0);

    if ($studentId > 0) {
      $unitsColumn = sams_column_exists($pdo, 'students', 'units') ? 's.units' : 'NULL';
      $unitsStmt = $pdo->prepare(
        "SELECT COALESCE({$unitsColumn}, 0), a.available_hours_per_week
         FROM students s
         LEFT JOIN applications a ON a.student_id = s.student_id
         WHERE s.student_id = :student_id
         ORDER BY a.application_id DESC LIMIT 1"
      );
      $unitsStmt->execute(['student_id' => $studentId]);
      $unitsRow = $unitsStmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
      $studentUnits = (int) ($unitsRow[0] ?? 0);
      $monthlyMinimumHours = sams_monthly_minimum_hours($studentUnits);
      $applicationStmt = $pdo->prepare(
        "SELECT a.application_id,
                EXISTS (
                  SELECT 1 FROM availability_change_requests r
                  WHERE r.application_id = a.application_id AND r.status = 'pending'
                ) AS availability_change_pending
         FROM applications a
         WHERE a.student_id = :student_id
         ORDER BY a.application_id DESC LIMIT 1"
      );
      try {
        $applicationStmt->execute(['student_id' => $studentId]);
        $applicationRow = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $studentApplicationId = (int) ($applicationRow['application_id'] ?? 0);
        $availabilityChangePending = (bool) ($applicationRow['availability_change_pending'] ?? false);
      } catch (Throwable $exception) {
        $studentApplicationId = 0;
      }

      $schedStmt = $pdo->prepare(
          "SELECT ds.duty_id AS id,
            COALESCE(NULLIF(TRIM(ds.office_name), ''), NULLIF(TRIM(a.preferred_office), ''), 'Unassigned') AS office_name,
            ds.day_of_week, ds.start_time AS time_start, ds.end_time AS time_end, ds.status,
                ds.term_id, t.term_name, t.term_year AS school_year,
                al.log_id AS attendance_id, al.status AS attendance_status, al.late_minutes, al.notes AS remarks, al.clock_in_time AS time_in, al.clock_out_time AS time_out
         FROM duty_schedules ds
         LEFT JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN terms t ON t.term_id = ds.term_id
         LEFT JOIN attendance_logs al ON al.log_id = (
           SELECT al2.log_id FROM attendance_logs al2
           WHERE al2.application_id = ds.application_id
             AND al2.duty_id = ds.duty_id
           ORDER BY al2.log_id DESC LIMIT 1
         )
         WHERE a.student_id = :student_id
         ORDER BY FIELD(ds.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), ds.start_time ASC"
      );
      $schedStmt->execute(['student_id' => $studentId]);
      $studentSchedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

      $seenAcceptedDays = [];
      foreach ($studentSchedules as $schedule) {
        $day = (string) ($schedule['day_of_week'] ?? '');

        $status = (string) ($schedule['status'] ?? 'pending');
        if ($status === 'accepted' || $status === 'deployed') {
          $start = strtotime((string) ($schedule['time_start'] ?? ''));
          $end = strtotime((string) ($schedule['time_end'] ?? ''));
          if ($start && $end && $end > $start) {
            $totalHours += ($end - $start) / 3600;
          }

          if ($day !== '' && !isset($seenAcceptedDays[$day])) {
            $seenAcceptedDays[$day] = true;
          }

          $acceptedSchedules++;
        } elseif ($status === 'declined') {
          $declinedSchedules++;
        } else {
          $pendingSchedules++;
        }
      }

      $totalDays = count($seenAcceptedDays);
      $notificationCount = $pendingSchedules + ($declinedSchedules > 0 ? 1 : 0);
      $estimatedMonthlyHours = $totalHours * 4.33;
    }
  }
}

$monthlyHoursWarning = $studentUnits > 0 && $estimatedMonthlyHours < $monthlyMinimumHours;

$calendarDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$calendarByDay = array_fill_keys($calendarDays, []);

foreach ($studentSchedules as $schedule) {
  $day = (string) ($schedule['day_of_week'] ?? '');
  if (!isset($calendarByDay[$day])) {
    continue;
  }

  $status = (string) ($schedule['status'] ?? 'pending');
  if ($status !== 'accepted' && $status !== 'deployed') {
    continue;
  }

  $calendarByDay[$day][] = [
    'id' => (int) ($schedule['id'] ?? 0),
    'office_name' => (string) ($schedule['office_name'] ?? ''),
    'start_label' => sams_schedule_time_label((string) ($schedule['time_start'] ?? '')),
    'end_label' => sams_schedule_time_label((string) ($schedule['time_end'] ?? '')),
    'top' => sams_schedule_event_top((string) ($schedule['time_start'] ?? '')),
    'height' => sams_schedule_event_height((string) ($schedule['time_start'] ?? ''), (string) ($schedule['time_end'] ?? '')),
    'status' => (string) ($schedule['status'] ?? 'pending'),
  ];
}

$weekStart = new DateTimeImmutable('monday this week');
$weekEnd = $weekStart->modify('+5 days');
$weekRangeLabel = $weekStart->format('M d') . ' - ' . $weekEnd->format('M d, Y');

$nextSchedule = null;
if (!empty($studentSchedules)) {
  $sorted = $studentSchedules;
  usort($sorted, static function (array $left, array $right): int {
    $dayCompare = sams_schedule_day_rank((string) ($left['day_of_week'] ?? '')) <=> sams_schedule_day_rank((string) ($right['day_of_week'] ?? ''));
    if ($dayCompare !== 0) {
      return $dayCompare;
    }

    return strcmp((string) ($left['time_start'] ?? ''), (string) ($right['time_start'] ?? ''));
  });

  $nextSchedule = $sorted[0] ?? null;
}

$todayDay = date('l');
$todaySchedules = array_values(array_filter($studentSchedules, static fn (array $schedule): bool => (string) ($schedule['day_of_week'] ?? '') === $todayDay));

$upcomingSchedules = [];
if (!empty($studentSchedules)) {
  $todayRank = sams_schedule_day_rank($todayDay);
  $todayTime = strtotime(date('H:i')) ?: 0;

  $futureOrCurrent = array_values(array_filter($studentSchedules, static function (array $schedule) use ($todayRank, $todayTime): bool {
    $status = (string) ($schedule['status'] ?? 'pending');
    if ($status !== 'accepted' && $status !== 'deployed') {
      return false;
    }

    $dayRank = sams_schedule_day_rank((string) ($schedule['day_of_week'] ?? ''));
    if ($dayRank > $todayRank) {
      return true;
    }

    if ($dayRank < $todayRank) {
      return false;
    }

    $scheduleTime = strtotime((string) ($schedule['time_start'] ?? ''));
    return $scheduleTime !== false ? $scheduleTime >= $todayTime : true;
  }));

  usort($futureOrCurrent, static function (array $left, array $right): int {
    $dayCompare = sams_schedule_day_rank((string) ($left['day_of_week'] ?? '')) <=> sams_schedule_day_rank((string) ($right['day_of_week'] ?? ''));
    if ($dayCompare !== 0) {
      return $dayCompare;
    }

    return strcmp((string) ($left['time_start'] ?? ''), (string) ($right['time_start'] ?? ''));
  });

  $upcomingSchedules = array_slice($futureOrCurrent, 0, 5);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Schedule – SAMS Student Portal | NU Lipa</title>
  <meta name="description" content="View and manage your weekly duty schedule in the SAMS Student Portal." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      /* Brand */
      --color-primary:       #003087;
      --color-primary-end:   #0047ab;
      --color-gold:          #ffb81c;
      --color-white:         #ffffff;

      /* Greys */
      --color-dark:          #101828;
      --color-body:          #364153;
      --color-muted:         #4a5565;
      --color-muted-light:   #99a1af;
      --color-bg:            #f9fafb;
      --color-card-border:   #f3f4f6;
      --color-border:        #e5e7eb;

      /* Accents */
      --color-blue-pale:     #bedbff;
      --color-blue-light:    #dbeafe;
      --color-blue-info:     #1c398e;

      /* Red dot */
      --color-red-dot:       #fb2c36;

      /* Sidebar */
      --sidebar-w:           288px;
      --grad-sidebar:        linear-gradient(180deg, #003087 0%, #0047ab 100%);
      --grad-primary-135:    linear-gradient(135deg, #003087 0%, #0047ab 100%);
      --grad-primary-126:    linear-gradient(126deg, #003087 0%, #0047ab 100%);
      --grad-gold:           linear-gradient(135deg, #ffb81c 0%, #ffa500 100%);
      --grad-page:           linear-gradient(133deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);

      /* Shadows */
      --shadow-sidebar:      0 25px 50px 0 rgba(0,0,0,.25);
      --shadow-card:         0 10px 15px 0 rgba(0,0,0,.10), 0 4px 6px 0 rgba(0,0,0,.10);
      --shadow-nav:          0 1px 3px 0 rgba(0,0,0,.10), 0 1px 2px 0 rgba(0,0,0,.10);
      --shadow-event:        0 10px 15px -3px rgba(0,0,0,.10), 0 4px 6px -4px rgba(0,0,0,.10);

      /* Radii */
      --radius-sm:           10px;
      --radius-md:           14px;
      --radius-lg:           16px;
      --radius-pill:         9999px;

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

      /* Calendar */
      --time-col-w:   150px;
      --day-col-w:    150px;
      --hour-h:       64px;
      --cal-start-hr: 7;   /* 7 AM */
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
    }

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
    .sidebar__brand-name { font-size: var(--font-xl);  font-weight: 900; color: var(--color-white); }
    .sidebar__brand-sub  { font-size: var(--font-xs);  font-weight: 400; color: var(--color-blue-pale); }

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
    .sidebar__user {
      background: #0047ab;
      border: 1px solid rgba(255,255,255,.22);
      border-radius: var(--radius-md);
      padding: var(--space-4);
    }
    .sidebar__user-label { font-size: var(--font-sm); font-weight: 600; color: #dbeafe; }
    .sidebar__user-name  { font-size: var(--font-base); font-weight: 900; color: var(--color-white); }
    .sidebar__user-id    { font-size: var(--font-xs);   font-weight: 500; color: #dbeafe; }
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
    }
    .sidebar__logout:hover { background: rgba(255,255,255,.18); }
    .sidebar__logout img  { width: 20px; height: 20px; }

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
    .topbar__title { font-size: var(--font-2xl); font-weight: 900; color: var(--color-dark); }
    .topbar__sub   { font-size: var(--font-sm);  font-weight: 500; color: var(--color-muted); }
    .topbar__actions { display: flex; align-items: center; gap: var(--space-2); }
    .topbar__icon-btn {
      width: 40px; height: 40px;
      border-radius: var(--radius-sm);
      display: flex; align-items: center; justify-content: center;
      background: none; border: none;
      position: relative; cursor: pointer;
    }
    .topbar__icon-btn img { width: 24px; height: 24px; }
    .topbar__icon-btn svg { width: 24px; height: 24px; }
    .topbar__notif-dot {
      position: absolute;
      top: -3px; right: -3px;
      min-width: 18px; height: 18px;
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
       PAGE CONTENT
    ============================================= */
    .content {
      flex: 1;
      padding: var(--space-8);
      display: flex;
      flex-direction: column;
      gap: var(--space-8);
    }

    /* =============================================
       PAGE HEADER ROW
    ============================================= */
    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-4);
      flex-wrap: wrap;
    }
    .page-header__left {}
    .page-header__title {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      font-size: var(--font-4xl);
      font-weight: 900;
      color: var(--color-dark);
      line-height: 1.1;
      margin-bottom: var(--space-2);
    }
    .page-header__title img { width: 40px; height: 40px; }
    .page-header__title svg { width: 40px; height: 40px; }
    .page-header__sub {
      font-size: var(--font-lg);
      font-weight: 500;
      color: var(--color-muted);
    }
    .page-header__actions {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      flex-wrap: wrap;
    }

    /* View toggle (Calendar / List) */
    .view-toggle {
      display: flex;
      align-items: center;
      background: var(--color-white);
      border: 2px solid var(--color-border);
      border-radius: var(--radius-md);
      padding: 6px;
      gap: var(--space-1);
      height: 52px;
    }
    .view-toggle__btn {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      height: 40px;
      padding: 0 var(--space-4);
      border-radius: var(--radius-sm);
      border: none;
      font-size: var(--font-base);
      font-weight: 700;
      cursor: pointer;
      transition: background .15s, color .15s;
      white-space: nowrap;
    }
    .view-toggle__btn img { width: 16px; height: 16px; }
    .view-toggle__btn svg { width: 16px; height: 16px; }
    .view-toggle__btn--active {
      background: var(--color-primary);
      color: var(--color-white);
    }
    .view-toggle__btn--inactive {
      background: none;
      color: var(--color-muted);
    }

    /* Load Demo Data button */
    .btn-demo {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 48px;
      padding: 0 var(--space-6);
      border-radius: var(--radius-md);
      background: var(--grad-primary-135);
      border: none;
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      cursor: pointer;
      transition: opacity .15s;
      white-space: nowrap;
    }
    .btn-demo:hover { opacity: .88; }
    .btn-demo img { width: 20px; height: 20px; }
    .btn-demo svg { width: 20px; height: 20px; }

    /* =============================================
       STAT MINI-CARDS ROW
    ============================================= */
    .stat-row {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 24px;
    }
    .stat-mini {
      background: var(--color-white);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: 26px;
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }
    .stat-mini__icon {
      width: 56px; height: 56px;
      border-radius: var(--radius-md);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      color: var(--color-white);
    }
    .stat-mini__icon img { width: 28px; height: 28px; }
    .stat-mini__icon svg { width: 28px; height: 28px; }
    .stat-mini__icon--blue { background: var(--grad-primary-135); }
    .stat-mini__icon--gold { background: var(--grad-gold); }
    .stat-mini__label { font-size: var(--font-sm); font-weight: 700; color: var(--color-muted); }
    .stat-mini__value { font-size: var(--font-3xl); font-weight: 900; color: var(--color-dark); line-height: 1.2; }

    .stat-mini__range { font-size: var(--font-sm); font-weight: 900; color: var(--color-dark); line-height: 1.4; }
    .stat-mini__sub { font-size: var(--font-xs); font-weight: 500; color: var(--color-muted); margin-top: 2px; }

    /* Week navigator */
    .week-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0;
    }
    .week-nav__btn {
      width: 40px; height: 40px;
      border-radius: var(--radius-sm);
      border: none;
      background: none;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
      transition: background .15s;
    }
    .week-nav__btn:hover { background: var(--color-bg); }
    .week-nav__btn img { width: 24px; height: 24px; }
    .week-nav__info { text-align: center; }
    .week-nav__label { font-size: var(--font-sm); font-weight: 700; color: var(--color-muted); }
    .week-nav__range { font-size: var(--font-lg); font-weight: 900; color: var(--color-dark); }

    /* =============================================
       CALENDAR GRID
    ============================================= */
    .cal-card {
      background: var(--color-white);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      overflow: hidden;
      width: 100%;
      min-width: 0;
    }

    .schedule-board {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 320px;
      gap: var(--space-6);
      align-items: start;
      width: 100%;
      min-width: 0;
    }

    .schedule-alert {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-top: 20px;
      padding: 14px 16px;
      border: 1px solid #fca5a5;
      border-radius: 12px;
      background: #fef2f2;
      color: #991b1b;
    }

    .schedule-alert > div {
      display: grid;
      gap: 4px;
    }

    .schedule-alert span {
      font-size: var(--font-sm);
      font-weight: 500;
      line-height: 1.45;
    }

    .schedule-alert__action {
      display: inline-flex;
      flex-shrink: 0;
      align-items: center;
      justify-content: center;
      min-height: 40px;
      padding: 0 14px;
      background: var(--color-primary);
      color: #fff;
      white-space: nowrap;
    }

    /* Horizontal scroll wrapper for smaller screens */
    .cal-scroll {
      position: relative;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      width: 100%;
      min-width: 0;
      scrollbar-gutter: stable;
    }

    .cal-events {
      position: absolute;
      top: 58px;
      left: 0;
      width: 100%;
      min-width: 0;
      height: calc(15 * var(--hour-h));
      display: flex;
      pointer-events: none;
      z-index: 2;
    }

    .cal-grid {
      display: grid;
      /* time col + rendered day cols */
      grid-template-columns: var(--time-col-w) repeat(<?php echo count($calendarDays); ?>, minmax(0, 1fr));
      min-width: 0;
      width: 100%;
    }

    .cal-events__time-spacer {
      width: var(--time-col-w);
      flex: 0 0 var(--time-col-w);
    }

    .cal-events__day {
      position: relative;
      width: auto;
      min-width: 0;
      flex: 1 1 0;
      height: calc(15 * var(--hour-h));
      pointer-events: auto;
    }

    @media (max-width: 980px) {
      .schedule-board {
        grid-template-columns: 1fr;
      }

      .upcoming-card {
        position: static;
      }

      .cal-scroll {
        overflow-x: auto;
      }

      .cal-grid,
      .cal-events {
        width: max-content;
        min-width: calc(var(--time-col-w) + <?php echo count($calendarDays); ?> * var(--day-col-w));
      }

      .cal-grid {
        grid-template-columns: var(--time-col-w) repeat(<?php echo count($calendarDays); ?>, var(--day-col-w));
      }

      .cal-events__day {
        flex: 0 0 var(--day-col-w);
        width: var(--day-col-w);
      }
    }

    @media (max-width: 640px) {
      .schedule-alert {
        align-items: stretch;
        flex-direction: column;
      }

      .schedule-alert__action {
        width: 100%;
      }
    }

    .upcoming-card {
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: 22px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      position: sticky;
      top: 16px;
    }

    .upcoming-card__heading {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .upcoming-card__title {
      font-size: var(--font-lg);
      font-weight: 900;
      color: var(--color-dark);
    }

    .upcoming-card__sub {
      font-size: var(--font-sm);
      color: var(--color-muted);
      line-height: 1.4;
    }

    .upcoming-card__list {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .upcoming-item {
      border: 1px solid var(--color-border);
      border-radius: 14px;
      padding: 14px;
      background: #fff;
      box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .upcoming-item__top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }

    .upcoming-item__day {
      font-size: var(--font-sm);
      font-weight: 900;
      color: var(--color-dark);
    }

    .upcoming-item__time {
      margin-top: 2px;
      font-size: 13px;
      color: var(--color-muted);
    }

    .upcoming-item__office {
      font-size: 13px;
      font-weight: 700;
      color: var(--color-body);
      line-height: 1.35;
    }

    .upcoming-item__badge {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      padding: 6px 10px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: .04em;
      white-space: nowrap;
    }

    .upcoming-item__badge--deployed {
      background: #dbeafe;
      color: #1d4ed8;
    }

    .upcoming-item__badge--accepted {
      background: #dcfce7;
      color: #166534;
    }

    .upcoming-item__badge--pending {
      background: #fffbeb;
      color: #d97706;
    }

    .upcoming-item__badge--declined {
      background: #fee2e2;
      color: #991b1b;
    }

    .upcoming-card__empty {
      padding: 16px;
      border-radius: 14px;
      background: #fff;
      border: 1px dashed var(--color-border);
      color: var(--color-muted);
      line-height: 1.5;
    }

    .upcoming-card__link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      height: 44px;
      border-radius: 12px;
      border: 1px solid var(--color-border);
      background: #fff;
      color: var(--color-primary);
      font-weight: 800;
      transition: background-color .15s ease, transform .15s ease;
    }

    .upcoming-card__link:hover {
      background: var(--color-bg);
      transform: translateY(-1px);
    }

    /* Header row */
    .cal-header {
      display: contents;
    }
    .cal-header__time,
    .cal-header__day {
      background: var(--color-bg);
      border-bottom: 2px solid var(--color-border);
      padding: 0 var(--space-4);
      height: 58px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-right: 1px solid var(--color-border);
    }
    .cal-header__time {
      justify-content: flex-start;
      font-size: var(--font-sm);
      font-weight: 900;
      color: var(--color-muted);
      letter-spacing: .05em;
    }
    .cal-header__day {
      font-size: var(--font-base);
      font-weight: 900;
      color: var(--color-dark);
    }

    /* Body: time column + day columns are laid out as row groups */
    .cal-body {
      display: contents;
    }

    /* Time column cells */
    .cal-time {
      border-right: 1px solid var(--color-border);
      border-bottom: 1px solid var(--color-border);
      height: var(--hour-h);
      padding: 6px 0 0 8px;
      font-size: var(--font-xs);
      font-weight: 700;
      color: var(--color-muted);
    }

    /* Day column: each is a relative container with absolutely-placed events */
    .cal-day-col {
      position: relative;
      border-right: 1px solid var(--color-border);
      /* total body height = 15 hours × 64px = 960px */
      height: calc(15 * var(--hour-h));
    }
    /* Empty hour lines inside each day column */
    .cal-day-col__line {
      position: absolute;
      left: 0; right: 0;
      height: var(--hour-h);
      border-bottom: 1px solid var(--color-border);
    }

    /* Event block */
    .cal-event {
      position: absolute;
      left: 6px;
      right: 6px;
      border-radius: var(--radius-sm);
      box-shadow: var(--shadow-event);
      padding: 14px 12px 12px;
      overflow: hidden;
      display: grid;
      grid-template-rows: 1fr auto 1fr;
      justify-items: center;
      text-align: center;
    }
    .cal-event--pending { background: #fff1f2; border: 2px solid #fda4af; }
    .cal-event--pending .cal-event__time, .cal-event--pending .cal-event__loc, .cal-event--pending .cal-event__recur, .cal-event--pending .cal-event__icon { color: #9f1239; }

    .cal-event--accepted { background: #f0fdf4; border: 2px solid #86efac; }
    .cal-event--accepted .cal-event__time, .cal-event--accepted .cal-event__loc, .cal-event--accepted .cal-event__recur, .cal-event--accepted .cal-event__icon { color: #166534; }

    .cal-event--deployed { background: #eff6ff; border: 2px solid #93c5fd; }
    .cal-event--deployed .cal-event__time, .cal-event--deployed .cal-event__loc, .cal-event--deployed .cal-event__recur, .cal-event--deployed .cal-event__icon { color: #1e3a8a; }

    .cal-event > div:first-child {
      width: 100%;
      grid-row: 2;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }
    .cal-event__time-row {
      display: flex;
      align-items: center;
      gap: 6px;
      justify-content: center;
      width: 100%;
      max-width: 100%;
      flex-wrap: wrap;
      margin: 0 auto;
    }
    .cal-event__icon { width: 12px; height: 12px; flex-shrink: 0; }
    .cal-event__time {
      font-size: 11px;
      font-weight: 900;
      white-space: normal;
      overflow-wrap: anywhere;
      text-align: center;
      line-height: 1.15;
    }
    .cal-event__loc-row {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 6px;
      justify-content: center;
      width: 100%;
      max-width: 100%;
      flex-wrap: wrap;
      margin-left: auto;
      margin-right: auto;
    }
    .cal-event__loc {
      font-size: 11px;
      font-weight: 700;
      white-space: normal;
      overflow-wrap: anywhere;
      text-align: center;
      line-height: 1.15;
    }
    .cal-event__recur {
      font-size: var(--font-xs);
      font-weight: 900;
      grid-row: 3;
      align-self: end;
      justify-self: center;
      margin-top: 0;
    }

    .schedule-empty-state {
      padding: var(--space-8);
      text-align: center;
      color: var(--color-muted);
      font-weight: 500;
    }
    .schedule-empty-state__title {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
      margin-bottom: 6px;
    }
    .schedule-empty-state__sub {
      font-size: var(--font-base);
      line-height: 1.6;
    }

    .card {
      background: var(--color-white);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: 28px;
      display: flex;
      flex-direction: column;
      gap: var(--space-5);
    }
    .card__title {
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-dark);
    }

    .schedule-list__table-wrap {
      overflow-x: auto;
      background: var(--color-white);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
    }

    .schedule-list__table {
      width: 100%;
      min-width: 860px;
      border-collapse: collapse;
    }

    .schedule-list__thead th {
      position: sticky;
      top: 0;
      z-index: 1;
      text-align: left;
      color: var(--color-dark);
      font-weight: 800;
      font-size: 13px;
      letter-spacing: .02em;
      background: var(--color-bg);
      border-bottom: 2px solid var(--color-border);
    }

    .schedule-list__row {
      border-bottom: 1px solid var(--color-border);
      transition: background-color .2s ease;
    }

    .schedule-list__row:hover {
      background: #f9fafb;
    }

    .schedule-list__cell {
      padding: 14px 16px;
      border-right: 1px solid var(--color-border);
      vertical-align: top;
    }

    .schedule-list__cell--last {
      border-right: none;
    }

    .schedule-list__meta {
      font-weight: 600;
      color: var(--color-dark);
    }

    .schedule-list__sub {
      margin-top: 3px;
      font-size: 13px;
      color: var(--color-muted);
    }

    .schedule-list__hours {
      font-weight: 700;
      color: var(--color-primary);
      white-space: nowrap;
    }

    .schedule-list__badge {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 9999px;
      font-weight: 700;
      font-size: 12px;
      letter-spacing: .04em;
      white-space: nowrap;
    }

    .schedule-list__action-btn {
      border: none;
      border-radius: 10px;
      padding: 8px 14px;
      font-weight: 700;
      font-size: 13px;
      cursor: pointer;
      transition: transform .15s ease, background-color .2s ease;
    }

    .schedule-list__action-btn:hover {
      transform: translateY(-1px);
    }

    .schedule-list__action-btn--accept {
      background: var(--grad-primary-135);
      color: var(--color-white);
    }

    .schedule-list__action-btn--decline {
      background: var(--color-gold);
      color: var(--color-dark);
    }

    .schedule-list__actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }

    .schedule-list__hint {
      color: var(--color-muted);
      font-size: 13px;
    }

    .view-panel.is-hidden {
      display: none;
    }

    /* =============================================
       SCHEDULE GUIDELINES
    ============================================= */
    .guidelines {
      border: 2px solid var(--color-blue-pale);
      border-radius: var(--radius-lg);
      background: linear-gradient(168deg, #eff6ff 0%, #eee2ff 100%);
      padding: 34px;
    }
    .guidelines__heading {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-blue-info);
      margin-bottom: var(--space-6);
    }
    .guidelines__icon {
      width: 28px;
      height: 28px;
      flex-shrink: 0;
      display: block;
    }
    .guidelines__heading img { width: 28px; height: 28px; }
    .guidelines__grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-5) var(--space-8);
    }
    .guideline-item {
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
    }
    .guideline-item__emoji { font-size: 30px; flex-shrink: 0; line-height: 1; }
    .guideline-item__title {
      font-size: var(--font-base);
      font-weight: 900;
      color: var(--color-dark);
      margin-bottom: 4px;
    }
    .guideline-item__desc {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-body);
    }

    /* =============================================
       SIDEBAR MOBILE OVERLAY
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
      .stat-row { grid-template-columns: 1fr 1fr; }
      .guidelines__grid { grid-template-columns: 1fr; }
      .schedule-board { grid-template-columns: 1fr; }
      .upcoming-card { position: static; }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .content { padding: var(--space-4); gap: var(--space-6); }
      .topbar { padding: 0 var(--space-4) 0 64px; height: 72px; }
      .topbar__title { font-size: var(--font-lg); }
      .page-header { flex-direction: column; align-items: flex-start; }
      .page-header__title { font-size: 26px; }
      .page-header__sub   { font-size: var(--font-base); }
      .stat-row { grid-template-columns: 1fr; }
    }
  </style>
<link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
</head>
<body>

<div class="app">


  <aside class="sidebar" id="sidebar" aria-label="Student navigation">

    <div class="sidebar__brand">
      <div class="sidebar__logo" aria-hidden="true">NU</div>
      <div>
        <div class="sidebar__brand-name">SAMS</div>
        <div class="sidebar__brand-sub">Student Assistant Management</div>
      </div>
    </div>

    <nav class="sidebar__nav" aria-label="Main navigation">
      <a class="nav-item" href="dashboard.php">
        <svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M3 11.5L12 4l9 7.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
          <path d="M5 10.5V20h5v-5h4v5h5v-9.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
        </svg>
        Dashboard
      </a>
      <a class="nav-item nav-item--active" href="#" aria-current="page">
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

    <div class="sidebar__footer">
      <div class="sidebar__user">
        <div class="sidebar__user-label">Logged in as</div>
        <div class="sidebar__user-name"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="sidebar__user-id">Student ID: <?php echo htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8'); ?></div>
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
        <button class="topbar__icon-btn" aria-label="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M12 4a5 5 0 0 0-5 5v2.2c0 .9-.2 1.8-.6 2.6L5.2 15.6A1 1 0 0 0 6 17h12a1 1 0 0 0 .8-1.4l-1.2-1.8c-.4-.8-.6-1.7-.6-2.6V9a5 5 0 0 0-5-5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
              <path d="M9.5 17.5a2.8 2.8 0 0 0 5 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
            <span class="topbar__notif-dot" style="display:none;" aria-label="New notifications"></span>
        </button>
        <script>window.SAMS_CSRF = '<?php echo addslashes(sams_csrf_token()); ?>';</script>
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

      <!-- ---- Page header ---- -->
      <div class="page-header">
        <div class="page-header__left">
          <div class="page-header__title">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="page-header__icon">
                <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
                <path d="M8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
              </svg>
            My Duty Schedule
          </div>
          <div class="page-header__sub">View and manage your weekly duty schedule</div>
        </div>
        <div class="page-header__actions">
          <div class="view-toggle" role="group" aria-label="View mode">
            <button class="view-toggle__btn view-toggle__btn--active" id="btn-calendar" aria-pressed="true">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
                <path d="M8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
              </svg>
              Calendar
            </button>
            <button class="view-toggle__btn view-toggle__btn--inactive" id="btn-list" aria-pressed="false">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M6 7h12M6 12h12M6 17h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <circle cx="4" cy="7" r="1" fill="currentColor" /><circle cx="4" cy="12" r="1" fill="currentColor" /><circle cx="4" cy="17" r="1" fill="currentColor" />
              </svg>
              List
            </button>
          </div>
          <a class="btn-demo" href="dashboard.php" style="background:var(--color-white); color:var(--color-primary); border:2px solid var(--color-border);">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M3 11.5L12 4l9 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
              <path d="M5 10.5V20h5v-5h4v5h5v-9.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
            </svg>
            Back to Dashboard
          </a>
        </div>
      </div>

      <?php if ($monthlyHoursWarning): ?>
        <div class="schedule-alert">
          <div>
            <strong>Monthly duty hours below minimum</strong>
            <span>Your schedule is estimated at <?= htmlspecialchars(number_format($estimatedMonthlyHours, 1), ENT_QUOTES, 'UTF-8') ?> hours/month, below the <?= (int) $monthlyMinimumHours ?>-hour minimum for <?= (int) $studentUnits ?> units.</span>
          </div>
          <?php if ($studentApplicationId > 0): ?>
            <a class="btn-demo schedule-alert__action" href="availability.php">
              <?= $availabilityChangePending ? 'View pending request' : 'Request availability change' ?>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- ---- Stat mini-cards + week navigator ---- -->
      <div class="stat-row">

        <!-- Total Hours This Week -->
        <div class="stat-mini">
          <div class="stat-mini__icon stat-mini__icon--blue">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="12" cy="12" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none" />
              <path d="M12 8.2V12l2.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
            </svg>
          </div>
          <div>
            <div class="stat-mini__label">Total Hours This Week</div>
            <div class="stat-mini__value"><?php echo htmlspecialchars(number_format($totalHours, 1), ENT_QUOTES, 'UTF-8'); ?> hrs</div>
          </div>
        </div>

        <!-- Scheduled Days -->
        <div class="stat-mini">
          <div class="stat-mini__icon stat-mini__icon--gold">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8" fill="none" />
              <path d="M8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
          </div>
          <div>
            <div class="stat-mini__label">Scheduled Days</div>
            <div class="stat-mini__value"><?php echo (int) $totalDays; ?></div>
          </div>
        </div>

        <!-- Week navigator -->
        <div class="stat-mini" style="justify-content: space-between;">
          <button class="week-nav__btn" aria-label="Previous week">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M14.5 6.5 9 12l5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
            </svg>
          </button>
          <div class="week-nav__info">
            <div class="week-nav__label">Current Week</div>
            <div class="week-nav__range"><?php echo htmlspecialchars($weekRangeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="stat-mini__sub"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <button class="week-nav__btn" aria-label="Next week">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M9.5 6.5 15 12l-5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
            </svg>
          </button>
        </div>

      </div>

      <!-- ---- Calendar Grid ---- -->
      <div class="schedule-board view-panel" id="calendar-panel">
        <div class="cal-card">
          <div class="cal-scroll">
            <div class="cal-grid" role="grid" aria-label="Weekly schedule grid">

            <!-- Header row -->
            <div class="cal-header__time" role="columnheader">TIME</div>
            <?php foreach ($calendarDays as $calendarDay): ?>
              <div class="cal-header__day" role="columnheader"><?php echo htmlspecialchars($calendarDay, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>

            <!-- TIME COLUMN – 15 hours: 7 AM … 9 PM -->
            <?php
              $hours = ['7AM','8AM','9AM','10AM','11AM','12PM','1PM','2PM','3PM','4PM','5PM','6PM','7PM','8PM','9PM'];
              foreach ($hours as $h) {
                echo '<div class="cal-time" role="rowheader">' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</div>';
                echo str_repeat('<div style="border-bottom:1px solid var(--color-border);"></div>', 6);
              }
            ?>

            </div>

            <!-- Day columns with events (separate layer overlay) -->
            <!--
              NOTE: The calendar uses a CSS Grid for the time labels and an
              absolutely-positioned overlay for the event blocks so both layers
              stay aligned to the same six-day column layout.
            -->
            <?php if (empty($studentSchedules)): ?>
              <div class="schedule-empty-state">
                <div class="schedule-empty-state__title">No schedules assigned yet.</div>
                <div class="schedule-empty-state__sub">Once admin assigns your duty, the calendar and schedule list will update automatically.</div>
              </div>
            <?php else: ?>
              <div class="cal-events">

              <!-- Time column spacer -->
              <div class="cal-events__time-spacer"></div>

              <?php foreach ($calendarDays as $calendarDay): ?>
                <div class="cal-events__day">
                  <?php for ($row = 0; $row < count($hours); $row++): ?>
                    <div class="cal-day-col__line" style="top:<?php echo $row * 64; ?>px;"></div>
                  <?php endfor; ?>

                  <?php if (!empty($calendarByDay[$calendarDay])): ?>
                    <?php foreach ($calendarByDay[$calendarDay] as $event): ?>
                      <div class="cal-event cal-event--<?php echo htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8'); ?>" style="top:<?php echo (int) $event['top']; ?>px; height:<?php echo max(88, (int) $event['height']); ?>px;" aria-label="<?php echo htmlspecialchars($calendarDay . ' duty: ' . $event['start_label'] . '–' . $event['end_label'] . ' at ' . $event['office_name'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div>
                          <div class="cal-event__time-row">
                              <svg class="cal-event__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <circle cx="12" cy="12" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none" />
                                <path d="M12 8.2V12l2.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                              </svg>
                            <span class="cal-event__time"><?php echo htmlspecialchars($event['start_label'] . ' - ' . $event['end_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                          </div>
                          <div class="cal-event__loc-row">
                              <svg class="cal-event__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 21s6-4.4 6-10a6 6 0 1 0-12 0c0 5.6 6 10 6 10Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none" />
                                <circle cx="12" cy="11" r="2.2" fill="currentColor" />
                              </svg>
                            <span class="cal-event__loc"><?php echo htmlspecialchars($event['office_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                          </div>
                        </div>
                        <div class="cal-event__recur"><?php echo htmlspecialchars(match($event['status']) { 'deployed' => '🚀', 'accepted' => '✓', default => '🔄' }, ENT_QUOTES, 'UTF-8'); ?></div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>

              </div><!-- /.event-layer -->
            <?php endif; ?>

          </div><!-- /.cal-scroll -->
        </div><!-- /.cal-card -->

        <aside class="upcoming-card" aria-labelledby="upcoming-schedules-heading">
          <div class="upcoming-card__heading">
            <div class="upcoming-card__title" id="upcoming-schedules-heading">Upcoming Schedules</div>
            <div class="upcoming-card__sub">Your next duties and their current status.</div>
          </div>

          <?php if (empty($upcomingSchedules)): ?>
            <div class="upcoming-card__empty">No upcoming schedules yet. Once your schedules are set, they’ll appear here with accepted or pending status.</div>
          <?php else: ?>
            <div class="upcoming-card__list">
              <?php foreach ($upcomingSchedules as $schedule): ?>
                <?php
                  $dayLabel = (string) ($schedule['day_of_week'] ?? '');
                  $status = (string) ($schedule['status'] ?? 'pending');
                  $badgeClass = match ($status) {
                    'deployed' => 'upcoming-item__badge--deployed',
                    'accepted' => 'upcoming-item__badge--accepted',
                    'declined' => 'upcoming-item__badge--declined',
                    default => 'upcoming-item__badge--pending',
                  };
                  $badgeLabel = match ($status) {
                    'deployed' => 'Deployed',
                    'accepted' => 'Accepted',
                    'declined' => 'Declined',
                    default => 'Pending',
                  };
                ?>
                <div class="upcoming-item">
                  <div class="upcoming-item__top">
                    <div>
                      <div class="upcoming-item__day"><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="upcoming-item__time"><?php echo htmlspecialchars(sams_schedule_time_label((string) ($schedule['time_start'] ?? '')) . ' - ' . sams_schedule_time_label((string) ($schedule['time_end'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="upcoming-item__badge <?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                  <div class="upcoming-item__office"><?php echo htmlspecialchars((string) ($schedule['office_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <a class="upcoming-card__link" href="#list-panel" onclick="document.getElementById('btn-list').click(); return false;">Open full schedule list</a>
        </aside>
      </div><!-- /.schedule-board -->

        <!-- ---- My Generated Schedules ---- -->
        <section class="card view-panel is-hidden" id="list-panel">
          <h2 class="card__title">My Assigned Schedules</h2>
          <?php if (isset($_SESSION['student_schedule_flash'])): ?>
            <div style="margin-bottom:10px;padding:10px;background:#fff;border:1px solid var(--color-border);border-radius:8px;font-weight:700;"><?= htmlspecialchars((string) $_SESSION['student_schedule_flash'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php unset($_SESSION['student_schedule_flash']); ?>
          <?php endif; ?>

          <?php if (empty($studentSchedules)): ?>
            <div style="padding:12px;background:#fff;border:1px solid var(--color-border);border-radius:10px;">No schedules assigned yet.</div>
          <?php else: ?>
            <div class="schedule-list__table-wrap">
              <table class="schedule-list__table">
                  <thead class="schedule-list__thead">
                    <tr>
                      <th class="schedule-list__cell">📅 Date & Time</th>
                      <th class="schedule-list__cell">🏢 Office</th>
                      <th class="schedule-list__cell">⏱️ Hours</th>
                      <th class="schedule-list__cell">Status</th>
                      <th class="schedule-list__cell schedule-list__cell--last">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($studentSchedules as $s): ?>
                      <?php
                        $dayOfWeek = (string) ($s['day_of_week'] ?? '');
                        $dateStr = sams_schedule_date_for_day($dayOfWeek);
                        $dateObj = new DateTime($dateStr);
                        $formattedDate = $dateObj->format('M d, Y');
                        
                        // Check if this is a past schedule
                        $isPast = strtotime($dateStr) < strtotime('today');
                        $isTodayRow = ($dateStr === date('Y-m-d'));
                        $hasAttendance = !empty($s['attendance_id']);
                        $timeIn = !empty($s['time_in']) ? $s['time_in'] : null;
                        $timeOut = !empty($s['time_out']) ? $s['time_out'] : null;
                        
                        // Determine what status to show
                        if ($isPast && $hasAttendance) {
                          // Show attendance status for past dates with records
                          $attendanceStatus = (string) ($s['attendance_status'] ?? 'absent');
                          $statusColor = match($attendanceStatus) {
                            'present' => '#dcfce7',
                            'late' => '#fef3c7',
                            'absent' => '#fee2e2',
                            'incomplete' => '#e5e7eb',
                            default => '#e5e7eb',
                          };
                          $statusTextColor = match($attendanceStatus) {
                            'present' => '#166534',
                            'late' => '#92400e',
                            'absent' => '#991b1b',
                            'incomplete' => '#374151',
                            default => '#374151',
                          };
                          $statusIcon = match($attendanceStatus) {
                            'present' => '✓',
                            'late' => '⏱',
                            'absent' => '✕',
                            'incomplete' => '?',
                            default => '?',
                          };
                          $statusLabel = ucfirst($attendanceStatus);
                          if ($attendanceStatus === 'late' && !empty($s['late_minutes'])) {
                            $statusLabel .= ' (' . (int) $s['late_minutes'] . 'm)';
                          }
                          $displayStatus = 'attendance';
                        } elseif ($isPast && !$hasAttendance) {
                          // Past date with no attendance record
                          $statusColor = '#f3f4f6';
                          $statusTextColor = '#6b7280';
                          $statusIcon = '—';
                          $statusLabel = 'Not Recorded';
                          $displayStatus = 'no-record';
                        } else {
                          // Future date - show schedule status
                          $statusColor = match((string) ($s['status'] ?? 'pending')) {
                            'deployed' => '#dbeafe',
                            'accepted' => '#dcfce7',
                            'declined' => '#fee2e2',
                            default => '#fffbeb',
                          };
                          $statusTextColor = match((string) ($s['status'] ?? 'pending')) {
                            'deployed' => '#1d4ed8',
                            'accepted' => '#166534',
                            'declined' => '#991b1b',
                            default => '#d97706',
                          };
                          $statusIcon = match((string) ($s['status'] ?? 'pending')) {
                            'deployed' => '🚀',
                            'accepted' => '✓',
                            'declined' => '✕',
                            default => '⏳',
                          };
                          $statusLabel = match((string) ($s['status'] ?? 'pending')) {
                            'deployed' => 'Deployed',
                            'accepted' => 'Accepted',
                            'declined' => 'Declined',
                            default => 'Pending',
                          };
                          $displayStatus = 'schedule';
                        }
                      ?>
                      <tr class="schedule-list__row">
                        <td class="schedule-list__cell">
                          <div class="schedule-list__meta"><?= htmlspecialchars($dayOfWeek, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8') ?></div>
                          <div class="schedule-list__sub"><?= htmlspecialchars((string) $s['time_start'], ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars((string) $s['time_end'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td class="schedule-list__cell"><?= htmlspecialchars((string) $s['office_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="schedule-list__cell schedule-list__hours"><?php $start = strtotime((string) ($s['time_start'] ?? '')); $end = strtotime((string) ($s['time_end'] ?? '')); $hours = ($start && $end && $end > $start) ? ($end - $start) / 3600 : 0; echo htmlspecialchars(number_format((float) $hours, 1), ENT_QUOTES, 'UTF-8'); ?>h</td>
                        <td class="schedule-list__cell">
                          <span class="schedule-list__badge" style="background:<?= $statusColor ?>;color:<?= $statusTextColor ?>;">
                            <?= htmlspecialchars($statusIcon, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                          </span>
                        </td>
                        <td class="schedule-list__cell schedule-list__cell--last">
                          <?php if ($isTodayRow || ($displayStatus === 'schedule' && ($s['status'] ?? '') === 'deployed')): ?>
                            <span class="schedule-list__hint">Attendance will be recorded by admin</span>
                          <?php elseif ($displayStatus === 'schedule' && ($s['status'] ?? '') === 'pending'): ?>
                            <div class="schedule-list__actions">
                              <form method="POST" action="respond_schedule.php" class="schedule-response-form" style="display:inline-block;">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(sams_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="schedule_id" value="<?= (int) ($s['id'] ?? 0) ?>">
                                <input type="hidden" name="status" value="accepted">
                                <button type="submit" class="schedule-list__action-btn schedule-list__action-btn--accept">✓ Accept</button>
                              </form>
                              <form method="POST" action="respond_schedule.php" class="schedule-response-form" style="display:inline-block;">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(sams_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="schedule_id" value="<?= (int) ($s['id'] ?? 0) ?>">
                                <input type="hidden" name="status" value="declined">
                                <button type="submit" class="schedule-list__action-btn schedule-list__action-btn--decline">✕ Decline</button>
                              </form>
                            </div>
                          <?php else: ?>
                            <span style="color:var(--color-muted);font-size:13px;">No action needed</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
            </div>
          <?php endif; ?>
        </section>

      <!-- ---- Schedule Guidelines ---- -->
      <section class="guidelines" aria-labelledby="guidelines-heading">
        <div class="guidelines__heading">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="guidelines__icon">
            <path d="M4 6h16v10H5.8L4 18V6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none" />
            <path d="M8 9h8M8 12h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
          <h2 id="guidelines-heading">Schedule Guidelines 📋</h2>
        </div>
        <div class="guidelines__grid">
          <div class="guideline-item">
            <span class="guideline-item__emoji" aria-hidden="true">⏰</span>
            <div>
              <div class="guideline-item__title">Be Punctual</div>
              <div class="guideline-item__desc">Arrive 5-10 minutes before your scheduled time</div>
            </div>
          </div>
          <div class="guideline-item">
            <span class="guideline-item__emoji" aria-hidden="true">📞</span>
            <div>
              <div class="guideline-item__title">Communication</div>
              <div class="guideline-item__desc">Notify Miss Zai if you need to reschedule</div>
            </div>
          </div>
          <div class="guideline-item">
            <span class="guideline-item__emoji" aria-hidden="true">📷</span>
            <div>
              <div class="guideline-item__title">Attendance</div>
              <div class="guideline-item__desc">Always scan QR code when checking in/out</div>
            </div>
          </div>
          <div class="guideline-item">
            <span class="guideline-item__emoji" aria-hidden="true">✅</span>
            <div>
              <div class="guideline-item__title">Complete Tasks</div>
              <div class="guideline-item__desc">Finish all assigned duties during your shift</div>
            </div>
          </div>
        </div>
      </section>

    </main>
  </div><!-- /.main -->

</div><!-- /.app -->

<script>
  (function () {
    'use strict';

    /* ---- Sidebar toggle ---- */
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

    /* ---- View toggle (Calendar / List) ---- */
    var btnCal  = document.getElementById('btn-calendar');
    var btnList = document.getElementById('btn-list');
    var calendarPanel = document.getElementById('calendar-panel');
    var listPanel = document.getElementById('list-panel');

    function setView(viewName) {
      var showCalendar = viewName === 'calendar';

      calendarPanel.classList.toggle('is-hidden', !showCalendar);
      listPanel.classList.toggle('is-hidden', showCalendar);

      var active = showCalendar ? btnCal : btnList;
      var inactive = showCalendar ? btnList : btnCal;

      active.classList.add('view-toggle__btn--active');
      active.classList.remove('view-toggle__btn--inactive');
      active.setAttribute('aria-pressed', 'true');
      inactive.classList.remove('view-toggle__btn--active');
      inactive.classList.add('view-toggle__btn--inactive');
      inactive.setAttribute('aria-pressed', 'false');
    }

    btnCal.addEventListener('click', function () { setView('calendar'); });
    btnList.addEventListener('click', function () { setView('list'); });

    setView('calendar');
  }());
</script>
<script>
(function () {
  document.querySelectorAll('.schedule-response-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
      }

      fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': form.querySelector('[name="_csrf"]').value },
        body: new FormData(form)
      })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok || !data.success) {
              throw new Error(data.message || 'Unable to update schedule.');
            }
            return data;
          });
        })
        .then(function () {
          window.location.reload();
        })
        .catch(function (error) {
          window.alert(error.message);
          if (button) {
            button.disabled = false;
          }
        });
    });
  });
}());
</script>
<script src="../assets/js/student-notifications.js?v=20260922"></script>

</body>
</html>