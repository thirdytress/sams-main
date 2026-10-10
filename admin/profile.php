<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/audit.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_ensure_profile_image_schema($pdo);
$userId = (int) ($currentUser['user_id'] ?? 0);
$message = '';
$error = '';

$profileStatement = $pdo->prepare(
    'SELECT user_id, email, first_name, last_name, phone_number, profile_image, role, created_at
     FROM users WHERE user_id = :user_id LIMIT 1'
);
$profileStatement->execute(['user_id' => $userId]);
$profile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'update_profile');

        if ($action === 'remove_photo') {
            $oldImg = (string) ($profile['profile_image'] ?? '');
            if ($oldImg !== '' && str_starts_with($oldImg, 'uploads/avatars/')) {
                $oldFile = dirname(__DIR__) . '/' . $oldImg;
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $pdo->prepare('UPDATE users SET profile_image = NULL WHERE user_id = :id')->execute(['id' => $userId]);
            $_SESSION['sams_user']['profile_image'] = null;
            $profile['profile_image'] = null;
            $message = 'Profile photo removed successfully.';

            sams_log_audit($pdo, 'DELETE', 'Profile', "Admin {$currentUser['name']} removed their profile photo.", [], $userId, 'user');
        } else {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = preg_replace('/\D+/', '', (string) ($_POST['phone_number'] ?? ''));

            if ($firstName === '' || $lastName === '' || $email === '') {
                $error = 'First name, last name, and email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                try {
                    $duplicate = $pdo->prepare(
                        'SELECT COUNT(*) FROM users WHERE email = :email AND user_id <> :user_id'
                    );
                    $duplicate->execute(['email' => $email, 'user_id' => $userId]);
                    if ((int) $duplicate->fetchColumn() > 0) {
                        throw new RuntimeException('That email is already in use.');
                    }

                    if ($phone !== '') {
                        $phoneDuplicate = $pdo->prepare(
                            'SELECT COUNT(*) FROM users WHERE phone_number = :phone AND user_id <> :user_id'
                        );
                        $phoneDuplicate->execute(['phone' => $phone, 'user_id' => $userId]);
                        if ((int) $phoneDuplicate->fetchColumn() > 0) {
                            throw new RuntimeException('That phone number is already in use.');
                        }
                    }

                    // Handle photo upload if provided
                    if (isset($_FILES['profile_photo']) && ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $uploadRes = sams_handle_avatar_upload($userId, $_FILES['profile_photo']);
                        if (!$uploadRes['success']) {
                            throw new RuntimeException($uploadRes['error']);
                        }
                        $profile['profile_image'] = $uploadRes['path'];
                    }

                    $update = $pdo->prepare(
                        'UPDATE users SET first_name = :first_name, last_name = :last_name,
                         email = :email, phone_number = :phone_number
                         WHERE user_id = :user_id'
                    );
                    $update->execute([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'phone_number' => $phone !== '' ? $phone : null,
                        'user_id' => $userId,
                    ]);

                    $_SESSION['sams_user'] = array_merge($_SESSION['sams_user'], [
                        'email' => $email,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'name' => sams_normalize_name($firstName, $lastName),
                    ]);
                    $message = 'Profile updated successfully.';
                    $profile['first_name'] = $firstName;
                    $profile['last_name'] = $lastName;
                    $profile['email'] = $email;
                    $profile['phone_number'] = $phone;

                    sams_log_audit($pdo, 'UPDATE', 'Profile', "Admin {$firstName} {$lastName} updated account profile information.", ['email' => $email], $userId, 'user');
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }
    }
}

$activeAdminNav = 'profile';
$displayName = sams_normalize_name($profile['first_name'] ?? null, $profile['last_name'] ?? null);
$avatarUrl = sams_user_avatar_url($profile['profile_image'] ?? null, '../');
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | SAMS</title>
    <link rel="stylesheet" href="../assets/css/admin-shell.css?v=20260925">
    <style>
        .profile-wrap { max-width: 800px; margin: 0 auto; }
        .profile-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 32px; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
        .profile-heading { display: flex; align-items: center; gap: 24px; margin-bottom: 28px; padding-bottom: 24px; border-bottom: 1px solid #f3f4f6; }
        
        .avatar-uploader { position: relative; width: 90px; height: 90px; flex-shrink: 0; }
        .avatar-uploader__preview {
            width: 90px; height: 90px; border-radius: 50%; display: grid; place-items: center;
            color: #fff; background: #003087; font-size: 32px; font-weight: 800; overflow: hidden;
            border: 3px solid #e5e7eb; box-shadow: 0 4px 10px rgba(0,48,135,.15);
        }
        .avatar-uploader__preview img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-uploader__btn {
            position: absolute; bottom: 0; right: 0; width: 32px; height: 32px;
            background: #003087; color: #fff; border-radius: 50%; border: 2px solid #fff;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,.2); transition: background .2s, transform .2s;
        }
        .avatar-uploader__btn:hover { background: #ffb81c; color: #003087; transform: scale(1.08); }
        
        .profile-heading h1 { margin: 0; font-size: 24px; font-weight: 800; color: #101828; }
        .profile-heading p { margin: 4px 0 0; color: #667085; font-size: 14px; font-weight: 500; }
        
        .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .profile-field { display: flex; flex-direction: column; gap: 7px; }
        .profile-field--full { grid-column: 1 / -1; }
        .profile-field label { font-size: 13px; font-weight: 700; color: #364153; }
        .profile-field input { min-height: 44px; padding: 0 14px; border: 1.5px solid #d1d5db; border-radius: 8px; font: inherit; font-size: 14px; }
        .profile-field input:focus { outline: none; border-color: #003087; box-shadow: 0 0 0 3px rgba(0,48,135,.12); }
        
        .profile-actions { margin-top: 28px; display: flex; justify-content: space-between; align-items: center; }
        .profile-btn { min-height: 44px; padding: 0 20px; border: 0; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; }
        .profile-btn--primary { color: #fff; background: #003087; box-shadow: 0 2px 6px rgba(0,48,135,.25); }
        .profile-btn--primary:hover { opacity: .92; }
        .profile-btn--secondary { color: #364153; background: #f3f4f6; text-decoration: none; }
        .profile-btn--secondary:hover { background: #e5e7eb; }
        .profile-btn--danger-sm { color: #991b1b; background: #fee2e2; border: 0; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .profile-btn--danger-sm:hover { background: #fecaca; }
        
        .profile-alert { padding: 14px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
        .profile-alert--success { background: #ecfdf3; color: #027a48; border: 1px solid #abebd2; }
        .profile-alert--error { background: #fef3f2; color: #b42318; border: 1px solid #fecdca; }
        @media (max-width: 640px) { .profile-grid { grid-template-columns: 1fr; } .profile-heading { flex-direction: column; text-align: center; } .profile-actions { flex-direction: column-reverse; gap: 12px; } .profile-btn { width: 100%; justify-content: center; } }
    </style>
</head>
<body>
<div class="shell">
    <?php require __DIR__ . '/_sidebar.php'; ?>
    <div class="main">
        <header class="topbar">
            <div><div class="topbar__title">Admin Profile</div><div class="topbar__sub">Manage your personal and account details</div></div>
        </header>
        <main class="page">
            <div class="profile-wrap">
                <section class="profile-card">
                    
                    <?php if ($message !== ''): ?><div class="profile-alert profile-alert--success">✅ <?= $h($message) ?></div><?php endif; ?>
                    <?php if ($error !== ''): ?><div class="profile-alert profile-alert--error">⚠️ <?= $h($error) ?></div><?php endif; ?>

                    <form method="post" enctype="multipart/form-data" id="profile-form">
                        <?= sams_csrf_input_field() ?>
                        <input type="hidden" name="action" value="update_profile" id="form-action">

                        <div class="profile-heading">
                            <div class="avatar-uploader">
                                <div class="avatar-uploader__preview" id="avatar-preview">
                                    <?php if ($avatarUrl): ?>
                                        <img src="<?= $h($avatarUrl) ?>?v=<?= time() ?>" alt="<?= $h($displayName) ?>">
                                    <?php else: ?>
                                        <span><?= $h(strtoupper(substr($displayName, 0, 1))) ?></span>
                                    <?php endif; ?>
                                </div>
                                <label for="profile_photo" class="avatar-uploader__btn" title="Change Profile Picture">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                                        <circle cx="12" cy="13" r="4"></circle>
                                    </svg>
                                </label>
                                <input type="file" id="profile_photo" name="profile_photo" accept="image/png, image/jpeg, image/webp, image/gif" style="display:none;" onchange="previewAvatar(this)">
                            </div>
                            <div style="flex:1;">
                                <h1><?= $h($displayName) ?></h1>
                                <p>SDAO Administrator &bull; <?= $h($profile['email'] ?? '') ?></p>
                                <?php if ($avatarUrl): ?>
                                    <button type="button" class="profile-btn--danger-sm" style="margin-top:8px;" onclick="removePhoto()">Remove Photo</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="profile-grid">
                            <div class="profile-field">
                                <label for="first_name">First name <span style="color:#e11d48;">*</span></label>
                                <input id="first_name" name="first_name" required value="<?= $h($profile['first_name'] ?? '') ?>">
                            </div>
                            <div class="profile-field">
                                <label for="last_name">Last name <span style="color:#e11d48;">*</span></label>
                                <input id="last_name" name="last_name" required value="<?= $h($profile['last_name'] ?? '') ?>">
                            </div>
                            <div class="profile-field profile-field--full">
                                <label for="email">Email address <span style="color:#e11d48;">*</span></label>
                                <input id="email" name="email" type="email" required value="<?= $h($profile['email'] ?? '') ?>">
                            </div>
                            <div class="profile-field profile-field--full">
                                <label for="phone_number">Contact number</label>
                                <input id="phone_number" name="phone_number" inputmode="numeric" placeholder="e.g. 09123456789" value="<?= $h($profile['phone_number'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="profile-actions">
                            <a class="profile-btn profile-btn--secondary" href="dashboard.php">Back to Dashboard</a>
                            <button class="profile-btn profile-btn--primary" type="submit">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
</div>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var container = document.getElementById('avatar-preview');
            container.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="width:100%;height:100%;object-fit:cover;">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removePhoto() {
    if (confirm('Are you sure you want to remove your profile photo?')) {
        document.getElementById('form-action').value = 'remove_photo';
        document.getElementById('profile-form').submit();
    }
}
</script>
</body>
</html>