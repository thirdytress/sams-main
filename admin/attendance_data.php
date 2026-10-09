<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = sams_authenticated_user();
    if (!$user || (($user['role'] ?? null) !== 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $pdo = sams_pdo();
    $period = trim((string) ($_GET['period'] ?? 'today'));
    if (!in_array($period, ['today', 'yesterday', 'week', 'month'], true)) {
        $period = 'today';
    }

    $office = trim((string) ($_GET['office'] ?? 'all'));
    $allowedOffices = array_merge(sams_office_options(), ['Unassigned']);
    if ($office !== 'all' && !in_array($office, $allowedOffices, true)) {
        $office = 'all';
    }

    $today = new DateTimeImmutable('today');
    $rangeEnd = $today;
    $rangeStart = match ($period) {
        'yesterday' => $today->modify('-1 day'),
        'week' => $today->modify('-' . ((int) $today->format('N') - 1) . ' days'),
        'month' => $today->modify('first day of this month'),
        default => $today,
    };
    if ($period === 'yesterday') {
        $rangeEnd = $rangeStart;
    }

    $dateQueries = [];
    $queryParams = [];
    $dateIndex = 0;
    for ($date = $rangeStart; $date <= $rangeEnd; $date = $date->modify('+1 day')) {
        $dateKey = 'range_date_' . $dateIndex;
        $dayKey = 'range_day_' . $dateIndex;
        $dateQueries[] = 'SELECT :' . $dateKey . ' AS attendance_date, :' . $dayKey . ' AS day_of_week';
        $queryParams[$dateKey] = $date->format('Y-m-d');
        $queryParams[$dayKey] = $date->format('l');
        $dateIndex++;
    }

    $activeTerm = sams_current_term($pdo);
    $activeTermId = (int) ($activeTerm['term_id'] ?? 0);
    $queryParams['term_id'] = $activeTermId;
    $attendanceSql =
        'SELECT u.first_name, u.last_name, s.student_id_number AS student_code,
                COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name,
                al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes,
                ds.start_time, date_range.attendance_date,
                de.excuse_id, de.excuse_type
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         CROSS JOIN (' . implode(' UNION ALL ', $dateQueries) . ') AS date_range
         LEFT JOIN duty_excuses de ON (de.application_id = ds.application_id AND de.duty_date = date_range.attendance_date)
         LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT al2.log_id
             FROM attendance_logs al2
             WHERE al2.application_id = ds.application_id
               AND al2.duty_id = ds.duty_id
               AND DATE(al2.created_at) = date_range.attendance_date
             ORDER BY al2.log_id DESC
             LIMIT 1
         )
         WHERE ds.day_of_week = date_range.day_of_week
           AND ds.status = "deployed"
           AND ds.term_id = :term_id';
    if ($office !== 'all') {
        $attendanceSql .= ' AND COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") = :office';
        $queryParams['office'] = $office;
    }
    $attendanceSql .= ' ORDER BY date_range.attendance_date DESC, ds.start_time ASC';
    $attendanceStmt = $pdo->prepare($attendanceSql);
    $attendanceStmt->execute($queryParams);
    $attendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $activeNow = 0;
    $recordedCount = 0;
    $totalSchedules = count($attendanceRows);
    $totalSeconds = 0;
    $officeSummary = [];

    foreach ($attendanceRows as &$attendanceRow) {
        $status = sams_attendance_display_status((string) ($attendanceRow['status'] ?? ''));
        if ($status === '') {
            $status = !empty($attendanceRow['excuse_id']) ? 'excused' : 'absent';
        }

        $timeInRaw = !empty($attendanceRow['time_in']) ? (string) $attendanceRow['time_in'] : null;
        $timeOutRaw = !empty($attendanceRow['time_out']) ? (string) $attendanceRow['time_out'] : null;
        if (!sams_attendance_clocking_enabled()) {
            $timeInRaw = null;
            $timeOutRaw = null;
            if (empty($attendanceRow['excuse_id'])) {
                $status = 'absent';
            }
        }

        if ($timeInRaw !== null) {
            $recordedCount++;
            if ($timeOutRaw === null) {
                $activeNow++;
            }
        }
        if ($timeInRaw !== null && $timeOutRaw !== null) {
            $startTimestamp = strtotime($timeInRaw);
            $endTimestamp = strtotime($timeOutRaw);
            if ($startTimestamp !== false && $endTimestamp !== false && $endTimestamp > $startTimestamp) {
                $totalSeconds += $endTimestamp - $startTimestamp;
            }
        }

        $officeName = (string) ($attendanceRow['office_name'] ?? 'Unassigned');
        if (!isset($officeSummary[$officeName])) {
            $officeSummary[$officeName] = ['name' => $officeName, 'count' => 0, 'active' => 0];
        }
        $officeSummary[$officeName]['count']++;
        if ($timeInRaw !== null && $timeOutRaw === null) {
            $officeSummary[$officeName]['active']++;
        }

        $attendanceRow['date_label'] = date('D, M j, Y', strtotime((string) $attendanceRow['attendance_date']));
        $attendanceRow['duration'] = sams_attendance_duration_label($timeInRaw, $timeOutRaw);
        $attendanceRow['time_in'] = $timeInRaw ? date('g:i A', strtotime($timeInRaw)) : '-';
        $attendanceRow['time_out'] = $timeOutRaw ? date('g:i A', strtotime($timeOutRaw)) : ($timeInRaw ? 'In Progress' : '-');
        $attendanceRow['method'] = $timeInRaw !== null ? 'Live DB' : (!empty($attendanceRow['excuse_id']) ? 'Duty Excuse' : '-');
        $attendanceRow['status'] = match ($status) {
            'present', 'completed' => 'Present',
            'late' => 'Late',
            'excused' => 'Excused',
            'active' => 'In Progress',
            default => 'Absent',
        };
    }
    unset($attendanceRow);

    $offices = [];
    foreach ($officeSummary as $officeRow) {
        $name = (string) $officeRow['name'];
        $lower = strtolower($name);
        $color = str_contains($lower, 'sdao') ? 'blue' : (
            str_contains($lower, 'library') ? 'green' : (
                str_contains($lower, 'computer') ? 'purple' : (
                    str_contains($lower, 'registrar') ? 'orange' : 'grey'
                )
            )
        );
        $count = (int) $officeRow['count'];
        $offices[] = [
            'name' => $name,
            'count' => $count,
            'active' => (int) $officeRow['active'],
            'pct' => $totalSchedules > 0 ? (int) round(($count / $totalSchedules) * 100) : 0,
            'color' => $color,
        ];
    }

    $rangeLabel = $rangeStart == $rangeEnd
        ? $rangeStart->format('l, F j, Y')
        : $rangeStart->format('M j') . ' – ' . $rangeEnd->format('M j, Y');
    $attendanceRate = $totalSchedules > 0 ? (int) round(($recordedCount / $totalSchedules) * 100) : 0;

    echo json_encode([
        'success' => true,
        'generated_at' => date('c'),
        'period_label' => $rangeLabel,
        'metrics' => [
            'recorded_count' => $recordedCount,
            'attendance_rate' => $attendanceRate,
            'active_now' => $activeNow,
            'total_schedules' => $totalSchedules,
            'total_hours' => sams_attendance_format_duration($totalSeconds),
        ],
        'today_rows' => $attendanceRows,
        'offices' => $offices,
        'security' => [
            'verified' => $recordedCount,
            'accuracy' => $attendanceRate,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
