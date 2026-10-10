<?php
declare(strict_types=1);

function sams_first_existing_column(PDO $pdo, string $table, array $columns): ?string
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $stmt->execute();
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[] = $row['Field'];
    }

    foreach ($columns as $col) {
        if (in_array($col, $existing, true)) {
            return $col;
        }
    }

    return null;
}

function sams_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function sams_normalize_name(?string $firstName, ?string $lastName): string
{
    $fullName = trim((string) $firstName . ' ' . (string) $lastName);
    return $fullName !== '' ? $fullName : 'SAMS User';
}

function sams_ensure_profile_image_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (!sams_column_exists($pdo, 'users', 'profile_image')) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER phone_number");
        } catch (Throwable $e) {
            // ignore if already exists
        }
    }
    $targetDir = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }
    $ensured = true;
}

function sams_authenticated_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $sessionUser = $_SESSION['sams_user'] ?? null;
    if (!is_array($sessionUser) || empty($sessionUser['user_id'])) {
        return null;
    }

    try {
        $pdo = sams_pdo();
        sams_ensure_profile_image_schema($pdo);

        $statement = $pdo->prepare(
            'SELECT user_id, email, role, first_name, last_name, profile_image, is_active
             FROM users WHERE user_id = :user_id LIMIT 1'
        );
        $statement->execute(['user_id' => (int) $sessionUser['user_id']]);
        $databaseUser = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$databaseUser || !(int) $databaseUser['is_active']) {
            return $sessionUser;
        }

        $_SESSION['sams_user'] = array_merge($sessionUser, [
            'user_id' => (int) $databaseUser['user_id'],
            'email' => (string) $databaseUser['email'],
            'role' => (string) $databaseUser['role'],
            'first_name' => (string) ($databaseUser['first_name'] ?? ''),
            'last_name' => (string) ($databaseUser['last_name'] ?? ''),
            'name' => sams_normalize_name($databaseUser['first_name'] ?? null, $databaseUser['last_name'] ?? null),
            'profile_image' => $databaseUser['profile_image'] ?? null,
        ]);
    } catch (Throwable $exception) {
        // Keep the session identity available if the profile refresh is temporarily unavailable.
    }

    return $_SESSION['sams_user'];
}

function sams_handle_avatar_upload(int $userId, array $fileInfo): array
{
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user ID.'];
    }

    if (!isset($fileInfo['error']) || is_array($fileInfo['error'])) {
        return ['success' => false, 'error' => 'Invalid upload parameters.'];
    }

    if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
        return match ($fileInfo['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ['success' => false, 'error' => 'Image is too large (maximum 5MB).'],
            UPLOAD_ERR_NO_FILE => ['success' => false, 'error' => 'No image file was selected.'],
            default => ['success' => false, 'error' => 'File upload error occurred (code ' . $fileInfo['error'] . ').'],
        };
    }

    if (($fileInfo['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Image must not exceed 5MB.'];
    }

    $tmpPath = (string) ($fileInfo['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpPath)) {
        return ['success' => false, 'error' => 'Uploaded file verification failed.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowedMimes[$mime])) {
        return ['success' => false, 'error' => 'Invalid image format. Allowed formats: JPG, PNG, WEBP, GIF.'];
    }

    $ext = $allowedMimes[$mime];
    $rootDir = dirname(__DIR__);
    $targetDir = $rootDir . '/uploads/avatars';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    $pdo = sams_pdo();
    sams_ensure_profile_image_schema($pdo);

    // Fetch existing avatar to delete if it exists
    $oldStmt = $pdo->prepare('SELECT profile_image FROM users WHERE user_id = :id');
    $oldStmt->execute(['id' => $userId]);
    $oldImg = (string) ($oldStmt->fetchColumn() ?: '');

    $newFilename = sprintf('avatar_%d_%s_%s.%s', $userId, date('YmdHis'), bin2hex(random_bytes(4)), $ext);
    $destPath = $targetDir . '/' . $newFilename;
    $dbPath = 'uploads/avatars/' . $newFilename;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded image.'];
    }

    // Delete old avatar file
    if ($oldImg !== '' && str_starts_with($oldImg, 'uploads/avatars/')) {
        $oldFile = $rootDir . '/' . $oldImg;
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    // Update database
    $upd = $pdo->prepare('UPDATE users SET profile_image = :img WHERE user_id = :id');
    $upd->execute(['img' => $dbPath, 'id' => $userId]);

    if (isset($_SESSION['sams_user']['user_id']) && (int)$_SESSION['sams_user']['user_id'] === $userId) {
        $_SESSION['sams_user']['profile_image'] = $dbPath;
    }

    return ['success' => true, 'path' => $dbPath];
}

function sams_user_avatar_url(?string $profileImage, string $prefix = ''): ?string
{
    if ($profileImage === null || trim($profileImage) === '') {
        return null;
    }
    $clean = ltrim(trim($profileImage), '/');
    $rootDir = dirname(__DIR__);
    if (file_exists($rootDir . '/' . $clean)) {
        return $prefix . $clean;
    }
    return null;
}

function sams_login(array $user): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);

    $_SESSION['sams_user'] = [
        'user_id' => (int) $user['id'],
        'role' => $user['role'],
        'email' => $user['email'],
        'name' => sams_normalize_name($user['first_name'] ?? null, $user['last_name'] ?? null),
        'student_id' => $user['student_id'] ?? null,
        'office_name' => $user['office_name'] ?? null,
        'profile_image' => $user['profile_image'] ?? null,
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
        'application_status' => $user['application_status'] ?? null,
    ];

    if ($user['role'] === 'admin') {
        $_SESSION['admin_id'] = (int) $user['id'];
    }

    if ($user['role'] === 'supervisor') {
        $_SESSION['supervisor_id'] = (int) $user['id'];
    }

    if ($user['role'] === 'student') {
        $_SESSION['student_id'] = $user['student_id'] ?? null;
    }
}

function sams_authenticate(string $identifier, string $password): array
{
    $pdo = sams_pdo();
    $passwordColumn = sams_first_existing_column($pdo, 'users', ['password', 'password_hash']);
    $mustChangePasswordColumn = sams_first_existing_column($pdo, 'users', ['must_change_password']);
    $userIdColumn = sams_first_existing_column($pdo, 'users', ['id', 'user_id']);
    
    if ($passwordColumn === null) {
        throw new RuntimeException('Users table missing password column.');
    }

    if ($userIdColumn === null) {
        throw new RuntimeException('Users table missing id or user_id column.');
    }

    if (!sams_first_existing_column($pdo, 'students', ['student_id_number'])) {
        throw new RuntimeException('Students table missing student_id_number column.');
    }

    $mustChangePasswordSelect = $mustChangePasswordColumn !== null
        ? 'u.' . $mustChangePasswordColumn . ' AS must_change_password'
        : '0 AS must_change_password';

     $statement = $pdo->prepare(
          "SELECT
                u.{$userIdColumn} AS id,
                u.email,
                u.role,
                u.first_name,
                u.last_name,
                u.profile_image,
                u.is_active,
                u.{$passwordColumn} AS password_stored,
                {$mustChangePasswordSelect},
                COALESCE(sp.office_name, '') AS office_name,
                s.student_id,
                s.student_id_number,
                (
                    SELECT a.status
                    FROM applications a
                    WHERE a.student_id = s.student_id
                    ORDER BY a.application_id DESC
                    LIMIT 1
                ) AS application_status
            FROM users u
            LEFT JOIN students s ON u.{$userIdColumn} = s.user_id
            LEFT JOIN supervisors sp ON u.{$userIdColumn} = sp.user_id
            WHERE u.email = :identifier_email
            LIMIT 1"
     );
    $statement->execute([
        'identifier_email' => $identifier,
    ]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('Invalid email.');
    }

    if (!password_verify($password, (string) ($user['password_stored'] ?? ''))) {
        throw new RuntimeException('Invalid password.');
    }

    $applicationStatus = strtolower(trim((string) ($user['application_status'] ?? '')));
    if (!$user['is_active'] && !($user['role'] === 'student' && in_array($applicationStatus, ['pending', 'rejected'], true))) {
        throw new RuntimeException('Your account is not active. Please contact support.');
    }

    return [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'student_id' => $user['student_id'] ?? null,
        'student_id_number' => $user['student_id_number'] ?? null,
        'application_status' => $user['application_status'] ?? null,
        'office_name' => $user['office_name'] ?? null,
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
    ];
}

function sams_dashboard_for_role(string $role): string
{
    return match ($role) {
        'admin' => 'admin/dashboard.php',
        'supervisor' => 'supervisor/dashboard.php',
        'student' => 'students/dashboard.php',
        default => 'index.php',
    };
}
