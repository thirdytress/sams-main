<?php
declare(strict_types=1);

function sams_attendance_clocking_enabled(): bool
{
    return true;
}

function sams_attendance_format_duration(int $seconds): string
{
    $totalMinutes = (int) round(max(0, $seconds) / 60);
    $hours = intdiv($totalMinutes, 60);
    $minutes = $totalMinutes % 60;

    if ($hours === 0) {
        return $minutes . ' min';
    }

    $hourLabel = $hours === 1 ? ' hr' : ' hrs';
    return $hours . $hourLabel . ($minutes > 0 ? ' ' . $minutes . ' min' : '');
}

function sams_attendance_duration_label(?string $timeIn, ?string $timeOut): string
{
    if (empty($timeIn) || empty($timeOut)) {
        return '-';
    }

    $start = strtotime($timeIn);
    $end = strtotime($timeOut);
    if ($start !== false && $end !== false && $end > $start) {
        return sams_attendance_format_duration($end - $start);
    }

    return '-';
}

function sams_attendance_month_summary(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT COALESCE(SUM(GREATEST(TIMESTAMPDIFF(SECOND, clock_in_time, COALESCE(clock_out_time, NOW())), 0)), 0) AS rendered_seconds,
                COUNT(DISTINCT DATE(created_at)) AS attendance_days
         FROM attendance_logs
         WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND created_at < DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
           AND clock_in_time IS NOT NULL"
    );
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $renderedSeconds = max(0, (int) ($row['rendered_seconds'] ?? 0));
    $attendanceDays = max(0, (int) ($row['attendance_days'] ?? 0));

    return [
        'rendered_seconds' => $renderedSeconds,
        'attendance_days' => $attendanceDays,
        'average_seconds_per_day' => $attendanceDays > 0
            ? (int) round($renderedSeconds / $attendanceDays)
            : 0,
    ];
}

function sams_current_term(PDO $pdo): array
{
    $termStatement = $pdo->query(
        'SELECT term_id, term_name, term_year
         FROM terms
         WHERE is_active = 1
         ORDER BY term_id DESC
         LIMIT 1'
    );
    $activeTerm = $termStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($activeTerm['term_id'])) {
        return $activeTerm;
    }

    $termStatement = $pdo->query(
        'SELECT term_id, term_name, term_year
         FROM terms
         WHERE start_date <= CURDATE()
           AND end_date >= CURDATE()
         ORDER BY term_id DESC
         LIMIT 1'
    );
    $activeTerm = $termStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($activeTerm['term_id'])) {
        return $activeTerm;
    }

    $termStatement = $pdo->query(
        'SELECT term_id, term_name, term_year
         FROM terms
         ORDER BY term_id DESC
         LIMIT 1'
    );

    return $termStatement->fetch(PDO::FETCH_ASSOC) ?: [];
}

function sams_attendance_display_status(string $status): string
{
    $canonical = sams_attendance_canonical_status($status);

    if ($canonical === 'excused') {
        return 'excused';
    }

    if (!sams_attendance_clocking_enabled()) {
        return 'absent';
    }

    return $canonical;
}

function sams_attendance_canonical_status(string $status): string
{
    return match (strtolower(trim($status))) {
        'present', 'active', 'completed' => 'present',
        'late' => 'late',
        'excused' => 'excused',
        'absent', 'incomplete' => 'absent',
        default => strtolower(trim($status)),
    };
}

function sams_attendance_status_priority(string $status): int
{
    return match (sams_attendance_canonical_status($status)) {
        'late' => 3,
        'present' => 2,
        'excused' => 1,
        'absent' => 1,
        default => 0,
    };
}

function sams_attendance_normalize_student_logs(PDO $pdo, int $applicationId, ?int $termId = null): array
{
    if ($applicationId <= 0) {
        return [];
    }

    $logsStmt = $pdo->prepare(
        'SELECT al.log_id, al.duty_id, al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, al.notes, al.created_at, ds.start_time AS scheduled_start
         FROM attendance_logs al
         LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
         WHERE al.application_id = :application_id
         ORDER BY al.created_at DESC, al.log_id DESC'
    );
    $logsStmt->execute(['application_id' => $applicationId]);
    $rawLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $records = [];
    foreach ($rawLogs as $log) {
        $dutyId = (int) ($log['duty_id'] ?? 0);
        $key = $dutyId > 0 ? 'duty:' . $dutyId : 'log:' . (int) ($log['log_id'] ?? 0);
        // derive lateness based on scheduled start when available
        $scheduledStart = isset($log['scheduled_start']) ? trim((string) $log['scheduled_start']) : '';
        $timeIn = isset($log['time_in']) ? trim((string) $log['time_in']) : '';

        if (strtolower(trim((string) ($log['status'] ?? ''))) === 'excused') {
            $log['status'] = 'excused';
        } elseif ($timeIn !== '' && $scheduledStart !== '') {
            $startTs = strtotime($scheduledStart);
            $inTs = strtotime($timeIn);
            if ($startTs && $inTs && $inTs > $startTs) {
                $computedLate = (int) floor(($inTs - $startTs) / 60);
                $log['late_minutes'] = $computedLate;
                // consider >=10 minutes as late
                $log['status'] = $computedLate >= 10 ? 'late' : 'present';
            } else {
                // default to whatever the log says or present
                $log['status'] = $log['status'] ?? 'present';
            }
        } else {
            $log['status'] = $log['status'] ?? 'present';
        }

        $log['status'] = sams_attendance_display_status((string) ($log['status'] ?? ''));
        $log['__priority'] = sams_attendance_status_priority((string) $log['status']);
        if (!sams_attendance_clocking_enabled()) {
            $log['time_in'] = null;
            $log['time_out'] = null;
        }

        if (!isset($records[$key])) {
            $records[$key] = $log;
            continue;
        }

        $currentPriority = (int) ($records[$key]['__priority'] ?? 0);
        $incomingPriority = (int) ($log['__priority'] ?? 0);
        $currentLogId = (int) ($records[$key]['log_id'] ?? 0);
        $incomingLogId = (int) ($log['log_id'] ?? 0);

        if ($incomingPriority > $currentPriority || ($incomingPriority === $currentPriority && $incomingLogId > $currentLogId)) {
            $records[$key] = $log;
        }
    }

    if ($termId !== null && $termId > 0) {
        $scheduleStmt = $pdo->prepare(
            'SELECT ds.duty_id, ds.day_of_week, ds.start_time, ds.end_time, ds.student_response_date, ds.created_at, ds.updated_at, COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name
             FROM duty_schedules ds
             INNER JOIN applications a ON a.application_id = ds.application_id
             WHERE ds.application_id = :application_id
                 AND ds.term_id = :term_id
                 AND ds.status = "deployed"
             ORDER BY FIELD(ds.day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"), ds.start_time ASC'
        );
        $scheduleStmt->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
        ]);
        $scheduleRows = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $termStmt = $pdo->prepare('SELECT start_date, end_date FROM terms WHERE term_id = :term_id LIMIT 1');
        $termStmt->execute(['term_id' => $termId]);
        $termRow = $termStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $termStartStr = !empty($termRow['start_date']) ? (string) $termRow['start_date'] : '';
        $termEndStr = !empty($termRow['end_date']) ? (string) $termRow['end_date'] : '';

        if ($termStartStr === '') {
            $termStartStr = date('Y-m-d', strtotime('monday this week'));
        }
        if ($termEndStr === '') {
            $termEndStr = date('Y-m-d', strtotime('sunday this week'));
        }

        $recordsByDutyAndDate = [];
        foreach ($rawLogs as $log) {
            $dId = (int) ($log['duty_id'] ?? 0);
            if ($dId > 0) {
                $timeRef = !empty($log['created_at']) ? (string) $log['created_at'] : (!empty($log['time_in']) ? (string) $log['time_in'] : '');
                if ($timeRef !== '') {
                    $lDate = date('Y-m-d', strtotime($timeRef));
                    $recordsByDutyAndDate[$dId][$lDate] = true;
                }
            }
        }

        $weekdayMap = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
        ];
        $today = new DateTimeImmutable('now');

        $excuseMap = [];
        try {
            $excuseStmt = $pdo->prepare(
                'SELECT duty_date, excuse_type, reason
                 FROM duty_excuses
                 WHERE application_id = :application_id'
            );
            $excuseStmt->execute(['application_id' => $applicationId]);
            foreach ($excuseStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
                $excuseMap[(string) $ex['duty_date']] = $ex;
            }
        } catch (Throwable $e) {
            $excuseMap = [];
        }

        foreach ($scheduleRows as $schedule) {
            $dutyId = (int) ($schedule['duty_id'] ?? 0);
            if ($dutyId <= 0) {
                continue;
            }

            $day = (string) ($schedule['day_of_week'] ?? '');
            if (!isset($weekdayMap[$day])) {
                continue;
            }

            // Skip derived-absent if the schedule was assigned/accepted/deployed after the scheduled end
            $assignmentTime = '';
            if (!empty($schedule['student_response_date'])) {
                $assignmentTime = trim((string) $schedule['student_response_date']);
            } elseif (!empty($schedule['updated_at'])) {
                $assignmentTime = trim((string) $schedule['updated_at']);
            } elseif (!empty($schedule['created_at'])) {
                $assignmentTime = trim((string) $schedule['created_at']);
            }

            $schedStartBound = $termStartStr;
            if ($assignmentTime !== '') {
                $assignedDate = date('Y-m-d', strtotime($assignmentTime));
                if ($assignedDate > $schedStartBound) {
                    $schedStartBound = $assignedDate;
                }
            }

            $currentIter = new DateTime((string) $schedStartBound);
            // endIter is always today — we track absences from assignment date up to now.
            // Do NOT cap by term end date: schedules are sometimes deployed after the term's
            // official end_date (e.g. the term record is stale), and capping there would
            // produce an impossible range (startBound > endIter) that generates zero records.
            $endIter = new DateTime('today');

            while ($currentIter <= $endIter) {
                if ($currentIter->format('l') === $day) {
                    break;
                }
                $currentIter->modify('+1 day');
            }

            while ($currentIter <= $endIter) {
                $dateStr = $currentIter->format('Y-m-d');
                $endTime = trim((string) ($schedule['end_time'] ?? ''));
                if ($endTime === '') {
                    $currentIter->modify('+7 days');
                    continue;
                }

                $scheduledEnd = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $dateStr . ' ' . $endTime
                );

                if (!($scheduledEnd instanceof DateTimeImmutable)) {
                    $currentIter->modify('+7 days');
                    continue;
                }

                if ($today < $scheduledEnd) {
                    $currentIter->modify('+7 days');
                    continue;
                }

                if (isset($recordsByDutyAndDate[$dutyId][$dateStr])) {
                    $currentIter->modify('+7 days');
                    continue;
                }

                $isExcused = isset($excuseMap[$dateStr]);
                $excuseInfo = $isExcused ? $excuseMap[$dateStr] : null;

                $records[] = [
                    'log_id' => null,
                    'duty_id' => $dutyId,
                    'day_of_week' => $day,
                    'start_time' => (string) ($schedule['start_time'] ?? ''),
                    'end_time' => (string) ($schedule['end_time'] ?? ''),
                    'office_name' => (string) ($schedule['office_name'] ?? 'Unassigned'),
                    'status' => $isExcused ? 'excused' : 'absent',
                    'time_in' => null,
                    'time_out' => null,
                    'late_minutes' => 0,
                    'notes' => $isExcused ? ('Excused: ' . ($excuseInfo['excuse_type'] ?? 'Valid reason')) : 'No clock-in by cutoff',
                    'created_at' => $dateStr . ' 00:00:00',
                    '__derived_absent' => !$isExcused,
                    '__priority' => 1,
                ];

                $currentIter->modify('+7 days');
            }
        }
    }

    usort($records, static function (array $left, array $right): int {
        $leftTime = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
        $rightTime = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
        if ($leftTime !== $rightTime) {
            return $rightTime <=> $leftTime;
        }

        return (int) ($right['log_id'] ?? 0) <=> (int) ($left['log_id'] ?? 0);
    });

    foreach ($records as &$record) {
        unset($record['__priority']);
    }
    unset($record);

    return array_values($records);
}