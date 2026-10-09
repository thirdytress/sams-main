<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Ensure duty_excuses and duty_excuse_reads tables exist.
 */
function sams_duty_excuses_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS duty_excuses (
            excuse_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            application_id INT NOT NULL,
            term_id INT NOT NULL,
            duty_date DATE NOT NULL,
            day_of_week VARCHAR(20) NOT NULL,
            office_name VARCHAR(150) NULL,
            excuse_type VARCHAR(60) NOT NULL,
            reason TEXT NOT NULL,
            proof_original_name VARCHAR(255) NOT NULL,
            proof_stored_name VARCHAR(255) NOT NULL,
            proof_path VARCHAR(500) NOT NULL,
            proof_mime VARCHAR(100) NULL,
            proof_size INT UNSIGNED NULL,
            status ENUM('excused') NOT NULL DEFAULT 'excused',
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_notes TEXT NULL,
            KEY idx_excuse_student_date (student_id, duty_date),
            KEY idx_excuse_app_date (application_id, duty_date),
            KEY idx_excuse_date (duty_date),
            KEY idx_excuse_office (office_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS duty_excuse_reads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            excuse_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_excuse_user (excuse_id, user_id),
            KEY idx_read_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $pdo->exec("ALTER TABLE attendance_logs MODIFY clock_in_time DATETIME NULL");
    } catch (Throwable $e) {
        // ignore if already allowed
    }
}

/**
 * Returns available excuse reason categories.
 */
function sams_duty_excuse_types(): array
{
    return [
        'Medical / Sickness' => 'Medical / Sickness (Illness or medical consultation)',
        'Scholarship Matters' => 'Scholarship Matters (Processing scholarship requirements)',
        'Academic Requirement' => 'Academic Requirement (Exams, Thesis Defense, Class Activity)',
        'Family / Personal Emergency' => 'Family / Personal Emergency (Urgent family or personal matter)',
        'Official University Activity' => 'Official University Activity (Institutional or campus event)',
        'Other Valid Reason' => 'Other Valid Reason (Other legitimate reason)',
    ];
}

/**
 * Syncs an approved/accepted excuse into attendance_logs so that
 * all attendance systems immediately recognize the student as excused.
 */
function sams_duty_excuse_sync_attendance_logs(
    PDO $pdo,
    int $applicationId,
    int $termId,
    string $dutyDate,
    string $dayOfWeek,
    string $excuseType,
    string $reason
): void {
    // Find all active duty schedules for this student on this day
    $dutyStmt = $pdo->prepare(
        "SELECT duty_id, start_time, end_time, office_name
         FROM duty_schedules
         WHERE application_id = :application_id
           AND term_id = :term_id
           AND day_of_week = :day_of_week
           AND status IN ('deployed', 'accepted', 'assigned')"
    );
    $dutyStmt->execute([
        'application_id' => $applicationId,
        'term_id' => $termId,
        'day_of_week' => $dayOfWeek,
    ]);
    $duties = $dutyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $noteText = 'Duty Excuse (' . $excuseType . '): ' . $reason;
    if (mb_strlen($noteText) > 250) {
        $noteText = mb_substr($noteText, 0, 247) . '...';
    }

    if (empty($duties)) {
        // If there's no specific duty_id found, insert a general attendance_log for this date if none exists
        $checkStmt = $pdo->prepare(
            "SELECT log_id FROM attendance_logs
             WHERE application_id = :application_id
               AND DATE(created_at) = :duty_date
             LIMIT 1"
        );
        $checkStmt->execute([
            'application_id' => $applicationId,
            'duty_date' => $dutyDate,
        ]);
        $existingLogId = (int) $checkStmt->fetchColumn();

        if ($existingLogId > 0) {
            $upd = $pdo->prepare(
                "UPDATE attendance_logs
                 SET status = 'excused', notes = :notes, updated_at = NOW()
                 WHERE log_id = :log_id"
            );
            $upd->execute(['notes' => $noteText, 'log_id' => $existingLogId]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO attendance_logs
                    (application_id, term_id, duty_id, clock_in_time, clock_out_time, status, late_minutes, notes, created_at, updated_at)
                 VALUES
                    (:application_id, :term_id, NULL, NULL, NULL, 'excused', 0, :notes, :created_at, NOW())"
            );
            $ins->execute([
                'application_id' => $applicationId,
                'term_id' => $termId,
                'notes' => $noteText,
                'created_at' => $dutyDate . ' 08:00:00',
            ]);
        }
        return;
    }

    foreach ($duties as $duty) {
        $dutyId = (int) $duty['duty_id'];
        $startTime = (string) ($duty['start_time'] ?? '08:00:00');
        $createdAt = $dutyDate . ' ' . $startTime;

        // Check if log already exists for this duty and date
        $check = $pdo->prepare(
            "SELECT log_id FROM attendance_logs
             WHERE application_id = :application_id
               AND duty_id = :duty_id
               AND DATE(created_at) = :duty_date
             LIMIT 1"
        );
        $check->execute([
            'application_id' => $applicationId,
            'duty_id' => $dutyId,
            'duty_date' => $dutyDate,
        ]);
        $existingLogId = (int) $check->fetchColumn();

        if ($existingLogId > 0) {
            $upd = $pdo->prepare(
                "UPDATE attendance_logs
                 SET status = 'excused', notes = :notes, updated_at = NOW()
                 WHERE log_id = :log_id"
            );
            $upd->execute(['notes' => $noteText, 'log_id' => $existingLogId]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO attendance_logs
                    (application_id, term_id, duty_id, clock_in_time, clock_out_time, status, late_minutes, notes, created_at, updated_at)
                 VALUES
                    (:application_id, :term_id, :duty_id, NULL, NULL, 'excused', 0, :notes, :created_at, NOW())"
            );
            $ins->execute([
                'application_id' => $applicationId,
                'term_id' => $termId,
                'duty_id' => $dutyId,
                'notes' => $noteText,
                'created_at' => $createdAt,
            ]);
        }
    }
}
