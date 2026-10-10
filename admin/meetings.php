<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_admin_meetings_ensure_schema($pdo);

$adminUserId = (int) ($currentUser['user_id'] ?? 0);
$adminName = (string) ($currentUser['name'] ?? 'Admin');

$flashMessage = '';
$flashError = '';

if (!function_exists('h')) {
    function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_meeting') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $meetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
            $startTime = trim((string) ($_POST['start_time'] ?? ''));
            $endTime = trim((string) ($_POST['end_time'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'admin_personal'));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $reminderMinutes = (int) ($_POST['reminder_minutes'] ?? 30);

            if ($title === '' || $meetingDate === '' || $startTime === '') {
                throw new RuntimeException('Title, date, and start time are required.');
            }

            $startAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $startTime);
            if (!$startAt) {
                throw new RuntimeException('Invalid date/time format.');
            }

            $now = new DateTimeImmutable();
            if ($startAt < $now) {
                throw new RuntimeException('Cannot schedule a meeting in the past.');
            }

            $maxDate = $now->modify('+1 month');
            if ($startAt > $maxDate) {
                throw new RuntimeException('Cannot schedule a meeting more than a month in the future.');
            }

            if ($endTime !== '') {
                $endAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $endTime);
                if (!$endAt || $endAt <= $startAt) {
                    throw new RuntimeException('End time must be later than start time.');
                }
            }

            $allowedCategories = ['admin_personal', 'sa_related', 'office_meeting', 'external'];
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'admin_personal';
            }

            $reminderMinutes = max(0, min(1440, $reminderMinutes));

            $createStmt = $pdo->prepare(
                'INSERT INTO admin_meetings
                    (admin_user_id, title, meeting_date, start_time, end_time, location, category, notes, reminder_minutes, status)
                 VALUES
                    (:admin_user_id, :title, :meeting_date, :start_time, :end_time, :location, :category, :notes, :reminder_minutes, :status)'
            );

            $createStmt->execute([
                'admin_user_id' => $adminUserId,
                'title' => $title,
                'meeting_date' => $meetingDate,
                'start_time' => $startTime,
                'end_time' => $endTime !== '' ? $endTime : null,
                'location' => $location !== '' ? $location : null,
                'category' => $category,
                'notes' => $notes !== '' ? $notes : null,
                'reminder_minutes' => $reminderMinutes,
                'status' => 'scheduled',
            ]);

            $flashMessage = 'Meeting scheduled successfully.';
        }

        if ($action === 'edit_meeting') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $meetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
            $startTime = trim((string) ($_POST['start_time'] ?? ''));
            $endTime = trim((string) ($_POST['end_time'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'admin_personal'));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $reminderMinutes = (int) ($_POST['reminder_minutes'] ?? 30);

            if ($meetingId <= 0 || $title === '' || $meetingDate === '' || $startTime === '') {
                throw new RuntimeException('Title, date, and start time are required.');
            }

            $startAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $startTime);
            if (!$startAt) {
                throw new RuntimeException('Invalid date/time format.');
            }

            // Check if rescheduled date/time is in the past or exceeds 1 month
            $stmt = $pdo->prepare('SELECT meeting_date, start_time FROM admin_meetings WHERE meeting_id = :id AND admin_user_id = :admin_id LIMIT 1');
            $stmt->execute(['id' => $meetingId, 'admin_id' => $adminUserId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existingStartAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $existing['meeting_date'] . ' ' . $existing['start_time']);
                if (!$existingStartAt) {
                    $existingStartAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $existing['meeting_date'] . ' ' . substr($existing['start_time'], 0, 5));
                }
                if ($existingStartAt && $existingStartAt->getTimestamp() !== $startAt->getTimestamp()) {
                    $now = new DateTimeImmutable();
                    if ($startAt < $now) {
                        throw new RuntimeException('Cannot reschedule a meeting to a past date/time.');
                    }
                    $maxDate = $now->modify('+1 month');
                    if ($startAt > $maxDate) {
                        throw new RuntimeException('Cannot reschedule a meeting to a date more than a month in the future.');
                    }
                }
            }

            if ($endTime !== '') {
                $endAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $endTime);
                if (!$endAt || $endAt <= $startAt) {
                    throw new RuntimeException('End time must be later than start time.');
                }
            }

            $allowedCategories = ['admin_personal', 'sa_related', 'office_meeting', 'external'];
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'admin_personal';
            }

            $reminderMinutes = max(0, min(1440, $reminderMinutes));

            $updateStmt = $pdo->prepare(
                'UPDATE admin_meetings
                 SET title = :title,
                     meeting_date = :meeting_date,
                     start_time = :start_time,
                     end_time = :end_time,
                     location = :location,
                     category = :category,
                     notes = :notes,
                     reminder_minutes = :reminder_minutes,
                     status = :status
                 WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id
                 LIMIT 1'
            );

            $updateStmt->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
                'title' => $title,
                'meeting_date' => $meetingDate,
                'start_time' => $startTime,
                'end_time' => $endTime !== '' ? $endTime : null,
                'location' => $location !== '' ? $location : null,
                'category' => $category,
                'notes' => $notes !== '' ? $notes : null,
                'reminder_minutes' => $reminderMinutes,
                'status' => 'scheduled',
            ]);

            $flashMessage = 'Meeting updated successfully.';
            $editMeeting = null;
        }

        if ($action === 'set_status') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'scheduled'));
            $allowedStatus = ['scheduled', 'completed', 'cancelled'];
            if ($meetingId <= 0 || !in_array($status, $allowedStatus, true)) {
                throw new RuntimeException('Invalid meeting update request.');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE admin_meetings SET status = :status WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id LIMIT 1'
            );
            $updateStmt->execute([
                'status' => $status,
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
            ]);

            $flashMessage = 'Meeting status updated.';
        }

        if ($action === 'delete_meeting') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            if ($meetingId <= 0) {

                throw new RuntimeException('Invalid meeting delete request.');
            }

            $pdo->beginTransaction();

            $deleteNotificationsStmt = $pdo->prepare(
                'DELETE FROM admin_meeting_notifications WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id'
            );
            $deleteNotificationsStmt->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
            ]);

            $deleteMeetingStmt = $pdo->prepare(
                'DELETE FROM admin_meetings WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id LIMIT 1'
            );
            $deleteMeetingStmt->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
            ]);

            $pdo->commit();
            $flashMessage = 'Meeting removed.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flashError = $e->getMessage();
    }
}

sams_admin_meetings_generate_notifications($pdo, $adminUserId);

// Week selection calculations
$weekOffset = (int) ($_GET['week_offset'] ?? 0);
$today = new DateTimeImmutable('today');
$dayOfWeek = (int) $today->format('N'); // 1 (Mon) to 7 (Sun)
$currentMonday = $today->modify('-' . ($dayOfWeek - 1) . ' days');

$meetingIdQuery = (int) ($_GET['meeting_id'] ?? 0);
if ($meetingIdQuery > 0) {
    $mQueryStmt = $pdo->prepare("SELECT meeting_date FROM admin_meetings WHERE meeting_id = :id AND admin_user_id = :admin_id LIMIT 1");
    $mQueryStmt->execute(['id' => $meetingIdQuery, 'admin_id' => $adminUserId]);
    $mQueryRow = $mQueryStmt->fetch(PDO::FETCH_ASSOC);
    if ($mQueryRow) {
        $mDateObj = new DateTimeImmutable($mQueryRow['meeting_date']);
        $mDayOfWeek = (int) $mDateObj->format('N');
        $meetingMonday = $mDateObj->modify('-' . ($mDayOfWeek - 1) . ' days');
        
        $diff = $currentMonday->diff($meetingMonday);
        $weeksDiff = (int) round(((int)$diff->format('%r%a')) / 7);
        $weekOffset = $weeksDiff;
    }
}

$monday = $currentMonday;
if ($weekOffset !== 0) {
    $monday = $monday->modify(($weekOffset > 0 ? '+' : '') . $weekOffset . ' weeks');
}
$sunday = $monday->modify('+6 days');

$startDate = $monday->format('Y-m-d');
$endDate = $sunday->format('Y-m-d');

// Fetch meetings for the selected week
$weeklyMeetingsStmt = $pdo->prepare(
    "SELECT
        meeting_id,
        title,
        category,
        meeting_date,
        start_time,
        end_time,
        location,
        notes,
        reminder_minutes,
        status,
        TIMESTAMP(meeting_date, start_time) AS starts_at
     FROM admin_meetings
     WHERE admin_user_id = :admin_user_id
       AND meeting_date BETWEEN :start_date AND :end_date
     ORDER BY meeting_date ASC, start_time ASC"
);
$weeklyMeetingsStmt->execute([
    'admin_user_id' => $adminUserId,
    'start_date' => $startDate,
    'end_date' => $endDate
]);
$meetings = $weeklyMeetingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$todayCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM admin_meetings WHERE admin_user_id = :admin_user_id AND meeting_date = CURDATE() AND status = :status'
);
$todayCountStmt->execute(['admin_user_id' => $adminUserId, 'status' => 'scheduled']);
$todayCount = (int) $todayCountStmt->fetchColumn();

$upcomingCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM admin_meetings
     WHERE admin_user_id = :admin_user_id
       AND status = 'scheduled'
       AND TIMESTAMP(meeting_date, start_time) >= NOW()"
);
$upcomingCountStmt->execute(['admin_user_id' => $adminUserId]);
$upcomingCount = (int) $upcomingCountStmt->fetchColumn();

$editMeeting = null;
$editMeetingId = (int) ($_GET['edit_id'] ?? 0);
if ($editMeetingId > 0) {
    $editStmt = $pdo->prepare(
        "SELECT meeting_id, title, meeting_date, start_time, end_time, location, category, notes, reminder_minutes, status
         FROM admin_meetings
         WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id
         LIMIT 1"
    );
    $editStmt->execute([
        'meeting_id' => $editMeetingId,
        'admin_user_id' => $adminUserId,
    ]);
    $editMeeting = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Fetch actual next upcoming meeting globally
$nextMeetingStmt = $pdo->prepare(
    "SELECT title, meeting_date, start_time, TIMESTAMP(meeting_date, start_time) AS starts_at
     FROM admin_meetings
     WHERE admin_user_id = :admin_user_id
       AND status = 'scheduled'
       AND TIMESTAMP(meeting_date, start_time) >= NOW()
     ORDER BY meeting_date ASC, start_time ASC
     LIMIT 1"
);
$nextMeetingStmt->execute(['admin_user_id' => $adminUserId]);
$nextMeeting = $nextMeetingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// Dynamically determine calendar hours for the selected week
$calendarStartHour = 8;
$calendarEndHour = 17; // 5 PM
foreach ($meetings as $m) {
    $startParts = explode(':', $m['start_time']);
    $startHour = (int) $startParts[0];
    if ($startHour < $calendarStartHour) {
        $calendarStartHour = $startHour;
    }
    
    $endTimeStr = !empty($m['end_time']) ? $m['end_time'] : null;
    if ($endTimeStr) {
        $endParts = explode(':', $endTimeStr);
        $endHour = (int) ceil(((int)$endParts[0]) + ((int)$endParts[1] / 60));
        if ($endHour > $calendarEndHour) {
            $calendarEndHour = $endHour;
        }
    } else {
        if ($startHour + 1 > $calendarEndHour) {
            $calendarEndHour = $startHour + 1;
        }
    }
}
$hours = range($calendarStartHour, $calendarEndHour);

// Group meetings by day of the week
$daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$meetingsByDay = [];
foreach ($daysOfWeek as $d) {
    $meetingsByDay[$d] = [];
}
foreach ($meetings as $m) {
    $dayName = date('l', strtotime($m['meeting_date']));
    if (isset($meetingsByDay[$dayName])) {
        $meetingsByDay[$dayName][] = $m;
    }
}

// Calculate label/data for each day of the week
$dayDates = [];
for ($i = 0; $i < 7; $i++) {
    $d = $monday->modify('+' . $i . ' days');
    $dayDates[$daysOfWeek[$i]] = [
        'label' => $d->format('D'),
        'num' => $d->format('M d'),
        'is_today' => $d->format('Y-m-d') === $today->format('Y-m-d')
    ];
}

// Style generator helper for positioning blocks
if (!function_exists('meeting_block_style')) {
    function meeting_block_style(string $start, ?string $end, int $calendarStartHour): string
    {
        $slotHeight = 40; // 40px per hour for a very compact view
        $calendarStartMinutes = $calendarStartHour * 60;
        
        $parts = explode(':', $start);
        $startMinutes = ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
        
        if (!empty($end)) {
            $partsEnd = explode(':', $end);
            $endMinutes = ((int)($partsEnd[0] ?? 0)) * 60 + ((int)($partsEnd[1] ?? 0));
        } else {
            $endMinutes = $startMinutes + 60; // default 1hr duration
        }
        
        $top = (($startMinutes - $calendarStartMinutes) / 60) * $slotHeight;
        $height = (($endMinutes - $startMinutes) / 60) * $slotHeight;
        
        $top = max(0, $top);
        $height = max(22, $height); // Capped at 22px minimum to ensure vertically centered text remains visible
        
        return sprintf('top:%dpx;height:%dpx;', (int)round($top), (int)round($height));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Meetings</title>
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, Segoe UI, Arial, sans-serif; }
        .meetings-page { padding: 32px; display: flex; flex-direction: column; gap: 16px; }
        .meetings-header {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }
        .meetings-header__title { margin: 0; font-size: 28px; line-height: 1.15; color: var(--color-heading); }
        .meetings-header__sub { margin: 6px 0 0; color: var(--color-body); }
        .meetings-header__actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .topbar__notif-btn {
            width: 40px;
            height: 40px;
            border-radius: 999px;
            border: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            cursor: pointer;
            background: var(--color-white);
            flex-shrink: 0;
        }
        .topbar__notif-dot {
            position: absolute;
            top: 3px;
            right: 3px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            display: none;
            text-align: center;
            line-height: 18px;
        }
        .meetings-content { display: flex; flex-direction: column; gap: 16px; }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .card {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }
        .stat__label { color: var(--color-muted); font-size: 13px; }
        .stat__value { font-size: 28px; font-weight: 800; margin-top: 8px; color: var(--color-heading); }
        .layout {
            display: grid;
            grid-template-columns: 1.1fr 1.4fr;
            gap: 16px;
        }
        label { font-size: 13px; color: var(--color-muted); margin-bottom: 5px; display: block; }
        input, select, textarea {
            width: 100%;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
            font: inherit;
        }
        textarea { min-height: 92px; resize: vertical; }
        .grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .alert { border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; font-size: 14px; }
        .alert--ok { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .alert--err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .meeting-list { display: flex; flex-direction: column; gap: 10px; }
        .meeting { border: 1px solid var(--color-border); border-radius: 12px; padding: 12px; background: #fff; }
        .meeting__head { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .meeting__title { font-weight: 700; color: var(--color-heading); }
        .meeting__meta { color: var(--color-body); font-size: 13px; line-height: 1.5; }
        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            font-size: 12px;
            padding: 4px 10px;
            border: 1px solid transparent;
            white-space: nowrap;
            height: fit-content;
        }
        .badge--scheduled { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .badge--completed { background: #ecfdf3; color: #166534; border-color: #bbf7d0; }
        .badge--cancelled { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .meeting__actions { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; }
        .btn {
            border: 1px solid var(--color-border);
            background: #fff;
            color: var(--color-heading);
            min-height: 36px;
            padding: 0 14px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .btn--brand { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
        .btn--brand:hover { background: var(--color-primary-dark); }
        .btn--small { min-height: 30px; border-radius: 8px; font-size: 12px; padding: 0 10px; }
        .btn--danger { border-color: #fecaca; color: #991b1b; }
        .btn--danger:hover { background: #fef2f2; }
        .meeting-form-title { margin: 0 0 10px; font-size: 19px; color: var(--color-heading); }
        .empty-state { padding: 12px; border: 1px dashed var(--color-border); border-radius: 10px; color: var(--color-body); background: #fff; }
        @media (max-width: 980px) {
            .meetings-page { padding: 20px; }
            .layout { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
            .meetings-header { align-items: flex-start; flex-direction: column; }
        }

        /* Weekly calendar layout */
        .meeting-calendar-card {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .meeting-cal-days {
            display: grid;
            grid-template-columns: 60px repeat(7, 1fr);
            border-bottom: 2px solid #e2e8f0;
            background: #f8fafc;
        }
        .meeting-cal-days__day {
            padding: 8px 4px;
            text-align: center;
            border-left: 1px solid #e2e8f0;
        }
        .meeting-cal-days__day-name {
            font-size: 11px;
            font-weight: 700;
            color: var(--color-muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 2px;
        }
        .meeting-cal-days__day-num {
            font-size: 13px;
            font-weight: 700;
            color: var(--color-heading);
        }
        .meeting-cal-days__day--today {
            background: #f0f9ff;
            border-bottom: 2px solid var(--color-primary);
        }
        .meeting-cal-days__day--today .meeting-cal-days__day-num {
            color: var(--color-primary);
            font-weight: 800;
        }

        .meeting-cal-body {
            display: grid;
            grid-template-columns: 60px repeat(7, 1fr);
            background: #fff;
        }
        .meeting-cal-time-col {
            display: flex;
            flex-direction: column;
            background: #f8fafc;
            border-right: 1px solid #e2e8f0;
        }
        .meeting-cal-time-slot {
            height: 40px;
            padding: 2px 6px 0 0;
            text-align: right;
            font-size: 10.5px;
            font-weight: 500;
            color: var(--color-muted);
            border-bottom: 1px solid #f1f5f9;
            flex-shrink: 0;
        }
        .meeting-cal-day-col {
            border-left: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            position: relative;
            background: #fff;
        }
        .meeting-cal-day-col--today {
            background: rgba(240, 249, 255, 0.4) !important;
        }
        .meeting-cal-day-col__slot {
            height: 40px;
            flex-shrink: 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .meeting-cal-day-col__slot:last-child {
            border-bottom: none;
        }

        /* Meeting Block Styling */
        .meeting-block {
            position: absolute;
            left: 2px;
            right: 2px;
            border-radius: 4px;
            padding: 1px 4px;
            overflow: hidden;
            transition: transform 0.15s ease, box-shadow 0.15s ease, z-index 0.15s ease;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0px;
            border: 1px solid transparent;
            z-index: 2;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        .meeting-block:hover {
            transform: translateY(-1px) scale(1.02);
            box-shadow: 0 4px 12px rgba(16, 24, 40, 0.12);
            z-index: 10;
        }
        .meeting-block__title {
            font-size: 10.5px;
            font-weight: 700;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .meeting-block__time {
            font-size: 9px;
            line-height: 1.1;
            opacity: 0.9;
        }
        .meeting-block__loc {
            font-size: 8.5px;
            line-height: 1.1;
            opacity: 0.85;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 0px;
        }

        /* Pastel Categories styling - Modern Tailwind Palette */
        .meeting-block--personal {
            background-color: #dbeafe;
            border: 1px solid #bfdbfe;
            border-left: 4px solid #1d4ed8;
            color: #1e40af;
        }
        .meeting-block--office {
            background-color: #dcfce7;
            border: 1px solid #bbf7d0;
            border-left: 4px solid #16a34a;
            color: #14532d;
        }
        .meeting-block--sa {
            background-color: #f3e8ff;
            border: 1px solid #e9d5ff;
            border-left: 4px solid #7c3aed;
            color: #5b21b6;
        }
        .meeting-block--external {
            background-color: #fef3c7;
            border: 1px solid #fde68a;
            border-left: 4px solid #d97706;
            color: #78350f;
        }
        .meeting-block--default {
            background-color: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #4b5563;
            color: #1f2937;
        }

        /* Mobile responsive container scroll */
        @media (max-width: 768px) {
            .meeting-calendar-card {
                overflow-x: auto;
            }
            .meeting-cal-days, .meeting-cal-body {
                min-width: 800px;
            }
        }

        /* Modal Backdrop and Wrapper */
        .meeting-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(16, 24, 40, 0.4);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }
        .meeting-modal-overlay--visible {
            opacity: 1;
            pointer-events: auto;
        }
        .meeting-modal {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            width: 90%;
            max-width: 480px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(16, 24, 40, 0.15);
            transform: scale(0.95);
            transition: transform 0.2s ease;
            position: relative;
        }
        .meeting-modal-overlay--visible .meeting-modal {
            transform: scale(1);
        }
        .meeting-modal__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--color-border);
            padding-bottom: 10px;
        }
        .meeting-modal__title {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--color-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .meeting-modal__close {
            font-size: 28px;
            font-weight: 700;
            color: var(--color-muted);
            border: none;
            background: none;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            transition: color 0.15s ease;
        }
        .meeting-modal__close:hover {
            color: var(--color-heading);
        }
        .meeting-modal__body {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .meeting-modal__section {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .meeting-modal__label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--color-muted);
            letter-spacing: 0.5px;
        }
        .meeting-modal__val {
            font-size: var(--font-sm);
            color: var(--color-body);
            line-height: 1.4;
        }
        .badge--sa-style { background: #faf5ff; color: #5b21b6; border-color: #e9d5ff; }
        .badge--external-style { background: #fffbeb; color: #92400e; border-color: #fde68a; }
    </style>
</head>
<body>
    <div class="shell">
        <?php $activeAdminNav = 'meetings'; include __DIR__ . '/_sidebar.php'; ?>


            <div class="main">
            <header class="topbar" role="banner">
                <div class="topbar__heading">
                    <div class="topbar__title">Admin Meetings</div>
                    <div class="topbar__sub">Set your own meetings and receive reminders on exact date and time.</div>
                </div>
                <div class="meetings-header__actions">
                    <a class="btn" href="dashboard.php">Back to Dashboard</a>
                    <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M10 2a6 6 0 00-6 6v3.6l-.7.7A1 1 0 004 14h12a1 1 0 00.7-1.7l-.7-.7V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/>
                        </svg>
                        <span class="topbar__notif-dot" aria-hidden="true"></span>
                    </div>
                    <span style="font-size:13px;color:var(--color-body);"><?php echo h($adminName); ?></span>
                </div>
            </header>

            <main class="meetings-page" id="main-content">
                <section class="meetings-content">
                    <section class="stats">
                        <article class="card">
                            <div class="stat__label">Meetings Today</div>
                            <div class="stat__value"><?php echo (int) $todayCount; ?></div>
                        </article>
                        <article class="card">
                            <div class="stat__label">Upcoming Meetings</div>
                            <div class="stat__value"><?php echo (int) $upcomingCount; ?></div>
                        </article>
                        <article class="card">
                            <div class="stat__label">Next Meeting</div>
                            <div class="stat__value" style="font-size:17px;line-height:1.4;">
                                <?php if ($nextMeeting): ?>
                                    <?php echo h((string) $nextMeeting['title']); ?><br>
                                    <span style="font-size:13px;color:var(--color-body);">
                                        <?php echo h(date('M d, Y', strtotime((string) $nextMeeting['meeting_date']))); ?> at <?php echo h(date('g:i A', strtotime((string) $nextMeeting['start_time']))); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:15px;color:var(--color-body);">No upcoming meetings</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    </section>

                    <!-- Week Navigation Header -->
                    <div class="week-navigation" style="display:flex; justify-content:space-between; align-items:center; background:#fff; padding:16px 24px; border:1px solid var(--color-border); border-radius:16px; box-shadow:0 1px 2px rgba(16,24,40,0.04); margin-bottom:24px; flex-wrap:wrap; gap:12px;">
                        <div style="display:flex; gap:8px;">
                            <a class="btn btn--small" href="meetings.php?week_offset=<?php echo $weekOffset - 1; ?><?php echo $editMeeting ? '&edit_id=' . $editMeetingId : ''; ?>">&larr; Prev Week</a>
                            <?php if ($weekOffset !== 0): ?>
                                <a class="btn btn--small" href="meetings.php?week_offset=0<?php echo $editMeeting ? '&edit_id=' . $editMeetingId : ''; ?>">Today</a>
                            <?php endif; ?>
                            <a class="btn btn--small" href="meetings.php?week_offset=<?php echo $weekOffset + 1; ?><?php echo $editMeeting ? '&edit_id=' . $editMeetingId : ''; ?>">Next Week &rarr;</a>
                        </div>
                        <div style="font-weight:700; color:var(--color-heading); font-size:16px;">
                            Week of <?php echo $monday->format('M d, Y'); ?> &ndash; <?php echo $sunday->format('M d, Y'); ?>
                        </div>
                        <div class="meeting-legend" style="display:flex; gap:12px; font-size:11px; color:var(--color-body); flex-wrap:wrap; font-weight:600;">
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span style="width:10px; height:10px; border-radius:50%; background-color:#dbeafe; display:inline-block; border:1.5px solid #1d4ed8;"></span>
                                <span>Admin Personal</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span style="width:10px; height:10px; border-radius:50%; background-color:#dcfce7; display:inline-block; border:1.5px solid #16a34a;"></span>
                                <span>Office Meeting</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span style="width:10px; height:10px; border-radius:50%; background-color:#f3e8ff; display:inline-block; border:1.5px solid #7c3aed;"></span>
                                <span>S.A. Related</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span style="width:10px; height:10px; border-radius:50%; background-color:#fef3c7; display:inline-block; border:1.5px solid #d97706;"></span>
                                <span>External</span>
                            </div>
                        </div>
                    </div>

                    <!-- Weekly Calendar Grid -->
                    <section class="meeting-calendar-card" aria-label="Weekly meetings schedule grid">
                        <div class="meeting-cal-days" role="row">
                            <div class="meeting-cal-time-gutter" aria-hidden="true" style="width:60px;"></div>
                            <?php foreach ($daysOfWeek as $day): ?>
                                <?php 
                                $isToday = $dayDates[$day]['is_today'];
                                $todayClass = $isToday ? 'meeting-cal-days__day--today' : '';
                                ?>
                                <div class="meeting-cal-days__day <?php echo $todayClass; ?>">
                                    <div class="meeting-cal-days__day-name"><?php echo h($dayDates[$day]['label']); ?></div>
                                    <div class="meeting-cal-days__day-num"><?php echo h($dayDates[$day]['num']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="meeting-cal-body" role="grid" aria-label="Calendar hours grid" style="min-height: <?php echo count($hours) * 40; ?>px;">
                            <div class="meeting-cal-time-col" aria-hidden="true">
                                <?php foreach ($hours as $hour): ?>
                                    <div class="meeting-cal-time-slot"><?php echo date('g A', strtotime(sprintf('%02d:00:00', $hour))); ?></div>
                                <?php endforeach; ?>
                            </div>
                            <?php foreach ($daysOfWeek as $day): ?>
                                <?php 
                                $isTodayCol = $dayDates[$day]['is_today'];
                                $todayColClass = $isTodayCol ? 'meeting-cal-day-col--today' : '';
                                ?>
                                <div class="meeting-cal-day-col <?php echo $todayColClass; ?>" role="gridcell" aria-label="<?php echo h($day); ?>">
                                    <?php foreach ($hours as $_): ?>
                                        <div class="meeting-cal-day-col__slot"></div>
                                    <?php endforeach; ?>
                                    
                                    <?php foreach ($meetingsByDay[$day] as $meeting): ?>
                                        <?php
                                        $mId = (int) $meeting['meeting_id'];
                                        $mTitle = (string) $meeting['title'];
                                        $mCategory = (string) $meeting['category'];
                                        $mStart = date('g:i A', strtotime($meeting['start_time']));
                                        $mEnd = !empty($meeting['end_time']) ? date('g:i A', strtotime($meeting['end_time'])) : '';
                                        $mLoc = (string) $meeting['location'];
                                        $mStatus = (string) $meeting['status'];
                                        
                                        $titleStyle = $mStatus === 'cancelled' ? 'text-decoration: line-through; opacity: 0.6;' : '';
                                        
                                        $classMap = [
                                            'admin_personal' => 'meeting-block--personal',
                                            'office_meeting' => 'meeting-block--office',
                                            'sa_related' => 'meeting-block--sa',
                                            'external' => 'meeting-block--external'
                                        ];
                                        $blockClass = $classMap[$mCategory] ?? 'meeting-block--default';
                                        ?>
                                        <a href="javascript:void(0);" onclick="openMeetingModal(<?php echo $mId; ?>)" 
                                           class="meeting-block <?php echo $blockClass; ?>" 
                                           style="<?php echo meeting_block_style($meeting['start_time'], $meeting['end_time'] ?? null, $calendarStartHour); ?>"
                                           title="<?php echo h($mTitle . ($mLoc !== '' ? ' @ ' . $mLoc : '') . ' (' . ucfirst($mStatus) . ')'); ?>">
                                            <div class="meeting-block__title" style="<?php echo $titleStyle; ?>"><?php echo h($mTitle); ?></div>
                                            <div class="meeting-block__time"><?php echo h($mStart . ($mEnd !== '' ? ' - ' . $mEnd : '')); ?></div>
                                            <?php if ($mLoc !== ''): ?>
                                                <div class="meeting-block__loc"><?php echo h($mLoc); ?></div>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="layout">
                        <article class="card">
                            <h2 class="meeting-form-title"><?php echo $editMeeting ? 'Edit Meeting' : 'Create Meeting'; ?></h2>
                <?php if ($flashMessage !== ''): ?>
                    <div class="alert alert--ok"><?php echo h($flashMessage); ?></div>
                <?php endif; ?>
                <?php if ($flashError !== ''): ?>
                    <div class="alert alert--err"><?php echo h($flashError); ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $editMeeting ? 'edit_meeting' : 'create_meeting'; ?>">
                    <?php if ($editMeeting): ?>
                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($editMeeting['meeting_id'] ?? 0); ?>">
                    <?php endif; ?>

                    <div style="margin-bottom:10px;">
                        <label for="title">Meeting Title</label>
                        <input id="title" name="title" required placeholder="Weekly admin check-in" value="<?php echo h((string) ($editMeeting['title'] ?? '')); ?>">
                    </div>

                    <div class="grid2" style="margin-bottom:10px;">
                        <div>
                            <label for="meeting_date">Date</label>
                            <input id="meeting_date" type="date" name="meeting_date" required 
                                   min="<?php echo ($editMeeting && ($editMeeting['meeting_date'] < date('Y-m-d'))) ? $editMeeting['meeting_date'] : date('Y-m-d'); ?>" 
                                   max="<?php echo ($editMeeting && ($editMeeting['meeting_date'] > date('Y-m-d', strtotime('+1 month')))) ? $editMeeting['meeting_date'] : date('Y-m-d', strtotime('+1 month')); ?>" 
                                   value="<?php echo h((string) ($editMeeting['meeting_date'] ?? '')); ?>">
                        </div>
                        <div>
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="admin_personal" <?php echo (($editMeeting['category'] ?? 'admin_personal') === 'admin_personal') ? 'selected' : ''; ?>>Admin Personal</option>
                                <option value="office_meeting" <?php echo (($editMeeting['category'] ?? '') === 'office_meeting') ? 'selected' : ''; ?>>Office Meeting</option>
                                <option value="sa_related" <?php echo (($editMeeting['category'] ?? '') === 'sa_related') ? 'selected' : ''; ?>>S.A Related</option>
                                <option value="external" <?php echo (($editMeeting['category'] ?? '') === 'external') ? 'selected' : ''; ?>>External Meeting</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid2" style="margin-bottom:10px;">
                        <div>
                            <label for="start_time">Start Time</label>
                            <input id="start_time" type="time" name="start_time" required value="<?php echo h(substr((string) ($editMeeting['start_time'] ?? ''), 0, 5)); ?>">
                        </div>
                        <div>
                            <label for="end_time">End Time</label>
                            <input id="end_time" type="time" name="end_time" value="<?php echo h(substr((string) ($editMeeting['end_time'] ?? ''), 0, 5)); ?>">
                        </div>
                    </div>

                    <div class="grid2" style="margin-bottom:10px;">
                        <div>
                            <label for="location">Location / Link</label>
                            <input id="location" name="location" placeholder="SDAO Office / Google Meet link" value="<?php echo h((string) ($editMeeting['location'] ?? '')); ?>">
                        </div>
                        <div>
                            <label for="reminder_minutes">Reminder (minutes before)</label>
                            <input id="reminder_minutes" name="reminder_minutes" type="number" value="<?php echo (int) ($editMeeting['reminder_minutes'] ?? 30); ?>" min="0" max="1440">
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Agenda or reminders"><?php echo h((string) ($editMeeting['notes'] ?? '')); ?></textarea>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <button class="btn btn--brand" type="submit"><?php echo $editMeeting ? 'Update Meeting' : 'Save Meeting'; ?></button>
                        <?php if ($editMeeting): ?>
                            <a class="btn" href="meetings.php?week_offset=<?php echo $weekOffset; ?>">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </article>

            <article class="card">
                <h2 class="meeting-form-title">Scheduled Meetings</h2>
                <div class="meeting-list">
                    <?php if (empty($meetings)): ?>
                        <div class="empty-state">No meetings scheduled yet.</div>
                    <?php endif; ?>

                    <?php foreach ($meetings as $meeting): ?>
                        <?php
                            $status = (string) ($meeting['status'] ?? 'scheduled');
                            $badgeClass = 'badge--scheduled';
                            if ($status === 'completed') {
                                $badgeClass = 'badge--completed';
                            }
                            if ($status === 'cancelled') {
                                $badgeClass = 'badge--cancelled';
                            }
                        ?>
                        <div class="meeting" id="meeting-<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                            <div class="meeting__head">
                                <div>
                                    <div class="meeting__title"><?php echo h((string) ($meeting['title'] ?? 'Meeting')); ?></div>
                                    <div class="meeting__meta">
                                        <?php echo h(date('M d, Y', strtotime((string) ($meeting['meeting_date'] ?? 'now')))); ?>
                                        at <?php echo h(date('g:i A', strtotime((string) ($meeting['start_time'] ?? '00:00:00')))); ?>
                                        <?php if (!empty($meeting['end_time'])): ?>
                                            - <?php echo h(date('g:i A', strtotime((string) $meeting['end_time']))); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge <?php echo h($badgeClass); ?>"><?php echo h(ucfirst($status)); ?></span>
                            </div>

                            <div class="meeting__meta">
                                Category: <?php echo h(str_replace('_', ' ', (string) ($meeting['category'] ?? 'admin_personal'))); ?>
                                <?php if (!empty($meeting['location'])): ?>
                                    <br>Location: <?php echo h((string) $meeting['location']); ?>
                                <?php endif; ?>
                                <?php if (!empty($meeting['notes'])): ?>
                                    <br>Notes: <?php echo h((string) $meeting['notes']); ?>
                                <?php endif; ?>
                                <br>Reminder: <?php echo (int) ($meeting['reminder_minutes'] ?? 30); ?> minutes before
                            </div>

                            <div class="meeting__actions">
                                <a class="btn btn--small" href="meetings.php?week_offset=<?php echo $weekOffset; ?>&edit_id=<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">Edit</a>
                                <?php if ($status !== 'completed'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <input type="hidden" name="status" value="completed">
                                        <button class="btn btn--small" type="submit">Mark Completed</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($status !== 'cancelled'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <input type="hidden" name="status" value="cancelled">
                                        <button class="btn btn--small" type="submit" style="border-color:#fecaca;color:#991b1b;">Cancel</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($status !== 'scheduled'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <input type="hidden" name="status" value="scheduled">
                                        <button class="btn btn--small" type="submit">Set Scheduled</button>
                                    </form>
                                <?php endif; ?>

                                    <form method="post" onsubmit="return confirm('Delete this meeting permanently?');">
                                    <input type="hidden" name="action" value="delete_meeting">
                                    <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <button class="btn btn--small btn--danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
            </section>
                </main>
            </div>
        </div>

    <!-- Meeting Details Modal -->
    <div class="meeting-modal-overlay" id="meetingModalOverlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="meeting-modal">
            <div class="meeting-modal__header">
                <h3 class="meeting-modal__title" id="modalTitle">Meeting Details</h3>
                <button class="meeting-modal__close" onclick="closeMeetingModal()">&times;</button>
            </div>
            <div class="meeting-modal__body">
                <div style="font-size: 20px; font-weight: 700; color: var(--color-heading); margin-bottom: 8px;" id="modalMeetingTitle">Title</div>
                <div class="meeting-modal__row" style="display: flex; gap: 8px; margin-bottom: 16px;">
                    <span class="badge" id="modalCategory">Category</span>
                    <span class="badge" id="modalStatus">Status</span>
                </div>
                <div class="meeting-modal__section">
                    <div class="meeting-modal__label">When</div>
                    <div class="meeting-modal__val" id="modalWhen">Date & Time</div>
                </div>
                <div class="meeting-modal__section" id="modalLocSection">
                    <div class="meeting-modal__label">Location / Link</div>
                    <div class="meeting-modal__val" id="modalLocation">Location Link</div>
                </div>
                <div class="meeting-modal__section" id="modalNotesSection">
                    <div class="meeting-modal__label">Notes / Agenda</div>
                    <div class="meeting-modal__val" id="modalNotes" style="white-space: pre-wrap;">Notes text</div>
                </div>
                <div class="meeting-modal__section" id="modalReminderSection">
                    <div class="meeting-modal__label">Reminder</div>
                    <div class="meeting-modal__val" id="modalReminder">30 minutes before</div>
                </div>
            </div>
            <div class="meeting-modal__footer" style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; flex-wrap: wrap;">
                <button class="btn btn--small btn--brand" id="modalEditBtn">Edit Details</button>
                <form method="post" id="modalStatusForm" style="display:inline;">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="meeting_id" id="modalStatusMeetingId">
                    <input type="hidden" name="status" id="modalStatusVal">
                    <button type="submit" class="btn btn--small" id="modalStatusBtn">Mark Status</button>
                </form>
                <form method="post" id="modalDeleteForm" style="display:inline;" onsubmit="return confirm('Delete this meeting permanently?');">
                    <input type="hidden" name="action" value="delete_meeting">
                    <input type="hidden" name="meeting_id" id="modalDeleteMeetingId">
                    <button type="submit" class="btn btn--small btn--danger">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const weeklyMeetings = <?php echo json_encode($meetings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function openMeetingModal(meetingId) {
            const meeting = weeklyMeetings.find(m => parseInt(m.meeting_id) === parseInt(meetingId));
            if (!meeting) return;

            document.getElementById('modalMeetingTitle').textContent = meeting.title;
            
            // Set category
            const catEl = document.getElementById('modalCategory');
            const catClean = meeting.category.replace('_', ' ');
            catEl.textContent = catClean.charAt(0).toUpperCase() + catClean.slice(1);
            catEl.className = 'badge'; // reset
            if (meeting.category === 'admin_personal') catEl.classList.add('badge--scheduled');
            else if (meeting.category === 'office_meeting') catEl.classList.add('badge--completed');
            else if (meeting.category === 'sa_related') catEl.classList.add('badge--sa-style');
            else if (meeting.category === 'external') catEl.classList.add('badge--external-style');
            else catEl.classList.add('badge--scheduled');

            // Set Status
            const statusEl = document.getElementById('modalStatus');
            statusEl.textContent = meeting.status.charAt(0).toUpperCase() + meeting.status.slice(1);
            statusEl.className = 'badge';
            if (meeting.status === 'scheduled') statusEl.classList.add('badge--scheduled');
            else if (meeting.status === 'completed') statusEl.classList.add('badge--completed');
            else if (meeting.status === 'cancelled') statusEl.classList.add('badge--cancelled');

            // Date & Time
            const mDate = new Date(meeting.meeting_date);
            const dateFormatted = mDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const formatTime = (timeStr) => {
                if (!timeStr) return '';
                const parts = timeStr.split(':');
                const d = new Date();
                d.setHours(parseInt(parts[0]), parseInt(parts[1]));
                return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            };
            const tStart = formatTime(meeting.start_time);
            const tEnd = formatTime(meeting.end_time);
            document.getElementById('modalWhen').textContent = `${dateFormatted} at ${tStart}` + (tEnd ? ` - ${tEnd}` : '');

            // Location
            const locSection = document.getElementById('modalLocSection');
            const locEl = document.getElementById('modalLocation');
            if (meeting.location) {
                locSection.style.display = 'block';
                if (meeting.location.startsWith('http://') || meeting.location.startsWith('https://')) {
                    locEl.innerHTML = `<a href="${meeting.location}" target="_blank" style="color: var(--color-primary); text-decoration: underline;">${meeting.location}</a>`;
                } else {
                    locEl.textContent = meeting.location;
                }
            } else {
                locSection.style.display = 'none';
            }

            // Notes
            const notesSection = document.getElementById('modalNotesSection');
            const notesEl = document.getElementById('modalNotes');
            if (meeting.notes) {
                notesSection.style.display = 'block';
                notesEl.textContent = meeting.notes;
            } else {
                notesSection.style.display = 'none';
            }

            // Reminder
            const reminderMinutes = parseInt(meeting.reminder_minutes) || 0;
            document.getElementById('modalReminder').textContent = reminderMinutes > 0 ? `${reminderMinutes} minutes before` : 'No reminder';

            // Set form fields for delete and status transitions
            document.getElementById('modalDeleteMeetingId').value = meeting.meeting_id;
            document.getElementById('modalStatusMeetingId').value = meeting.meeting_id;

            // Status action buttons:
            const statusBtn = document.getElementById('modalStatusBtn');
            const statusVal = document.getElementById('modalStatusVal');
            const statusForm = document.getElementById('modalStatusForm');
            
            if (meeting.status === 'scheduled') {
                statusForm.style.display = 'inline-block';
                statusBtn.textContent = 'Mark Completed';
                statusBtn.style.borderColor = '#bbf7d0';
                statusBtn.style.color = '#166534';
                statusBtn.style.backgroundColor = '#ecfdf3';
                statusVal.value = 'completed';
            } else if (meeting.status === 'completed' || meeting.status === 'cancelled') {
                statusForm.style.display = 'inline-block';
                statusBtn.textContent = 'Re-schedule';
                statusBtn.style.borderColor = '#bfdbfe';
                statusBtn.style.color = '#1d4ed8';
                statusBtn.style.backgroundColor = '#eff6ff';
                statusVal.value = 'scheduled';
            } else {
                statusForm.style.display = 'none';
            }

            // Connect Edit details button
            document.getElementById('modalEditBtn').onclick = function() {
                closeMeetingModal();
                
                document.getElementById('title').value = meeting.title;
                const dateInput = document.getElementById('meeting_date');
                dateInput.value = meeting.meeting_date;
                
                // Timezone-safe today string
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                const todayStr = `${year}-${month}-${day}`;
                dateInput.min = meeting.meeting_date < todayStr ? meeting.meeting_date : todayStr;

                // Timezone-safe max string (+1 month)
                const dMax = new Date();
                dMax.setMonth(dMax.getMonth() + 1);
                const yMax = dMax.getFullYear();
                const mMax = String(dMax.getMonth() + 1).padStart(2, '0');
                const dayMax = String(dMax.getDate()).padStart(2, '0');
                const maxStr = `${yMax}-${mMax}-${dayMax}`;
                dateInput.max = meeting.meeting_date > maxStr ? meeting.meeting_date : maxStr;
                
                document.getElementById('category').value = meeting.category;
                document.getElementById('start_time').value = meeting.start_time.substring(0, 5);
                document.getElementById('end_time').value = meeting.end_time ? meeting.end_time.substring(0, 5) : '';
                document.getElementById('location').value = meeting.location || '';
                document.getElementById('reminder_minutes').value = meeting.reminder_minutes;
                document.getElementById('notes').value = meeting.notes || '';
                
                const actionInput = document.querySelector('form input[name="action"]');
                if (actionInput) actionInput.value = 'edit_meeting';
                
                const submitBtn = document.querySelector('form button[type="submit"]');
                if (submitBtn) submitBtn.textContent = 'Update Meeting';
                
                let editIdInput = document.getElementById('formEditMeetingId');
                if (!editIdInput) {
                    editIdInput = document.createElement('input');
                    editIdInput.type = 'hidden';
                    editIdInput.name = 'meeting_id';
                    editIdInput.id = 'formEditMeetingId';
                    document.querySelector('form').appendChild(editIdInput);
                }
                editIdInput.value = meeting.meeting_id;

                let cancelBtn = document.getElementById('formCancelEditBtn');
                if (!cancelBtn) {
                    cancelBtn = document.createElement('a');
                    cancelBtn.className = 'btn';
                    cancelBtn.id = 'formCancelEditBtn';
                    cancelBtn.textContent = 'Cancel Edit';
                    cancelBtn.href = `meetings.php?week_offset=${<?php echo $weekOffset; ?>}`;
                    cancelBtn.style.marginLeft = '8px';
                    document.querySelector('form button[type="submit"]').parentNode.appendChild(cancelBtn);
                }

                document.querySelector('.meeting-form-title').scrollIntoView({ behavior: 'smooth' });
            };

            const overlay = document.getElementById('meetingModalOverlay');
            overlay.classList.add('meeting-modal-overlay--visible');
        }

        function closeMeetingModal() {
            const overlay = document.getElementById('meetingModalOverlay');
            if (overlay) overlay.classList.remove('meeting-modal-overlay--visible');
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeMeetingModal();
            }
        });

        document.getElementById('meetingModalOverlay').addEventListener('click', function(event) {
            if (event.target === this) {
                closeMeetingModal();
            }
        });

        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const meetingIdParam = urlParams.get('meeting_id');
            if (meetingIdParam) {
                openMeetingModal(meetingIdParam);
            }
        });
    </script>
    <script src="../assets/js/admin-notifications.js?v=20260922"></script>
</body>
</html>
