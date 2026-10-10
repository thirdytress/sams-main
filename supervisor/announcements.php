<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();

$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorName = trim((string) ($user['name'] ?? 'Supervisor'));
$officeName = trim((string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? 'Assigned Office')));

$stmt = $pdo->prepare("SELECT id, title, body, audience, is_active, created_at FROM announcements WHERE is_active = 1 AND audience IN ('supervisors','all') ORDER BY created_at DESC LIMIT 100");
$stmt->execute();
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Announcements — Supervisor Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
  <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Inter, Arial, sans-serif; background: #f8fafc; color: var(--color-heading); }
    .page-content { padding: 28px 32px; max-width: 1100px; }
    .ann-hero {
      background: linear-gradient(135deg, #003087 0%, #155dfc 100%);
      color: #fff;
      border-radius: 18px;
      padding: 24px 28px;
      margin-bottom: 24px;
      box-shadow: 0 10px 25px rgba(0, 48, 135, 0.15);
    }
    .ann-hero h1 { margin: 0 0 6px; font-size: 24px; font-weight: 800; }
    .ann-hero p { margin: 0; color: #dbeafe; font-size: 14px; }
    .ann-grid { display: flex; flex-direction: column; gap: 16px; }
    .ann-card {
      background: #ffffff;
      border: 1px solid var(--color-border, #e2e8f0);
      border-radius: 16px;
      padding: 22px 24px;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .ann-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.07);
    }
    .ann-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
    .ann-title { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
    .ann-badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      white-space: nowrap;
    }
    .ann-meta {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 13px;
      color: #64748b;
      margin-bottom: 14px;
      padding-bottom: 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .ann-body { color: #334155; font-size: 14.5px; line-height: 1.6; }
    .empty-state {
      background: #fff;
      border: 1px dashed #cbd5e1;
      border-radius: 16px;
      padding: 48px 24px;
      text-align: center;
      color: #64748b;
    }
  </style>
</head>
<body>
<div class="shell">
<?php 
    $activeSupervisorNav = 'announcements';
    require_once __DIR__ . '/_sidebar.php'; 
?>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="topbar__title">Announcements</div>
                <div class="topbar__sub"><?php echo h($officeName); ?> · <?php echo h($supervisorName); ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <a href="profile.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:#101828;font-weight:700;font-size:14px;" title="My Profile">
                    <?php $supAv = sams_user_avatar_url($user['profile_image'] ?? null, '../'); ?>
                    <?php if ($supAv): ?>
                        <img src="<?php echo h($supAv); ?>?v=<?php echo time(); ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1.5px solid #003087;">
                    <?php else: ?>
                        <div style="width:36px;height:36px;border-radius:50%;background:#003087;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;">
                            <?php echo h(strtoupper(substr($supervisorName, 0, 1))); ?>
                        </div>
                    <?php endif; ?>
                    <span><?php echo h($supervisorName); ?></span>
                </a>
            </div>
        </header>

        <div class="page-content">
            <div class="ann-hero">
                <h1>Official Announcements & Bulletins</h1>
                <p>Stay up-to-date with institutional notices, term schedules, and university announcements for supervisors.</p>
            </div>

            <?php if (empty($announcements)): ?>
                <div class="empty-state">
                    <p style="margin:0;font-size:15px;font-weight:600;">No announcements posted at this time.</p>
                    <p style="margin:6px 0 0;font-size:13px;">Check back later for new updates from administrator office.</p>
                </div>
            <?php else: ?>
                <div class="ann-grid">
                    <?php foreach ($announcements as $a): ?>
                        <article class="ann-card">
                            <div class="ann-header">
                                <h2 class="ann-title"><?php echo h($a['title']); ?></h2>
                                <span class="ann-badge"><?php echo h($a['audience'] === 'supervisors' ? 'Supervisor Notice' : 'General Announcement'); ?></span>
                            </div>
                            <div class="ann-meta">
                                <span>📅 <?php echo date('M d, Y · h:i A', strtotime((string)$a['created_at'])); ?></span>
                            </div>
                            <div class="ann-body"><?php echo nl2br(h($a['body'])); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
