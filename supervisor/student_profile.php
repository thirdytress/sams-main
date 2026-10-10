<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$applicationId = (int) ($_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    header('Location: students.php');
    exit;
}

$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = trim((string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? '')));

$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);

$studentStmt = $pdo->prepare(
    'SELECT
        a.application_id,
        a.preferred_office,
        a.term_id,
        s.student_id,
        s.student_id_number,
        s.program,
        s.year_level,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_image
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.application_id = :application_id
       AND EXISTS (
            SELECT 1
            FROM duty_schedules ds
            WHERE ds.application_id = a.application_id
              AND ds.status IN ("assigned", "pending", "accepted", "deployed")
                            AND (ds.office_name = :office_ds OR a.preferred_office = :office_app)
                            AND (:term_id_guard = 0 OR ds.term_id = :term_id_ds)
       )
     LIMIT 1'
);
$studentStmt->execute([
    'application_id' => $applicationId,
        'office_ds' => $supervisorOffice,
        'office_app' => $supervisorOffice,
        'term_id_guard' => $activeTermId,
        'term_id_ds' => $activeTermId,
]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$student) {
    header('Location: students.php');
    exit;
}

$schedulesStmt = $pdo->prepare(
    'SELECT ds.duty_id, ds.day_of_week, ds.start_time, ds.end_time, ds.status, COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     WHERE ds.application_id = :application_id
    AND ds.status IN ("assigned", "pending", "accepted", "deployed")
             AND (ds.office_name = :office_ds OR a.preferred_office = :office_app)
             AND (:term_id_guard = 0 OR ds.term_id = :term_id_ds)
     ORDER BY FIELD(ds.day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"), ds.start_time ASC'
);
$schedulesStmt->execute([
    'application_id' => $applicationId,
        'office_ds' => $supervisorOffice,
        'office_app' => $supervisorOffice,
        'term_id_guard' => $activeTermId,
        'term_id_ds' => $activeTermId,
]);
$schedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Group schedules by day of the week
$groupedSchedules = [];
$daysOrder = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
foreach ($daysOrder as $d) {
    $groupedSchedules[$d] = [
        'morning' => null,
        'afternoon' => null,
        'offices' => [],
        'statuses' => []
    ];
}

foreach ($schedules as $sch) {
    $day = $sch['day_of_week'] ?? '';
    if (!isset($groupedSchedules[$day])) {
        $groupedSchedules[$day] = [
            'morning' => null,
            'afternoon' => null,
            'offices' => [],
            'statuses' => []
        ];
    }
    
    $startTime = $sch['start_time'] ?? '';
    $startHour = 12;
    if ($startTime !== '') {
        $parts = explode(':', $startTime);
        $startHour = (int)$parts[0];
    }
    
    if ($startHour < 12) {
        $groupedSchedules[$day]['morning'] = $sch;
    } else {
        $groupedSchedules[$day]['afternoon'] = $sch;
    }
    
    $office = trim((string)($sch['office_name'] ?? 'Unassigned'));
    if ($office !== '') {
        $groupedSchedules[$day]['offices'][$office] = true;
    }
    
    $status = trim((string)($sch['status'] ?? 'accepted'));
    if ($status !== '') {
        $groupedSchedules[$day]['statuses'][$status] = true;
    }
}

foreach ($groupedSchedules as $day => $data) {
    if (empty($data['morning']) && empty($data['afternoon'])) {
        unset($groupedSchedules[$day]);
    }
}

if (!function_exists('formatTimeRange')) {
    function formatTimeRange(?string $start, ?string $end): string {
        if (!$start || !$end) return '—';
        $startFormatted = date("h:i A", strtotime($start));
        $endFormatted = date("h:i A", strtotime($end));
        return $startFormatted . ' - ' . $endFormatted;
    }
}

$allowedDutyIds = [];
$resolvedOfficeSet = [];
foreach ($schedules as $schedule) {
    $dutyId = (int) ($schedule['duty_id'] ?? 0);
    if ($dutyId > 0) {
        $allowedDutyIds[$dutyId] = true;
    }

    $officeName = trim((string) ($schedule['office_name'] ?? ''));
    if ($officeName !== '') {
        $resolvedOfficeSet[$officeName] = true;
    }
}

$resolvedOfficeLabel = !empty($resolvedOfficeSet)
    ? implode(', ', array_keys($resolvedOfficeSet))
    : ($supervisorOffice !== '' ? $supervisorOffice : ((string) ($student['preferred_office'] ?? 'Unassigned')));

$summary = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'total' => 0, 'hours' => 0.0];

$normalizedLogsAll = sams_attendance_normalize_student_logs($pdo, $applicationId, $activeTermId > 0 ? $activeTermId : null);
$normalizedLogs = array_values(array_filter(
    $normalizedLogsAll,
    static function (array $log) use ($allowedDutyIds): bool {
        $dutyId = (int) ($log['duty_id'] ?? 0);
        return $dutyId > 0 && isset($allowedDutyIds[$dutyId]);
    }
));
$attendanceRows = [];
$absentSchedules = [];
foreach (array_slice($normalizedLogs, 0, 50) as $log) {
    $status = sams_attendance_display_status((string) ($log['status'] ?? 'absent'));
    if ($status === 'present' || $status === 'completed') {
        $summary['present']++;
    } elseif ($status === 'late') {
        $summary['late']++;
    } elseif ($status === 'excused') {
        $summary['excused']++;
    } else {
        $summary['absent']++;
    }
    $summary['total']++;

    $timeIn = (string) ($log['time_in'] ?? '-');
    $timeOut = (string) ($log['time_out'] ?? '-');
    if (trim($timeIn) === '') {
        $timeIn = '-';
    }
    if (trim($timeOut) === '') {
        $timeOut = '-';
    }

    $inTs = $timeIn !== '-' ? strtotime($timeIn) : false;
    $outTs = $timeOut !== '-' ? strtotime($timeOut) : false;
    if ($inTs && $outTs && $outTs > $inTs) {
        $summary['hours'] += ($outTs - $inTs) / 3600;
    }

    $attendanceRows[] = [
        'created_at' => (string) ($log['created_at'] ?? ''),
        'clock_in_time' => $timeIn,
        'clock_out_time' => $timeOut,
        'status' => $status,
        'late_minutes' => (int) ($log['late_minutes'] ?? 0),
    ];

    if (!empty($log['__derived_absent'])) {
        $absentSchedules[] = [
            'duty_id' => (int) ($log['duty_id'] ?? 0),
            'day_of_week' => (string) ($log['day_of_week'] ?? ''),
            'start_time' => (string) ($log['start_time'] ?? ''),
            'end_time' => (string) ($log['end_time'] ?? ''),
            'office_name' => (string) ($log['office_name'] ?? 'Unassigned'),
        ];
    }
}

$summary['absent'] = count($absentSchedules);
$summary['total'] = $summary['present'] + $summary['late'] + $summary['excused'] + $summary['absent'];

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$studentName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Profile | Supervisor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Inter,Arial,sans-serif;background:var(--color-bg-app);color:var(--color-heading)}
        .wrap{max-width:1200px;margin:24px auto;padding:0 16px;display:flex;flex-direction:column;gap:16px}
        .card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius);padding:18px}
        .title{font-size:28px;font-weight:800}
        .sub{margin-top:6px;color:var(--color-body)}
        .grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
        .tile{background:#fff;border:1px solid var(--color-border);border-radius:12px;padding:14px}
        .tile strong{display:block;font-size:26px;line-height:1.1}
        .tile span{display:block;font-size:13px;color:var(--color-body);margin-top:4px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px 12px;border-bottom:1px solid var(--color-border);font-size:14px;text-align:left}
        th{background:#f9fafb;font-size:13px}
        .btn{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 12px;border-radius:10px;background:#155dfc;color:#fff;text-decoration:none;font-weight:700}
        .muted{color:var(--color-body)}
        .badge{display:inline-flex;align-items:center;height:22px;padding:0 10px;border-radius:9999px;font-size:12px;font-weight:700}
        .badge--present{background:#dcfce7;color:#008236}
        .badge--late{background:#dbeafe;color:#1447e6}
        .badge--excused{background:#fef3c7;color:#a16207}
        .badge--absent{background:#f3f4f6;color:#4a5565}
        .live-pill{display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:9999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:700}
        @media (max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width:560px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="flex-between flex-row" style="align-items:center;gap:16px;">
            <div style="display:flex;align-items:center;gap:16px;">
                <?php $studentAvatarUrl = sams_user_avatar_url($student['profile_image'] ?? null, '../'); ?>
                <?php if ($studentAvatarUrl): ?>
                    <img src="<?php echo h($studentAvatarUrl); ?>" alt="<?php echo h($studentName); ?>" style="width:58px;height:58px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
                <?php else: ?>
                    <div style="width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg, #003087, #155dfc);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;flex-shrink:0;">
                        <?php echo h(sams_admin_dashboard_initials((string)($student['first_name'] ?? ''), (string)($student['last_name'] ?? ''))); ?>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="title"><?php echo h($studentName); ?></div>
                    <div class="sub">Student ID: <?php echo h((string) ($student['student_id_number'] ?? '')); ?> · Program: <?php echo h((string) ($student['program'] ?? '-')); ?> · Office: <?php echo h($resolvedOfficeLabel); ?></div>
                </div>
            </div>
            <div class="flex-row" style="gap:8px;">
                <span class="live-pill">Live</span>
                <a class="btn" href="students.php">Back to Students</a>
                <a class="btn" href="reports.php">Open Reports</a>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="tile"><strong id="sum-hours"><?php echo number_format((float) $summary['hours'], 1); ?>h</strong><span>Rendered Hours</span></div>
        <div class="tile"><strong id="sum-present"><?php echo (int) $summary['present']; ?></strong><span>Present</span></div>
        <div class="tile"><strong id="sum-late"><?php echo (int) $summary['late']; ?></strong><span>Late</span></div>
        <div class="tile"><strong id="sum-excused"><?php echo (int) $summary['excused']; ?></strong><span>Excused</span></div>
        <div class="tile"><strong id="sum-absent"><?php echo (int) $summary['absent']; ?></strong><span>Absent</span></div>
    </div>

    <div class="card">
        <h2 class="heading-sm">Accepted Schedules</h2>
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Morning Shift (AM)</th>
                    <th>Afternoon Shift (PM)</th>
                    <th>Office</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($groupedSchedules)): ?>
                    <?php foreach ($groupedSchedules as $dayName => $data): ?>
                        <?php
                            $officeText = implode(', ', array_keys($data['offices']));
                            if ($officeText === '') {
                                $officeText = 'Unassigned';
                            }
                            $statusText = implode(', ', array_keys($data['statuses']));
                            if ($statusText === '') {
                                $statusText = 'accepted';
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo h($dayName); ?></strong></td>
                            <td>
                                <?php if ($data['morning']): ?>
                                    <span style="font-weight: 500; color: #1e293b;"><?php echo h(formatTimeRange($data['morning']['start_time'], $data['morning']['end_time'])); ?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($data['afternoon']): ?>
                                    <span style="font-weight: 500; color: #1e293b;"><?php echo h(formatTimeRange($data['afternoon']['start_time'], $data['afternoon']['end_time'])); ?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($officeText); ?></td>
                            <td><span class="badge badge--present"><?php echo h(ucfirst($statusText)); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="muted">No deployed schedules found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 class="heading-sm">Absent Schedules (No Log)</h2>
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Office</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="absent-body">
                <?php if (!empty($absentSchedules)): ?>
                    <?php foreach ($absentSchedules as $as): ?>
                        <tr>
                            <td><?php echo h((string) ($as['day_of_week'] ?? '')); ?></td>
                            <td><?php echo h((string) ($as['start_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($as['end_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($as['office_name'] ?? 'Unassigned')); ?></td>
                            <td><button class="btn" type="button" data-duty="<?php echo (int) ($as['duty_id'] ?? 0); ?>">Mark Excused</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="muted">No absent schedules detected.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 class="heading-sm">Recent Attendance Logs</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Status</th>
                    <th>Late (min)</th>
                </tr>
            </thead>
            <tbody id="attendance-body">
                <?php if (!empty($attendanceRows)): ?>
                    <?php foreach ($attendanceRows as $log): ?>
                        <?php
                            $status = sams_attendance_display_status((string) ($log['status'] ?? ''));
                            $badgeClass = $status === 'late' ? 'badge--late' : (($status === 'present' || $status === 'completed') ? 'badge--present' : (($status === 'excused') ? 'badge--excused' : 'badge--absent'));
                            $statusLabel = $status === 'late' ? 'Late' : (($status === 'present' || $status === 'completed') ? 'Present' : (($status === 'excused') ? 'Excused' : 'Absent'));
                        ?>
                        <tr>
                            <td><?php echo h((string) ($log['created_at'] ?? '')); ?></td>
                            <td><?php echo h((string) ($log['clock_in_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($log['clock_out_time'] ?? '-')); ?></td>
                            <td><span class="badge <?php echo h($badgeClass); ?>"><?php echo h($statusLabel); ?></span></td>
                            <td><?php echo (int) ($log['late_minutes'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="muted">No attendance logs found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    'use strict';

    var appId = <?php echo (int) $applicationId; ?>;
    var sumHours = document.getElementById('sum-hours');
    var sumPresent = document.getElementById('sum-present');
    var sumLate = document.getElementById('sum-late');
    var sumExcused = document.getElementById('sum-excused');
    var sumAbsent = document.getElementById('sum-absent');
    var attendanceBody = document.getElementById('attendance-body');
    var absentBody = document.getElementById('absent-body');
    var csrfToken = <?php echo json_encode(sams_csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function esc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderRows(rows) {
        if (!rows || rows.length === 0) {
            attendanceBody.innerHTML = '<tr><td colspan="5" class="muted">No attendance logs found.</td></tr>';
            return;
        }

        var html = rows.map(function (row) {
            return '<tr>' +
                '<td>' + esc(row.created_at || '') + '</td>' +
                '<td>' + esc(row.clock_in_time || '-') + '</td>' +
                '<td>' + esc(row.clock_out_time || '-') + '</td>' +
                '<td><span class="badge ' + esc(row.badge_class || 'badge--absent') + '">' + esc(row.status_label || 'Absent') + '</span></td>' +
                '<td>' + esc(row.late_minutes || 0) + '</td>' +
                '</tr>';
        }).join('');

        attendanceBody.innerHTML = html;
    }

    function applyPayload(data) {
        if (!data || !data.summary) return;
        sumHours.textContent = Number(data.summary.hours || 0).toFixed(1) + 'h';
        sumPresent.textContent = data.summary.present || 0;
        sumLate.textContent = data.summary.late || 0;
        if (sumExcused) {
            sumExcused.textContent = data.summary.excused || 0;
        }
        sumAbsent.textContent = data.summary.absent || 0;
        renderRows(data.attendance_rows || []);
        renderAbsent(data.absent_schedules || []);
    }

    function renderAbsent(rows) {
        var body = absentBody;
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="muted">No absent schedules detected.</td></tr>';
            return;
        }

        var html = rows.map(function (r) {
            return '<tr>' +
                '<td>' + esc(r.day_of_week || '') + '</td>' +
                '<td>' + esc(r.start_time || '-') + '</td>' +
                '<td>' + esc(r.end_time || '-') + '</td>' +
                '<td>' + esc(r.office_name || 'Unassigned') + '</td>' +
                '<td><button class="btn" type="button" data-duty="' + (r.duty_id || 0) + '">Mark Excused</button></td>' +
                '</tr>';
        }).join('');

        body.innerHTML = html;
    }

    if (absentBody) {
        absentBody.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('button[data-duty]') : null;
            if (!button) return;

            var dutyId = parseInt(button.getAttribute('data-duty') || '0', 10);
            if (!dutyId) return;

            if (!confirm('Mark this schedule as excused?')) {
                return;
            }

            button.disabled = true;
            fetch('mark_excused.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    application_id: appId,
                    duty_id: dutyId,
                    _csrf: csrfToken
                })
            })
            .then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            })
            .then(function (payload) {
                if (!payload.ok || !payload.data || !payload.data.success) {
                    throw new Error((payload.data && payload.data.message) ? payload.data.message : 'Unable to mark excused');
                }

                poll();
            })
            .catch(function (error) {
                alert(error && error.message ? error.message : 'Unable to mark excused');
            })
            .finally(function () {
                button.disabled = false;
            });
        });
    }
    function poll() {
        fetch('student_profile_data.php?application_id=' + encodeURIComponent(appId), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('Network');
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success) return;
                applyPayload(data);
            })
            .catch(function () {
                // keep UI as-is on transient failures
            });
    }

    setInterval(poll, 5000);
})();
</script>
</body>
</html>
