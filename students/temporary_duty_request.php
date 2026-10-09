<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'student') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
$studentStmt = $pdo->prepare(
    'SELECT s.student_id, s.student_id_number, u.first_name, u.last_name
     FROM students s INNER JOIN users u ON u.user_id = s.user_id
     WHERE s.user_id = :user_id LIMIT 1'
);
$studentStmt->execute(['user_id' => $userId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$studentId = (int) ($student['student_id'] ?? 0);

$applicationStmt = $pdo->prepare(
    "SELECT a.application_id, a.term_id, COALESCE(NULLIF(TRIM(a.preferred_office), ''), 'STUDENT DEVELOPMENT AND ACTIVITIES OFFICE') AS office_name
     FROM applications a
     WHERE a.student_id = :student_id AND a.status IN ('approved', 'deployed')
     ORDER BY a.application_id DESC LIMIT 1"
);
$applicationStmt->execute(['student_id' => $studentId]);
$application = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(sams_csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid request token. Please refresh and try again.');
        }
        $applicationId = (int) ($application['application_id'] ?? 0);
        $termId = (int) ($application['term_id'] ?? 0);
        $dutyDate = trim((string) ($_POST['duty_date'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        // The temporary duty office is always inherited from the admin-assigned
        // application office; never trust a student-edited POST value.
        $office = trim((string) ($application['office_name'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $proof = $_FILES['proof'] ?? null;

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $dutyDate);
        if ($studentId <= 0 || $applicationId <= 0 || $termId <= 0) {
            throw new RuntimeException('You need an approved student assistant application before requesting temporary duty.');
        }
        if (!$date || $date->format('Y-m-d') !== $dutyDate || $dutyDate < date('Y-m-d')) {
            throw new RuntimeException('Choose a valid duty date today or later.');
        }
        $start = DateTimeImmutable::createFromFormat('H:i', $startTime);
        $end = DateTimeImmutable::createFromFormat('H:i', $endTime);
        if (!$start || !$end || $end <= $start) {
            throw new RuntimeException('Enter a valid duty time range.');
        }
        $requestedHours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        if ($requestedHours < 2) {
            throw new RuntimeException('Temporary duty request must be at least 2 hours.');
        }

        $requestedDay = $date->format('l');
        $classScheduleStmt = $pdo->prepare(
            'SELECT start_time, end_time
             FROM class_schedules
             WHERE application_id = :application_id
               AND term_id = :term_id
               AND day_of_week = :day_of_week
             ORDER BY start_time ASC'
        );
        $classScheduleStmt->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
            'day_of_week' => $requestedDay,
        ]);
        $classSchedules = $classScheduleStmt->fetchAll(PDO::FETCH_ASSOC);
        $firstClassStart = null;
        $lastClassEnd = null;
        foreach ($classSchedules as $classSchedule) {
            $classStart = DateTimeImmutable::createFromFormat(
                'H:i',
                substr((string) ($classSchedule['start_time'] ?? ''), 0, 5)
            );
            $classEnd = DateTimeImmutable::createFromFormat(
                'H:i',
                substr((string) ($classSchedule['end_time'] ?? ''), 0, 5)
            );
            if (!$classStart || !$classEnd || $classEnd <= $classStart) {
                continue;
            }
            if ($firstClassStart === null || $classStart < $firstClassStart) {
                $firstClassStart = $classStart;
            }
            if ($lastClassEnd === null || $classEnd > $lastClassEnd) {
                $lastClassEnd = $classEnd;
            }
        }
        if (
            $firstClassStart === null
            || $lastClassEnd === null
            || $start < $firstClassStart
            || $end > $lastClassEnd
        ) {
            throw new RuntimeException(
                'Temporary duty must be within your recorded class schedule range on ' . $requestedDay . '.'
            );
        }

        $startsDuringClass = false;
        $endsDuringClass = false;
        foreach ($classSchedules as $classSchedule) {
            $classStart = DateTimeImmutable::createFromFormat(
                'H:i',
                substr((string) ($classSchedule['start_time'] ?? ''), 0, 5)
            );
            $classEnd = DateTimeImmutable::createFromFormat(
                'H:i',
                substr((string) ($classSchedule['end_time'] ?? ''), 0, 5)
            );
            if (!$classStart || !$classEnd || $classEnd <= $classStart) {
                continue;
            }
            if ($start >= $classStart && $start < $classEnd) {
                $startsDuringClass = true;
            }
            if ($end > $classStart && $end <= $classEnd) {
                $endsDuringClass = true;
            }
        }
        if (!$startsDuringClass || !$endsDuringClass) {
            throw new RuntimeException(
                'The request must start and end within your recorded class schedules on ' . $requestedDay . '.'
            );
        }

        $dutyOverlapStmt = $pdo->prepare(
            "SELECT start_time, end_time
             FROM duty_schedules
             WHERE application_id = :application_id
               AND term_id = :term_id
               AND day_of_week = :day_of_week
               AND status IN ('assigned', 'pending', 'accepted', 'deployed')
               AND (scheduled_date IS NULL OR scheduled_date = :duty_date)
               AND start_time < :requested_end
               AND end_time > :requested_start
             LIMIT 1"
        );
        $dutyOverlapStmt->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
            'day_of_week' => $requestedDay,
            'duty_date' => $dutyDate,
            'requested_start' => $startTime . ':00',
            'requested_end' => $endTime . ':00',
        ]);
        $existingDuty = $dutyOverlapStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingDuty) {
            throw new RuntimeException(
                'You already have a duty schedule during this time on ' . $requestedDay
                . ' (' . substr((string) $existingDuty['start_time'], 0, 5)
                . ' - ' . substr((string) $existingDuty['end_time'], 0, 5) . ').'
            );
        }

        if ($reason === '' || strlen($reason) < 10) {
            throw new RuntimeException('Please provide a clear reason of at least 10 characters.');
        }
        if ($office === '') {
            throw new RuntimeException('Office is required.');
        }
        if (!$proof || (int) ($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload proof of the cancelled or vacant class.');
        }
        if ((int) $proof['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Proof file must not exceed 5 MB.');
        }
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $proof['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Proof must be a PDF, JPG, or PNG file.');
        }

        $duplicateStmt = $pdo->prepare(
            "SELECT request_id FROM temporary_duty_requests
             WHERE student_id = :student_id AND duty_date = :duty_date AND status = 'pending' LIMIT 1"
        );
        $duplicateStmt->execute(['student_id' => $studentId, 'duty_date' => $dutyDate]);
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('You already have a pending request for this date.');
        }

        $safeOriginal = basename((string) $proof['name']);
        $storedName = bin2hex(random_bytes(16)) . '.' . (strtolower($mime) === 'application/pdf' ? 'pdf' : (strtolower($mime) === 'image/png' ? 'png' : 'jpg'));
        $relativeDir = 'uploads/temporary_duty/student_' . $studentId;
        $absoluteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Unable to prepare the proof upload folder.');
        }
        if (!move_uploaded_file((string) $proof['tmp_name'], $absoluteDir . DIRECTORY_SEPARATOR . $storedName)) {
            throw new RuntimeException('Unable to save the proof file.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO temporary_duty_requests
             (application_id, student_id, term_id, duty_date, start_time, end_time, office_name, reason,
              proof_original_name, proof_stored_name, proof_path, proof_mime, proof_size)
             VALUES (:application_id, :student_id, :term_id, :duty_date, :start_time, :end_time, :office_name, :reason,
              :proof_original_name, :proof_stored_name, :proof_path, :proof_mime, :proof_size)'
        );
        $insert->execute([
            'application_id' => $applicationId,
            'student_id' => $studentId,
            'term_id' => $termId,
            'duty_date' => $dutyDate,
            'start_time' => $startTime . ':00',
            'end_time' => $endTime . ':00',
            'office_name' => $office,
            'reason' => substr($reason, 0, 5000),
            'proof_original_name' => $safeOriginal,
            'proof_stored_name' => $storedName,
            'proof_path' => $relativeDir . '/' . $storedName,
            'proof_mime' => $mime,
            'proof_size' => (int) $proof['size'],
        ]);
        $message = 'Temporary duty request submitted. Please wait for admin approval.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$recentStmt = $pdo->prepare(
    'SELECT duty_date, start_time, end_time, office_name, status, requested_at, review_note
     FROM temporary_duty_requests WHERE student_id = :student_id
     ORDER BY request_id DESC LIMIT 10'
);
$recentStmt->execute(['student_id' => $studentId]);
$requests = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Temporary Duty Request | SAMS</title>
    <link rel="stylesheet" href="../assets/css/sams-shell.css">
    <style>
        body { font-family: Inter, sans-serif; background:#f8fafc; color:#101828; }
        .page { max-width:900px; margin:0 auto; padding:32px 20px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; margin-bottom:20px; box-shadow:0 8px 20px rgba(16,24,40,.06); }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        label { display:grid; gap:6px; font-weight:700; font-size:13px; }
        input, textarea, select { width:100%; padding:11px 12px; border:1px solid #d0d5dd; border-radius:9px; font:inherit; }
        textarea { min-height:110px; resize:vertical; }
        .full { grid-column:1/-1; }
        .btn { border:0; border-radius:9px; padding:12px 18px; background:#003087; color:#fff; font-weight:800; cursor:pointer; }
        .notice { padding:12px 14px; border-radius:9px; margin-bottom:16px; }
        .success { background:#dcfce7; color:#166534; } .error { background:#fee2e2; color:#991b1b; }
        table { width:100%; border-collapse:collapse; } th,td { text-align:left; padding:10px 8px; border-bottom:1px solid #e5e7eb; font-size:13px; }
        .status { font-weight:800; text-transform:capitalize; }
        @media (max-width:650px) { .grid { grid-template-columns:1fr; } .full { grid-column:auto; } }
    </style>
</head>
<body>
<main class="page">
    <p><a href="dashboard.php">← Back to dashboard</a></p>
    <div class="card">
        <h1>Temporary Duty Request</h1>
        <p>If your class is cancelled or vacant, submit proof and request a temporary duty shift. The request must be at least 2 hours, may combine multiple class schedules on the same day including the gaps between them, and must not overlap an existing duty schedule.</p>
        <?php if ($message !== ''): ?><div class="notice success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(sams_csrf_token()) ?>">
            <div class="grid">
                <label>Duty date <input type="date" name="duty_date" min="<?= date('Y-m-d') ?>" required></label>
                <label>Assigned office
                    <input value="<?= htmlspecialchars((string) ($application['office_name'] ?? '')) ?>" readonly aria-readonly="true">
                    <small>This office is assigned by the admin and cannot be changed.</small>
                </label>
                <label>Start time <input type="time" name="start_time" required></label>
                <label>End time <input type="time" name="end_time" required></label>
                <div id="time-error" class="notice error full" hidden role="alert"></div>
                <label class="full">Reason <textarea name="reason" minlength="10" maxlength="5000" required placeholder="Explain why the class is unavailable."><?= htmlspecialchars((string) ($_POST['reason'] ?? '')) ?></textarea></label>
                <label class="full">Proof (PDF, JPG, PNG; max 5 MB) <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png" required></label>
            </div>
            <p><button class="btn" type="submit">Submit Request</button></p>
        </form>
    </div>
    <div class="card">
        <h2>My Requests</h2>
        <table><thead><tr><th>Date</th><th>Time</th><th>Office</th><th>Status</th><th>Admin note</th></tr></thead><tbody>
        <?php foreach ($requests as $request): ?>
            <tr><td><?= htmlspecialchars((string) $request['duty_date']) ?></td><td><?= htmlspecialchars(substr((string) $request['start_time'], 0, 5) . ' - ' . substr((string) $request['end_time'], 0, 5)) ?></td><td><?= htmlspecialchars((string) $request['office_name']) ?></td><td class="status"><?= htmlspecialchars((string) $request['status']) ?></td><td><?= htmlspecialchars((string) ($request['review_note'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="5">No temporary duty requests yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</main>
<script>
(function () {
    'use strict';

    var form = document.querySelector('form[enctype="multipart/form-data"]');
    var startInput = form ? form.querySelector('input[name="start_time"]') : null;
    var endInput = form ? form.querySelector('input[name="end_time"]') : null;
    var timeError = document.getElementById('time-error');

    function validateDutyDuration() {
        if (!startInput || !endInput || !timeError) {
            return true;
        }

        startInput.setCustomValidity('');
        endInput.setCustomValidity('');
        timeError.hidden = true;
        timeError.textContent = '';

        if (!startInput.value || !endInput.value) {
            return true;
        }

        var startParts = startInput.value.split(':');
        var endParts = endInput.value.split(':');
        var startMinutes = (parseInt(startParts[0], 10) * 60) + parseInt(startParts[1], 10);
        var endMinutes = (parseInt(endParts[0], 10) * 60) + parseInt(endParts[1], 10);
        var durationMinutes = endMinutes - startMinutes;

        if (durationMinutes <= 0) {
            timeError.textContent = 'End time must be later than start time.';
            timeError.hidden = false;
            endInput.setCustomValidity('End time must be later than start time.');
            return false;
        }

        if (durationMinutes < 120) {
            timeError.textContent = 'Temporary duty must be at least 2 hours.';
            timeError.hidden = false;
            endInput.setCustomValidity('Temporary duty must be at least 2 hours.');
            return false;
        }

        return true;
    }

    if (startInput && endInput) {
        startInput.addEventListener('input', validateDutyDuration);
        startInput.addEventListener('change', validateDutyDuration);
        endInput.addEventListener('input', validateDutyDuration);
        endInput.addEventListener('change', validateDutyDuration);
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            if (!validateDutyDuration()) {
                event.preventDefault();
                endInput.focus();
            }
        });
    }
}());
</script>
</body>
</html>
