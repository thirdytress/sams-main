<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']);
  exit;
}

$pdo = sams_pdo();
$user = sams_authenticated_user();
if (!$user) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Not authenticated']);
  exit;
}

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if (!sams_attendance_clocking_enabled()) {
  http_response_code(503);
  echo json_encode(['success' => false, 'message' => 'Attendance clocking is not enabled yet']);
  exit;
}

if ($scheduleId <= 0 || ($action !== 'in' && $action !== 'out')) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
  exit;
}

try {
  // Find student id for current user
  $stmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1');
  $stmt->execute(['user_id' => $user['user_id'] ?? $user['id']]);
  $studentRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $studentId = (int) ($studentRow['student_id'] ?? 0);
  if ($studentId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Student record not found']);
    exit;
  }

  // Load schedule and associated student/application (include application_id and term_id)
  $sStmt = $pdo->prepare(
    'SELECT ds.duty_id AS duty_id, ds.application_id AS application_id, ds.term_id AS term_id, a.student_id, ds.start_time AS time_start, ds.end_time AS time_end, ds.scheduled_date
     FROM duty_schedules ds
     LEFT JOIN applications a ON a.application_id = ds.application_id
     WHERE ds.duty_id = :duty_id
     LIMIT 1'
  );
  $sStmt->execute(['duty_id' => $scheduleId]);
  $sched = $sStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$sched) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Schedule not found']);
    exit;
  }
  if ((int) $sched['student_id'] !== $studentId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not assigned to this schedule']);
    exit;
  }
  if (!empty($sched['scheduled_date']) && (string) $sched['scheduled_date'] !== date('Y-m-d')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This duty schedule is not active today']);
    exit;
  }

  $applicationId = (int) ($sched['application_id'] ?? 0);
  $termId = (int) ($sched['term_id'] ?? 0);
  if ($applicationId <= 0 || $termId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Schedule is missing application or term data']);
    exit;
  }

  $now = new DateTimeImmutable('now');
  $pdo->beginTransaction();

  if ($action === 'in') {
    // Check existing attendance for today
    $att = $pdo->prepare('SELECT * FROM attendance_logs WHERE application_id = :appid AND duty_id = :dsid AND DATE(created_at) = CURDATE() LIMIT 1');
    $att->execute(['appid' => $applicationId, 'dsid' => $scheduleId]);
    $row = $att->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row && !empty($row['clock_in_time'])) {
      $pdo->rollBack();
      echo json_encode(['success' => false, 'message' => 'Already clocked in']);
      exit;
    }

    $endTime = new DateTimeImmutable(date('Y-m-d') . ' ' . $sched['time_end']);
    if ($now > $endTime) {
      $pdo->rollBack();
      echo json_encode([
        'success' => false,
        'message' => 'Unable to Clock In. Your scheduled shift for today ended at ' . date('g:i A', $endTime->getTimestamp()) . '.'
      ]);
      exit;
    }

    if ($row) {
      $upd = $pdo->prepare('UPDATE attendance_logs SET clock_in_time = NOW(), status = :status, late_minutes = :late WHERE log_id = :log_id');
      // Compute late
      $start = new DateTimeImmutable($sched['time_start']);
      $diff = (int) (($now->getTimestamp() - $start->getTimestamp()) / 60);
      $late = max(0, $diff);
      $status = ($late > sams_attendance_late_threshold()) ? 'late' : 'present';
      $upd->execute(['status' => $status, 'late' => $late, 'log_id' => (int) $row['log_id']]);
      $attendanceId = (int) $row['log_id'];
    } else {
        $scheduledStart = new DateTimeImmutable((string) $sched['time_start']);
        $scheduledEnd = new DateTimeImmutable((string) $sched['time_end']);
      // Insert
      $start = new DateTimeImmutable($sched['time_start']);
      $diff = (int) (($now->getTimestamp() - $start->getTimestamp()) / 60);
      $late = max(0, $diff);
      $status = ($late > sams_attendance_late_threshold()) ? 'late' : 'present';
      $ins = $pdo->prepare('INSERT INTO attendance_logs (application_id, term_id, duty_id, clock_in_time, status, late_minutes, created_at) VALUES (:appid, :termid, :dsid, NOW(), :status, :late, NOW())');
      $ins->execute(['appid' => $applicationId, 'termid' => $termId, 'dsid' => $scheduleId, 'status' => $status, 'late' => $late]);
      $attendanceId = (int) $pdo->lastInsertId();
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'action' => 'in', 'attendance_id' => $attendanceId, 'status' => $status, 'late_minutes' => $late]);
    exit;
  }

  if ($action === 'out') {
    // Find today's attendance (if any)
    $att = $pdo->prepare('SELECT * FROM attendance_logs WHERE application_id = :appid AND duty_id = :dsid AND DATE(created_at) = CURDATE() LIMIT 1');
    $att->execute(['appid' => $applicationId, 'dsid' => $scheduleId]);
    $row = $att->fetch(PDO::FETCH_ASSOC) ?: null;

    // Determine late based on recorded clock_in_time when available
    $late = 0;
    $status = 'present';
    if ($row && !empty($row['clock_in_time'])) {
      try {
        $start = new DateTimeImmutable($sched['time_start']);
        $inTs = strtotime($row['clock_in_time']);
        if ($inTs) {
          $diff = (int) (($inTs - $start->getTimestamp()) / 60);
          $late = max(0, $diff);
        }
      } catch (Throwable $e) {
        $late = 0;
      }
      $status = ($late > sams_attendance_late_threshold()) ? 'late' : 'present';
    }

    if ($row && empty($row['clock_in_time'])) {
      // No time_in but user wants to clock out -> mark incomplete
      $upd = $pdo->prepare('UPDATE attendance_logs SET clock_out_time = NOW(), status = :status WHERE log_id = :log_id');
      $upd->execute(['status' => 'incomplete', 'log_id' => (int) $row['log_id']]);
      $pdo->commit();
      echo json_encode(['success' => true, 'action' => 'out', 'attendance_id' => (int) $row['log_id'], 'status' => 'incomplete']);
      exit;
    }

    if ($row && empty($row['clock_out_time'])) {
      $upd = $pdo->prepare('UPDATE attendance_logs SET clock_out_time = NOW() WHERE log_id = :log_id');
      $upd->execute(['log_id' => (int) $row['log_id']]);
      $pdo->commit();
      echo json_encode(['success' => true, 'action' => 'out', 'attendance_id' => (int) $row['log_id'], 'status' => 'completed']);
      exit;
    }

    if (!$row) {
      // Create an incomplete record with only clock_out_time (include application_id/term_id when available)
      $ins = $pdo->prepare('INSERT INTO attendance_logs (application_id, term_id, duty_id, clock_out_time, status, created_at) VALUES (:appid, :termid, :dsid, NOW(), :status, NOW())');
      $ins->execute(['appid' => $applicationId, 'termid' => $termId, 'dsid' => $scheduleId, 'status' => 'incomplete']);
      $id = (int) $pdo->lastInsertId();
      $pdo->commit();
      echo json_encode(['success' => true, 'action' => 'out', 'attendance_id' => $id, 'status' => 'incomplete']);
      exit;
    }

    // Otherwise already clocked out
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Already clocked out or invalid state']);
    exit;
  }

} catch (Exception $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
  exit;
}
