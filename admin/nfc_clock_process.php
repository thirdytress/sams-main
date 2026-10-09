<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Authenticate that user is Admin (the Kiosk page runs under an admin session)
$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$nfcUid = trim((string) ($_POST['nfc_uid'] ?? ''));

if ($nfcUid === '') {
    echo json_encode(['success' => false, 'message' => 'No card scanned or empty card data.']);
    exit;
}

try {
    $pdo = sams_pdo();

    // 1. Find student matching NFC UID
    $studentStmt = $pdo->prepare('
        SELECT s.student_id, s.student_id_number, u.first_name, u.last_name, u.is_active
        FROM students s
        INNER JOIN users u ON u.user_id = s.user_id
        WHERE s.nfc_uid = :nfc_uid
        LIMIT 1
    ');
    $studentStmt->execute(['nfc_uid' => $nfcUid]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'NFC Card not registered. Please register this card in your Student Profile.']);
        exit;
    }

    if (!(int)$student['is_active']) {
        echo json_encode(['success' => false, 'message' => 'Student account is currently inactive.']);
        exit;
    }

    // 2. Find active term
    $activeTerm = sams_current_term($pdo);
    $activeTermId = (int) ($activeTerm['term_id'] ?? 0);
    if ($activeTermId <= 0) {
        echo json_encode(['success' => false, 'message' => 'No active semester term found in system settings.']);
        exit;
    }

    // 3. Find approved application for active term
    $appStmt = $pdo->prepare('
        SELECT application_id, preferred_office, status
        FROM applications
        WHERE student_id = :student_id AND term_id = :term_id AND status = "approved"
        LIMIT 1
    ');
    $appStmt->execute([
        'student_id' => (int) $student['student_id'],
        'term_id' => $activeTermId
    ]);
    $app = $appStmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        echo json_encode(['success' => false, 'message' => 'Student does not have an approved application for this term.']);
        exit;
    }

    // 4. Check if student has scheduled shifts today (deployed status)
    $dayOfWeek = date('l');
    $schedStmt = $pdo->prepare('
        SELECT duty_id, start_time, end_time, status, COALESCE(NULLIF(TRIM(office_name), ""), :pref_office) AS office_name
        FROM duty_schedules
        WHERE application_id = :app_id
          AND day_of_week = :day
          AND status = "deployed"
          AND (scheduled_date IS NULL OR scheduled_date = CURDATE())
        ORDER BY start_time ASC
    ');
    $schedStmt->execute([
        'app_id' => (int) $app['application_id'],
        'day' => $dayOfWeek,
        'pref_office' => $app['preferred_office'] ?: 'Unassigned'
    ]);
    $schedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

    $studentName = trim($student['first_name'] . ' ' . $student['last_name']);

    if (empty($schedules)) {
        echo json_encode([
            'success' => false, 
            'message' => 'No deployed schedule found for today (' . $dayOfWeek . ') for ' . $studentName . '.'
        ]);
        exit;
    }

    // 5. Enforce 5-minute debounce limit for attendance scans across student today
    $debounceStmt = $pdo->prepare('
        SELECT clock_in_time, clock_out_time
        FROM attendance_logs
        WHERE application_id = :app_id AND DATE(created_at) = CURDATE()
        ORDER BY log_id DESC
        LIMIT 1
    ');
    $debounceStmt->execute(['app_id' => (int) $app['application_id']]);
    $lastScan = $debounceStmt->fetch(PDO::FETCH_ASSOC);

    $now = new DateTimeImmutable('now');

    if ($lastScan) {
        $lastTimeStr = !empty($lastScan['clock_out_time']) ? $lastScan['clock_out_time'] : $lastScan['clock_in_time'];
        if ($lastTimeStr) {
            $lastTime = new DateTimeImmutable($lastTimeStr);
            $interval = $now->getTimestamp() - $lastTime->getTimestamp();

            if ($interval < 300) { // 5 minutes
                $remaining = 300 - $interval;
                $min = (int) floor($remaining / 60);
                $sec = $remaining % 60;
                $timeWait = ($min > 0) ? "{$min}m {$sec}s" : "{$sec} seconds";
                echo json_encode([
                    'success' => false, 
                    'message' => 'Scan ignored to prevent database spamming. Please wait ' . $timeWait . ' before tapping again.'
                ]);
                exit;
            }
        }
    }

    // 6. Find the appropriate schedule for the current action
    $targetSchedule = null;
    $targetAction = null; // 'in' or 'out'
    $targetLog = null;
    $allCompleted = true;
    $latestEndTime = null;

    foreach ($schedules as $s) {
        $endTime = new DateTimeImmutable(date('Y-m-d') . ' ' . $s['end_time']);
        if ($latestEndTime === null || $endTime > $latestEndTime) {
            $latestEndTime = $endTime;
        }

        $logStmt = $pdo->prepare('
            SELECT log_id, duty_id, clock_in_time, clock_out_time, created_at
            FROM attendance_logs
            WHERE application_id = :app_id AND duty_id = :duty_id AND DATE(created_at) = CURDATE()
            ORDER BY log_id DESC
            LIMIT 1
        ');
        $logStmt->execute([
            'app_id' => (int) $app['application_id'],
            'duty_id' => (int) $s['duty_id']
        ]);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);

        // Priority 1: Shift currently clocked IN (and not yet clocked out)
        if ($log && !empty($log['clock_in_time']) && empty($log['clock_out_time'])) {
            $targetSchedule = $s;
            $targetAction = 'out';
            $targetLog = $log;
            break;
        }

        // Priority 2: Shift with no log where current time has not passed shift end_time
        if (!$log && $now <= $endTime && $targetSchedule === null) {
            $targetSchedule = $s;
            $targetAction = 'in';
            $targetLog = null;
        }

        if (!$log || empty($log['clock_out_time'])) {
            $allCompleted = false;
        }
    }

    if ($targetSchedule !== null && $targetAction === 'out' && $targetLog !== null) {
        // Clock Out
        $upd = $pdo->prepare('
            UPDATE attendance_logs 
            SET clock_out_time = NOW() 
            WHERE log_id = :log_id
        ');
        $upd->execute(['log_id' => (int) $targetLog['log_id']]);

        echo json_encode([
            'success' => true,
            'action' => 'out',
            'student_name' => $studentName,
            'student_code' => $student['student_id_number'],
            'office' => $targetSchedule['office_name'],
            'status' => 'Completed',
            'time' => date('g:i A'),
            'message' => 'Goodbye, ' . $studentName . '! Checked OUT successfully.'
        ]);
        exit;
    }

    if ($targetSchedule !== null && $targetAction === 'in') {
        // Clock In
        $start = new DateTimeImmutable(date('Y-m-d') . ' ' . $targetSchedule['start_time']);
        $diff = (int) (($now->getTimestamp() - $start->getTimestamp()) / 60);
        $late = max(0, $diff);
        $status = ($late > sams_attendance_late_threshold()) ? 'late' : 'present';

        $ins = $pdo->prepare('
            INSERT INTO attendance_logs (application_id, term_id, duty_id, clock_in_time, status, late_minutes, created_at)
            VALUES (:appid, :termid, :dsid, NOW(), :status, :late, NOW())
        ');
        $ins->execute([
            'appid' => (int) $app['application_id'],
            'termid' => $activeTermId,
            'dsid' => (int) $targetSchedule['duty_id'],
            'status' => $status,
            'late' => $late
        ]);

        echo json_encode([
            'success' => true,
            'action' => 'in',
            'student_name' => $studentName,
            'student_code' => $student['student_id_number'],
            'office' => $targetSchedule['office_name'],
            'status' => ucfirst($status),
            'time' => date('g:i A'),
            'message' => 'Welcome, ' . $studentName . '! Checked IN successfully.'
        ]);
        exit;
    }

    if ($allCompleted) {
        echo json_encode([
            'success' => false,
            'message' => $studentName . ' has already completed all scheduled duties for today.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Unable to Clock In. Your scheduled shift for today ended at ' . ($latestEndTime ? date('g:i A', $latestEndTime->getTimestamp()) : 'earlier') . '.'
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred during clocking.', 'error' => $e->getMessage()]);
    exit;
}
