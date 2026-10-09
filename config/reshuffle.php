<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Ensure database schema support for reshuffle count and evaluation retention decisions.
 */
function sams_reshuffle_ensure_schema(PDO $pdo): void
{
    // 1. Ensure students has reshuffle_count column
    try {
        if (!sams_column_exists($pdo, 'students', 'reshuffle_count')) {
            $pdo->exec("ALTER TABLE students ADD COLUMN reshuffle_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_good_standing");
        }
    } catch (Throwable $e) {
        // ignore if exists
    }

    // 2. Ensure evaluations table exists and has retention_decision column
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS evaluations (
                evaluation_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                application_id INT NOT NULL,
                term_id INT NOT NULL,
                supervisor_id INT NOT NULL,
                performance_rating INT NULL,
                reliability_rating INT NULL,
                professionalism_rating INT NULL,
                comments TEXT NULL,
                retention_decision ENUM('pending', 'retain', 'reshuffle') NOT NULL DEFAULT 'pending',
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_eval_app (application_id),
                KEY idx_eval_term (term_id),
                KEY idx_eval_sup (supervisor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!sams_column_exists($pdo, 'evaluations', 'retention_decision')) {
            $pdo->exec("ALTER TABLE evaluations ADD COLUMN retention_decision ENUM('pending', 'retain', 'reshuffle') NOT NULL DEFAULT 'pending' AFTER comments");
        }
    } catch (Throwable $e) {
        // ignore
    }

    // 3. Ensure shuffle_requests table exists
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shuffle_requests (
                request_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                term_id INT(11) NOT NULL,
                supervisor_id INT(11) NOT NULL,
                from_student_id INT(11) NOT NULL,
                to_student_id INT(11) NULL,
                evaluation_id INT(11) NULL,
                reason TEXT NOT NULL,
                status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                reviewed_by INT(11) NULL,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                review_notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_shuffle_term (term_id),
                KEY idx_shuffle_sup (supervisor_id),
                KEY idx_shuffle_from (from_student_id),
                KEY idx_shuffle_to (to_student_id),
                KEY idx_shuffle_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!sams_column_exists($pdo, 'shuffle_requests', 'evaluation_id')) {
            $pdo->exec("ALTER TABLE shuffle_requests ADD COLUMN evaluation_id INT(11) NULL AFTER to_student_id");
        }
    } catch (Throwable $e) {
        // ignore
    }

    // 4. Ensure shuffle_history table exists
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shuffle_history (
                shuffle_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                term_id INT(11) NOT NULL,
                shuffled_by INT(11) NULL,
                from_student_id INT(11) NULL,
                to_student_id INT(11) NULL,
                old_schedule_data LONGTEXT NULL,
                new_schedule_data LONGTEXT NULL,
                reason TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_hist_term (term_id),
                KEY idx_hist_from (from_student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Returns current reshuffle count for a student.
 */
function sams_student_reshuffle_count(PDO $pdo, int $studentId): int
{
    sams_reshuffle_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT COALESCE(reshuffle_count, 0) FROM students WHERE student_id = :id LIMIT 1');
    $stmt->execute(['id' => $studentId]);
    $count = $stmt->fetchColumn();
    if ($count !== false) {
        return (int) $count;
    }

    // Fallback: count approved shuffle requests for this student
    $histStmt = $pdo->prepare("SELECT COUNT(*) FROM shuffle_requests WHERE from_student_id = :id AND status = 'approved'");
    $histStmt->execute(['id' => $studentId]);
    return (int) $histStmt->fetchColumn();
}

/**
 * Returns whether a student is eligible for reshuffle (< 3 shuffles).
 */
function sams_student_can_reshuffle(PDO $pdo, int $studentId): bool
{
    return sams_student_reshuffle_count($pdo, $studentId) < 3;
}

/**
 * Returns latest evaluation for a student in a specific term.
 */
function sams_student_latest_evaluation(PDO $pdo, int $studentId, int $termId): ?array
{
    sams_reshuffle_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT e.*,
                e.evaluation_id AS eval_id,
                ROUND((COALESCE(e.performance_rating,0) + COALESCE(e.reliability_rating,0) + COALESCE(e.professionalism_rating,0)) / 3, 1) AS avg_rating
         FROM evaluations e
         INNER JOIN applications a ON a.application_id = e.application_id
         WHERE a.student_id = :student_id
           AND e.term_id = :term_id
         ORDER BY e.submitted_at DESC
         LIMIT 1"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'term_id' => $termId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Returns list of students who have reached the 3-reshuffle maximum limit.
 */
function sams_admin_get_max_reshuffle_students(PDO $pdo): array
{
    sams_reshuffle_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT s.student_id, s.student_id_number, s.reshuffle_count,
                u.first_name, u.last_name, u.email,
                COALESCE(a.preferred_office, 'Unassigned') AS office_name,
                a.application_id, a.term_id
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         LEFT JOIN applications a ON a.student_id = s.student_id AND a.status IN ('approved', 'deployed')
         WHERE s.reshuffle_count >= 3
         ORDER BY s.updated_at DESC, s.student_id DESC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
