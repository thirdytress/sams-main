<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(sams_csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid request token.');
        }
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $action = (string) ($_POST['request_action'] ?? '');
        $reviewNote = trim((string) ($_POST['review_note'] ?? ''));
        if ($requestId <= 0 || !in_array($action, ['approve', 'decline'], true)) {
            throw new RuntimeException('Invalid temporary duty review request.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT r.*, u.email, u.first_name, u.last_name
             FROM temporary_duty_requests r
             INNER JOIN students s ON s.student_id = r.student_id
             INNER JOIN users u ON u.user_id = s.user_id
             WHERE r.request_id = :request_id AND r.status = 'pending' FOR UPDATE"
        );
        $stmt->execute(['request_id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new RuntimeException('Request is no longer pending.');
        }
        $dutyId = null;
        if ($action === 'approve') {
            $day = date('l', strtotime((string) $request['duty_date']));
            $hasOffice = sams_column_exists($pdo, 'duty_schedules', 'office_name');
            $hasDate = sams_column_exists($pdo, 'duty_schedules', 'scheduled_date');
            if (!$hasDate) {
                throw new RuntimeException('Database is missing duty_schedules.scheduled_date. Run migrations first.');
            }
            $sql = $hasOffice
                ? 'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, scheduled_date, status) VALUES (:application_id, :office_name, :term_id, :day_of_week, :start_time, :end_time, :scheduled_date, "deployed")'
                : 'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, scheduled_date, status) VALUES (:application_id, :term_id, :day_of_week, :start_time, :end_time, :scheduled_date, "deployed")';
            $insert = $pdo->prepare($sql);
            $params = [
                'application_id' => (int) $request['application_id'],
                'term_id' => (int) $request['term_id'],
                'day_of_week' => $day,
                'start_time' => $request['start_time'],
                'end_time' => $request['end_time'],
                'scheduled_date' => $request['duty_date'],
            ];
            if ($hasOffice) {
                $params['office_name'] = $request['office_name'];
            }
            $insert->execute($params);
            $dutyId = (int) $pdo->lastInsertId();
        }
        $update = $pdo->prepare(
            'UPDATE temporary_duty_requests
             SET status = :status, reviewed_at = NOW(), reviewed_by = :reviewed_by,
                 review_note = :review_note, duty_id = :duty_id
             WHERE request_id = :request_id'
        );
        $update->execute([
            'status' => $action === 'approve' ? 'approved' : 'declined',
            'reviewed_by' => (int) ($user['user_id'] ?? $user['id'] ?? 0),
            'review_note' => $reviewNote !== '' ? substr($reviewNote, 0, 500) : null,
            'duty_id' => $dutyId,
            'request_id' => $requestId,
        ]);
        $pdo->commit();
        try {
            sams_send_temporary_duty_email(
                (string) $request['email'],
                trim((string) $request['first_name'] . ' ' . (string) $request['last_name']),
                $action === 'approve' ? 'approved' : 'declined',
                $request,
                $reviewNote
            );
        } catch (Throwable $mailException) {
            error_log('[sams] Temporary duty notification failed: ' . $mailException->getMessage());
        }
        $flash = $action === 'approve' ? 'Temporary duty request approved.' : 'Temporary duty request declined.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$requests = $pdo->query(
    "SELECT r.*, s.student_id_number, u.first_name, u.last_name
     FROM temporary_duty_requests r
     INNER JOIN students s ON s.student_id = r.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     ORDER BY (r.status = 'pending') DESC, r.requested_at DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<?php
$pendingCount = count(array_filter($requests, static fn (array $request): bool => $request['status'] === 'pending'));
$approvedCount = count(array_filter($requests, static fn (array $request): bool => $request['status'] === 'approved'));
$declinedCount = count(array_filter($requests, static fn (array $request): bool => $request['status'] === 'declined'));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporary Duty Requests | SAMS</title>
<link rel="stylesheet" href="../assets/css/admin-shell.css?v=20260922"><style>
body{background:#f8fafc;color:var(--color-body)}.page{max-width:1260px;margin:0 auto;padding:30px 24px 44px}.page>p{display:none}.card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-card);padding:24px;box-shadow:var(--shadow-card);overflow:hidden}.card h1{margin:0 0 22px;color:var(--color-heading);font-size:28px;letter-spacing:-.02em}.notice{padding:13px 16px;border:1px solid;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:600}.success{background:#ecfdf3;border-color:#bbf7d0;color:#166534}.error{background:#fef2f2;border-color:#fecaca;color:#991b1b}table{width:100%;border-collapse:collapse;min-width:980px}th{padding:12px 14px;background:#f8fafc;color:var(--color-muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em;text-align:left}td{padding:17px 14px;border-top:1px solid var(--color-border);font-size:13px;vertical-align:top;color:var(--color-body)}tr:hover td{background:#fbfdff}textarea{width:180px;min-height:58px;box-sizing:border-box;padding:9px 10px;border:1px solid var(--color-border);border-radius:9px;resize:vertical;font:inherit;font-size:12px}button{border:0;border-radius:8px;padding:9px 13px;font-weight:800;cursor:pointer}.approve{background:#15803d;color:#fff}.decline{background:#fee2e2;color:#b91c1c}.proof{white-space:nowrap}.proof a{color:var(--color-primary);font-weight:700;text-decoration:none}.proof a:hover{text-decoration:underline}.page .card table td:first-child{font-weight:700;color:var(--color-heading)}.page .card table td:nth-child(2){font-weight:700;color:var(--color-heading)}.page .card table td:nth-child(2) br+*{color:var(--color-muted)}@media(max-width:700px){.page{padding:22px 14px 34px}.card h1{font-size:23px}}
</style></head><body><div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div><div class="shell"><?php $activeAdminNav = 'temporary_duty'; include __DIR__ . '/_sidebar.php'; ?><div class="main"><header class="topbar" role="banner"><div class="topbar__left-wrap"><button class="topbar__hamburger" id="hamburger-btn" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation"><span class="topbar__hamburger-bar"></span><span class="topbar__hamburger-bar"></span><span class="topbar__hamburger-bar"></span></button><div><div class="topbar__title">Temporary Duty Requests</div><div class="topbar__sub">Review student requests and manage approvals</div></div></div><div class="topbar__right"><div class="topbar__user-info"><div class="topbar__user-name"><?= htmlspecialchars((string) ($user['name'] ?? 'SAMS Admin')) ?></div><div class="topbar__user-role">SDAO Head</div></div><div class="topbar__avatar" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="4" fill="white" opacity=".9"/><path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/></svg></div></div></header><main class="page"><div class="card"><h1>Temporary Duty Requests</h1>
<?php if ($flash !== ''): ?><div class="notice success"><?= htmlspecialchars($flash) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<table><thead><tr><th>Student</th><th>Date/time</th><th>Office</th><th>Reason</th><th>Proof</th><th>Status/review</th><th>Action</th></tr></thead><tbody>
<?php foreach ($requests as $request): ?><tr><td><?= htmlspecialchars(trim((string) $request['first_name'] . ' ' . (string) $request['last_name'])) ?><br><?= htmlspecialchars((string) $request['student_id_number']) ?></td><td><?= htmlspecialchars((string) $request['duty_date']) ?><br><?= htmlspecialchars(substr((string) $request['start_time'],0,5) . ' - ' . substr((string) $request['end_time'],0,5)) ?></td><td><?= htmlspecialchars((string) $request['office_name']) ?></td><td><?= nl2br(htmlspecialchars((string) $request['reason'])) ?></td><td class="proof"><a href="../<?= htmlspecialchars((string) $request['proof_path']) ?>" target="_blank" rel="noopener">View proof</a></td><td><?= htmlspecialchars((string) $request['status']) ?><br><?= htmlspecialchars((string) ($request['review_note'] ?? '')) ?></td><td><?php if ($request['status'] === 'pending'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(sams_csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><textarea name="review_note" maxlength="500" placeholder="Optional note"></textarea><br><button class="approve" name="request_action" value="approve">Accept</button> <button class="decline" name="request_action" value="decline">Decline</button></form><?php else: ?>Reviewed<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$requests): ?><tr><td colspan="7" style="padding:44px;text-align:center;color:#667085">No temporary duty requests.</td></tr><?php endif; ?></tbody></table></div></main></div></div><script>(function(){var s=document.getElementById('sidebar'),o=document.getElementById('sidebar-overlay'),h=document.getElementById('hamburger-btn');function c(){if(!s||!o||!h)return;s.classList.remove('sidebar--open');o.classList.remove('sidebar-overlay--visible');h.setAttribute('aria-expanded','false');o.setAttribute('aria-hidden','true')}if(h)h.addEventListener('click',function(){if(s.classList.contains('sidebar--open'))c();else{s.classList.add('sidebar--open');o.classList.add('sidebar-overlay--visible');h.setAttribute('aria-expanded','true');o.setAttribute('aria-hidden','false')}});if(o)o.addEventListener('click',c)})();</script></body></html>
