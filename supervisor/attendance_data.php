<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    // Allow CLI usage for tests (no session)
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    // When running from CLI, attempt to pick the first supervisor's office
    $pdoTemp = sams_pdo();
    $row = $pdoTemp->query('SELECT s.office_name FROM supervisors s LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $user = ['role' => 'supervisor', 'office_name' => (string) ($row['office_name'] ?? '')];
}

$pdo = sams_pdo();
$supervisorOffice = (string) ($user['office_name'] ?? '');
if (($user['role'] ?? null) === 'supervisor') {
    $officeStmt = $pdo->prepare(
        'SELECT s.office_name
         FROM supervisors s
         WHERE s.user_id = :user_id
         LIMIT 1'
    );
    $officeStmt->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
    $officeRow = $officeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $resolvedOffice = trim((string) ($officeRow['office_name'] ?? ''));
    if ($resolvedOffice !== '') {
        $supervisorOffice = $resolvedOffice;
    }
}
$currentDay = date('l');
$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);

// Fetch today's attendance rows for supervisor's office using a derived latest-log join (more efficient)
$stmt = $pdo->query(
    "SELECT u.first_name, u.last_name, s.student_id AS student_code, a.application_id AS application_id, ds.duty_id AS duty_id, COALESCE(NULLIF(TRIM(ds.office_name), ''), NULLIF(TRIM(a.preferred_office), ''), 'Unassigned') AS office_name,
            al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, ds.start_time, ds.end_time,
            de.excuse_id, de.excuse_type
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     LEFT JOIN students s ON s.student_id = a.student_id
     LEFT JOIN users u ON u.user_id = s.user_id
     LEFT JOIN duty_excuses de ON (de.application_id = a.application_id AND de.duty_date = CURDATE())
     LEFT JOIN (
         SELECT al1.* FROM attendance_logs al1
         INNER JOIN (
             SELECT application_id, duty_id, MAX(log_id) AS max_log_id
             FROM attendance_logs
             GROUP BY application_id, duty_id
         ) lm ON lm.max_log_id = al1.log_id
     ) al ON al.application_id = a.application_id AND al.duty_id = ds.duty_id
     WHERE ds.day_of_week = '" . $currentDay . "'
         AND ds.status = 'deployed'
         AND ds.term_id = " . $activeTermId . "
         AND COALESCE(NULLIF(TRIM(ds.office_name), ''), NULLIF(TRIM(a.preferred_office), ''), 'Unassigned') = " . $pdo->quote($supervisorOffice) . "
     ORDER BY ds.start_time ASC, al.log_id DESC"
);

$today_rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $status = sams_attendance_display_status((string) ($row['status'] ?? ''));
    if ($status === '') {
        $status = !empty($row['excuse_id']) ? 'excused' : 'absent';
    }
    $timeIn = sams_attendance_clocking_enabled() ? ($row['time_in'] ?? null) : null;
    $timeOut = sams_attendance_clocking_enabled() ? ($row['time_out'] ?? null) : null;

    $today_rows[] = [
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'application_id' => (int) ($row['application_id'] ?? 0),
        'duty_id' => (int) ($row['duty_id'] ?? 0),
        'office_name' => (string) ($row['office_name'] ?? '-'),
        'student_code' => (string) ($row['student_code'] ?? ''),
        'time_in' => $timeIn ? date('g:i A', strtotime((string) $timeIn)) : '-',
        'time_out' => $timeOut ? date('g:i A', strtotime((string) $timeOut)) : ($timeIn ? 'In Progress' : '-'),
        'duration' => sams_attendance_duration_label(
            $timeIn ? (string) $timeIn : null,
            $timeOut ? (string) $timeOut : null
        ),
        'duty_start' => !empty($row['start_time']) ? date('g:i A', strtotime((string) $row['start_time'])) : '-',
        'duty_end' => !empty($row['end_time']) ? date('g:i A', strtotime((string) $row['end_time'])) : '-',
        'status' => match ($status) {
            'present', 'completed' => 'Present',
            'late' => 'Late',
            'excused' => 'Excused',
            default => 'Absent',
        },
    ];
}

// compute metrics
$present = 0;
$late = 0;
$excused = 0;
$absent = 0;
foreach ($today_rows as $r) {
    $s = strtolower((string) ($r['status'] ?? ''));
    if ($s === 'present') $present++;
    elseif ($s === 'late') $late++;
    elseif ($s === 'excused') $excused++;
    else $absent++;
}
$total = $present + $late + $excused + $absent;

echo json_encode([
    'success' => true,
    'metrics' => [
        'present' => $present,
        'late' => $late,
        'excused' => $excused,
        'absent' => $absent,
        'total' => $total,
    ],
    'today_rows' => $today_rows,
]);
