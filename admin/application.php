<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/audit.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
  header('Location: ../login.php');
  exit;
}
$admin_name = (string) ($currentUser['name'] ?? 'SAMS Admin');

$flashMessage = '';
$flashError = '';
$officeOptions = sams_office_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $applicationId = (int) ($_POST['application_id'] ?? 0);
  $reviewAction = trim((string) ($_POST['review_action'] ?? ''));

  if ($applicationId <= 0 || !in_array($reviewAction, ['approve', 'reject'], true)) {
    $flashError = 'Invalid review request.';
  } else {
    try {
      $pdo = sams_pdo();

      $applicationStatement = $pdo->prepare(
        'SELECT
          a.application_id AS application_id,
          a.status,
          a.term_id,
          a.preferred_office,
          u.email,
          u.first_name,
          u.last_name
        FROM applications a
        INNER JOIN students s ON s.student_id = a.student_id
        INNER JOIN users u ON u.user_id = s.user_id
        WHERE a.application_id = :application_id
        LIMIT 1'
      );
      $applicationStatement->execute(['application_id' => $applicationId]);
      $application = $applicationStatement->fetch();

      if (!$application) {
        throw new RuntimeException('Application not found.');
      }

      $newStatus = $reviewAction === 'approve' ? 'approved' : 'rejected';

      $updateStatement = $pdo->prepare(
        'UPDATE applications
         SET status = :status,
             reviewed_by = :reviewed_by,
             reviewed_at = NOW()
         WHERE application_id = :application_id'
      );
      $updateStatement->execute([
        'status' => $newStatus,
        'reviewed_by' => (int) ($currentUser['user_id'] ?? 0),
        'application_id' => $applicationId,
      ]);

      if ($reviewAction === 'approve') {
        $activateStatement = $pdo->prepare(
          'UPDATE users u
           INNER JOIN students s ON s.user_id = u.user_id
           INNER JOIN applications a ON a.student_id = s.student_id
           SET u.is_active = 1
           WHERE a.application_id = :application_id'
        );

        $activateStatement->execute(['application_id' => $applicationId]);

        // Automatically populate duty_schedules based on student's availability
        $availabilityFilter = sams_column_exists($pdo, 'availability', 'is_available')
            ? ' AND is_available = 1'
            : '';
        $availStmt = $pdo->prepare(
            "SELECT day_of_week, start_time AS time_start, end_time AS time_end
             FROM availability
             WHERE application_id = :application_id
               AND term_id = :term_id{$availabilityFilter}"
        );
        $availStmt->execute([
            'application_id' => $applicationId,
            'term_id' => (int) $application['term_id']
        ]);
        $availRows = $availStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($availRows)) {
            // Delete existing duty schedules before inserting to prevent duplicates
            $delAppStmt = $pdo->prepare('DELETE FROM duty_schedules WHERE application_id = :application_id');
            $delAppStmt->execute(['application_id' => $applicationId]);

            $hasOfficeColumn = sams_column_exists($pdo, 'duty_schedules', 'office_name');
            if ($hasOfficeColumn) {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status) 
                     VALUES (:application_id, :office_name, :term_id, :day_of_week, :time_start, :time_end, "assigned")'
                );
            } else {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, status) 
                     VALUES (:application_id, :term_id, :day_of_week, :time_start, :time_end, "assigned")'
                );
            }

            foreach ($availRows as $arow) {
                $day = trim((string) $arow['day_of_week']);
                $normalizedDay = match (strtolower($day)) {
                    'monday', 'mon' => 'Monday',
                    'tuesday', 'tue' => 'Tuesday',
                    'wednesday', 'wed' => 'Wednesday',
                    'thursday', 'thu' => 'Thursday',
                    'friday', 'fri' => 'Friday',
                    'saturday', 'sat' => 'Saturday',
                    default => $day,
                };

                $insertParams = [
                    'application_id' => $applicationId,
                    'term_id' => (int) $application['term_id'],
                    'day_of_week' => $normalizedDay,
                    'time_start' => (string) $arow['time_start'],
                    'time_end' => (string) $arow['time_end'],
                ];

                if ($hasOfficeColumn) {
                    $insertParams['office_name'] = $application['preferred_office'];
                }

                $insertAppStmt->execute($insertParams);
            }
        }
      }

      try {
        sams_send_application_review_email(
          (string) ($application['email'] ?? ''),
          trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? '')),
          $newStatus
        );
      } catch (Throwable $mailException) {
        $flashError = 'Application was updated, but the email notification could not be sent: ' . $mailException->getMessage();
      }

      // Log to Audit Trail
      $applicantFullName = trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? ''));
      $auditAction = $reviewAction === 'approve' ? 'APPLICATION_APPROVE' : 'APPLICATION_REJECT';
      sams_log_audit(
          $auditAction,
          'APPLICATION',
          "Admin {$admin_name} " . ($reviewAction === 'approve' ? 'approved' : 'rejected') . " application #{$applicationId} for student {$applicantFullName}.",
          'application',
          $applicationId,
          [
              'application_id' => $applicationId,
              'applicant_name' => $applicantFullName,
              'new_status'     => $newStatus,
              'office'         => $application['preferred_office'] ?? 'Unassigned',
          ],
          $currentUser
      );

      $flashMessage = $reviewAction === 'approve'
        ? 'Application approved successfully.'
        : 'Application rejected successfully.';
    } catch (Throwable $exception) {
      $flashError = $exception->getMessage();
    }
  }

  if ($flashMessage !== '') {
    $_SESSION['sams_app_flash'] = $flashMessage;
  }
  if ($flashError !== '') {
    $_SESSION['sams_app_error'] = $flashError;
  }

  if ($applicationId > 0 && ($_POST['return_to_detail'] ?? '') === '1') {
    header('Location: application_view.php?application_id=' . $applicationId);
  } else {
    header('Location: application.php');
  }
  exit;
}

function sams_application_status_label(string $status): string
{
  return match ($status) {
    'pending' => 'Pending',
    'under_review' => 'Under Review',
    'interview' => 'Interview',
    'approved' => 'Approved',
    'deployed' => 'Deployed',
    'rejected' => 'Rejected',
    default => ucfirst(str_replace('_', ' ', $status)),
  };
}

function sams_application_status_class(string $status): string
{
  return match ($status) {
    'pending' => 'badge--pending',
    'under_review' => 'badge--interview',
    'interview' => 'badge--interview',
    'approved' => 'badge--approved',
    'deployed' => 'badge--deployed',
    'rejected' => 'badge--rejected',
    default => 'badge--pending',
  };
}

function sams_application_avatar(string $name): string
{
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $initials = '';

  foreach ($parts as $part) {
    if ($part === '') {
      continue;
    }

    $initials .= strtoupper(substr($part, 0, 1));

    if (strlen($initials) >= 2) {
      break;
    }
  }

  return $initials !== '' ? substr($initials, 0, 2) : 'SA';
}

function sams_application_skill_tags(?string $skills): array
{
  if ($skills === null || trim($skills) === '') {
    return [];
  }

  $items = preg_split('/[\r\n,;]+/', $skills) ?: [];

  return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
}

$pdo = sams_pdo();
$courseOptions = sams_course_options();

$applicationCounts = [
  'all' => 0,
  'pending' => 0,
  'rejected' => 0,
];

$applicationCountsStatement = $pdo->query(
  "SELECT
    CASE
      WHEN EXISTS (
        SELECT 1
        FROM duty_schedules ds
        WHERE ds.application_id = a.application_id
          AND ds.status = 'deployed'
      ) THEN 'deployed'
      ELSE a.status
    END AS display_status,
    COUNT(*) AS total
   FROM applications a
     WHERE a.status NOT IN ('draft', 'approved')
       AND NOT EXISTS (
         SELECT 1
         FROM duty_schedules ds
         WHERE ds.application_id = a.application_id
           AND ds.status = 'deployed'
       )
   GROUP BY display_status"
);
foreach ($applicationCountsStatement->fetchAll(PDO::FETCH_ASSOC) as $countRow) {
  $status = (string) $countRow['display_status'];
  $count = (int) $countRow['total'];
    $applicationCounts['all'] += $count;
  if (array_key_exists($status, $applicationCounts)) {
    $applicationCounts[$status] = $count;
  }
}

$availabilityHoursFilter = sams_column_exists($pdo, 'availability', 'is_available')
  ? ' AND is_available = 1'
  : '';
$applicationsStatement = $pdo->query(
  "SELECT
    a.application_id AS application_id,
    CASE
      WHEN EXISTS (
        SELECT 1
        FROM duty_schedules ds
        WHERE ds.application_id = a.application_id
          AND ds.status = 'deployed'
      ) THEN 'deployed'
      ELSE a.status
    END AS status,
    a.preferred_office,
    a.skills,
    a.submitted_at,
    a.reviewed_at,
    a.reviewed_by,
    u.first_name,
    u.last_name,
    u.email,
    s.student_id_number AS student_id_number,
    s.program,
    s.year_level,
    t.term_name,
    t.term_year AS school_year,
    (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)), 0) / 60
     FROM availability
     WHERE application_id = a.application_id{$availabilityHoursFilter}) AS total_available_hours
  FROM applications a
  INNER JOIN students s ON s.student_id = a.student_id
  INNER JOIN users u ON u.user_id = s.user_id
  INNER JOIN terms t ON t.term_id = a.term_id
  WHERE a.status NOT IN ('draft', 'approved')
    AND NOT EXISTS (
      SELECT 1
      FROM duty_schedules ds
      WHERE ds.application_id = a.application_id
        AND ds.status = 'deployed'
    )
  ORDER BY a.submitted_at DESC, a.application_id DESC"
);

$applications = $applicationsStatement->fetchAll();

// --- RECOMMENDATION LOGIC ---
$selectedSkills = isset($_GET['filter_skills']) ? array_filter(array_map('trim', explode(',', (string)$_GET['filter_skills']))) : [];

// If skills are filtered, filter the original application list to only show applicants with matching skills
if (!empty($selectedSkills)) {
    $filteredApps = [];
    foreach ($applications as $app) {
        $appSkills = sams_application_skill_tags($app['skills'] ?? null);
        $matches = array_intersect($appSkills, $selectedSkills);
        if (count($matches) > 0) {
            $filteredApps[] = $app;
        }
    }
    $applications = $filteredApps;
}

$recommendedIds = [];
$eligibleApps = [];
foreach ($applications as $app) {
    if ($app['status'] === 'pending') {
        $eligibleApps[] = $app;
    }
}

if (!empty($selectedSkills)) {
    usort($eligibleApps, function($a, $b) use ($selectedSkills) {
        $skillsA = sams_application_skill_tags($a['skills'] ?? null);
        $skillsB = sams_application_skill_tags($b['skills'] ?? null);
        $matchA = count(array_intersect($skillsA, $selectedSkills));
        $matchB = count(array_intersect($skillsB, $selectedSkills));
        
        if ($matchA !== $matchB) {
            return $matchB <=> $matchA; // higher skill match count first
        }
        
        $hoursA = (float)($a['total_available_hours'] ?? 0);
        $hoursB = (float)($b['total_available_hours'] ?? 0);
        return $hoursB <=> $hoursA; // then higher available hours
    });
} else {
    usort($eligibleApps, function($a, $b) {
        $hoursA = (float)($a['total_available_hours'] ?? 0);
        $hoursB = (float)($b['total_available_hours'] ?? 0);
        return $hoursB <=> $hoursA;
    });
}

$top5 = array_slice($eligibleApps, 0, 5);
foreach ($top5 as $app) {
    $recommendedIds[$app['application_id']] = true;
}

$recommendedAppsList = [];
$otherAppsList = [];
foreach ($applications as $app) {
    if (isset($recommendedIds[$app['application_id']])) {
        $app['is_recommended'] = true;
        $recommendedAppsList[] = $app;
    } else {
        $app['is_recommended'] = false;
        $otherAppsList[] = $app;
    }
}

if (!empty($selectedSkills)) {
    usort($recommendedAppsList, function($a, $b) use ($selectedSkills) {
        $skillsA = sams_application_skill_tags($a['skills'] ?? null);
        $skillsB = sams_application_skill_tags($b['skills'] ?? null);
        $matchA = count(array_intersect($skillsA, $selectedSkills));
        $matchB = count(array_intersect($skillsB, $selectedSkills));
        
        if ($matchA !== $matchB) {
            return $matchB <=> $matchA;
        }
        
        $hoursA = (float)($a['total_available_hours'] ?? 0);
        $hoursB = (float)($b['total_available_hours'] ?? 0);
        return $hoursB <=> $hoursA;
    });
} else {
    usort($recommendedAppsList, function($a, $b) {
        $hoursA = (float)($a['total_available_hours'] ?? 0);
        $hoursB = (float)($b['total_available_hours'] ?? 0);
        return $hoursB <=> $hoursA;
    });
}

$applications = array_merge($recommendedAppsList, $otherAppsList);
// ----------------------------

if ($flashMessage === '' && isset($_SESSION['sams_app_flash'])) {
  $flashMessage = (string) $_SESSION['sams_app_flash'];
  unset($_SESSION['sams_app_flash']);
}

if ($flashError === '' && isset($_SESSION['sams_app_error'])) {
  $flashError = (string) $_SESSION['sams_app_error'];
  unset($_SESSION['sams_app_error']);
}

$statusFilterOptions = [
  'all' => 'All',
  'pending' => 'Pending',
  'rejected' => 'Rejected',
];

$activeAdminNav = 'applications';
$pendingApplications = (int) $applicationCounts['pending'];
?>
<style>

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
       TOOLBAR (search + filters + export)
    ============================================= */
    .toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      user-select: none;
      -webkit-user-select: none;
      margin-bottom: 28px;
      padding: 0;
    }
    .toolbar__left {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      flex: 1;
      min-width: 0;
    }
    .program-filter-select {
      height: 38px;
      min-width: 220px;
      max-width: 280px;
      padding: 0 12px;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      color: #1f2937;
      font: inherit;
      font-size: 14px;
    }
    .toolbar__search {
      position: relative;
      flex: 0 1 340px;
      min-width: 280px;
    }
    .toolbar__search-icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      width: 18px;
      height: 18px;
      pointer-events: none;
      color: #9ca3af;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .toolbar__search input {
      width: 100%;
      height: 40px;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: 10px 14px 10px 44px;
      font-size: 14px;
      color: #1f2937;
      background: #ffffff;
      outline: none;
      transition: all 0.2s ease;
      font-weight: 500;
    }
    .toolbar__search input::placeholder { 
      color: #9ca3af;
      font-weight: 400;
    }
    .toolbar__search input:focus { 
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    /* Filter buttons */
    .filter-btn {
      height: 38px;
      border-radius: 8px;
      border: 1px solid transparent;
      cursor: pointer;
      font-size: 14px;
      font-weight: 500;
      padding: 0 14px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s ease;
      white-space: nowrap;
    }
    .filter-btn--active {
      background: #3b82f6;
      color: #ffffff;
      border-color: #3b82f6;
    }
    .filter-btn--active:hover {
      background: #2563eb;
      border-color: #2563eb;
    }
    .filter-btn--inactive {
      background: #f3f4f6;
      color: #4b5563;
      border-color: #e5e7eb;
    }
    .filter-btn--inactive:hover {
      background: #e5e7eb;
      border-color: #d1d5db;
    }
    .filter-btn__count { 
      opacity: 0.7;
      font-size: 13px;
    }
    .filter-btn--active .filter-btn__count { opacity: 0.85; }

    /* Export button */
    .btn-export {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      height: 38px;
      padding: 0 16px;
      border-radius: 8px;
      border: none;
      background: #3b82f6;
      color: #ffffff;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.2s ease;
      text-decoration: none;
    }
    .btn-export:hover { 
      background: #2563eb;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .btn-export__icon { width: 16px; height: 16px; }

    /* =============================================
       TABLE CARD
    ============================================= */
    .table-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      min-height: 0;
      user-select: none;
      -webkit-user-select: none;
      margin-bottom: 32px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }
    .table-wrap {
      overflow-x: auto;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      max-height: min(72vh, 860px);
      min-height: 0;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 900px;
    }
    thead {
      background: #f9fafb;
      border-bottom: 1px solid #e5e7eb;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    th {
      padding: 14px 16px;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      text-align: left;
      white-space: nowrap;
      letter-spacing: 0.02em;
      text-transform: uppercase;
    }
    tbody tr {
      border-bottom: 1px solid #f3f4f6;
      transition: background-color 0.15s ease;
    }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #fafbfc; }
    td {
      padding: 14px 16px;
      vertical-align: middle;
      font-size: 14px;
      color: #1f2937;
    }

    /* Applicant cell */
    .applicant {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .applicant__avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      color: #ffffff;
      flex-shrink: 0;
      box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
    }
    .applicant__name {
      font-weight: 600;
      color: #1f2937;
      line-height: 1.4;
    }
    .applicant__date {
      font-size: 13px;
      color: #9ca3af;
      font-weight: 400;
    }

    /* Skills tags */
    .skills {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }
    .skill-tag {
      background: #ece8ff;
      color: #6d28d9;
      font-size: 12px;
      padding: 4px 10px;
      border-radius: 6px;
      white-space: nowrap;
      font-weight: 500;
    }

    /* Status badges */
    .badge {
      display: inline-block;
      font-size: 12px;
      padding: 6px 12px;
      border-radius: 20px;
      white-space: nowrap;
      font-weight: 600;
    }
    .badge--pending   { background: #fef3c7; color: #92400e; }
    .badge--interview { background: #bfdbfe; color: #1e40af; }
    .badge--approved  { background: #d1fae5; color: #065f46; }
    .badge--deployed  { background: #dbeafe; color: #1d4ed8; }
    .badge--rejected  { background: #fee2e2; color: #991b1b; }
    .badge--recommended { background: #fef08a; color: #854d0e; margin-left: 8px; border: 1px solid #fde047; }
    .tr-recommended { background-color: #fefce8 !important; }
    .tr-recommended:hover { background-color: #fef9c3 !important; }

    /* Page content styling */
    .page.content {
      user-select: none;
      -webkit-user-select: none;
      padding: 32px;
      max-width: 1600px;
      margin: 0 auto;
    }

    .page.content input,
    .page.content textarea,
    .page.content select {
      user-select: text;
      -webkit-user-select: text;
    }

    .page.content ::selection {
      background: transparent;
      color: inherit;
    }

    .page.content ::-moz-selection {
      background: transparent;
      color: inherit;
    }

    /* Top bar */
    .topbar__user {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      flex-shrink: 0;
      white-space: nowrap;
    }
    .topbar__user-info {
      text-align: right;
      white-space: nowrap;
      min-width: 0;
    }
    .topbar__user-name,
    .topbar__user-role {
      white-space: nowrap;
      line-height: 1.15;
    }
    .topbar__user-name {
      font-size: var(--font-sm);
      font-weight: 700;
    }
    .topbar__user-role {
      font-size: var(--font-xs);
      color: var(--color-muted);
    }
    .topbar__avatar {
      flex-shrink: 0;
    }

    /* Action buttons */
    .actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }
    .action-btn {
      width: 32px;
      height: 32px;
      border-radius: var(--radius-md);
      border: none;
      background: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      transition: background .15s;
      flex-shrink: 0;
    }
    .action-btn:hover { background: var(--color-tag-bg); }
    .action-btn svg { width: 16px; height: 16px; }
    .action-btn--view  svg { color: var(--color-muted); }
    .action-btn--view-text {
      width: auto;
      min-width: 64px;
      height: 36px;
      padding: 0 12px;
      border: 1px solid var(--color-border);
      background: #ffffff;
      color: var(--color-primary);
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
    }
    .action-btn--view-text:hover { background: #eff6ff; }
    .action-btn--approve svg { color: #008236; }
    .action-btn--reject  svg { color: #b91c1c; }
    .action-btn--msg   svg { color: var(--color-blue); }
    .action-form {
      display: inline-flex;
      margin: 0;
    }

    /* =============================================
       MODAL
    ============================================= */
    .modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(15, 23, 42, 0.55);
      z-index: 500;
    }
    .modal.is-open {
      display: flex;
    }
    .modal__panel {
      width: min(760px, 100%);
      max-height: 85vh;
      overflow-y: auto;
      background: var(--color-white);
      border-radius: 20px;
      box-shadow: 0 24px 64px rgba(15, 23, 42, 0.28);
      border: 1px solid var(--color-border);
    }
    .modal__header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
      padding: 28px 28px 20px;
      border-bottom: 1px solid #f3f4f6;
    }
    .modal__title {
      margin: 0;
      font-size: 22px;
      font-weight: 900;
      color: var(--color-dark);
      letter-spacing: -0.01em;
    }
    .modal__subtitle {
      margin-top: 6px;
      font-size: 13px;
      color: var(--color-muted);
      font-weight: 500;
      line-height: 1.5;
    }
    .modal__close {
      width: 40px;
      height: 40px;
      border-radius: 9999px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      cursor: pointer;
      font-size: 24px;
      line-height: 1;
      color: #9ca3af;
      transition: all 0.2s ease;
      flex-shrink: 0;
    }
    .modal__close:hover {
      background: #f3f4f6;
      border-color: #d1d5dc;
      color: #6b7280;
    }
    .modal__body {
      padding: 28px;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      width: 100%;
    }
    .detail-item {
      background: linear-gradient(180deg, #ffffff 0%, #fbfdfe 100%);
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 16px 18px;
      word-break: break-word;
      box-shadow: 0 1px 2px rgba(16, 24, 40, 0.02);
      transition: all 0.15s ease;
    }
    .detail-item:hover {
      border-color: #d1d5dc;
      box-shadow: 0 2px 4px rgba(16, 24, 40, 0.04);
    }
    .detail-item[style*="grid-column"] {
      grid-column: 1 / -1;
    }
    .detail-item__label {
      display: block;
      font-size: 10px;
      font-weight: 800;
      color: #9ca3af;
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .detail-item__value {
      font-size: 15px;
      color: var(--color-dark);
      line-height: 1.5;
      word-break: break-word;
      font-weight: 600;
    }
    .modal__skills {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }
    .modal__skills .skill-badge {
      display: inline-flex;
      align-items: center;
      background: #dbeafe;
      color: #1e40af;
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      border: 1px solid #93c5fd;
    }
    .modal__skills:empty::before {
      content: '—';
      color: #d1d5dc;
    }
    .modal__footer {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      flex-wrap: wrap;
      padding: 20px 24px 24px;
      border-top: 1px solid #f3f4f6;
      margin-top: 4px;
    }
    .modal__action {
      display: inline-flex;
      margin: 0;
    }
    .modal__button {
      border: 1px solid transparent;
      border-radius: 10px;
      padding: 11px 18px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
      letter-spacing: 0.01em;
    }
    .modal__button:hover {
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(16, 24, 40, 0.08);
    }
    .modal__button--approve {
      background: #10b981;
      color: #ffffff;
      border-color: #10b981;
    }
    .modal__button--approve:hover {
      background: #059669;
      border-color: #059669;
    }
    .modal__button--reject {
      background: #ef4444;
      color: #ffffff;
      border-color: #ef4444;
    }
    .modal__button--reject:hover {
      background: #dc2626;
      border-color: #dc2626;
    }
    .modal__button--ghost {
      background: #f3f4f6;
      color: #374151;
      border-color: #d1d5dc;
    }
    .modal__button--ghost:hover {
      background: #e5e7eb;
      border-color: #b3b7bc;
    }

    /* =============================================
       PAGE ALERTS
    ============================================= */
    .page-alert {
      margin-bottom: 16px;
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
    }
    .page-alert--success {
      background: #f0fdf4;
      color: #166534;
      border: 1px solid #bbf7d0;
    }
    .page-alert--error {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    /* =============================================
       BOTTOM INFO CARDS (2-col grid)
    ============================================= */
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      align-items: stretch;
      user-select: none;
      -webkit-user-select: none;
    }

    /* Auto-Filtering card */
    .card-auto {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      min-height: 200px;
      user-select: none;
      -webkit-user-select: none;
      transition: all 0.15s ease;
    }
    .card-auto:hover {
      border-color: #d1d5db;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }
    .card-auto__title {
      font-size: 16px;
      font-weight: 700;
      color: #1f2937;
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0;
    }
    .card-auto__desc {
      font-size: 14px;
      color: #6b7280;
      line-height: 1.5;
      margin: 0;
    }
    .card-auto__stats {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 8px;
    }
    .stat-box {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      text-align: center;
      transition: all 0.15s ease;
    }
    .stat-box:hover {
      border-color: #d1d5db;
      background: #fafbfc;
    }
    .stat-box__num {
      font-size: 28px;
      font-weight: 800;
      color: #1f2937;
      line-height: 1.2;
    }
    .stat-box__label {
      font-size: 13px;
      color: #6b7280;
      font-weight: 500;
    }

    /* AI Recommendations card */
    .card-ai {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      min-height: 200px;
      user-select: none;
      -webkit-user-select: none;
      transition: all 0.15s ease;
    }
    .card-ai:hover {
      border-color: #d1d5db;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }
    .card-ai__title {
      font-size: 16px;
      font-weight: 700;
      color: #1f2937;
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0;
    }
    .card-ai__desc {
      font-size: 14px;
      color: #6b7280;
      line-height: 1.5;
      margin: 0;
    }
    .card-ai__list {
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin-top: 4px;
    }
    .rec-item {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 14px;
      display: grid;
      grid-template-columns: 1fr auto;
      align-items: center;
      gap: 12px;
      min-height: 60px;
      user-select: none;
      -webkit-user-select: none;
      transition: all 0.15s ease;
    }
    .rec-item:hover {
      border-color: #d1d5db;
      background: #fafbfc;
    }
    .rec-item__name {
      font-size: 14px;
      font-weight: 600;
      color: #1f2937;
      margin: 0;
    }
    .rec-item__office {
      font-size: 12px;
      color: #6b7280;
      font-weight: 400;
      margin: 4px 0 0 0;
    }
    .rec-item__match {
      text-align: right;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 4px;
    }
    .rec-item__pct {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 28px;
      padding: 4px 12px;
      border-radius: 9999px;
      background: #dbeafe;
      color: #1e40af;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.04em;
    }
    .rec-item__label {
      font-size: 11px;
      color: #9ca3af;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    /* =============================================
       IMAGE LIGHTBOX  ← FIXED: moved OUT of media query
    ============================================= */
    .image-lightbox {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, 0.75);
      z-index: 600;
      padding: 20px;
    }
    .image-lightbox.is-open {
      display: flex;
    }
    .image-lightbox__panel {
      max-width: 95%;
      max-height: 95%;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
    }
    .image-lightbox__panel img {
      max-width: 100%;
      max-height: 85vh;
      border-radius: 8px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .5);
    }
    .image-lightbox__close {
      position: fixed;
      top: 18px;
      right: 18px;
      width: 40px;
      height: 40px;
      border-radius: 9999px;
      background: rgba(255, 255, 255, 0.9);
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 18px;
      color: #111827;
      z-index: 601;
    }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .info-grid { grid-template-columns: 1fr; }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .page-alert { margin-bottom: 12px; }
      .toolbar { flex-direction: column; align-items: stretch; }
      .toolbar__left { flex-wrap: wrap; }
      .toolbar__search input { width: 100%; }
      .btn-export { justify-content: center; }
      .topbar__user-info { display: none; }
      .toolbar__left { gap: 8px; }
      .filter-btn { width: 100%; justify-content: center; }
      .filter-btn__count { margin-left: auto; }
      .program-filter-select { width: 100%; max-width: none; }
      .table-card { border-radius: 14px; }
      .table-wrap { overflow: visible; }
      table { min-width: 0; }
      thead { display: none; }
      tbody tr {
        display: block;
        padding: 12px;
      }
      tbody td {
        display: block;
        height: auto;
        padding: 8px 0;
      }
      tbody td::before {
        content: attr(data-label);
        display: block;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--color-muted);
        margin-bottom: 4px;
      }
      tbody td:first-child::before {
        margin-bottom: 8px;
      }
      tbody td:last-child::before {
        margin-bottom: 8px;
      }
      .actions { flex-wrap: wrap; }
      .action-btn { width: 36px; height: 36px; }
      .detail-grid { grid-template-columns: 1fr; }
      .modal { padding: 12px; }
      .modal__panel {
        max-height: calc(100vh - 24px);
        border-radius: 16px;
      }
      .modal__header,
      .modal__body,
      .modal__footer {
        padding-left: 16px;
        padding-right: 16px;
      }
      .modal__footer { justify-content: stretch; }
      .modal__action,
      .modal__button { width: 100%; }
      .modal__footer { flex-direction: column; }
      .modal__close { flex-shrink: 0; }
      .card-auto__stats { grid-template-columns: 1fr; }
    }
    
    /* Skill recommendation filter styles */
    .skills-filter-tag:hover {
      background-color: #2563eb !important;
      color: #ffffff !important;
      border-color: #2563eb !important;
      box-shadow: 0 4px 6px rgba(37, 99, 235, 0.15) !important;
      transform: translateY(-1px) !important;
    }
    .tr-recommended {
      background-color: #f0f6ff !important;
      border-left: 4px solid #3b82f6 !important;
    }
  </style>
<link rel="stylesheet" href="../assets/css/sams-shell.css" />
<link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
</head>
<body>

<div class="shell">
<?php require_once __DIR__ . '/_sidebar.php'; ?>

  <!-- ============================================
       MAIN
  ============================================= -->
  <div class="main">

    <!-- Top bar -->
    <header class="topbar" role="banner">
      <div>
        <div class="topbar__title">Application Management</div>
        <div class="topbar__sub">NU Lipa - Student Development and Activities Office</div>
      </div>
      <div class="topbar__user">
        <div class="topbar__notif" aria-label="Notifications">
          <svg class="topbar__notif-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/>
          </svg>
          <span class="topbar__notif-dot" aria-label="New notifications"></span>
        </div>
        <div class="topbar__user-info">
          <div class="topbar__user-name"><?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="topbar__user-role">SDAO Head</div>
        </div>
        <div class="topbar__avatar">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="10" cy="7" r="4" fill="white" opacity=".9"/>
            <path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/>
          </svg>
        </div>
      </div>
    </header>

    <!-- Page content -->
    <main class="page content" role="main">

      <?php if ($flashMessage !== ''): ?>
        <div class="page-alert page-alert--success" role="status"><?= htmlspecialchars($flashMessage) ?></div>
      <?php endif; ?>
      <?php if ($flashError !== ''): ?>
        <div class="page-alert page-alert--error" role="alert"><?= htmlspecialchars($flashError) ?></div>
      <?php endif; ?>

      <!-- ---- Skills Filter Tag Bar ---- -->
      <div class="skills-filter-card" style="
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      ">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
          <div>
            <h3 style="font-size: 15px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 6px;">
              🔍 Skill-based Recommendation Filter
            </h3>
            <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
              Select skills to filter applications and dynamically prioritize top candidates for Miss Zai.
            </div>
          </div>
          <?php if (!empty($selectedSkills)): ?>
            <a href="application.php" style="
              font-size: 13px;
              color: #dc2626;
              font-weight: 600;
              text-decoration: none;
              display: inline-flex;
              align-items: center;
              gap: 4px;
            ">
              ✕ Clear Filter
            </a>
          <?php endif; ?>
        </div>
        <div class="skills-filter-tags" style="display: flex; flex-wrap: wrap; gap: 8px;">
          <?php
          $all_available_skills = [
              'Time Management',
              'Teamwork',
              'Leadership',
              'Problem Solving',
              'Adaptability',
              'Attention to Detail',
              'Multitasking',
              'Organization'
          ];
          foreach ($all_available_skills as $skill):
              $isActive = in_array($skill, $selectedSkills, true);
              
              // Build query string toggling this skill
              $tempSkills = $selectedSkills;
              if ($isActive) {
                  $tempSkills = array_diff($tempSkills, [$skill]);
              } else {
                  $tempSkills[] = $skill;
              }
              $queryString = !empty($tempSkills) ? '?filter_skills=' . urlencode(implode(',', $tempSkills)) : 'application.php';
          ?>
            <a href="<?= $queryString ?>" class="skills-filter-tag" style="
              display: inline-flex;
              align-items: center;
              justify-content: center;
              padding: 8px 14px;
              font-size: 13px;
              font-weight: 600;
              color: <?= $isActive ? '#ffffff' : '#4b5563' ?>;
              background: <?= $isActive ? 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)' : '#f3f4f6' ?>;
              border: 1px solid <?= $isActive ? '#2563eb' : '#e5e7eb' ?>;
              border-radius: 9999px;
              text-decoration: none;
              transition: all 0.2s ease;
              cursor: pointer;
            ">
              <?= htmlspecialchars($skill) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ---- Toolbar ---- -->
      <div class="toolbar">
        <div class="toolbar__left">
          <!-- Search -->
          <div class="toolbar__search">
            <span class="toolbar__search-icon" aria-hidden="true">
              <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="9" cy="9" r="6" stroke="#6A7282" stroke-width="1.6"/>
                <path d="M13.5 13.5L17 17" stroke="#6A7282" stroke-width="1.6" stroke-linecap="round"/>
              </svg>
            </span>
            <input type="search" id="search-input" placeholder="Search applicants..."
              aria-label="Search applicants" />
          </div>
          <!-- Filter buttons -->
          <?php foreach ($statusFilterOptions as $filterKey => $filterLabel): ?>
          <button class="filter-btn <?= $filterKey === 'all' ? 'filter-btn--active' : 'filter-btn--inactive' ?>"
            data-filter="<?= htmlspecialchars($filterKey) ?>"
            aria-pressed="<?= $filterKey === 'all' ? 'true' : 'false' ?>">
            <?= htmlspecialchars($filterLabel) ?>
            <span class="filter-btn__count">(<?= (int) ($filterKey === 'all' ? $applicationCounts['all'] : $applicationCounts[$filterKey]) ?>)</span>
          </button>
          <?php endforeach; ?>
          <select id="program-filter" class="program-filter-select" aria-label="Filter applications by course or program">
            <option value="">All Courses / Programs</option>
            <?php foreach ($courseOptions as $courseCode => $courseLabel): ?>
              <option value="<?= htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($courseLabel, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <a class="btn-export" href="#" aria-label="Export applicant list">
          <svg class="btn-export__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M10 3v9M10 12L6.5 8.5M10 12l3.5-3.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M3 14.5v1A1.5 1.5 0 004.5 17h11A1.5 1.5 0 0017 15.5v-1" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
          Export List
        </a>
      </div>

      <!-- ---- Table ---- -->
      <div class="table-card">
        <div class="table-wrap">
          <table aria-label="Applications table">
            <thead>
              <tr>
                <th scope="col">Applicant</th>
                <th scope="col">Student ID</th>
                <th scope="col">Program</th>
                <th scope="col">Preferred Office</th>
                <th scope="col">Skills</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody id="table-body">

              <?php if (empty($applications)): ?>
              <tr>
                <td colspan="7" style="padding: 32px; text-align: center; color: var(--color-muted);">
                  No applications have been submitted yet.
                </td>
              </tr>
              <?php else: ?>
                <?php foreach ($applications as $application): ?>
                <?php
                  $fullName = trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? ''));
                  $avatar = sams_application_avatar($fullName);
                  $skills = sams_application_skill_tags($application['skills'] ?? null);
                  $status = (string) ($application['status'] ?? 'pending');
                  $submittedAt = $application['submitted_at'] ? date('M j, Y', strtotime((string) $application['submitted_at'])) : 'N/A';
                  $skillsText = implode(', ', $skills);
                ?>
              <tr data-status="<?= htmlspecialchars($status) ?>" data-program="<?= htmlspecialchars((string) ($application['program'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="<?= !empty($application['is_recommended']) ? 'tr-recommended' : '' ?>">
                <td data-label="Applicant">
                  <div class="applicant">
                    <div class="applicant__avatar" aria-hidden="true"><?= htmlspecialchars($avatar) ?></div>
                    <div>
                      <div class="applicant__name">
                        <?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Applicant') ?>
                        <?php if (!empty($application['is_recommended'])): ?>
                          <?php 
                            $recTitle = "This student has the highest available hours (" . round((float)($application['total_available_hours'] ?? 0), 1) . " hrs/week)";
                            if (!empty($selectedSkills)) {
                              $appSkills = sams_application_skill_tags($application['skills'] ?? null);
                              $matches = array_intersect($appSkills, $selectedSkills);
                              $recTitle = "Matches " . count($matches) . " selected skill(s): " . implode(', ', $matches);
                            }
                          ?>
                          <span class="badge badge--recommended" title="<?= htmlspecialchars($recTitle) ?>">🌟 Top Recommended</span>
                        <?php endif; ?>
                      </div>
                      <div class="applicant__date">Applied <?= htmlspecialchars($submittedAt) ?></div>
                    </div>
                  </div>
                </td>
                <td data-label="Student ID"><?= htmlspecialchars((string) ($application['student_id_number'] ?? '')) ?></td>
                <td data-label="Program"><?= htmlspecialchars((string) ($application['program'] ?? '')) ?></td>
                <td data-label="Preferred Office"><?= htmlspecialchars((string) ($application['preferred_office'] ?? '')) ?></td>
                <td data-label="Skills">
                  <div class="skills">
                    <?php if (empty($skills)): ?>
                      <span class="skill-tag">No skills listed</span>
                    <?php else: ?>
                      <?php foreach (array_slice($skills, 0, 3) as $skill): ?>
                        <span class="skill-tag"><?= htmlspecialchars($skill) ?></span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td data-label="Status"><span class="badge <?= htmlspecialchars(sams_application_status_class($status)) ?>"><?= htmlspecialchars(sams_application_status_label($status)) ?></span></td>
                <td data-label="Actions">
                  <div class="actions">
                    <a
                      class="action-btn action-btn--view-text"
                      href="application_view.php?application_id=<?= (int) $application['application_id'] ?>"
                      target="_blank"
                      rel="noopener noreferrer"
                      title="View application"
                      aria-label="View <?= htmlspecialchars($fullName !== '' ? $fullName : 'applicant') ?>"
                    >
                      View
                    </a>
                  </div>
                </td>
              </tr>
                <?php endforeach; ?>
              <?php endif; ?>

            </tbody>
          </table>
        </div>
      </div>

      <!-- ---- Bottom info grid ---- -->
      <div class="info-grid">

        <!-- Auto-Filtering card -->
        <div class="card-auto">
          <div class="card-auto__title">🤖 Live Applications</div>
          <div class="card-auto__desc">Applications are loaded directly from the database. Only submitted applicants are shown above.</div>
          <div class="card-auto__stats">
            <div class="stat-box">
              <div class="stat-box__num"><?= (int) $applicationCounts['all'] ?></div>
              <div class="stat-box__label">Total Applications</div>
            </div>
            <div class="stat-box">
              <div class="stat-box__num"><?= (int) $applicationCounts['pending'] ?></div>
              <div class="stat-box__label">Pending</div>
            </div>
          </div>
        </div>

        <!-- AI Recommendations card -->
        <div class="card-ai">
          <div class="card-ai__title">✨ AI Recommendations</div>
          <div class="card-ai__desc">Based on the latest submitted application records</div>
          <div class="card-ai__list">
            <?php if (empty($applications)): ?>
              <div class="rec-item">
                <div>
                  <div class="rec-item__name">No applications yet</div>
                  <div class="rec-item__office">Wait for student submissions</div>
                </div>
              </div>
            <?php else: ?>
              <?php foreach (array_slice($applications, 0, 2) as $application): ?>
                <?php $fullName = trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? '')); ?>
                <div class="rec-item">
                  <div>
                    <div class="rec-item__name"><?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Applicant') ?></div>
                    <div class="rec-item__office"><?= htmlspecialchars((string) ($application['preferred_office'] ?? '')) ?></div>
                  </div>
                  <div class="rec-item__match">
                    <div class="rec-item__pct"><?= htmlspecialchars(strtoupper((string) ($application['status'] ?? 'PENDING'))) ?></div>
                    <div class="rec-item__label">Status</div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>

    </main>
  </div><!-- /.main -->

  <!-- ============================================
       APPLICATION DETAIL MODAL
  ============================================= -->
  <div class="modal" id="application-modal" aria-hidden="true" role="dialog" aria-labelledby="application-modal-title">
    <div class="modal__panel" role="document">
      <div class="modal__header">
        <div>
          <h2 class="modal__title" id="application-modal-title">Applicant Details</h2>
          <div class="modal__subtitle" id="application-modal-subtitle">Review the submission before making a decision.</div>
        </div>
        <button class="modal__close" type="button" id="application-modal-close" aria-label="Close details panel">&times;</button>
      </div>
      <div class="modal__body">
        <div class="detail-grid">
          <div class="detail-item">
            <span class="detail-item__label">Applicant</span>
            <div class="detail-item__value" id="modal-name"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Student ID</span>
            <div class="detail-item__value" id="modal-student-id"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Email</span>
            <div class="detail-item__value" id="modal-email"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Program / Year Level</span>
            <div class="detail-item__value" id="modal-program"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Preferred Office</span>
            <div class="detail-item__value" id="modal-office"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Status</span>
            <div class="detail-item__value" id="modal-status"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Submitted At</span>
            <div class="detail-item__value" id="modal-submitted-at"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Skills</span>
            <div class="detail-item__value modal__skills" id="modal-skills"></div>
          </div>
          <div class="detail-item" style="grid-column: 1 / -1;">
            <span class="detail-item__label">Documents</span>
            <div class="detail-item__value" id="modal-documents">Loading documents…</div>
          </div>
          <div class="detail-item" style="grid-column: 1 / -1;">
            <span class="detail-item__label">Availability</span>
            <div class="detail-item__value" id="modal-availability">Loading availability…</div>
          </div>
        </div>
      </div>
      <div class="modal__footer">
        <button class="modal__button modal__button--ghost" type="button" id="modal-cancel">Close</button>
        <form class="modal__action" method="post" id="modal-approve-form">
          <input type="hidden" name="application_id" id="modal-approve-id" value="" />
          <input type="hidden" name="review_action" value="approve" />
          <button class="modal__button modal__button--approve" type="submit">Approve</button>
          <button id="resendEmailBtn" class="modal__button" type="button" style="margin-left:8px;background:#f3f4f6;color:#111;border:1px solid var(--color-border);">Resend Email</button>
        </form>
        <form class="modal__action" method="post" id="modal-reject-form">
          <input type="hidden" name="application_id" id="modal-reject-id" value="" />
          <input type="hidden" name="review_action" value="reject" />
          <button class="modal__button modal__button--reject" type="submit">Reject</button>
        </form>
      </div>
    </div>
  </div>

</div><!-- /.app -->

<!-- ============================================
     IMAGE LIGHTBOX
============================================= -->
<div class="image-lightbox" id="image-lightbox" aria-hidden="true" role="dialog" aria-label="Document preview">
  <div class="image-lightbox__panel" id="image-lightbox-panel">
    <button class="image-lightbox__close" id="image-lightbox-close" aria-label="Close image">&times;</button>
    <img id="image-lightbox-img" src="" alt="" />
  </div>
</div>

<script>
  (function () {
    'use strict';

    /* ---- Sidebar toggle (mobile/tablet) ---- */
    var toggle  = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');

    function openSidebar() {
      if (!sidebar || !overlay || !toggle) return;
      sidebar.classList.add('is-open');
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'true');
    }
    function closeSidebar() {
      if (!sidebar || !overlay || !toggle) return;
      sidebar.classList.remove('is-open');
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
      toggle.setAttribute('aria-expanded', 'false');
    }

    if (toggle && sidebar && overlay) {
      toggle.addEventListener('click', function () {
        sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
      });
      overlay.addEventListener('click', closeSidebar);
    }

    /* ---- Filter buttons ---- */
    var filterBtns = document.querySelectorAll('.filter-btn');
    var rows = document.querySelectorAll('#table-body tr');
    var activeStatusFilter = 'all';
    var programFilter = document.getElementById('program-filter');
    var searchInput = document.getElementById('search-input');

    function applyApplicationFilters() {
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

        applyApplicationFilters();
      });
    });

    /* ---- Live search ---- */
    if (searchInput) {
      searchInput.addEventListener('input', applyApplicationFilters);
    }
    if (programFilter) {
      programFilter.addEventListener('change', applyApplicationFilters);
    }

    /* ---- Image lightbox ---- */
    var lightbox      = document.getElementById('image-lightbox');
    var lightboxClose = document.getElementById('image-lightbox-close');
    var lightboxPanel = document.getElementById('image-lightbox-panel');
    var lightboxImg   = document.getElementById('image-lightbox-img');

    function showImageModal(src, alt) {
      if (!lightbox || !lightboxImg) return;
      lightboxImg.src = src;
      lightboxImg.alt = alt || '';
      lightboxImg.onerror = function () {
        lightboxImg.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="120"%3E%3Crect fill="%23f3f4f6" width="200" height="120"/%3E%3Ctext x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="13" fill="%236b7280"%3EImage not found%3C/text%3E%3C/svg%3E';
      };
      lightbox.classList.add('is-open');
      lightbox.setAttribute('aria-hidden', 'false');
    }

    function closeImageModal() {
      if (!lightbox || !lightboxImg) return;
      lightbox.classList.remove('is-open');
      lightbox.setAttribute('aria-hidden', 'true');
      lightboxImg.src = '';
      lightboxImg.alt = '';
    }

    if (lightboxClose) {
      lightboxClose.addEventListener('click', function (e) {
        e.stopPropagation();
        closeImageModal();
      });
    }
    if (lightbox) {
      lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox) closeImageModal();
      });
    }
    if (lightboxPanel) {
      lightboxPanel.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }

    /* ---- Applicant details modal ---- */
    var modal       = document.getElementById('application-modal');
    var modalClose  = document.getElementById('application-modal-close');
    var modalCancel = document.getElementById('modal-cancel');
    var modalButtons = document.querySelectorAll('.action-btn--view');
    var approveId   = document.getElementById('modal-approve-id');
    var rejectId    = document.getElementById('modal-reject-id');

    var modalFields = {
      name:        document.getElementById('modal-name'),
      studentId:   document.getElementById('modal-student-id'),
      email:       document.getElementById('modal-email'),
      program:     document.getElementById('modal-program'),
      office:      document.getElementById('modal-office'),
      status:      document.getElementById('modal-status'),
      submittedAt: document.getElementById('modal-submitted-at'),
      skills:      document.getElementById('modal-skills')
    };

    function setSkills(value) {
      modalFields.skills.innerHTML = '';
      var items = (value || '').split(',').map(function (i) { return i.trim(); }).filter(Boolean);
      if (items.length === 0) { items = ['No skills listed']; }
      items.forEach(function (item) {
        var span = document.createElement('span');
        span.className = 'skill-tag';
        span.textContent = item;
        modalFields.skills.appendChild(span);
      });
    }

    function renderDocuments(docs) {

  var container = document.getElementById('modal-documents');

  if (!container) return;

  container.innerHTML = '';

  if (!Array.isArray(docs) || docs.length === 0) {

    container.innerHTML = `
      <div style="
        padding:12px;
        border-radius:10px;
        background:#f9fafb;
        color:#6b7280;
        font-size:14px;
      ">
        No documents uploaded.
      </div>
    `;

    return;
  }

  docs.forEach(function (doc) {

    var wrapper = document.createElement('div');

    wrapper.style.background = '#f8fafc';
    wrapper.style.border = '1px solid #e5e7eb';
    wrapper.style.borderRadius = '14px';
    wrapper.style.padding = '16px';
    wrapper.style.marginBottom = '16px';

    /* ---------------- TITLE ---------------- */

    var title = document.createElement('div');

    title.style.fontSize = '14px';
    title.style.fontWeight = '700';
    title.style.marginBottom = '12px';
    title.style.color = '#111827';

    title.textContent =
      (doc.document_type || 'Document') +
      ' • ' +
      (doc.original_filename || 'file');

    wrapper.appendChild(title);

    /* ---------------- FILE ---------------- */

    var mime = (doc.mime_type || '').toLowerCase();

    var filePath = '';

    if (doc.file_path) {
      filePath = '../' + doc.file_path;
    }

    /* ---------------- IMAGE ---------------- */

    if (mime.startsWith('image/') && filePath) {

      var image = document.createElement('img');

      image.src = filePath;

      image.alt =
        doc.original_filename || 'Uploaded Document';

      image.style.width = '160px';
      image.style.height = '160px';
      image.style.objectFit = 'cover';
      image.style.borderRadius = '12px';
      image.style.border = '1px solid #d1d5db';
      image.style.cursor = 'pointer';
      image.style.transition = '0.2s ease';
      image.style.display = 'block';
      image.style.background = '#ffffff';

      image.addEventListener('mouseover', function () {
        image.style.transform = 'scale(1.03)';
      });

      image.addEventListener('mouseout', function () {
        image.style.transform = 'scale(1)';
      });

      image.addEventListener('click', function (e) {

        e.preventDefault();
        e.stopPropagation();

        var lightbox =
          document.getElementById('image-lightbox');

        var lightboxImg =
          document.getElementById('image-lightbox-img');

        if (!lightbox || !lightboxImg) return;

        lightboxImg.src = filePath;

        lightboxImg.alt =
          doc.original_filename || 'Preview';

        lightbox.classList.add('is-open');

        lightbox.setAttribute(
          'aria-hidden',
          'false'
        );
      });

      image.onerror = function () {

        image.src =
          'https://via.placeholder.com/160x160?text=Image+Not+Found';
      };

      wrapper.appendChild(image);

    }

    /* ---------------- PDF / FILE ---------------- */

    else if (filePath) {

      var fileLink = document.createElement('a');

      fileLink.href = filePath;

      fileLink.target = '_blank';

      fileLink.textContent =
        '📄 Open ' +
        (doc.original_filename || 'Document');

      fileLink.style.display = 'inline-block';
      fileLink.style.padding = '10px 14px';
      fileLink.style.background = '#2563eb';
      fileLink.style.color = '#ffffff';
      fileLink.style.borderRadius = '10px';
      fileLink.style.fontSize = '14px';
      fileLink.style.fontWeight = '600';
      fileLink.style.textDecoration = 'none';

      wrapper.appendChild(fileLink);

    }

    /* ---------------- NO FILE ---------------- */

    else {

      var unavailable = document.createElement('div');

      unavailable.textContent =
        'File not available.';

      unavailable.style.color = '#dc2626';

      wrapper.appendChild(unavailable);
    }

    container.appendChild(wrapper);
  });
}

    function formatTime12Hour(timeStr) {
      if (!timeStr) return '';
      var time = timeStr.substr(0, 5); // Get HH:MM
      var parts = time.split(':');
      var hours = parseInt(parts[0], 10);
      var minutes = parts[1] || '00';
      var ampm = hours >= 12 ? 'PM' : 'AM';
      var displayHours = hours > 12 ? hours - 12 : (hours === 0 ? 12 : hours);
      return (displayHours < 10 ? '0' : '') + displayHours + ':' + minutes + ' ' + ampm;
    }

    function renderAvailability(list) {
      var container = document.getElementById('modal-availability');
      if (!container) return;
      container.innerHTML = '';

      if (!Array.isArray(list) || list.length === 0) {
        container.textContent = 'No availability provided.';
        return;
      }

      var ul = document.createElement('ul');
      ul.style.cssText = 'list-style:none;padding:0;margin:0;';
      list.forEach(function (row) {
        var li = document.createElement('li');
        li.style.padding = '8px 0';
        li.style.borderBottom = '1px solid #e5e7eb';
        var start = formatTime12Hour(row.time_start || row.start_time || '');
        var end = formatTime12Hour(row.time_end || row.end_time || '');
        var note = row.notes === null || row.notes === undefined ? '' : String(row.notes).trim();
        var type = note !== '' || String(row.is_available) === '0' ? 'Personal Reason' : 'Duty Hours';

        var heading = document.createElement('div');
        heading.style.cssText = 'font-weight:700;color:#1e3a8a;';
        heading.textContent = (row.day_of_week || '') + ': ' + start + ' — ' + end;
        li.appendChild(heading);

        var typeLabel = document.createElement('span');
        typeLabel.style.cssText = 'display:inline-block;margin-top:4px;padding:3px 8px;border-radius:999px;background:' + (note !== '' ? '#fef3c7;color:#92400e;' : '#dcfce7;color:#166534;') + 'font-size:11px;font-weight:800;text-transform:uppercase;';
        typeLabel.textContent = type;
        li.appendChild(typeLabel);

        if (note !== '') {
          var noteText = document.createElement('div');
          noteText.style.cssText = 'margin-top:6px;color:#475569;font-size:12px;line-height:1.45;white-space:pre-wrap;';
          noteText.textContent = 'Note: ' + note;
          li.appendChild(noteText);
        }
        ul.appendChild(li);
      });
      container.appendChild(ul);
    }

    function openModal(button) {
      modalFields.name.textContent        = button.getAttribute('data-name') || '';
      modalFields.studentId.textContent   = button.getAttribute('data-student-id') || '';
      modalFields.email.textContent       = button.getAttribute('data-email') || '';
      modalFields.program.textContent     = ((button.getAttribute('data-program') || '') + ' • ' + (button.getAttribute('data-year-level') || '')).trim();
      modalFields.office.textContent      = button.getAttribute('data-office') || '';
      modalFields.status.textContent      = button.getAttribute('data-status') || '';
      modalFields.submittedAt.textContent = button.getAttribute('data-submitted-at') || '';
      setSkills(button.getAttribute('data-skills') || '');

      var appId = button.getAttribute('data-application-id') || '';
      approveId.value = appId;
      rejectId.value  = appId;

      document.getElementById('modal-documents').innerHTML  = 'Loading documents…';
      document.getElementById('modal-availability').innerHTML = 'Loading availability…';

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');

      if (appId) {
        fetch('application_detail.php?application_id=' + encodeURIComponent(appId), { credentials: 'same-origin' })
          .then(function (res) {
            if (!res.ok) throw new Error('Failed to load details');
            return res.json();
          })
          .then(function (json) {
            renderDocuments(json.documents || []);
            renderAvailability(json.availability || []);
          })
          .catch(function () {
            document.getElementById('modal-documents').textContent  = 'Unable to load documents.';
            document.getElementById('modal-availability').textContent = 'Unable to load availability.';
          });
      }
    }

    function closeModal() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }

    modalButtons.forEach(function (btn) {
      btn.addEventListener('click', function () { openModal(btn); });
    });

    modalClose.addEventListener('click', closeModal);
    modalCancel.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });

    /* ---- Global Escape key handler ---- */
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (lightbox && lightbox.classList.contains('is-open')) {
        closeImageModal();
      } else if (modal.classList.contains('is-open')) {
        closeModal();
      }
    });

    // Resend approval email button handler
    var resendBtn = document.getElementById('resendEmailBtn');
    if (resendBtn) {
      resendBtn.addEventListener('click', function () {
        var id = parseInt(document.getElementById('modal-approve-id').value || '0', 10);
        if (!id) return alert('No application selected');
        resendBtn.disabled = true;
        fetch('resend_application_email.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ application_id: id })
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.success) {
            alert('Email resent');
          } else {
            alert('Failed to resend: ' + (d && d.message ? d.message : 'Unknown'));
          }
        }).catch(function (e) { console.error(e); alert('Request failed'); }).finally(function () { resendBtn.disabled = false; });
      });
    }

  }());
</script>

<script src="../assets/js/admin-notifications.js?v=20260922"></script>

</body>
</html>