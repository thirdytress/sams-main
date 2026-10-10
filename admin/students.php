<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
  header('Location: ../login.php');
  exit;
}

$pdo = sams_pdo();
$courseOptions = sams_course_options();

$applicationBadgeCount = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'")->fetchColumn();

$admin_name = (string) ($currentUser['name'] ?? trim((string) ($currentUser['first_name'] ?? '') . ' ' . (string) ($currentUser['last_name'] ?? '')) ?: 'SAMS Admin');
$admin_role = 'SDAO Head';
$department = 'NU Lipa - Student Development and Activities Office';

function sams_student_cell_initials(array $student): string
{
  $first = strtoupper(substr(trim((string) ($student['first_name'] ?? '')), 0, 1));
  $last = strtoupper(substr(trim((string) ($student['last_name'] ?? '')), 0, 1));
  $initials = trim($first . $last);

  if ($initials !== '') {
    return $initials;
  }

  $studentCode = preg_replace('/[^A-Za-z0-9]/', '', (string) ($student['student_id_number'] ?? ''));
  return strtoupper(substr($studentCode, -3)) ?: 'SA';
}

function sams_student_year_label($yearLevel): string
{
  $value = trim((string) $yearLevel);

  return match ($value) {
    '1', '1st', '1st Year' => '1st Year',
    '2', '2nd', '2nd Year' => '2nd Year',
    '3', '3rd', '3rd Year' => '3rd Year',
    '4', '4th', '4th Year' => '4th Year',
    '5', '5th', '5th Year' => '5th Year',
    default => $value !== '' ? $value : 'Year Level N/A',
  };
}

$studentStmt = $pdo->query(
  "SELECT
    s.student_id,
    s.student_id_number,
    s.program,
    s.year_level,
    s.current_gpa,
    s.is_enrolled,
    latest_app.application_status,
    COALESCE(u.first_name, '') AS first_name,
    COALESCE(u.last_name, '') AS last_name,
    u.profile_image,
    COALESCE(latest_app.preferred_office, 'Unassigned') AS preferred_office,
    COALESCE(hours.total_hours, 0) AS total_hours,
    COALESCE(rating.avg_rating, 0) AS avg_rating
   FROM students s
   LEFT JOIN users u ON u.user_id = s.user_id
   INNER JOIN (
    SELECT a1.student_id, a1.preferred_office,
      CASE
        WHEN a1.status = 'approved' AND EXISTS (
          SELECT 1
          FROM duty_schedules ds
          WHERE ds.application_id = a1.application_id
            AND ds.status = 'deployed'
        ) THEN 'deployed'
        ELSE a1.status
      END AS application_status
    FROM applications a1
    INNER JOIN (
      SELECT student_id, MAX(application_id) AS application_id
      FROM applications
      GROUP BY student_id
    ) latest ON latest.application_id = a1.application_id
  ) latest_app ON latest_app.student_id = s.student_id
   LEFT JOIN (
    SELECT a.student_id,
         SUM(TIMESTAMPDIFF(SECOND, al.clock_in_time, al.clock_out_time) / 3600) AS total_hours
    FROM attendance_logs al
    INNER JOIN duty_schedules ds ON ds.duty_id = al.duty_id
    INNER JOIN applications a ON a.application_id = ds.application_id
    WHERE al.clock_in_time IS NOT NULL
      AND al.clock_out_time IS NOT NULL
    GROUP BY a.student_id
   ) hours ON hours.student_id = s.student_id
   LEFT JOIN (
    SELECT a.student_id,
         AVG((e.performance_rating + e.reliability_rating + e.professionalism_rating) / 3) AS avg_rating
    FROM evaluations e
    INNER JOIN applications a ON a.application_id = e.application_id
    WHERE e.performance_rating IS NOT NULL
      AND e.reliability_rating IS NOT NULL
      AND e.professionalism_rating IS NOT NULL
    GROUP BY a.student_id
  ) rating ON rating.student_id = s.student_id
  WHERE latest_app.application_status IN ('approved', 'deployed')
   ORDER BY s.is_enrolled DESC, u.last_name ASC, u.first_name ASC, s.student_id DESC"
);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalStudents = count($students);
$activeStudents = 0;
$officeNames = [];
$ratingValues = [];

foreach ($students as $student) {
  if ((int) ($student['is_enrolled'] ?? 0) === 1) {
    $activeStudents++;
  }

  $officeName = trim((string) ($student['preferred_office'] ?? ''));
  if ($officeName !== '' && strcasecmp($officeName, 'Unassigned') !== 0) {
    $officeNames[$officeName] = true;
  }

  $ratingValue = (float) ($student['avg_rating'] ?? 0);
  if ($ratingValue > 0) {
    $ratingValues[] = $ratingValue;
  }
}

$inactiveStudents = max(0, $totalStudents - $activeStudents);
$departmentCount = count($officeNames);
$averageRating = !empty($ratingValues) ? array_sum($ratingValues) / count($ratingValues) : 0.0;
$approvedStudents = count(array_filter($students, static fn (array $student): bool => ($student['application_status'] ?? '') === 'approved'));
$deployedStudents = count(array_filter($students, static fn (array $student): bool => ($student['application_status'] ?? '') === 'deployed'));

function sams_student_status_class(string $applicationStatus): string
{
  return $applicationStatus === 'deployed' ? 'badge badge--deployed' : 'badge badge--approved';
}

function sams_student_status_label(string $applicationStatus): string
{
  return $applicationStatus === 'deployed' ? 'Deployed' : 'Approved';
}

function sams_student_rating_display(float $rating): string
{
  return $rating > 0 ? number_format($rating, 1) : 'N/A';
}

function sams_student_hours_display($hours): string
{
  $value = (float) $hours;
  return number_format($value, $value > 0 && fmod($value, 1.0) !== 0.0 ? 1 : 0) . 'h';
}

function sams_html(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student List Management – SA System</title>
  <meta name="description" content="Admin Student List Management for NU Lipa Student Assistant System." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      --color-navy:           #1e3a8a;
      --color-navy-mid:       #1e40af;
      --color-blue:           #155dfc;
      --color-blue-dark:      #1447e6;
      --color-purple:         #9810fa;
      --color-dark:           #101828;
      --color-body:           #364153;
      --color-muted:          #4a5565;
      --color-muted-light:    #99a1af;
      --color-white:          #ffffff;
      --color-bg:             #f9fafb;
      --color-border:         #e5e7eb;
      --color-input-border:   #d1d5dc;
      --color-tag-bg:         #f3f4f6;
      --color-red-dot:        #fb2c36;
      --color-star:           #fbbf24;

      /* Stat card backgrounds */
      --color-stat-blue:      #eff6ff;
      --color-stat-green:     #f0fdf4;
      --color-stat-purple:    #faf5ff;
      --color-stat-orange:    #fff7ed;

      /* Status badge colours */
      --color-active-bg:      #dcfce7;
      --color-active-text:    #008236;
      --color-inactive-bg:    #f3f4f6;
      --color-inactive-text:  #364153;

      /* Office pill */
      --color-office-bg:      #dbeafe;
      --color-office-text:    #1447e6;

      /* Applications badge in nav */
      --color-apps-badge-bg:  #dbeafe;
      --color-apps-badge-txt: #155dfc;

      --grad-brand:   linear-gradient(135deg, #155dfc 0%, #9810fa 100%);
      --grad-navy:    linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);

      --shadow-card:  0 1px 3px 0 rgba(0,0,0,.10), 0 1px 2px 0 rgba(0,0,0,.06);

      --sidebar-w:    256px;
      --topbar-h:     89px;

      --radius-sm:    4px;
      --radius-md:    10px;
      --radius-lg:    16px;
      --radius-pill:  9999px;

      --font-xs:   12px;
      --font-sm:   14px;
      --font-base: 16px;
      --font-lg:   18px;
      --font-xl:   24px;
      --font-2xl:  30px;

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
      background: var(--color-bg);
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; }
    button, input { font-family: inherit; }

    /* =============================================
       APP SHELL
    ============================================= */
    .app {
      display: flex;
      height: 100vh;
      overflow: hidden;
    }

    /* =============================================
       SIDEBAR
    ============================================= */
    .sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: var(--color-white);
      border-right: 1px solid var(--color-border);
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .sidebar__brand {
      height: var(--topbar-h);
      border-bottom: 1px solid var(--color-border);
      padding: var(--space-6) var(--space-6) 0;
      display: flex;
      align-items: center;
      gap: var(--space-3);
      flex-shrink: 0;
    }
    .sidebar__logo {
      width: 40px;
      height: 40px;
      border-radius: var(--radius-md);
      background: var(--grad-brand);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-lg);
      font-weight: 700;
      color: var(--color-white);
      flex-shrink: 0;
    }
    .sidebar__brand-name { font-size: var(--font-base); font-weight: 700; color: var(--color-dark); }
    .sidebar__brand-sub  { font-size: var(--font-xs); color: var(--color-muted); }

    .sidebar__nav {
      flex: 1;
      overflow-y: auto;
      padding: var(--space-4);
      display: flex;
      flex-direction: column;
      gap: var(--space-1);
      scroll-behavior: smooth;
    }
    .sidebar__nav::-webkit-scrollbar { width: 6px; }
    .sidebar__nav::-webkit-scrollbar-track { background: transparent; }
    .sidebar__nav::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
    .sidebar__nav::-webkit-scrollbar-thumb:hover { background: #999; }

    .sidebar__nav-link {
      display: flex;
      align-items: center;
      gap: 12px;
      height: 48px;
      padding: 0 16px;
      border-radius: var(--radius-md);
      font-size: var(--font-base);
      color: var(--color-label);
      transition: background .15s, color .15s;
      white-space: nowrap;
      text-decoration: none !important;
      cursor: pointer;
    }
    .sidebar__nav-link:hover { background: var(--color-bg); }
    .sidebar__nav-link:visited { color: var(--color-label); }
    .sidebar__nav-link--active { 
      background: var(--color-blue); 
      color: white !important;
    }
    .sidebar__nav-link--active .sidebar__nav-label { 
      color: white !important; 
    }
    .sidebar__nav-link--active:hover { opacity: .92; }
    .sidebar__nav-link--active:visited { color: white !important; }
    .sidebar__nav-link--active svg { stroke: white !important; }
    .sidebar__nav-icon { width: 20px; height: 20px; flex-shrink: 0; }
    .sidebar__nav-badge {
      background: var(--color-apps-badge-bg);
      color: var(--color-blue);
      font-size: var(--font-xs);
      font-weight: 700;
      line-height: 16px;
      padding: 2px 8px;
      border-radius: var(--radius-badge);
      margin-left: auto;
    }

    .sidebar__footer {
      border-top: 1px solid var(--color-border);
      padding: 17px var(--space-4) var(--space-4);
      display: flex;
      flex-direction: column;
      gap: var(--space-1);
      flex-shrink: 0;
    }

    /* Mobile sidebar toggle */
    .sidebar-toggle {
      display: none;
      position: fixed;
      top: 16px;
      left: 16px;
      z-index: 200;
      width: 36px;
      height: 36px;
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      cursor: pointer;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 4px;
    }
    .sidebar-toggle__bar {
      display: block;
      width: 18px;
      height: 2px;
      background: var(--color-dark);
      border-radius: 2px;
      transition: transform .3s, opacity .3s;
    }

    /* =============================================
       MAIN AREA
    ============================================= */
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

    /* Top bar */
    .topbar {
      height: var(--topbar-h);
      flex-shrink: 0;
      background: var(--color-white);
      border-bottom: 1px solid var(--color-border);
      padding: 0 var(--space-8);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .topbar__title { font-size: var(--font-xl); font-weight: 700; color: var(--color-dark); line-height: 1.33; }
    .topbar__sub   { font-size: var(--font-sm); color: var(--color-muted); }

    .topbar__user { display: flex; align-items: center; gap: var(--space-3); }
    .topbar__notif {
      position: relative;
      width: 36px; height: 36px;
      border-radius: var(--radius-pill);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
    }
    .topbar__notif-icon { width: 20px; height: 20px; }
    .topbar__notif-dot {
      position: absolute;
      top: 4px; right: 0;
      width: 8px; height: 8px;
      border-radius: 50%;
      background: var(--color-red-dot);
    }
    .topbar__user-info { text-align: right; }
    .topbar__user-name { font-size: var(--font-sm); color: var(--color-dark); }
    .topbar__user-role { font-size: var(--font-xs); color: var(--color-muted); }
    .topbar__avatar {
      width: 40px; height: 40px;
      border-radius: var(--radius-pill);
      background: var(--grad-brand);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .topbar__avatar img, .topbar__avatar svg { width: 20px; height: 20px; display: block; }

    /* =============================================
       PAGE CONTENT
    ============================================= */
    .content {
      flex: 1;
      overflow-y: auto;
      padding: var(--space-8);
      display: flex;
      flex-direction: column;
      gap: var(--space-6);
    }

    /* Section heading */
    .section-head__title { font-size: var(--font-xl); font-weight: 700; color: var(--color-dark); margin-bottom: var(--space-2); }
    .section-head__sub   { font-size: var(--font-base); color: var(--color-muted); }

    /* =============================================
       STAT CARDS
    ============================================= */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-4);
    }
    .stat-card {
      border-radius: var(--radius-lg);
      padding: var(--space-6);
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .stat-card--blue   { background: var(--color-stat-blue);   }
    .stat-card--green  { background: var(--color-stat-green);  }
    .stat-card--purple { background: var(--color-stat-purple); }
    .stat-card--orange { background: var(--color-stat-orange); }

    .stat-card__label {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-sm);
      color: var(--color-muted);
    }
    .stat-card__label img { width: 20px; height: 20px; }
    .stat-icon { width: 20px; height: 20px; color: currentColor; }
    .stat-card__value {
      font-size: var(--font-2xl);
      font-weight: 700;
      color: var(--color-dark);
      line-height: 1.2;
    }

    /* =============================================
       TOOLBAR
    ============================================= */
    .toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-4);
      flex-wrap: wrap;
    }
    .toolbar__left { display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; }
    .program-filter-select {
      height: 36px;
      min-width: 220px;
      max-width: 280px;
      padding: 0 12px;
      border: 1px solid var(--color-input-border);
      border-radius: var(--radius-md);
      background: var(--color-white);
      color: var(--color-dark);
      font: inherit;
      font-size: var(--font-sm);
    }

    .toolbar__search { position: relative; }
    .toolbar__search-icon {
      position: absolute;
      left: 12px; top: 50%;
      transform: translateY(-50%);
      width: 20px; height: 20px;
      pointer-events: none;
    }
    .toolbar__search input {
      width: 320px;
      height: 42px;
      border: 1px solid var(--color-input-border);
      border-radius: var(--radius-md);
      padding: var(--space-2) var(--space-4) var(--space-2) 40px;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--color-white);
      outline: none;
      transition: border-color .2s;
    }
    .toolbar__search input::placeholder { color: rgba(10,10,10,.5); }
    .toolbar__search input:focus { border-color: var(--color-navy); }

    .filter-btn {
      height: 36px;
      border-radius: var(--radius-md);
      border: none;
      cursor: pointer;
      font-size: var(--font-sm);
      padding: 0 var(--space-4);
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: opacity .15s;
    }
    .filter-btn--active   { background: var(--color-navy); color: var(--color-white); }
    .filter-btn--inactive { background: var(--color-tag-bg); color: var(--color-body); }
    .filter-btn__count    { opacity: .6; }
    .filter-btn--active .filter-btn__count { opacity: .8; }

    .btn-export {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 40px;
      padding: 0 var(--space-4);
      border-radius: var(--radius-md);
      border: none;
      background: var(--color-navy);
      color: var(--color-white);
      font-size: var(--font-base);
      cursor: pointer;
      white-space: nowrap;
      transition: opacity .15s;
      text-decoration: none;
    }
    .btn-export:hover { opacity: .88; }
    .btn-export__icon { width: 16px; height: 16px; }

    /* =============================================
       TABLE CARD
    ============================================= */
    .table-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      overflow: hidden;
    }
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    table { width: 100%; border-collapse: collapse; min-width: 960px; }
    thead { background: var(--color-bg); border-bottom: 1px solid var(--color-border); }
    th {
      padding: 16px var(--space-6);
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-dark);
      text-align: left;
      white-space: nowrap;
    }
    tbody tr { border-bottom: 1px solid var(--color-border); }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #fafafa; }
    td { padding: 0 var(--space-6); height: 77px; vertical-align: middle; }

    /* Student cell */
    .student-cell { display: flex; align-items: center; gap: var(--space-3); }
    .student-cell__avatar {
      width: 40px; height: 40px;
      border-radius: var(--radius-pill);
      background: var(--grad-navy);
      display: flex; align-items: center; justify-content: center;
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      flex-shrink: 0;
    }
    .student-cell__name { font-size: var(--font-base); font-weight: 700; color: var(--color-dark); }
    .student-cell__year { font-size: var(--font-sm); color: var(--color-muted); }

    /* Office pill */
    .office-pill {
      display: inline-block;
      background: var(--color-office-bg);
      color: var(--color-office-text);
      font-size: var(--font-xs);
      padding: var(--space-1) var(--space-3);
      border-radius: var(--radius-pill);
      white-space: nowrap;
    }

    /* Hours */
    .hours { font-size: var(--font-base); font-weight: 700; color: var(--color-dark); }

    /* Rating */
    .rating { display: flex; align-items: center; gap: var(--space-1); }
    .rating__star { font-size: var(--font-base); color: var(--color-star); }
    .rating__val  { font-size: var(--font-base); font-weight: 700; color: var(--color-dark); }

    /* Status badges */
    .badge {
      display: inline-block;
      font-size: var(--font-xs);
      font-weight: 700;
      padding: var(--space-1) var(--space-3);
      border-radius: var(--radius-pill);
      white-space: nowrap;
    }
    .badge--active   { background: var(--color-active-bg);   color: var(--color-active-text);   }
    .badge--inactive { background: var(--color-inactive-bg); color: var(--color-inactive-text); }
    .badge--approved { background: #dcfce7; color: #166534; }
    .badge--deployed { background: #dbeafe; color: #1d4ed8; }

    /* View button */
    .btn-view {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 32px;
      padding: 0 var(--space-3);
      border-radius: var(--radius-md);
      border: none;
      background: var(--color-navy);
      color: var(--color-white);
      font-size: var(--font-sm);
      cursor: pointer;
      transition: opacity .15s;
      white-space: nowrap;
    }
    .btn-view:hover { opacity: .88; }
    .btn-view img, .btn-view svg { width: 16px; height: 16px; display: block; }

    /* Student profile modal */
    .student-profile-modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: flex-start;
      justify-content: center;
      padding: 56px 24px 24px;
      background: rgba(16, 24, 40, .62);
      backdrop-filter: blur(8px);
      z-index: 9999;
      overflow-y: auto;
    }
    .student-profile-modal.is-open { display: flex; }
    .student-profile-modal__dialog {
      position: relative;
      width: min(1180px, 100%);
      background: linear-gradient(136deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);
      border: 1px solid rgba(229, 231, 235, .9);
      border-radius: 36px 36px 24px 24px;
      box-shadow: 0 28px 80px rgba(16, 24, 40, .25);
      padding: 40px 28px 28px;
    }
    .student-profile-modal__close {
      position: absolute;
      top: 16px;
      right: 16px;
      width: 40px;
      height: 40px;
      border-radius: 999px;
      border: 1px solid var(--color-border);
      background: var(--color-white);
      color: var(--color-dark);
      font-size: 26px;
      line-height: 1;
    }
    .student-profile-modal__header { margin-bottom: 18px; }
    .student-profile-modal__title { font-size: 28px; font-weight: 900; color: var(--color-dark); line-height: 1.2; }
    .student-profile-modal__subtitle { margin-top: 4px; color: var(--color-muted); font-size: var(--font-sm); }
    .student-profile-modal__grid {
      display: grid;
      grid-template-columns: 290px minmax(0, 1fr);
      gap: 20px;
      align-items: start;
    }
    .student-profile-modal__left,
    .student-profile-modal__right {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .student-profile-modal__avatar-card,
    .student-profile-modal__info-card,
    .student-profile-modal__perf-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: 20px;
      box-shadow: 0 14px 30px rgba(15, 23, 42, .08);
    }
    .student-profile-modal__avatar-card { padding: 28px 22px 22px; text-align: center; }
    .student-profile-modal__avatar {
      width: 82px;
      height: 82px;
      border-radius: 999px;
      margin: 0 auto 12px;
      background: var(--grad-navy);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--color-white);
      font-size: 28px;
      font-weight: 900;
      box-shadow: inset 0 0 0 3px rgba(255,255,255,.28);
    }
    .student-profile-modal__name { font-size: 24px; font-weight: 900; color: var(--color-dark); line-height: 1.2; }
    .student-profile-modal__id { margin-top: 4px; color: var(--color-muted); font-size: var(--font-sm); }
    .student-profile-modal__status-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 30px;
      padding: 0 12px;
      margin-top: 12px;
      border-radius: 999px;
      font-size: var(--font-xs);
      font-weight: 700;
    }
    .student-profile-modal__status-badge--green { background: #dcfce7; color: #166534; }
    .student-profile-modal__status-badge--red { background: #fef2f2; color: #b91c1c; }
    .student-profile-modal__stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-top: 18px;
    }
    .student-profile-modal__stat {
      border-radius: 14px;
      padding: 12px 10px;
      text-align: center;
    }
    .student-profile-modal__stat--blue { background: #eff6ff; }
    .student-profile-modal__stat--gold { background: #fffbeb; }
    .student-profile-modal__stat-value { display: block; font-size: 22px; font-weight: 900; color: var(--color-dark); line-height: 1.1; }
    .student-profile-modal__stat-label { display: block; margin-top: 4px; font-size: 12px; color: var(--color-muted); }
    .student-profile-modal__info-card,
    .student-profile-modal__perf-card { padding: 20px; }
    .student-profile-modal__right > .student-profile-modal__info-card:first-child {
      padding-top: 28px;
    }
    .student-profile-modal__heading { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
    .student-profile-modal__heading-icon {
      width: 28px;
      height: 28px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .student-profile-modal__heading-icon svg { width: 28px; height: 28px; }
    .student-profile-modal__section-title { font-size: 18px; font-weight: 900; color: var(--color-dark); }
    .student-profile-modal__fields {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px 18px;
    }
    .student-profile-modal__field {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
      text-align: left;
    }
    .student-profile-modal__field-label {
      font-size: 12px;
      font-weight: 700;
      color: var(--color-muted);
      display: inline-flex;
      align-items: center;
      justify-content: flex-start;
      gap: 6px;
      width: 100%;
      text-align: left;
    }
    .student-profile-modal__field-value {
      font-size: 15px;
      font-weight: 700;
      color: var(--color-dark);
      line-height: 1.35;
      word-break: break-word;
      display: block;
      width: 100%;
      text-align: left;
      margin: 0;
    }
    .student-profile-modal__hint {
      font-size: 11px;
      color: var(--color-muted);
      text-align: left;
      width: 100%;
    }
    .student-profile-modal__pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 30px;
      padding: 0 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      width: fit-content;
    }
    .student-profile-modal__pill--green { background: #dcfce7; color: #166534; }
    .student-profile-modal__pill--blue { background: #dbeafe; color: #1d4ed8; }
    .student-profile-modal__pill--red { background: #fef2f2; color: #b91c1c; }
    .student-profile-modal__summary {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin: 18px 0;
    }
    .student-profile-modal__summary-item {
      border-radius: 14px;
      padding: 14px 12px;
      background: #f8fafc;
      text-align: left;
    }
    .student-profile-modal__summary-label {
      display: block;
      font-size: 12px;
      color: var(--color-muted);
      text-align: left;
    }
    .student-profile-modal__summary-value {
      display: block;
      margin-top: 4px;
      font-size: 16px;
      font-weight: 900;
      color: var(--color-dark);
      text-align: left;
    }
    .student-profile-modal__chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      justify-content: flex-start;
      width: 100%;
    }
    .student-profile-modal__chip {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      background: #eff6ff;
      color: #1d4ed8;
      font-size: 12px;
      font-weight: 700;
    }
    .student-profile-modal__note {
      margin-top: 14px;
      font-size: 12px;
      color: var(--color-muted);
      line-height: 1.5;
      text-align: left;
    }
    .student-profile-modal__perf-card { background: linear-gradient(135deg, var(--color-navy), #0f4ab5); color: var(--color-white); }
    .student-profile-modal__perf-card .student-profile-modal__section-title,
    .student-profile-modal__perf-card .student-profile-modal__field-label,
    .student-profile-modal__perf-card .student-profile-modal__summary-label,
    .student-profile-modal__perf-card .student-profile-modal__note { color: rgba(255,255,255,.82); }
    .student-profile-modal__perf-card .student-profile-modal__summary-item { background: rgba(255,255,255,.12); }
    .student-profile-modal__perf-card .student-profile-modal__summary-value { color: var(--color-white); }
    .student-profile-modal__achievement {
      margin-top: 16px;
      padding: 14px;
      border-radius: 16px;
      background: rgba(255,255,255,.12);
      display: flex;
      gap: 12px;
      align-items: center;
      justify-content: flex-start;
      text-align: left;
    }
    .student-profile-modal__achievement-icon {
      width: 42px;
      height: 42px;
      border-radius: 999px;
      background: rgba(255,255,255,.20);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .student-profile-modal__achievement-title { display: block; font-size: 15px; font-weight: 900; color: var(--color-white); text-align: left; }
    .student-profile-modal__achievement-subtitle { display: block; margin-top: 2px; font-size: 12px; color: rgba(255,255,255,.78); text-align: left; }
    .student-profile-modal__loading { padding: 40px 20px; text-align: center; color: var(--color-muted); }

    @media (max-width: 980px) {
      .student-profile-modal__grid { grid-template-columns: 1fr; }
      .student-profile-modal__summary { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .student-profile-modal { padding: 24px 12px 12px; }
      .student-profile-modal__dialog { padding: 28px 18px 18px; border-radius: 26px 26px 18px 18px; }
      .student-profile-modal__fields { grid-template-columns: 1fr; }
      .student-profile-modal__title { font-size: 22px; }
      .student-profile-modal__avatar-card { padding: 22px 16px 18px; }
      .student-profile-modal__stats { grid-template-columns: 1fr; }
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
      .stat-grid { grid-template-columns: repeat(2, 1fr); }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .content { padding: var(--space-4); gap: var(--space-4); }
      .topbar { padding: 0 var(--space-4) 0 64px; }
      .topbar__title { font-size: var(--font-lg); }
      .topbar__user-info { display: none; }
      .stat-grid { grid-template-columns: 1fr 1fr; }
      .toolbar { flex-direction: column; align-items: stretch; }
      .toolbar__left { flex-wrap: wrap; }
      .toolbar__search input { width: 100%; }
      .program-filter-select { width: 100%; max-width: none; }
    }
  </style>
</head>
<body>

<div class="app">
    <?php $activeAdminNav = 'students'; $pendingApplications = (int) $applicationBadgeCount; include __DIR__ . '/_sidebar.php'; ?>

  <!-- ============================================
       MAIN
  ============================================= -->
  <div class="main">

    <!-- Top bar -->
    <header class="topbar" role="banner">
      <div>
        <div class="topbar__title">Student List Management</div>
        <div class="topbar__sub">NU Lipa - Student Development and Activities Office</div>
      </div>
      <div class="topbar__user">
        <div class="topbar__notif" aria-label="Notifications">
          <svg class="topbar__notif-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="topbar__notif-dot" aria-label="New notifications"></span>
        </div>
        <div class="topbar__user-info">
            <div class="topbar__user-name"><?php echo sams_html($admin_name); ?></div>
            <div class="topbar__user-role"><?php echo sams_html($admin_role); ?></div>
        </div>
        <div class="topbar__avatar" style="overflow:hidden;">
          <?php $topAvatar = sams_user_avatar_url($currentUser['profile_image'] ?? null, '../'); ?>
          <?php if ($topAvatar): ?>
            <img src="<?php echo sams_html($topAvatar); ?>" alt="<?php echo sams_html($admin_name); ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
          <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" aria-label="User avatar"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <!-- Page content -->
    <main class="content" role="main">

      <!-- Section heading -->
      <div class="section-head">
        <div class="section-head__title">Student Assistants</div>
        <div class="section-head__sub">Manage and view all student assistant profiles</div>
      </div>

      <!-- ---- Stat cards ---- -->
      <div class="stat-grid">
        <div class="stat-card stat-card--blue">
          <div class="stat-card__label">
            <svg class="stat-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/>
              <path d="M4 20c0-3.314 3.582-6 8-6s8 2.686 8 6H4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Total Students
          </div>
          <div class="stat-card__value"><?php echo (int) $totalStudents; ?></div>
        </div>
        <div class="stat-card stat-card--green">
          <div class="stat-card__label">
            <svg class="stat-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
              <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Enrolled
          </div>
          <div class="stat-card__value"><?php echo (int) $activeStudents; ?></div>
        </div>
        <div class="stat-card stat-card--purple">
          <div class="stat-card__label">
            <svg class="stat-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M12 2l3 6 6 .5-4.5 3 1.5 6L12 14l-6 4 1.5-6L3 8.5 9 8 12 2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Avg Rating
          </div>
          <div class="stat-card__value"><?php echo sams_student_rating_display($averageRating); ?>/5</div>
        </div>
        <div class="stat-card stat-card--orange">
          <div class="stat-card__label">
            <svg class="stat-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <rect x="2" y="3" width="7" height="18" rx="1" stroke="currentColor" stroke-width="2"/>
              <rect x="11" y="8" width="7" height="13" rx="1" stroke="currentColor" stroke-width="2"/>
              <rect x="20" y="5" width="2" height="16" rx="1" stroke="currentColor" stroke-width="2" opacity="0.5"/>
            </svg>
            Departments
          </div>
          <div class="stat-card__value"><?php echo (int) $departmentCount; ?></div>
        </div>
      </div>

      <!-- ---- Toolbar ---- -->
      <div class="toolbar">
        <div class="toolbar__left">
          <div class="toolbar__search">
            <svg class="toolbar__search-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <input type="search" id="search-input" placeholder="Search students..."
              aria-label="Search students" />
          </div>
          <button class="filter-btn filter-btn--active"   data-filter="all"      aria-pressed="true">All <span class="filter-btn__count"><?php echo (int) $totalStudents; ?></span></button>
          <button class="filter-btn filter-btn--inactive" data-filter="approved" aria-pressed="false">Approved <span class="filter-btn__count"><?php echo (int) $approvedStudents; ?></span></button>
          <button class="filter-btn filter-btn--inactive" data-filter="deployed" aria-pressed="false">Deployed <span class="filter-btn__count"><?php echo (int) $deployedStudents; ?></span></button>
          <select id="program-filter" class="program-filter-select" aria-label="Filter students by course or program">
            <option value="">All Courses / Programs</option>
            <?php foreach ($courseOptions as $courseCode => $courseLabel): ?>
              <option value="<?php echo sams_html($courseCode); ?>"><?php echo sams_html($courseLabel); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <a class="btn-export" href="#" aria-label="Export student list">
          <svg class="btn-export__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Export List
        </a>
      </div>

      <!-- ---- Table ---- -->
      <div class="table-card">
        <div class="table-wrap">
          <table aria-label="Student assistants table">
            <thead>
              <tr>
                <th scope="col">Student</th>
                <th scope="col">Student ID</th>
                <th scope="col">Program</th>
                <th scope="col">Office</th>
                <th scope="col">Hours</th>
                <th scope="col">Rating</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody id="table-body">
              <?php foreach ($students as $student): ?>
              <?php
                $isEnrolled = (int) ($student['is_enrolled'] ?? 0);
                $applicationStatus = (string) ($student['application_status'] ?? 'approved');
                $studentName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
                if ($studentName === '') {
                    $studentName = 'Unassigned Student';
                }
                $studentCode = trim((string) ($student['student_id_number'] ?? ''));
                $program = trim((string) ($student['program'] ?? '')) ?: 'Program N/A';
                $office = trim((string) ($student['preferred_office'] ?? '')) ?: 'Unassigned';
                $hours = sams_student_hours_display($student['total_hours'] ?? 0);
                $rating = sams_student_rating_display((float) ($student['avg_rating'] ?? 0));
                $yearLabel = sams_student_year_label($student['year_level'] ?? '');
              ?>
              <tr data-status="<?php echo sams_html($applicationStatus); ?>" data-program="<?php echo sams_html((string) ($student['program'] ?? '')); ?>">
                <td>
                  <div class="student-cell">
                    <?php $stuAv = sams_user_avatar_url($student['profile_image'] ?? null, '../'); ?>
                    <div class="student-cell__avatar" aria-hidden="true" style="overflow:hidden;">
                      <?php if ($stuAv): ?>
                        <img src="<?php echo sams_html($stuAv); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                      <?php else: ?>
                        <?php echo sams_html(sams_student_cell_initials($student)); ?>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="student-cell__name"><?php echo sams_html($studentName); ?></div>
                      <div class="student-cell__year"><?php echo sams_html($yearLabel); ?></div>
                    </div>
                  </div>
                </td>
                <td><?php echo sams_html($studentCode); ?></td>
                <td><?php echo sams_html($program); ?></td>
                <td><span class="office-pill"><?php echo sams_html($office); ?></span></td>
                <td><span class="hours"><?php echo sams_html($hours); ?></span></td>
                <td>
                  <div class="rating">
                    <span class="rating__star">★</span>
                    <span class="rating__val"><?php echo sams_html($rating); ?></span>
                  </div>
                </td>
                <td><span class="badge <?php echo sams_student_status_class($applicationStatus); ?>"><?php echo sams_html(sams_student_status_label($applicationStatus)); ?></span></td>
                <td>
                  <button class="btn-view" data-student-id="<?= (int) ($student['student_id'] ?? 0) ?>" aria-label="View <?php echo sams_html($studentName); ?> profile">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg>
                    View
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>

            </tbody>
          </table>
        </div>
      </div>

    </main>
  </div><!-- /.main -->
</div><!-- /.app -->

<div id="studentModal" class="student-profile-modal" aria-hidden="true">
  <div class="student-profile-modal__dialog" role="dialog" aria-modal="true" aria-label="Student profile">
    <button type="button" class="student-profile-modal__close" aria-label="Close profile" onclick="closeStudentModal()">&times;</button>
    <div id="studentModalBody" class="student-profile-modal__loading">Loading...</div>
  </div>
</div>
<script>
  (function () {
    'use strict';

    /* ---- Filter buttons ---- */
    var filterBtns = document.querySelectorAll('.filter-btn');
    var rows       = document.querySelectorAll('#table-body tr');
    var activeStatusFilter = 'all';
    var programFilter = document.getElementById('program-filter');
    var searchInput = document.getElementById('search-input');

    function applyStudentFilters() {
      var selectedProgram = programFilter ? programFilter.value.toLowerCase().trim() : '';
      var searchQuery = searchInput ? searchInput.value.toLowerCase().trim() : '';

      rows.forEach(function (row) {
        var matchesStatus = activeStatusFilter === 'all' || row.getAttribute('data-status') === activeStatusFilter;
        var matchesProgram = !selectedProgram || (row.getAttribute('data-program') || '').toLowerCase().trim() === selectedProgram;
        var matchesSearch = !searchQuery || row.textContent.toLowerCase().includes(searchQuery);
        row.style.display = matchesStatus && matchesProgram && matchesSearch ? '' : 'none';
      });
    }

    filterBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activeStatusFilter = btn.getAttribute('data-filter');

        filterBtns.forEach(function (b) {
          b.classList.remove('filter-btn--active');
          b.classList.add('filter-btn--inactive');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('filter-btn--active');
        btn.classList.remove('filter-btn--inactive');
        btn.setAttribute('aria-pressed', 'true');

        applyStudentFilters();
      });
    });

    /* ---- Live search ---- */
    if (searchInput) {
      searchInput.addEventListener('input', applyStudentFilters);
    }
    if (programFilter) {
      programFilter.addEventListener('change', applyStudentFilters);
    }

  }());

  // Student profile modal handling
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.btn-view');
    if (!btn) return;
    var studentId = btn.getAttribute('data-student-id');
    if (!studentId) return;

    // show loading modal
    openStudentModal();
    var url = './student_detail.php?student_id=' + encodeURIComponent(studentId);
    fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) {
          return res.text().then(function (txt) {
            renderStudentModalError('Error loading profile. (' + res.status + ')');
            throw new Error('HTTP ' + res.status);
          });
        }
        return res.json();
      })
      .then(function (payload) {
        if (!payload || payload.success !== true) {
          renderStudentModalError('Error loading profile.');
          return;
        }
        renderStudentProfileModal(payload.data || {});
      })
      .catch(function () { renderStudentModalError('Error loading profile.'); });
  });

  function openStudentModal() {
    var el = document.getElementById('studentModal');
    if (el) {
      el.classList.add('is-open');
      el.setAttribute('aria-hidden', 'false');
      setStudentModalContent('<div class="student-profile-modal__loading">Loading...</div>');
    }
  }
  function closeStudentModal() {
    var el = document.getElementById('studentModal');
    if (el) {
      el.classList.remove('is-open');
      el.setAttribute('aria-hidden', 'true');
    }
  }
  function setStudentModalContent(html) {
    var el = document.getElementById('studentModalBody');
    if (el) el.innerHTML = html;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function statusBadgeClass(kind) {
    return kind === 'green' ? 'green' : (kind === 'blue' ? 'blue' : 'red');
  }

  function renderSkillChips(skills) {
    if (!Array.isArray(skills) || skills.length === 0) {
      return '<span class="student-profile-modal__note">No skills have been recorded yet.</span>';
    }
    return '<div class="student-profile-modal__chips">' + skills.map(function (skill) {
      return '<span class="student-profile-modal__chip">' + escapeHtml(skill) + '</span>';
    }).join('') + '</div>';
  }

  function renderStudentProfileModal(d) {
    var avatar = escapeHtml(d.initials || '');
    var name = escapeHtml(d.name || 'Student');
    var email = escapeHtml(d.email || '');
    var studentCode = escapeHtml(d.student_code || '-');
    var program = escapeHtml(d.program || 'Not provided');
    var yearLevel = escapeHtml(d.year_level ? d.year_level + ' Year' : 'Not provided');
    var joinedAt = escapeHtml(d.joined_at || 'N/A');
    var office = escapeHtml(d.preferred_office || 'Not assigned yet');
    var totalHours = escapeHtml(d.total_hours || '0.0');
    var acceptedSchedules = escapeHtml(d.accepted_schedules || 0);
    var pendingSchedules = escapeHtml(d.pending_schedules || 0);
    var attendanceRate = escapeHtml(d.attendance_rate || 0);
    var availability = d.available_hours_per_week != null ? escapeHtml(d.available_hours_per_week + ' hrs') : 'Not set';
    var studentStatus = escapeHtml(d.student_status_label || 'Inactive Student');
    var studentStatusClass = 'student-profile-modal__status-badge--' + statusBadgeClass(d.student_status_class);
    var applicationStatus = escapeHtml(d.application_status || 'Pending Review');
    var applicationStatusClass = 'student-profile-modal__pill--' + statusBadgeClass(d.application_status_class);
    var latestAttendance = d.latest_attendance || {};
    var latestAttendanceText = latestAttendance.status ? escapeHtml(latestAttendance.status) : 'No attendance recorded';
    var latestRemarks = latestAttendance.remarks ? escapeHtml(latestAttendance.remarks) : 'No remarks';
    var hasSkills = Array.isArray(d.skills) && d.skills.length > 0;
    var skillTags = renderSkillChips(d.skills || []);

    var html = '';
    html += '<div class="student-profile-modal__header">';
    html += '<div class="student-profile-modal__title">Student Profile</div>';
    html += '<div class="student-profile-modal__subtitle">Profile details styled like the student portal, without the action buttons.</div>';
    html += '</div>';

    html += '<div class="student-profile-modal__grid">';
    html += '<div class="student-profile-modal__left">';
    html += '<div class="student-profile-modal__avatar-card">';
    html += '<div class="student-profile-modal__avatar" aria-hidden="true">' + avatar + '</div>';
    html += '<div class="student-profile-modal__name">' + name + '</div>';
    html += '<div class="student-profile-modal__id">' + studentCode + '</div>';
    html += '<div class="student-profile-modal__status-badge ' + studentStatusClass + '" role="status">' + studentStatus + '</div>';
    html += '<div class="student-profile-modal__stats">';
    html += '<div class="student-profile-modal__stat student-profile-modal__stat--blue"><span class="student-profile-modal__stat-value">' + totalHours + '</span><span class="student-profile-modal__stat-label">Total Duty Hours</span></div>';
    html += '<div class="student-profile-modal__stat student-profile-modal__stat--gold"><span class="student-profile-modal__stat-value">' + acceptedSchedules + '</span><span class="student-profile-modal__stat-label">Accepted</span></div>';
    html += '</div>';
    html += '</div>';
    html += '</div>';

    html += '<div class="student-profile-modal__right">';
    html += '<div class="student-profile-modal__info-card">';
    html += '<div class="student-profile-modal__heading"><span class="student-profile-modal__heading-icon" aria-hidden="true"><svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="14" cy="9" r="6" stroke="#003087" stroke-width="1.8"/><path d="M2 25c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#003087" stroke-width="1.8" stroke-linecap="round"/></svg></span><div class="student-profile-modal__section-title">Personal Information</div></div>';
    html += '<div class="student-profile-modal__fields">';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Full Name</span><span class="student-profile-modal__field-value">' + name + '</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Student ID</span><span class="student-profile-modal__field-value">' + studentCode + '</span><span class="student-profile-modal__hint">Cannot be changed</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Email Address</span><span class="student-profile-modal__field-value">' + email + '</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Contact Number</span><span class="student-profile-modal__field-value">Not provided</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">NFC Card ID</span>';
    html += '<div style="display: flex; align-items: center; gap: 8px; width: 100%;">';
    html += '<span id="modal-nfc-uid" class="student-profile-modal__field-value" style="margin: 0; flex: 1;">' + (d.nfc_uid ? escapeHtml(d.nfc_uid) : 'No card registered') + '</span>';
    html += '<button type="button" class="btn-view" id="btn-modal-nfc-scan" style="height: 32px; font-size: 13px; font-weight: 700; background: var(--color-navy); padding: 0 12px; display: inline-flex; align-items: center; justify-content: center;" onclick="startNfcScan(' + (d.student_id || d.student_db_id) + ')">Scan ID</button>';
    if (d.nfc_uid) {
      html += '<button type="button" class="btn-view" id="btn-modal-nfc-clear" style="height: 32px; font-size: 13px; font-weight: 700; background: #e5e7eb; border: 1px solid var(--color-border); color: var(--color-red-dot); padding: 0 12px; display: inline-flex; align-items: center; justify-content: center;" onclick="clearModalNfcCard(' + (d.student_id || d.student_db_id) + ')">Clear ID</button>';
    }
    html += '</div></div>';
    html += '</div></div>';

    html += '<div class="student-profile-modal__info-card">';
    html += '<div class="student-profile-modal__heading"><span class="student-profile-modal__heading-icon" aria-hidden="true"><svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 3L2 9l12 6 12-6-12-6z" stroke="#003087" stroke-width="1.8" stroke-linejoin="round"/><path d="M6 12v6c0 2.21 3.582 4 8 4s8-1.79 8-4v-6" stroke="#003087" stroke-width="1.8" stroke-linecap="round"/><path d="M24 9v6" stroke="#003087" stroke-width="1.8" stroke-linecap="round"/></svg></span><div class="student-profile-modal__section-title">Academic Information</div></div>';
    html += '<div class="student-profile-modal__fields">';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Course/Program</span><span class="student-profile-modal__field-value">' + program + '</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Year Level</span><span class="student-profile-modal__field-value">' + yearLevel + '</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Date Joined SAMS</span><span class="student-profile-modal__field-value">' + joinedAt + '</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Enrollment Status</span><span class="student-profile-modal__pill student-profile-modal__pill--' + statusBadgeClass(d.student_status_class) + '">' + studentStatus + '</span></div>';
    html += '</div></div>';

    html += '<div class="student-profile-modal__info-card">';
    html += '<div class="student-profile-modal__heading"><span class="student-profile-modal__heading-icon" aria-hidden="true"><svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 3l3.5 7.1 7.8 1.1-5.6 5.5 1.3 7.8L14 20.7 7 24.5l1.3-7.8-5.6-5.5 7.8-1.1L14 3z" stroke="#003087" stroke-width="1.8" stroke-linejoin="round"/></svg></span><div class="student-profile-modal__section-title">Skills &amp; Qualifications</div></div>';
    html += '<div class="student-profile-modal__fields">';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Assigned Role / Office</span><span class="student-profile-modal__field-value">' + office + '</span><span class="student-profile-modal__hint">Based on your latest application and current schedule</span></div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Application Status</span><span class="student-profile-modal__pill student-profile-modal__pill--' + applicationStatusClass + '">' + applicationStatus + '</span><span class="student-profile-modal__hint">Reviewed by the student affairs team</span></div>';
    html += '</div>';
    html += '<div class="student-profile-modal__summary">';
    html += '<div class="student-profile-modal__summary-item"><span class="student-profile-modal__summary-label">Availability per Week</span><span class="student-profile-modal__summary-value">' + escapeHtml(availability) + '</span></div>';
    html += '<div class="student-profile-modal__summary-item"><span class="student-profile-modal__summary-label">Profile Approval</span><span class="student-profile-modal__summary-value">' + applicationStatus + '</span></div>';
    html += '<div class="student-profile-modal__summary-item"><span class="student-profile-modal__summary-label">Assignment Readiness</span><span class="student-profile-modal__summary-value">' + (hasSkills ? 'Ready' : 'Incomplete') + '</span></div>';
    html += '</div>';
    html += '<div class="student-profile-modal__field"><span class="student-profile-modal__field-label">Skills</span>' + skillTags + '</div>';
    html += '<div class="student-profile-modal__note">Profile changes stay under admin oversight. If your skills or assignment details are outdated, ask the SDAO office to review your record.</div>';
    html += '</div>';

    html += '<div class="student-profile-modal__perf-card">';
    html += '<div class="student-profile-modal__heading"><span class="student-profile-modal__heading-icon" aria-hidden="true"><svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="14" cy="10" r="6" stroke="#FFB81C" stroke-width="1.8"/><path d="M14 4v1M14 15v1M8 10H7M21 10h-1M9.172 5.172l-.707.707M19.535 15.535l-.707.707M9.172 14.828l-.707-.707M19.535 4.465l-.707-.707" stroke="#FFB81C" stroke-width="1.5" stroke-linecap="round"/></svg></span><div class="student-profile-modal__section-title">Performance Summary</div></div>';
    html += '<div class="student-profile-modal__summary">';
    html += '<div class="student-profile-modal__summary-item"><span class="student-profile-modal__summary-label">Total Duty Hours</span><span class="student-profile-modal__summary-value">' + totalHours + ' hrs</span></div>';
    html += '<div class="student-profile-modal__summary-item"><span class="student-profile-modal__summary-label">Accepted / Pending</span><span class="student-profile-modal__summary-value">' + acceptedSchedules + ' / ' + pendingSchedules + '</span></div>';
    html += '<div class="student-profile-modal__summary-item"><span class="student-profile-modal__summary-label">Attendance Rate</span><span class="student-profile-modal__summary-value">' + attendanceRate + '%</span></div>';
    html += '</div>';
    html += '<div class="student-profile-modal__achievement">';
    html += '<div class="student-profile-modal__achievement-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l2.09 4.26L19 7.27l-3.5 3.41.83 4.82L12 13.27l-4.33 2.23.83-4.82L5 7.27l4.91-.71L12 2z" fill="white"/></svg></div>';
    html += '<div class="student-profile-modal__achievement-info"><span class="student-profile-modal__achievement-title">' + (attendanceRate === 100 ? 'Perfect Attendance!' : 'Keep Improving!') + '</span><span class="student-profile-modal__achievement-subtitle">' + (latestAttendanceText !== 'No attendance recorded' ? ('Latest log: ' + latestAttendanceText + ' • ' + latestRemarks) : 'No attendance log recorded yet') + '</span></div>';
    html += '</div>';
    html += '</div>';

    html += '</div>';

    setStudentModalContent(html);
  }

  function renderStudentModalError(message) {
    setStudentModalContent('<div class="student-profile-modal__loading">' + escapeHtml(message) + '</div>');
  }

  document.addEventListener('click', function (event) {
    var modal = document.getElementById('studentModal');
    if (modal && event.target === modal) {
      closeStudentModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeStudentModal();
    }
  });

  // NFC Card Scanning on Admin Modal
  var scanBuffer = '';
  var isScanningMode = false;
  var currentScanStudentId = null;
  var scanFinalizeTimer = null;

  function finalizeNfcScan() {
    if (scanFinalizeTimer) {
      window.clearTimeout(scanFinalizeTimer);
      scanFinalizeTimer = null;
    }

    var uid = scanBuffer.trim();
    if (uid !== '') {
      saveModalNfcUid(uid);
    }
  }

  document.addEventListener('keydown', function (e) {
    if (!isScanningMode) return;

    // Safety check: do not intercept if the user is typing in another input
    var activeEl = document.activeElement;
    if (activeEl && activeEl.id !== 'nfc-uid-input-placeholder') {
      var tag = activeEl.tagName.toLowerCase();
      if (tag === 'input' || tag === 'textarea' || activeEl.isContentEditable) {
        return;
      }
    }

    if (e.key === 'Enter') {
      e.preventDefault();
      finalizeNfcScan();
      return;
    }

    // Some readers finish with Tab instead of Enter.
    if (e.key === 'Tab') {
      e.preventDefault();
      finalizeNfcScan();
      return;
    }

    if (e.key.length !== 1) {
      return;
    }

    // NFC/RFID readers may include separators such as :, -, or spaces.
    if (/^[a-zA-Z0-9:_\-. ]$/.test(e.key)) {
      e.preventDefault();
      scanBuffer += e.key;
      var displayVal = document.getElementById('modal-nfc-uid');
      if (displayVal) {
        displayVal.textContent = 'Scanning... ' + scanBuffer;
      }

      // Finalize readers that do not send Enter or Tab after the UID.
      if (scanFinalizeTimer) {
        window.clearTimeout(scanFinalizeTimer);
      }
      scanFinalizeTimer = window.setTimeout(finalizeNfcScan, 500);
    }
  });

  window.startNfcScan = function (studentId) {
    if (scanFinalizeTimer) {
      window.clearTimeout(scanFinalizeTimer);
      scanFinalizeTimer = null;
    }
    isScanningMode = true;
    scanBuffer = '';
    currentScanStudentId = studentId;

    var displayVal = document.getElementById('modal-nfc-uid');
    if (displayVal) {
      displayVal.textContent = 'Tap ID card on reader now...';
      displayVal.style.color = '#e17100'; // Gold/orange highlight
    }
    var scanBtn = document.getElementById('btn-modal-nfc-scan');
    if (scanBtn) {
      scanBtn.textContent = 'Scanning...';
      scanBtn.disabled = true;
    }
  };

  window.clearModalNfcCard = function (studentId) {
    if (confirm('Are you sure you want to unregister/clear this student\'s NFC ID card?')) {
      currentScanStudentId = studentId;
      saveModalNfcUid('');
    }
  };

  function saveModalNfcUid(uid) {
    var displayVal = document.getElementById('modal-nfc-uid');
    if (displayVal) {
      displayVal.textContent = 'Saving... Please wait.';
      displayVal.style.color = 'var(--color-navy)';
    }

    var formData = new FormData();
    formData.append('student_id', currentScanStudentId);
    formData.append('nfc_uid', uid);

    fetch('save_student_nfc.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data.success) {
        alert(data.message || 'NFC card saved successfully!');
        window.location.reload();
      } else {
        alert(data.message || 'Error occurred.');
        resetModalNfcInput();
      }
    })
    .catch(function () {
      alert('A network or server error occurred. Please try again.');
      resetModalNfcInput();
    });
  }

  function resetModalNfcInput() {
    if (scanFinalizeTimer) {
      window.clearTimeout(scanFinalizeTimer);
      scanFinalizeTimer = null;
    }
    isScanningMode = false;
    scanBuffer = '';
    var displayVal = document.getElementById('modal-nfc-uid');
    if (displayVal) {
      displayVal.textContent = 'No card registered';
      displayVal.style.color = '';
    }
    var scanBtn = document.getElementById('btn-modal-nfc-scan');
    if (scanBtn) {
      scanBtn.textContent = 'Scan ID';
      scanBtn.disabled = false;
    }
  }

</script>

<script src="../assets/js/admin-notifications.js?v=20260922"></script>

</body>
</html>