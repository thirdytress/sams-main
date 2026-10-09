<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Ensure audit_logs table schema has all rich metadata columns and indexes.
 */
function sams_audit_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                audit_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT(11) NULL,
                user_role ENUM('admin', 'supervisor', 'student', 'system') NOT NULL DEFAULT 'system',
                user_name VARCHAR(150) NULL,
                user_email VARCHAR(150) NULL,
                action_type VARCHAR(60) NOT NULL,
                category VARCHAR(60) NOT NULL,
                description TEXT NOT NULL,
                target_type VARCHAR(60) NULL,
                target_id INT(11) NULL,
                details LONGTEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_audit_user (user_id),
                KEY idx_audit_role_time (user_role, created_at),
                KEY idx_audit_cat_time (category, created_at),
                KEY idx_audit_action_time (action_type, created_at),
                KEY idx_audit_time (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Add missing columns if legacy audit_logs exists
        if (!sams_column_exists($pdo, 'audit_logs', 'user_id')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN user_id INT(11) NULL AFTER audit_id");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'user_role')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN user_role ENUM('admin', 'supervisor', 'student', 'system') NOT NULL DEFAULT 'system' AFTER user_id");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'user_name')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN user_name VARCHAR(150) NULL AFTER user_role");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'user_email')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN user_email VARCHAR(150) NULL AFTER user_name");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'action_type')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN action_type VARCHAR(60) NOT NULL DEFAULT 'SYSTEM' AFTER user_email");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'category')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN category VARCHAR(60) NOT NULL DEFAULT 'General' AFTER action_type");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'description')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN description TEXT NOT NULL AFTER category");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'target_type')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN target_type VARCHAR(60) NULL AFTER description");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'target_id')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN target_id INT(11) NULL AFTER target_type");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'details')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN details LONGTEXT NULL AFTER target_id");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'ip_address')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN ip_address VARCHAR(45) NULL AFTER details");
        }
        if (!sams_column_exists($pdo, 'audit_logs', 'user_agent')) {
            $pdo->exec("ALTER TABLE audit_logs ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address");
        }
    } catch (Throwable $e) {
        // ignore if already configured
    }
}

/**
 * Log a system activity / audit log entry.
 */
function sams_log_audit(
    PDO $pdo,
    string $actionType,
    string $category,
    string $description,
    ?array $details = null,
    ?int $targetId = null,
    ?string $targetType = null,
    ?int $userId = null,
    ?string $userRole = null,
    ?string $userName = null,
    ?string $userEmail = null
): int {
    try {
        sams_audit_ensure_schema($pdo);

        $currentUser = sams_authenticated_user();
        if ($userId === null && $currentUser) {
            $userId = (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0);
        }
        if ($userRole === null && $currentUser) {
            $userRole = (string) ($currentUser['role'] ?? 'system');
        }
        if ($userName === null && $currentUser) {
            $userName = (string) ($currentUser['name'] ?? 'System User');
        }
        if ($userEmail === null && $currentUser) {
            $userEmail = (string) ($currentUser['email'] ?? '');
        }

        if ($userRole === null || !in_array($userRole, ['admin', 'supervisor', 'student', 'system'], true)) {
            $userRole = 'system';
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }

        $userAgent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System')), 0, 255);
        $detailsJson = $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, user_role, user_name, user_email, action_type, category, description, target_type, target_id, details, ip_address, user_agent, created_at)
             VALUES
                (:user_id, :user_role, :user_name, :user_email, :action_type, :category, :description, :target_type, :target_id, :details, :ip_address, :user_agent, NOW())"
        );
        $stmt->execute([
            'user_id' => $userId > 0 ? $userId : null,
            'user_role' => $userRole,
            'user_name' => $userName,
            'user_email' => $userEmail,
            'action_type' => strtoupper(trim($actionType)),
            'category' => trim($category),
            'description' => trim($description),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $detailsJson,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        // Return 0 if audit logging fails so that core user transaction is never blocked
        return 0;
    }
}

/**
 * Backfill initial historical activity into audit_logs if empty.
 */
function sams_audit_seed_initial_history(PDO $pdo): void
{
    sams_audit_ensure_schema($pdo);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    if ($count > 0) {
        return;
    }

    // 1. Seed recent duty excuses
    try {
        $excuses = $pdo->query(
            "SELECT de.*, u.first_name, u.last_name, u.email
             FROM duty_excuses de
             INNER JOIN students s ON s.student_id = de.student_id
             INNER JOIN users u ON u.user_id = s.user_id
             ORDER BY de.submitted_at ASC LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($excuses as $de) {
            $name = trim((string) $de['first_name'] . ' ' . (string) $de['last_name']);
            sams_log_audit(
                $pdo,
                'DUTY_EXCUSE',
                'Duty Excuses',
                "Student {$name} filed a Duty Excuse for {$de['duty_date']} ({$de['excuse_type']}).",
                ['reason' => $de['reason'], 'proof' => $de['proof_original_name'], 'duty_date' => $de['duty_date']],
                (int) $de['excuse_id'],
                'duty_excuse',
                (int) ($de['user_id'] ?? 0),
                'student',
                $name,
                (string) ($de['email'] ?? '')
            );
        }
    } catch (Throwable $e) {}

    // 2. Seed evaluations
    try {
        $evals = $pdo->query(
            "SELECT e.*, uSup.first_name AS sup_first, uSup.last_name AS sup_last, uSup.email AS sup_email,
                    uStu.first_name AS stu_first, uStu.last_name AS stu_last
             FROM evaluations e
             INNER JOIN supervisors sup ON sup.supervisor_id = e.supervisor_id
             INNER JOIN users uSup ON uSup.user_id = sup.user_id
             INNER JOIN applications a ON a.application_id = e.application_id
             INNER JOIN students s ON s.student_id = a.student_id
             INNER JOIN users uStu ON uStu.user_id = s.user_id
             ORDER BY e.submitted_at ASC LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($evals as $ev) {
            $supName = trim((string) $ev['sup_first'] . ' ' . (string) $ev['sup_last']);
            $stuName = trim((string) $ev['stu_first'] . ' ' . (string) $ev['stu_last']);
            $avg = round(((int)$ev['performance_rating'] + (int)$ev['reliability_rating'] + (int)$ev['professionalism_rating']) / 3, 1);
            sams_log_audit(
                $pdo,
                'EVALUATE',
                'Evaluations',
                "Supervisor {$supName} submitted performance evaluation for {$stuName} ({$avg}/5.0).",
                ['performance' => $ev['performance_rating'], 'reliability' => $ev['reliability_rating'], 'professionalism' => $ev['professionalism_rating'], 'comments' => $ev['comments']],
                (int) $ev['evaluation_id'],
                'evaluation',
                (int) ($ev['user_id'] ?? 0),
                'supervisor',
                $supName,
                (string) ($ev['sup_email'] ?? '')
            );
        }
    } catch (Throwable $e) {}

    // 3. Seed temporary duty requests
    try {
        $tempDuties = $pdo->query(
            "SELECT tdr.*, u.first_name, u.last_name, u.email
             FROM temporary_duty_requests tdr
             INNER JOIN students s ON s.student_id = tdr.student_id
             INNER JOIN users u ON u.user_id = s.user_id
             ORDER BY tdr.requested_at ASC LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($tempDuties as $td) {
            $name = trim((string) $td['first_name'] . ' ' . (string) $td['last_name']);
            sams_log_audit(
                $pdo,
                'CREATE',
                'Temporary Duty',
                "Student {$name} submitted a Temporary Duty Request for {$td['duty_date']} ({$td['start_time']} - {$td['end_time']}).",
                ['reason' => $td['reason'], 'duty_date' => $td['duty_date'], 'status' => $td['status']],
                (int) $td['request_id'],
                'temporary_duty_request',
                (int) ($td['user_id'] ?? 0),
                'student',
                $name,
                (string) ($td['email'] ?? '')
            );
        }
    } catch (Throwable $e) {}

    // 4. Seed system term initialization
    sams_log_audit(
        $pdo,
        'SYSTEM_START',
        'System Settings',
        'SAMS System Audit Logging initialized with role filtering and comprehensive change tracking.',
        ['environment' => 'Production / Academic Year AY 2026-2027'],
        null,
        'system',
        1,
        'admin',
        'SAMS Administrator',
        'admin@nu-lipa.edu.ph'
    );
}
