<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/audit.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$flashMessage = '';
$flashError = '';

$userIdColumn = sams_first_existing_column($pdo, 'users', ['user_id', 'id']);
$passwordColumn = sams_first_existing_column($pdo, 'users', ['password_hash', 'password']);
$mustChangeColumn = sams_first_existing_column($pdo, 'users', ['must_change_password']);
$activeColumn = sams_first_existing_column($pdo, 'users', ['is_active']);

if ($userIdColumn === null || $passwordColumn === null) {
    throw new RuntimeException('Users table is missing a required authentication column.');
}

$officeOptions = sams_office_options();
$postAction = (string) ($_POST['action'] ?? 'create');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'create') {
    if (!sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
        $flashError = 'Your session expired. Please refresh and try again.';
    } else {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $officeName = trim((string) ($_POST['office_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $maxStudents = (int) ($_POST['max_students'] ?? 5);

        if ($firstName === '' || $lastName === '' || $email === '' || $password === '' || $officeName === '') {
            $flashError = 'Please complete all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $flashError = 'Password must be at least 8 characters.';
        } elseif ($maxStudents < 1) {
            $flashError = 'Max students must be at least 1.';
        } else {
            try {
                $pdo->beginTransaction();

                $existingStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
                $existingStmt->execute(['email' => $email]);
                if ((int) $existingStmt->fetchColumn() > 0) {
                    throw new RuntimeException('That email is already registered.');
                }

                $userColumns = ['email', $passwordColumn, 'role', 'first_name', 'last_name', 'is_active'];
                $userParams = [':email', ':password_value', ':role', ':first_name', ':last_name', ':is_active'];
                $userValues = [
                    'email' => $email,
                    'password_value' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'supervisor',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'is_active' => 1,
                ];

                if ($mustChangeColumn !== null) {
                    $userColumns[] = $mustChangeColumn;
                    $userParams[] = ':must_change_password';
                    $userValues['must_change_password'] = 1;
                }

                if (sams_column_exists($pdo, 'users', 'office_name')) {
                    $userColumns[] = 'office_name';
                    $userParams[] = ':office_name_user';
                    $userValues['office_name_user'] = $officeName;
                }

                $userInsertSql = 'INSERT INTO users (' . implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $userColumns)) . ') VALUES (' . implode(', ', $userParams) . ')';
                $userInsertStmt = $pdo->prepare($userInsertSql);
                $userInsertStmt->execute($userValues);
                $newUserId = (int) $pdo->lastInsertId();

                $supervisorInsertStmt = $pdo->prepare(
                    'INSERT INTO supervisors (user_id, office_name, max_students, phone)
                     VALUES (:user_id, :office_name, :max_students, :phone)'
                );
                $supervisorInsertStmt->execute([
                    'user_id' => $newUserId,
                    'office_name' => $officeName,
                    'max_students' => $maxStudents,
                    'phone' => $phone !== '' ? $phone : null,
                ]);

                $pdo->commit();

                // Log to Audit Trail
                $adminName = (string) ($currentUser['name'] ?? 'Admin');
                $supFullName = trim($firstName . ' ' . $lastName);
                sams_log_audit(
                    'SUPERVISOR_CREATE',
                    'SUPERVISOR',
                    "Admin {$adminName} created supervisor account for {$supFullName} ({$officeName}, {$email}).",
                    'user',
                    $newUserId,
                    [
                        'user_id'     => $newUserId,
                        'name'        => $supFullName,
                        'email'       => $email,
                        'office_name' => $officeName,
                        'max_students'=> $maxStudents,
                    ],
                    $currentUser
                );

                $mailStatus = 'sent';
                try {
                    sams_send_supervisor_account_email(
                        $email,
                        trim($firstName . ' ' . $lastName),
                        $password,
                        $officeName,
                        $maxStudents
                    );
                } catch (Throwable $mailException) {
                    $mailStatus = 'failed';
                    error_log('[sams] Supervisor account created but email failed: ' . $mailException->getMessage());
                }

                header('Location: supervisors.php?created=1&mail=' . $mailStatus);
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = $exception->getMessage();
            }
        }
    }
}

if (isset($_GET['created'])) {
    $flashMessage = 'Supervisor account created successfully.';
    if (($_GET['mail'] ?? '') === 'sent') {
        $flashMessage .= ' Login details were sent to the supervisor email.';
    } elseif (($_GET['mail'] ?? '') === 'failed') {
        $flashError = 'Account was created, but the email could not be sent. Check your mail settings and share the temporary password securely.';
    }
}

$editSupervisorId = (int) ($_GET['edit_id'] ?? 0);
$editSupervisor = null;
if ($editSupervisorId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT
            s.supervisor_id,
            s.user_id,
            s.office_name,
            s.max_students,
            s.phone,
            u.email,
            u.first_name,
            u.last_name,
            u.is_active
         FROM supervisors s
         INNER JOIN users u ON u.' . $userIdColumn . ' = s.user_id
         WHERE s.supervisor_id = :supervisor_id
         LIMIT 1'
    );
    $editStmt->execute(['supervisor_id' => $editSupervisorId]);
    $editSupervisor = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashError === '') {
    $action = (string) ($_POST['action'] ?? 'create');
    if ($action === 'update_supervisor' || $action === 'toggle_supervisor_status' || $action === 'reset_supervisor_password') {
        if (!sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
            $flashError = 'Your session expired. Please refresh and try again.';
        } else {
            $supervisorId = (int) ($_POST['supervisor_id'] ?? 0);
            if ($supervisorId <= 0) {
                $flashError = 'Invalid supervisor request.';
            } else {
                $targetStmt = $pdo->prepare(
                    'SELECT s.supervisor_id, s.user_id, s.office_name, s.max_students, s.phone, u.email, u.first_name, u.last_name, u.is_active
                     FROM supervisors s
                     INNER JOIN users u ON u.' . $userIdColumn . ' = s.user_id
                     WHERE s.supervisor_id = :supervisor_id
                     LIMIT 1'
                );
                $targetStmt->execute(['supervisor_id' => $supervisorId]);
                $target = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if (!$target) {
                    $flashError = 'Supervisor account not found.';
                } else {
                    try {
                        if ($action === 'toggle_supervisor_status') {
                            $nextStatus = ((int) ($target['is_active'] ?? 0) === 1) ? 0 : 1;
                            $pdo->beginTransaction();

                            if ($activeColumn !== null) {
                                $updateUserStmt = $pdo->prepare('UPDATE users SET is_active = :is_active WHERE ' . $userIdColumn . ' = :user_id');
                                $updateUserStmt->execute([
                                    'is_active' => $nextStatus,
                                    'user_id' => (int) $target['user_id'],
                                ]);
                            }

                            $pdo->prepare('UPDATE supervisors SET updated_at = NOW() WHERE supervisor_id = :supervisor_id')
                                ->execute(['supervisor_id' => $supervisorId]);
                            $pdo->commit();

                            header('Location: supervisors.php?updated=1');
                            exit;
                        }

                        if ($action === 'reset_supervisor_password') {
                            $tempPassword = bin2hex(random_bytes(4)) . strtoupper(substr((string) ($target['office_name'] ?? 'SA'), 0, 2));
                            $pdo->beginTransaction();

                            $updateSql = 'UPDATE users SET ' . $passwordColumn . ' = :password';
                            $params = [
                                'password' => password_hash($tempPassword, PASSWORD_DEFAULT),
                                'user_id' => (int) $target['user_id'],
                            ];

                            if ($mustChangeColumn !== null) {
                                $updateSql .= ', ' . $mustChangeColumn . ' = 1';
                            }

                            $updateSql .= ' WHERE ' . $userIdColumn . ' = :user_id';
                            $pdo->prepare($updateSql)->execute($params);
                            $pdo->commit();

                            $flashMessage = 'Temporary password reset for ' . (string) ($target['email'] ?? 'supervisor') . '. New temp password: ' . $tempPassword;
                        }

                        if ($action === 'update_supervisor') {
                            $firstName = trim((string) ($_POST['first_name'] ?? ''));
                            $lastName = trim((string) ($_POST['last_name'] ?? ''));
                            $email = trim((string) ($_POST['email'] ?? ''));
                            $newPassword = trim((string) ($_POST['password'] ?? ''));
                            $officeName = trim((string) ($_POST['office_name'] ?? ''));
                            $phone = trim((string) ($_POST['phone'] ?? ''));
                            $maxStudents = (int) ($_POST['max_students'] ?? 5);
                            $isActive = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

                            if ($firstName === '' || $lastName === '' || $email === '' || $officeName === '') {
                                throw new RuntimeException('Please complete all required fields.');
                            }
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                throw new RuntimeException('Please enter a valid email address.');
                            }
                            if ($maxStudents < 1) {
                                throw new RuntimeException('Max students must be at least 1.');
                            }
                            if ($newPassword !== '' && strlen($newPassword) < 8) {
                                throw new RuntimeException('Password must be at least 8 characters.');
                            }

                            $emailCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND ' . $userIdColumn . ' <> :user_id');
                            $emailCheck->execute([
                                'email' => $email,
                                'user_id' => (int) $target['user_id'],
                            ]);
                            if ((int) $emailCheck->fetchColumn() > 0) {
                                throw new RuntimeException('That email is already used by another account.');
                            }

                            $pdo->beginTransaction();

                            $userUpdateSql = 'UPDATE users SET email = :email, first_name = :first_name, last_name = :last_name, is_active = :is_active';
                            $userParams = [
                                'email' => $email,
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'is_active' => $isActive,
                                'user_id' => (int) $target['user_id'],
                            ];

                            if (sams_column_exists($pdo, 'users', 'office_name')) {
                                $userUpdateSql .= ', office_name = :office_name_user';
                                $userParams['office_name_user'] = $officeName;
                            }

                            if ($newPassword !== '') {
                                $userUpdateSql .= ', ' . $passwordColumn . ' = :password_value';
                                $userParams['password_value'] = password_hash($newPassword, PASSWORD_DEFAULT);

                                if ($mustChangeColumn !== null) {
                                    $userUpdateSql .= ', ' . $mustChangeColumn . ' = 0';
                                }
                            }

                            $userUpdateSql .= ' WHERE ' . $userIdColumn . ' = :user_id';
                            $pdo->prepare($userUpdateSql)->execute($userParams);

                            $supervisorUpdateSql = 'UPDATE supervisors SET office_name = :office_name, max_students = :max_students, phone = :phone, updated_at = NOW() WHERE supervisor_id = :supervisor_id';
                            $pdo->prepare($supervisorUpdateSql)->execute([
                                'office_name' => $officeName,
                                'max_students' => $maxStudents,
                                'phone' => $phone !== '' ? $phone : null,
                                'supervisor_id' => $supervisorId,
                            ]);

                            $pdo->commit();
                            header('Location: supervisors.php?updated=1');
                            exit;
                        }
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $flashError = $exception->getMessage();
                    }
                }
            }
        }
    }
}

if (isset($_GET['updated'])) {
    $flashMessage = 'Supervisor account updated successfully.';
}

$supervisorsStmt = $pdo->query(
    'SELECT
        s.supervisor_id,
        s.office_name,
        s.max_students,
        s.phone,
        s.created_at,
        u.email,
        u.first_name,
        u.last_name,
        u.is_active
     FROM supervisors s
     INNER JOIN users u ON u.' . $userIdColumn . ' = s.user_id
     ORDER BY s.created_at DESC, s.supervisor_id DESC'
);
$supervisors = $supervisorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$officeCount = count($officeOptions);
$supervisorCount = count($supervisors);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Supervisor Accounts – Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Inter,Arial,Helvetica,sans-serif;background:var(--color-bg-app);color:var(--color-heading);min-height:100vh;display:flex}
        a{text-decoration:none;color:inherit}
        .shell{display:flex;width:100%;min-height:100vh}
        .sidebar{width:var(--sidebar-width);min-height:100vh;background:var(--color-white);border-right:1px solid var(--color-border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
        .sidebar__brand{display:flex;align-items:center;gap:12px;padding:24px 24px 20px;border-bottom:1px solid var(--color-border)}
        .sidebar__logo{width:40px;height:40px;background:var(--gradient-brand);border-radius:var(--radius-icon);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sidebar__logo-text{font-size:18px;font-weight:700;color:var(--color-white)}
        .sidebar__brand-name{font-size:var(--font-base);font-weight:700;color:var(--color-heading)}
        .sidebar__brand-sub{font-size:var(--font-xs);color:var(--color-body)}
        .sidebar__nav{flex:1;padding:16px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
        .sidebar__nav-link{display:flex;align-items:center;gap:12px;height:48px;padding:0 16px;border-radius:var(--radius-nav);font-size:var(--font-base);color:var(--color-label);transition:background .15s;white-space:nowrap}
        .sidebar__nav-link:hover{background:var(--color-bg-app)}
        .sidebar__nav-link--active{background:var(--color-primary);color:#fff}
        .sidebar__nav-link--active:hover{opacity:.92}
        .sidebar__nav-icon{width:20px;height:20px;flex-shrink:0}
        .sidebar__footer{border-top:1px solid var(--color-border);padding:16px;display:flex;flex-direction:column;gap:4px;flex-shrink:0}
        .main{flex:1;min-width:0;display:flex;flex-direction:column}
        .topbar{background:var(--color-white);border-bottom:1px solid var(--color-border);height:var(--topbar-height);padding:0 32px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-shrink:0;position:sticky;top:0;z-index:50}
        .topbar__title{font-size:var(--font-lg);font-weight:700;color:var(--color-heading)}
        .topbar__sub{font-size:var(--font-sm);color:var(--color-body)}
        .topbar__right{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .page{flex:1;padding:32px;display:flex;flex-direction:column;gap:20px}
        .summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .summary-card{background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--radius-card);padding:20px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
        .summary-card__label{font-size:13px;color:var(--color-body);margin-bottom:6px}
        .summary-card__value{font-size:28px;font-weight:800;color:var(--color-heading)}
        .panel{background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--radius-card);padding:24px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
        .panel__title{font-size:22px;font-weight:800;color:var(--color-heading);margin-bottom:6px}
        .panel__sub{color:var(--color-body);font-size:14px;margin-bottom:18px;max-width:760px}
        .grid-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .field{display:flex;flex-direction:column;gap:6px}
        .field label{font-weight:600;color:var(--color-label);font-size:14px}
        .field input,.field select{width:100%;padding:11px 12px;border:1px solid var(--color-border);border-radius:10px;background:#fff;font:inherit;color:var(--color-heading)}
        .field input:focus,.field select:focus{outline:none;border-color:var(--color-primary)}
        .field--full{grid-column:1 / -1}
        .actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}
        .btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 14px;border-radius:10px;border:1px solid transparent;font-weight:700;cursor:pointer}
        .btn--primary{background:var(--color-primary);color:#fff}
        .btn--primary:hover{background:var(--color-primary-dark)}
        .btn--secondary{background:#fff;color:var(--color-heading);border-color:var(--color-border)}
        .btn--secondary:hover{border-color:var(--color-primary);color:var(--color-primary)}
        .alert{padding:12px 14px;border-radius:12px;border:1px solid var(--color-border);background:#fff}
        .alert--success{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
        .alert--error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:900px}
        thead th{background:#f9fafb;text-align:left;padding:14px 16px;border-bottom:1px solid var(--color-border);font-size:13px;color:var(--color-heading)}
        tbody td{padding:14px 16px;border-bottom:1px solid var(--color-border);font-size:14px;color:var(--color-heading);vertical-align:top}
        .pill{display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:9999px;font-size:12px;font-weight:700;background:#eff6ff;color:#1447e6}
        .pill--off{background:#f3f4f6;color:#4a5565}
        .muted{font-size:13px;color:var(--color-body)}
        @media (max-width: 960px){.summary-grid{grid-template-columns:1fr}.grid-form{grid-template-columns:1fr}.page{padding:20px}.topbar{padding:0 20px}.panel{padding:20px}}
    </style>
</head>
<body>
<div class="shell">
    <?php $activeAdminNav = 'supervisors'; include __DIR__ . '/_sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div>
                <div class="topbar__title">Supervisor Accounts</div>
                <div class="topbar__sub">Assign admin-created supervisor accounts to a single office so their portal data stays scoped.</div>
            </div>
            <div class="topbar__right">
                <a class="btn btn--secondary" href="dashboard.php">Back to Dashboard</a>
                <span class="pill"><?php echo (int) $supervisorCount; ?> accounts</span>
            </div>
        </header>

        <main class="page">
            <section class="summary-grid" aria-label="Supervisor summary">
                <div class="summary-card">
                    <div class="summary-card__label">Supervisor Accounts</div>
                    <div class="summary-card__value"><?php echo (int) $supervisorCount; ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-card__label">Known Offices</div>
                    <div class="summary-card__value"><?php echo (int) $officeCount; ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-card__label">Assignment Rule</div>
                    <div class="summary-card__value" style="font-size:18px;line-height:1.35;">One supervisor = one office</div>
                </div>
            </section>

            <?php if ($editSupervisor !== null): ?>
            <section class="panel">
                <div class="panel__title">Edit Supervisor Account</div>
                <div class="panel__sub">Update office assignment, profile details, and activation status. This keeps the supervisor portal scoped to the assigned office only.</div>

                <form method="post">
                    <?php echo sams_csrf_input_field(); ?>
                    <input type="hidden" name="action" value="update_supervisor" />
                    <input type="hidden" name="supervisor_id" value="<?php echo (int) $editSupervisor['supervisor_id']; ?>" />
                    <div class="grid-form">
                        <div class="field">
                            <label for="edit_first_name">First Name *</label>
                            <input id="edit_first_name" name="first_name" type="text" required value="<?php echo h((string) ($editSupervisor['first_name'] ?? '')); ?>" />
                        </div>
                        <div class="field">
                            <label for="edit_last_name">Last Name *</label>
                            <input id="edit_last_name" name="last_name" type="text" required value="<?php echo h((string) ($editSupervisor['last_name'] ?? '')); ?>" />
                        </div>
                        <div class="field field--full">
                            <label for="edit_email">Email *</label>
                            <input id="edit_email" name="email" type="email" required value="<?php echo h((string) ($editSupervisor['email'] ?? '')); ?>" />
                        </div>
                        <div class="field field--full">
                            <label for="edit_password">New Password</label>
                            <input id="edit_password" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current password" />
                        </div>
                        <div class="field field--full">
                            <label for="edit_office_name">Office Assignment *</label>
                            <input id="edit_office_name" name="office_name" type="text" list="office-options" required value="<?php echo h((string) ($editSupervisor['office_name'] ?? '')); ?>" />
                        </div>
                        <div class="field">
                            <label for="edit_max_students">Max Students</label>
                            <input id="edit_max_students" name="max_students" type="number" min="1" value="<?php echo h((string) ($editSupervisor['max_students'] ?? '5')); ?>" />
                        </div>
                        <div class="field">
                            <label for="edit_phone">Phone</label>
                            <input id="edit_phone" name="phone" type="text" value="<?php echo h((string) ($editSupervisor['phone'] ?? '')); ?>" />
                        </div>
                        <div class="field field--full">
                            <label for="edit_is_active">Account Status</label>
                            <select id="edit_is_active" name="is_active">
                                <option value="1"<?php echo ((int) ($editSupervisor['is_active'] ?? 1) === 1) ? ' selected' : ''; ?>>Active</option>
                                <option value="0"<?php echo ((int) ($editSupervisor['is_active'] ?? 1) === 0) ? ' selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="actions">
                        <button class="btn btn--primary" type="submit">Save Changes</button>
                        <a class="btn btn--secondary" href="supervisors.php">Cancel</a>
                    </div>
                </form>

                <div class="actions" style="margin-top:12px;">
                    <form method="post" style="display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <?php echo sams_csrf_input_field(); ?>
                        <input type="hidden" name="action" value="toggle_supervisor_status" />
                        <input type="hidden" name="supervisor_id" value="<?php echo (int) $editSupervisor['supervisor_id']; ?>" />
                        <button class="btn btn--secondary" type="submit"><?php echo ((int) ($editSupervisor['is_active'] ?? 1) === 1) ? 'Deactivate Account' : 'Activate Account'; ?></button>
                    </form>
                    <form method="post" style="display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <?php echo sams_csrf_input_field(); ?>
                        <input type="hidden" name="action" value="reset_supervisor_password" />
                        <input type="hidden" name="supervisor_id" value="<?php echo (int) $editSupervisor['supervisor_id']; ?>" />
                        <button class="btn btn--secondary" type="submit">Reset Temporary Password</button>
                    </form>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($editSupervisor === null): ?>
            <section class="panel">
                <div class="panel__title">Create Supervisor Account</div>
                <div class="panel__sub">Admin creates the account, sets the login, and assigns the office. Supervisor pages already filter data by this office.</div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert alert--success"><?php echo h($flashMessage); ?></div>
                <?php endif; ?>
                <?php if ($flashError !== ''): ?>
                    <div class="alert alert--error"><?php echo h($flashError); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo sams_csrf_input_field(); ?>
                    <div class="grid-form">
                        <div class="field">
                            <label for="first_name">First Name *</label>
                            <input id="first_name" name="first_name" type="text" required value="<?php echo h((string) ($_POST['first_name'] ?? '')); ?>" />
                        </div>
                        <div class="field">
                            <label for="last_name">Last Name *</label>
                            <input id="last_name" name="last_name" type="text" required value="<?php echo h((string) ($_POST['last_name'] ?? '')); ?>" />
                        </div>
                        <div class="field field--full">
                            <label for="email">Email *</label>
                            <input id="email" name="email" type="email" required value="<?php echo h((string) ($_POST['email'] ?? '')); ?>" />
                        </div>
                        <div class="field">
                            <label for="password">Temporary Password *</label>
                            <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password" />
                        </div>
                        <div class="field">
                            <label for="max_students">Max Students</label>
                            <input id="max_students" name="max_students" type="number" min="1" value="<?php echo h((string) ($_POST['max_students'] ?? '5')); ?>" />
                        </div>
                        <div class="field field--full">
                            <label for="office_name">Office Assignment *</label>
                            <input id="office_name" name="office_name" type="text" list="office-options" placeholder="e.g., ITSO" required value="<?php echo h((string) ($_POST['office_name'] ?? '')); ?>" />
                            <datalist id="office-options">
                                <?php foreach ($officeOptions as $officeOption): ?>
                                    <option value="<?php echo h((string) $officeOption); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="field field--full">
                            <label for="phone">Phone</label>
                            <input id="phone" name="phone" type="text" value="<?php echo h((string) ($_POST['phone'] ?? '')); ?>" />
                        </div>
                    </div>
                    <div class="actions">
                        <button class="btn btn--primary" type="submit">Create Supervisor</button>
                        <span class="muted">The supervisor will see only their assigned office data after login.</span>
                    </div>
                </form>
            </section>
            <?php else: ?>
            <section class="panel">
                <div class="panel__title">Editing Existing Supervisor</div>
                <div class="panel__sub">Create mode is hidden while editing to keep the page focused on one account. Use Cancel to return to the full create form.</div>
            </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel__title">Existing Supervisors</div>
                <div class="panel__sub">Current office assignments in the system.</div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Supervisor</th>
                                <th>Email</th>
                                <th>Office</th>
                                <th>Max Students</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($supervisors)): ?>
                            <?php foreach ($supervisors as $supervisor): ?>
                                <?php $fullName = trim((string) ($supervisor['first_name'] ?? '') . ' ' . (string) ($supervisor['last_name'] ?? '')); ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h($fullName !== '' ? $fullName : 'Supervisor'); ?></strong>
                                        <div class="muted">ID #<?php echo (int) ($supervisor['supervisor_id'] ?? 0); ?></div>
                                    </td>
                                    <td><?php echo h((string) ($supervisor['email'] ?? '')); ?></td>
                                    <td><span class="pill"><?php echo h((string) ($supervisor['office_name'] ?? 'Unassigned')); ?></span></td>
                                    <td><?php echo (int) ($supervisor['max_students'] ?? 0); ?></td>
                                    <td><?php echo h((string) ($supervisor['phone'] ?? '-')); ?></td>
                                    <td>
                                        <?php if ((int) ($supervisor['is_active'] ?? 0) === 1): ?>
                                            <span class="pill">Active</span>
                                        <?php else: ?>
                                            <span class="pill pill--off">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h((string) ($supervisor['created_at'] ?? '-')); ?></td>
                                    <td>
                                        <div class="actions" style="margin-top:0;">
                                            <a class="btn btn--secondary" href="supervisors.php?edit_id=<?php echo (int) ($supervisor['supervisor_id'] ?? 0); ?>">Edit</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="muted">No supervisor accounts created yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
