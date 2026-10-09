<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/reshuffle.php';

$admin = sams_authenticated_user();
if (!$admin || ($admin['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_reshuffle_ensure_schema($pdo);

$message = '';
$error = '';
$adminIdStmt = $pdo->prepare('SELECT admin_id FROM admins WHERE user_id = :user_id LIMIT 1');
$adminIdStmt->execute(['user_id' => (int) ($admin['user_id'] ?? 0)]);
$adminId = (int) ($adminIdStmt->fetchColumn() ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
        if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            throw new RuntimeException('Invalid shuffle request action.');
        }

        $pdo->beginTransaction();
        $requestStmt = $pdo->prepare(
            'SELECT sr.*, aFrom.application_id AS from_application_id, aTo.application_id AS to_application_id,
                    CONCAT(uFrom.first_name, " ", uFrom.last_name) AS from_name,
                    sFrom.reshuffle_count AS current_reshuffle_count
             FROM shuffle_requests sr
             INNER JOIN applications aFrom ON aFrom.student_id = sr.from_student_id AND aFrom.term_id = sr.term_id
             INNER JOIN students sFrom ON sFrom.student_id = sr.from_student_id
             INNER JOIN users uFrom ON uFrom.user_id = sFrom.user_id
             LEFT JOIN applications aTo ON aTo.student_id = sr.to_student_id AND aTo.term_id = sr.term_id AND aTo.status IN ("approved", "deployed")
             WHERE sr.request_id = :request_id AND sr.status = "pending" LIMIT 1'
        );
        $requestStmt->execute(['request_id' => $requestId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$request) {
            throw new RuntimeException('Request is no longer pending.');
        }

        if ($decision === 'reject') {
            $update = $pdo->prepare('UPDATE shuffle_requests SET status = "rejected", reviewed_by = :admin_id, reviewed_at = NOW(), review_notes = :notes WHERE request_id = :request_id');
            $update->execute(['admin_id' => $adminId ?: null, 'notes' => $reviewNotes, 'request_id' => $requestId]);
            $message = 'Shuffle request rejected.';
        } else {
            $fromStudentId = (int) $request['from_student_id'];
            $currentShuffles = (int) ($request['current_reshuffle_count'] ?? 0);

            if ($currentShuffles >= 3) {
                throw new RuntimeException('Student ' . $request['from_name'] . ' has already reached the maximum limit of 3 reshuffles.');
            }

            $toApplicationId = (int) ($request['to_application_id'] ?? 0);
            if ($toApplicationId <= 0 && empty($_POST['assign_new_to_student_id'])) {
                // Check if admin chose a replacement student on the fly
                throw new RuntimeException('A replacement student must be designated before approving this reshuffle request.');
            }

            if ($toApplicationId <= 0 && !empty($_POST['assign_new_to_student_id'])) {
                $newToStudentId = (int) $_POST['assign_new_to_student_id'];
                $findApp = $pdo->prepare('SELECT application_id FROM applications WHERE student_id = :sid AND term_id = :tid AND status IN ("approved", "deployed") LIMIT 1');
                $findApp->execute(['sid' => $newToStudentId, 'tid' => (int) $request['term_id']]);
                $toApplicationId = (int) ($findApp->fetchColumn() ?: 0);
                if ($toApplicationId <= 0) {
                    throw new RuntimeException('Invalid replacement student chosen.');
                }
                $pdo->prepare('UPDATE shuffle_requests SET to_student_id = :to_sid WHERE request_id = :rid')
                    ->execute(['to_sid' => $newToStudentId, 'rid' => $requestId]);
            }

            $scheduleStmt = $pdo->prepare('SELECT * FROM duty_schedules WHERE application_id = :application_id AND term_id = :term_id AND status = "deployed" ORDER BY duty_id');
            $scheduleStmt->execute(['application_id' => (int) $request['from_application_id'], 'term_id' => (int) $request['term_id']]);
            $oldSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($oldSchedules) {
                $pdo->prepare('UPDATE duty_schedules SET status = "declined", student_response_date = NOW(), updated_at = NOW() WHERE application_id = :application_id AND term_id = :term_id AND status = "deployed"')
                    ->execute(['application_id' => (int) $request['from_application_id'], 'term_id' => (int) $request['term_id']]);

                $hasOffice = sams_column_exists($pdo, 'duty_schedules', 'office_name');
                $insertSql = $hasOffice
                    ? 'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :office_name, :term_id, :day, :start_time, :end_time, "deployed")'
                    : 'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :term_id, :day, :start_time, :end_time, "deployed")';
                $insert = $pdo->prepare($insertSql);
                foreach ($oldSchedules as $schedule) {
                    $params = ['application_id' => $toApplicationId, 'term_id' => (int) $request['term_id'], 'day' => $schedule['day_of_week'], 'start_time' => $schedule['start_time'], 'end_time' => $schedule['end_time']];
                    if ($hasOffice) {
                        $params['office_name'] = $schedule['office_name'] ?? null;
                    }
                    $insert->execute($params);
                }
            }

            // Increment student reshuffle count
            $newCount = $currentShuffles + 1;
            $pdo->prepare('UPDATE students SET reshuffle_count = :cnt WHERE student_id = :sid')
                ->execute(['cnt' => $newCount, 'sid' => $fromStudentId]);

            $history = $pdo->prepare('INSERT INTO shuffle_history (term_id, shuffled_by, from_student_id, to_student_id, old_schedule_data, new_schedule_data, reason) VALUES (:term_id, :admin_id, :from_id, :to_id, :old_data, :new_data, :reason)');
            $history->execute([
                'term_id' => (int) $request['term_id'],
                'admin_id' => $adminId ?: null,
                'from_id' => $fromStudentId,
                'to_id' => (int) ($request['to_student_id'] ?? 0),
                'old_data' => json_encode(['from_student_id' => $fromStudentId, 'schedules' => $oldSchedules]),
                'new_data' => json_encode(['to_student_id' => (int) $request['to_student_id'], 'application_id' => $toApplicationId]),
                'reason' => $request['reason'] . ($reviewNotes !== '' ? "\nAdmin: " . $reviewNotes : '')
            ]);

            $update = $pdo->prepare('UPDATE shuffle_requests SET status = "approved", reviewed_by = :admin_id, reviewed_at = NOW(), review_notes = :notes WHERE request_id = :request_id');
            $update->execute(['admin_id' => $adminId ?: null, 'notes' => $reviewNotes, 'request_id' => $requestId]);

            if ($newCount >= 3) {
                $message = 'Shuffle approved and schedules transferred. ⚠️ Notice: Student ' . $request['from_name'] . ' has now reached the maximum limit of 3 office transfers.';
            } else {
                $message = 'Shuffle approved and schedules transferred. (Student ' . $request['from_name'] . ' transfer count: ' . $newCount . ' of 3).';
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$requests = $pdo->query(
    'SELECT sr.*, t.term_name, t.term_year, sup.office_name,
            CONCAT(uSup.first_name, " ", uSup.last_name) AS supervisor_name,
            CONCAT(uFrom.first_name, " ", uFrom.last_name) AS from_name,
            sFrom.student_id_number AS from_code,
            COALESCE(sFrom.reshuffle_count, 0) AS from_reshuffle_count,
            CONCAT(uTo.first_name, " ", uTo.last_name) AS to_name,
            sTo.student_id_number AS to_code,
            e.performance_rating, e.reliability_rating, e.professionalism_rating, e.comments AS eval_comments
     FROM shuffle_requests sr
     INNER JOIN terms t ON t.term_id = sr.term_id
     INNER JOIN supervisors sup ON sup.supervisor_id = sr.supervisor_id
     INNER JOIN users uSup ON uSup.user_id = sup.user_id
     INNER JOIN students sFrom ON sFrom.student_id = sr.from_student_id
     INNER JOIN users uFrom ON uFrom.user_id = sFrom.user_id
     LEFT JOIN evaluations e ON e.evaluation_id = sr.evaluation_id
     LEFT JOIN students sTo ON sTo.student_id = sr.to_student_id
     LEFT JOIN users uTo ON uTo.user_id = sTo.user_id
     ORDER BY FIELD(sr.status, "pending", "approved", "rejected", "cancelled"), sr.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$maxStudents = sams_admin_get_max_reshuffle_students($pdo);
$pendingCount = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Shuffle Requests & Transfer Limits | SAMS Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <style>
    :root {
      --primary: #155dfc;
      --border: #e2e8f0;
      --surface: #ffffff;
      --text: #0f172a;
      --muted: #64748b;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: var(--text); }
    a { text-decoration: none; color: inherit; }
    .page { padding: 32px; }
    .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.04); margin-bottom: 24px; }
    .topbar { background: #fff; border-bottom: 1px solid var(--border); height: 80px; padding: 0 32px; display: flex; align-items: center; justify-content: space-between; }
    .topbar__title { font-size: 20px; font-weight: 800; }
    .topbar__sub { font-size: 13px; color: var(--muted); }
    .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
    .tile { border: 1px solid var(--border); border-radius: 12px; padding: 16px; background: #f8fafc; }
    .tile span { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; display: block; margin-bottom: 4px; }
    .tile strong { font-size: 20px; font-weight: 800; color: var(--text); }
    .table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
    th { background: #f1f5f9; padding: 12px 16px; color: #475569; font-weight: 700; border-bottom: 1px solid var(--border); white-space: nowrap; }
    td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
    tr:last-child td { border-bottom: 0; }
    tr:hover td { background: #fafafa; }
    .btn { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 12px; border-radius: 8px; border: 0; cursor: pointer; font-weight: 600; font-size: 13px; text-decoration: none; }
    .btn--primary { background: #155dfc; color: #fff; }
    .btn--danger { background: #ef4444; color: #fff; }
    .btn--sec { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
    .badge { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; }
    .badge--pending { background: #fef3c7; color: #92400e; }
    .badge--approved { background: #dcfce7; color: #166534; }
    .badge--rejected { background: #fee2e2; color: #991b1b; }
    .badge--max { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-weight: 800; }
    .pill { display: inline-block; padding: 2px 7px; border-radius: 6px; font-size: 11px; font-weight: 700; background: #f1f5f9; color: #475569; }
    .pill--warn { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .pill--max { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-weight: 800; }
    .flash-msg { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
    .flash--succ { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .flash--err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  </style>
</head>
<body>
<div class="shell">
  <?php $activeAdminNav = 'shuffle_requests'; require __DIR__ . '/_sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <div>
        <div class="topbar__title">Student Reshuffle & Transfer Management</div>
        <div class="topbar__sub">Review supervisor post-evaluation transfer requests (Enforcing 3-transfer limit per student)</div>
      </div>
    </header>

    <main class="page">
      <?php if ($message !== ''): ?><div class="flash-msg flash--succ">✓ <?= $h($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="flash-msg flash--err">⚠️ <?= $h($error) ?></div><?php endif; ?>

      <!-- High-level stats -->
      <div class="grid">
        <div class="tile"><span>Pending Reshuffle Requests</span><strong><?= (int) $pendingCount ?></strong></div>
        <div class="tile"><span>Total Requests Logged</span><strong><?= count($requests) ?></strong></div>
        <div class="tile"><span>Students at Max Reshuffles (3/3)</span><strong style="color:<?= count($maxStudents) > 0 ? '#dc2626' : '#0f172a' ?>;"><?= count($maxStudents) ?></strong></div>
      </div>

      <!-- Alert if any student has reached 3 reshuffles -->
      <?php if (!empty($maxStudents)): ?>
        <div class="card" style="background:#fff7ed;border-color:#ffedd5;border-left:4px solid #ea580c;padding:18px 22px;">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
            <span style="font-size:18px;">⚠️</span>
            <strong style="font-size:15px;color:#9a3412;">Maximum Reshuffle Threshold Alert (3 of 3 Transfers)</strong>
          </div>
          <p style="font-size:13px;color:#7c2d12;line-height:1.4;margin:0 0 12px;">
            The following student assistants have reached the maximum allowed limit of 3 office transfers across academic terms. No further supervisor reshuffle requests can be accepted for them.
          </p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($maxStudents as $ms): ?>
              <span class="badge badge--max" style="padding:6px 12px;font-size:12px;">
                <?= $h(trim($ms['first_name'] . ' ' . $ms['last_name'])) ?> (<?= $h($ms['student_id_number']) ?>) • <?= $h($ms['office_name']) ?> • 3/3 Transfers
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <section class="card">
        <div style="padding-bottom:16px;border-bottom:1px solid var(--border);margin-bottom:16px;">
          <h1 style="font-size:18px;font-weight:800;">Supervisor Transfer Requests</h1>
          <p style="margin:4px 0 0;color:var(--muted);font-size:13px;">
            Reshuffling is evaluated based on supervisor performance reviews and office balancing. A student can only be transferred up to 3 times.
          </p>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Status</th>
                <th>Office & Supervisor</th>
                <th>Current Student Assistant</th>
                <th>Evaluation Rating</th>
                <th>Transfer History</th>
                <th>Proposed Replacement</th>
                <th>Reason</th>
                <th>Admin Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($requests)): ?>
                <tr><td colspan="8" style="padding:32px;text-align:center;color:var(--muted);">No student reshuffle requests submitted yet.</td></tr>
              <?php else: ?>
                <?php foreach ($requests as $request): ?>
                  <?php
                    $curShuffles = (int) ($request['from_reshuffle_count'] ?? 0);
                    $willReachMax = ($curShuffles + 1 >= 3);
                    $hasEval = !empty($request['evaluation_id']);
                    $avgEval = $hasEval ? round(((int)$request['performance_rating'] + (int)$request['reliability_rating'] + (int)$request['professionalism_rating']) / 3, 1) : 0;
                  ?>
                  <tr>
                    <td>
                      <span class="badge badge--<?= $h($request['status']) ?>"><?= $h(ucfirst($request['status'])) ?></span>
                      <div style="font-size:11px;color:var(--muted);margin-top:4px;"><?= $h($request['term_name'] . ' ' . $request['term_year']) ?></div>
                    </td>
                    <td>
                      <strong><?= $h($request['office_name']) ?></strong>
                      <div style="font-size:12px;color:var(--muted);"><?= $h($request['supervisor_name']) ?></div>
                    </td>
                    <td>
                      <strong><?= $h($request['from_name']) ?></strong>
                      <div style="font-size:12px;color:var(--muted);"><code><?= $h($request['from_code']) ?></code></div>
                    </td>
                    <td>
                      <?php if ($hasEval): ?>
                        <strong style="color:#059669;"><?= number_format((float)$avgEval, 1) ?> / 5.0</strong>
                        <?php if (!empty($request['eval_comments'])): ?>
                          <div style="font-size:11px;color:var(--muted);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= $h($request['eval_comments']) ?>">"<?= $h($request['eval_comments']) ?>"</div>
                        <?php endif; ?>
                      <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">--</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($curShuffles >= 3): ?>
                        <span class="pill pill--max">3/3 Max Reached</span>
                      <?php elseif ($curShuffles === 2): ?>
                        <span class="pill pill--warn">2 of 3 (Next is Final)</span>
                      <?php else: ?>
                        <span class="pill"><?= $curShuffles ?> of 3 Transfers</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= $h($request['to_name'] ?: 'Admin to Assign') ?>
                      <?php if (!empty($request['to_code'])): ?>
                        <div style="font-size:12px;color:var(--muted);"><code><?= $h($request['to_code']) ?></code></div>
                      <?php endif; ?>
                    </td>
                    <td style="max-width:240px;line-height:1.4;">
                      <?= nl2br($h($request['reason'])) ?>
                    </td>
                    <td>
                      <?php if ($request['status'] === 'pending'): ?>
                        <form method="post" style="display:flex;flex-direction:column;gap:6px;min-width:180px;">
                          <?= sams_csrf_input_field() ?>
                          <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                          <input name="review_notes" placeholder="Admin note (optional)" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;">

                          <?php if ($willReachMax): ?>
                            <div style="font-size:11px;color:#dc2626;font-weight:700;">⚠️ Approval will reach 3/3 limit</div>
                          <?php endif; ?>

                          <div style="display:flex;gap:6px;">
                            <button name="decision" value="approve" type="submit" class="btn btn--primary" style="flex:1;height:30px;font-size:12px;">Approve</button>
                            <button name="decision" value="reject" type="submit" class="btn btn--sec" style="flex:1;height:30px;font-size:12px;color:#b91c1c;">Reject</button>
                          </div>
                        </form>
                      <?php else: ?>
                        <div style="font-size:12px;color:var(--muted);">
                          <?= $h(ucfirst($request['status'])) ?>
                          <?php if (!empty($request['reviewed_at'])): ?>
                            <div style="font-size:11px;"><?= date('M d, Y', strtotime((string) $request['reviewed_at'])) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($request['review_notes'])): ?>
                            <div style="font-size:11px;font-style:italic;">"<?= $h($request['review_notes']) ?>"</div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
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