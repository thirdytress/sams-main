<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
  header('Location: ../login.php');
  exit;
}

$pdo = sams_pdo();

// Student details
$studentName = trim((string) ($currentUser['name'] ?? 'Student Assistant'));
$studentCode = '';
$studentStmt = $pdo->prepare('SELECT student_id_number FROM students WHERE user_id = :user_id LIMIT 1');
$studentStmt->execute(['user_id' => (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
$studentCode = (string) ($studentStmt->fetchColumn() ?: '');

// Fetch recent announcements for students
$stmt = $pdo->prepare(
  'SELECT a.id, a.title, a.body, a.created_at, 
          EXISTS(SELECT 1 FROM announcement_reads r WHERE r.announcement_id = a.id AND r.user_id = :user_id) AS is_read
   FROM announcements a
   WHERE a.is_active = 1 AND a.audience IN ("students","all")
   ORDER BY a.created_at DESC'
);
$stmt->execute(['user_id' => (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function escape($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Announcements – SAMS Student Portal | NU Lipa</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: #f8fafc;
      color: var(--color-body, #334155);
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
    }
    .app-shell {
      display: flex;
      min-height: 100vh;
    }
    .main-viewport {
      flex: 1;
      min-width: 0;
      padding: 32px 28px;
    }
    .announcements-container {
      max-width: 920px;
    }
    .hero-banner {
      background: linear-gradient(135deg, #003087 0%, #155dfc 100%);
      color: #fff;
      border-radius: 18px;
      padding: 24px 28px;
      margin-bottom: 24px;
      box-shadow: 0 10px 25px rgba(0, 48, 135, 0.15);
    }
    .hero-banner h1 { margin: 0 0 6px; font-size: 24px; font-weight: 800; font-family: 'Poppins', sans-serif; }
    .hero-banner p { margin: 0; color: #dbeafe; font-size: 14px; }
    .announcements-card {
      background: #ffffff;
      border-radius: 16px;
      padding: 24px 28px;
      border: 1px solid var(--color-border, #e2e8f0);
      box-shadow: 0 2px 6px rgba(15,23,42,0.04);
    }
    .announcement {
      border-left: 4px solid #003087;
      padding: 18px 20px;
      border-radius: 12px;
      margin-bottom: 16px;
      background: #ffffff;
      border-top: 1px solid #e2e8f0;
      border-right: 1px solid #e2e8f0;
      border-bottom: 1px solid #e2e8f0;
      box-shadow: 0 1px 2px rgba(15,23,42,0.04);
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .announcement:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(15,23,42,0.08);
    }
    .announcement--yellow {
      border-left-color: #ffb81c;
      background: #fffdf8;
    }
    .announcement--green {
      border-left-color: #10b981;
      background: #fcfdfd;
    }
    .announcement__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
    }
    .announcement__time {
      font-size: 12px;
      font-weight: 600;
      color: #64748b;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .announcement__status {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 2px 8px;
      border-radius: 9999px;
    }
    .announcement__status--unread {
      background: #fff7e6;
      color: #b37b00;
      border: 1px solid #fff2d1;
    }
    .announcement__title {
      font-family: 'Poppins', sans-serif;
      font-weight: 700;
      font-size: 17px;
      color: #0f172a;
      margin-bottom: 8px;
      line-height: 1.35;
    }
    .announcement__body {
      color: #334155;
      font-size: 14px;
      line-height: 1.6;
    }
    .read {
      opacity: 0.68;
    }
    .read .announcement__status--unread {
      display: none;
    }
    .empty-state {
      padding: 48px 24px;
      text-align: center;
      color: #64748b;
      font-size: 15px;
    }
    @media (max-width: 900px) {
      .app-shell { flex-direction: column; }
      .main-viewport { padding: 16px; }
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <?php 
      $activeStudentNav = 'announcements';
      require_once __DIR__ . '/_sidebar.php'; 
    ?>

    <main class="main-viewport">
      <div class="announcements-container">
        <div class="hero-banner">
          <h1>Student Announcements</h1>
          <p>Official bulletins, schedule reminders, and campus updates for Student Assistants.</p>
        </div>

        <div class="announcements-card">
          <div id="anns">
            <?php if (empty($announcements)): ?>
              <div class="empty-state">No announcements yet. Check back later for updates.</div>
            <?php else: ?>
              <?php foreach ($announcements as $i => $a): ?>
                <?php 
                  $isRead = (bool)((int)($a['is_read'] ?? 0));
                  $cls = $i % 3 === 0 ? 'announcement' : ($i % 3 === 1 ? 'announcement announcement--yellow' : 'announcement announcement--green'); 
                ?>
                <div class="<?php echo $cls; ?> <?php echo $isRead ? 'read' : ''; ?>" data-id="<?php echo (int)$a['id']; ?>">
                  <div class="announcement__header">
                    <span class="announcement__time">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                      <?php echo escape(date('M j, Y g:i A', strtotime((string)$a['created_at']))); ?>
                    </span>
                    <?php if (!$isRead): ?>
                      <span class="announcement__status announcement__status--unread">New</span>
                    <?php endif; ?>
                  </div>
                  <div class="announcement__title"><?php echo escape($a['title']); ?></div>
                  <div class="announcement__body"><?php echo nl2br(escape($a['body'])); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    // Clicking an announcement marks it as read (idempotent)
    document.querySelectorAll('.announcement').forEach(function(el){
      el.addEventListener('click', function(){
        var id = parseInt(el.getAttribute('data-id'), 10);
        fetch('../api/announcements/mark_read.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.SAMS_CSRF || '' },
          body: JSON.stringify({ announcement_id: id })
        }).then(function(){ el.classList.add('read'); }).catch(function(){ /* ignore */ });
      });
    });
  </script>
</body>
</html>
