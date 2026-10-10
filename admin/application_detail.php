<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$applicationId = (int) ($_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing application ID']);
    exit;
}

function sams_admin_column_exists(\PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$key];
    } catch (\Throwable $exception) {
        $cache[$key] = false;
        return false;
    }
}

function sams_admin_first_existing_column(\PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (sams_admin_column_exists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

try {
    $pdo = sams_pdo();

    $applicationIdColumn = sams_admin_first_existing_column($pdo, 'applications', ['id', 'application_id']);
    $studentPkColumn = sams_admin_first_existing_column($pdo, 'students', ['id', 'student_id']);
    $userPkColumn = sams_admin_first_existing_column($pdo, 'users', ['id', 'user_id']);
    $termPkColumn = sams_admin_first_existing_column($pdo, 'terms', ['id', 'term_id']);

    if ($applicationIdColumn === null || $studentPkColumn === null || $userPkColumn === null || $termPkColumn === null) {
        throw new \RuntimeException('Required database ID columns are missing.');
    }

    $unitsSelect = sams_admin_column_exists($pdo, 'students', 'units') ? 's.units' : 'NULL AS units';
    $applicationStmt = $pdo->prepare(
        "SELECT
            a.{$applicationIdColumn} AS application_id,
            a.student_id,
            a.term_id,
            a.status,
            a.preferred_office,
            a.skills,
            a.available_hours_per_week,
            a.submitted_at,
            u.first_name,
            u.last_name,
            u.email,
            s.student_id_number AS student_code,
            s.program,
            s.year_level,
            {$unitsSelect},
            t.term_name,
            t.term_year AS school_year
         FROM applications a
         INNER JOIN students s ON s.{$studentPkColumn} = a.student_id
         INNER JOIN users u ON u.{$userPkColumn} = s.user_id
         INNER JOIN terms t ON t.{$termPkColumn} = a.term_id
         WHERE a.{$applicationIdColumn} = :application_id
         LIMIT 1"
    );
    $applicationStmt->execute(['application_id' => $applicationId]);
    $application = $applicationStmt->fetch();

    if (!$application) {
        throw new \RuntimeException('Application not found.');
    }

    if ($application['status'] === 'draft') {
        throw new \RuntimeException('Application is incomplete and not yet submitted.');
    }

    $documents = [];
    if (sams_admin_column_exists($pdo, 'document_uploads', 'application_id')) {
        $docColumns = [];
        foreach (['upload_id', 'user_id', 'document_type', 'original_filename', 'stored_filename', 'file_path', 'file_size', 'mime_type', 'uploaded_at'] as $column) {
            if (sams_admin_column_exists($pdo, 'document_uploads', $column)) {
                $docColumns[] = $column;
            }
        }

        if (!empty($docColumns)) {
            $orderColumn = in_array('uploaded_at', $docColumns, true) ? 'uploaded_at' : (in_array('upload_id', $docColumns, true) ? 'upload_id' : $docColumns[0]);
            $docStmt = $pdo->prepare(
                'SELECT ' . implode(', ', $docColumns) . "\n                 FROM document_uploads\n                 WHERE application_id = :application_id\n                 ORDER BY " . $orderColumn . " ASC"
            );
            $docStmt->execute(['application_id' => $applicationId]);
            $documents = $docStmt->fetchAll();
        }
    }

    $availability = [];
    if (sams_admin_column_exists($pdo, 'availability', 'application_id')) {
        $timeStartColumn = sams_admin_first_existing_column($pdo, 'availability', ['time_start', 'start_time']);
        $timeEndColumn = sams_admin_first_existing_column($pdo, 'availability', ['time_end', 'end_time']);
        $notesSelect = sams_admin_column_exists($pdo, 'availability', 'notes') ? 'notes' : 'NULL AS notes';

        if ($timeStartColumn !== null && $timeEndColumn !== null && sams_admin_column_exists($pdo, 'availability', 'day_of_week')) {
            $availableColumn = sams_admin_column_exists($pdo, 'availability', 'is_available') ? 'is_available' : '1 AS is_available';
            $availabilityStmt = $pdo->prepare(
                "SELECT
                    day_of_week,
                    {$timeStartColumn} AS time_start,
                    {$timeEndColumn} AS time_end,
                    {$notesSelect},
                    {$availableColumn}
                 FROM availability
                 WHERE application_id = :application_id
                 ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')"
            );
            $availabilityStmt->execute(['application_id' => $applicationId]);
            $availability = $availabilityStmt->fetchAll();
        }

        $availabilityChangeRequest = null;
        if (sams_admin_column_exists($pdo, 'availability_change_requests', 'request_id')) {
            $requestStmt = $pdo->prepare(
                "SELECT request_id, proposed_availability, status, requested_at, review_note
                 FROM availability_change_requests
                 WHERE application_id = :application_id
                 ORDER BY request_id DESC LIMIT 1"
            );
            $requestStmt->execute(['application_id' => $applicationId]);
            $availabilityChangeRequest = $requestStmt->fetch() ?: null;
            if ($availabilityChangeRequest && is_string($availabilityChangeRequest['proposed_availability'])) {
                $decoded = json_decode($availabilityChangeRequest['proposed_availability'], true);
                $availabilityChangeRequest['proposed_availability'] = is_array($decoded) ? $decoded : [];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'application' => $application,
        'documents' => $documents,
        'availability' => $availability,
        'availability_change_request' => $availabilityChangeRequest,
    ]);
    exit;
} catch (\Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage(),
        'documents' => [],
        'availability' => [],
    ]);
    exit;
}
