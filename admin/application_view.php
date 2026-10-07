<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$applicationId = (int) ($_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    header('Location: applications.php');
    exit;
}

$pendingApplications = 0;
try {
    $pdo = sams_pdo();
    $pendingApplications = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $exception) {
    $pendingApplications = 0;
}

$activeAdminNav = 'applications';
$adminName = trim((string) ($currentUser['name'] ?? ((string) ($currentUser['first_name'] ?? '') . ' ' . (string) ($currentUser['last_name'] ?? ''))));
if ($adminName === '') {
    $adminName = 'SAMS Admin';
}
$reviewFlashMessage = (string) ($_SESSION['sams_app_flash'] ?? '');
$reviewFlashError = (string) ($_SESSION['sams_app_error'] ?? '');
unset($_SESSION['sams_app_flash'], $_SESSION['sams_app_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Application Details</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bg: #f3f7fb;
            --surface: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #dbe5f0;
            --brand: #1d4ed8;
            --brand-soft: #dbeafe;
            --ok-bg: #dcfce7;
            --ok-text: #166534;
            --warn-bg: #fee2e2;
            --warn-text: #991b1b;
            --shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', Arial, sans-serif;
        }

        .shell {
            min-height: 100vh;
            display: flex;
        }

        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 28px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(120deg, #ffffff 0%, #f6f9ff 100%);
        }

        .topbar__title {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.01em;
        }

        .topbar__sub {
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
        }

        .topbar__user {
            color: #334155;
            font-size: 14px;
            font-weight: 600;
        }

        .page {
            padding: 24px;
            display: grid;
            gap: 18px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card__header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .card__title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .card__body {
            padding: 20px;
        }

        .review-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            background: #fbfdff;
        }

        .review-actions[hidden] { display: none; }
        .review-actions__label { color: var(--muted); font-size: 14px; font-weight: 600; }
        .review-actions__buttons { display: flex; gap: 10px; }
        .review-action-button {
            min-height: 40px;
            padding: 0 16px;
            border: 1px solid transparent;
            border-radius: 8px;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .review-action-button--accept { background: #15803d; color: #fff; }
        .review-action-button--decline { background: #fff; border-color: #fecaca; color: #b91c1c; }
        .review-action-button:hover { filter: brightness(.96); }
        .review-notice { padding: 12px 16px; border: 1px solid #86efac; border-radius: 10px; background: #f0fdf4; color: #166534; font-weight: 600; }
        .review-notice--error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            background: var(--brand-soft);
            color: var(--brand);
        }

        .status-pill.status-approved {
            background: var(--ok-bg);
            color: var(--ok-text);
        }

        .status-pill.status-rejected {
            background: var(--warn-bg);
            color: var(--warn-text);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .item {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            background: #fbfdff;
        }

        .item__label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .item__value {
            margin-top: 6px;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.35;
            word-break: break-word;
        }

        .full {
            grid-column: 1 / -1;
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .chip {
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1e3a8a;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .list {
            display: grid;
            gap: 10px;
        }

        .row {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            background: #fcfdff;
        }

        .row__title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .row__meta {
            color: #475569;
            font-size: 13px;
        }

        .row__actions {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
        }

        .availability-days {
            display: grid;
            gap: 12px;
        }

        .day-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fcfdff;
            padding: 12px;
            display: grid;
            grid-template-columns: 120px minmax(0, 1fr);
            gap: 12px;
            align-items: start;
        }

        .day-card__label {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            padding-top: 4px;
        }

        .day-card__slots {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .slot-column {
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #ffffff;
            padding: 10px;
            display: grid;
            gap: 8px;
        }

        .slot-column__title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #1d4ed8;
        }

        .slot-item {
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            background: #f8fbff;
            padding: 8px;
        }

        .slot-item__time {
            color: #334155;
            font-size: 13px;
            font-weight: 700;
        }

        .slot-empty {
            color: var(--muted);
            font-size: 12px;
            padding: 8px;
            border: 1px dashed #cbd5e1;
            border-radius: 9px;
            background: #f8fafc;
        }

        .slot-item__type {
            display: inline-flex;
            align-items: center;
            margin-bottom: 6px;
            border-radius: 999px;
            padding: 4px 9px;
            background: #dcfce7;
            color: #166534;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .02em;
            text-transform: uppercase;
        }

        .slot-item__note {
            margin-top: 7px;
            padding-top: 7px;
            border-top: 1px solid #dbeafe;
            color: #475569;
            font-size: 12px;
            line-height: 1.45;
            white-space: pre-wrap;
        }

        .doc-link {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 700;
            word-break: break-all;
        }

        .doc-link:hover {
            text-decoration: underline;
        }

        .empty {
            color: var(--muted);
            font-size: 14px;
        }

        .error {
            border: 1px solid #fecaca;
            background: #fff1f2;
            color: #9f1239;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
        }

        .loading {
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
        }

        @media (max-width: 960px) {
            .grid { grid-template-columns: 1fr; }
            .topbar { padding: 16px 18px; }
            .page { padding: 14px; }
            .day-card {
                grid-template-columns: 1fr;
            }
            .day-card__slots {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div>
                <h1 class="topbar__title">Application Details</h1>
                <div class="topbar__sub">Full profile and submitted records for Application #<?= (int) $applicationId ?></div>
            </div>
            <div class="topbar__user"><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></div>
        </header>

        <main class="page">
            <?php if ($reviewFlashMessage !== ''): ?>
                <div class="review-notice" role="status"><?= htmlspecialchars($reviewFlashMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($reviewFlashError !== ''): ?>
                <div class="review-notice review-notice--error" role="alert"><?= htmlspecialchars($reviewFlashError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <section class="card">
                <div class="card__header">
                    <h2 class="card__title">Applicant Information</h2>
                    <span id="status-pill" class="status-pill">Loading</span>
                </div>
                <div id="app-info" class="card__body">
                    <div class="loading">Loading application details...</div>
                </div>
                <div class="review-actions" id="review-actions" hidden>
                    <span class="review-actions__label">Review this application</span>
                    <div class="review-actions__buttons">
                        <form method="post" action="application.php">
                            <input type="hidden" name="application_id" value="<?= (int) $applicationId ?>" />
                            <input type="hidden" name="review_action" value="approve" />
                            <input type="hidden" name="return_to_detail" value="1" />
                            <button class="review-action-button review-action-button--accept" type="submit">Accept</button>
                        </form>
                        <form method="post" action="application.php">
                            <input type="hidden" name="application_id" value="<?= (int) $applicationId ?>" />
                            <input type="hidden" name="review_action" value="reject" />
                            <input type="hidden" name="return_to_detail" value="1" />
                            <button class="review-action-button review-action-button--decline" type="submit">Decline</button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card__header">
                    <h2 class="card__title">Uploaded Documents</h2>
                </div>
                <div id="docs" class="card__body">
                    <div class="loading">Loading documents...</div>
                </div>
            </section>

            <section class="card">
                <div class="card__header">
                    <h2 class="card__title">Availability</h2>
                </div>
                <div id="availability" class="card__body">
                    <div class="loading">Loading availability...</div>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
(function () {
    var applicationId = <?= (int) $applicationId ?>;

    var statusPill = document.getElementById('status-pill');
    var reviewActions = document.getElementById('review-actions');
    var infoContainer = document.getElementById('app-info');
    var docsContainer = document.getElementById('docs');
    var availabilityContainer = document.getElementById('availability');

    function text(value, fallback) {
        if (value === null || value === undefined || String(value).trim() === '') {
            if (typeof fallback !== 'undefined') {
                return String(fallback);
            }
            return '-';
        }
        return String(value);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeStatusClass(status) {
        var value = String(status || '').toLowerCase();
        if (value === 'approved') return 'status-approved';
        if (value === 'rejected') return 'status-rejected';
        return '';
    }

    function renderInfo(app) {
        var firstName = text(app.first_name, '');
        var lastName = text(app.last_name, '');
        var fullName = (firstName + ' ' + lastName).trim() || 'Unnamed Applicant';
        var program = text(app.program, '-');
        var yearLevel = text(app.year_level, '-');
        var skillsRaw = text(app.skills, '');
        var skills = skillsRaw
            .split(/[\r\n,;]+/)
            .map(function (item) { return item.trim(); })
            .filter(Boolean);

        var status = text(app.status, 'pending');
        statusPill.className = 'status-pill ' + safeStatusClass(status);
        statusPill.textContent = status;
        reviewActions.hidden = String(status).toLowerCase() !== 'pending';

        var skillsHtml = skills.length
            ? '<div class="chips">' + skills.map(function (item) {
                return '<span class="chip">' + escapeHtml(item) + '</span>';
              }).join('') + '</div>'
            : '<div class="empty">No skills listed.</div>';

        infoContainer.innerHTML = '' +
            '<div class="grid">' +
                '<div class="item"><div class="item__label">Full Name</div><div class="item__value">' + escapeHtml(fullName) + '</div></div>' +
                '<div class="item"><div class="item__label">Email</div><div class="item__value">' + escapeHtml(text(app.email, '-')) + '</div></div>' +
                '<div class="item"><div class="item__label">Student ID</div><div class="item__value">' + escapeHtml(text(app.student_code, '-')) + '</div></div>' +
                '<div class="item"><div class="item__label">Program / Year</div><div class="item__value">' + escapeHtml(program + ' / ' + yearLevel) + '</div></div>' +
                '<div class="item"><div class="item__label">Units</div><div class="item__value">' + escapeHtml(text(app.units, 'Not recorded')) + '</div></div>' +
                '<div class="item"><div class="item__label">Preferred Office</div><div class="item__value">' + escapeHtml(text(app.preferred_office, '-')) + '</div></div>' +
                '<div class="item"><div class="item__label">Hours per Week</div><div class="item__value">' + escapeHtml(text(app.available_hours_per_week, '-')) + '</div></div>' +
                '<div class="item"><div class="item__label">Term</div><div class="item__value">' + escapeHtml(text(app.term_name, '-')) + ' (' + escapeHtml(text(app.school_year, '-')) + ')</div></div>' +
                '<div class="item"><div class="item__label">Submitted At</div><div class="item__value">' + escapeHtml(text(app.submitted_at, '-')) + '</div></div>' +
                '<div class="item full"><div class="item__label">Skills</div><div class="item__value">' + skillsHtml + '</div></div>' +
            '</div>';
    }

    function renderDocs(documents) {
        if (!Array.isArray(documents) || documents.length === 0) {
            docsContainer.innerHTML = '<div class="empty">No documents uploaded.</div>';
            return;
        }

        var rows = documents.map(function (doc) {
            var type = escapeHtml(text(doc.document_type, 'Document'));
            var filename = escapeHtml(text(doc.original_filename, 'File'));
            var path = text(doc.file_path, '');
            var uploaded = escapeHtml(text(doc.uploaded_at, '-'));
            var link = path
                ? '<a class="doc-link" target="_blank" rel="noopener noreferrer" href="../' + encodeURI(path) + '">Open file</a>'
                : '<span class="empty">File path unavailable</span>';

            return '<article class="row">' +
                '<div class="row__title">' + type + ' - ' + filename + '</div>' +
                '<div class="row__meta">Uploaded: ' + uploaded + '</div>' +
                '<div class="row__meta" style="margin-top:6px;">' + link + '</div>' +
            '</article>';
        }).join('');

        docsContainer.innerHTML = '<div class="list">' + rows + '</div>';
    }

    function to12Hour(timeValue) {
        var raw = String(timeValue || '').slice(0, 5);
        if (!/^\d{2}:\d{2}$/.test(raw)) return text(timeValue, '-');

        var parts = raw.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1];
        var suffix = h >= 12 ? 'PM' : 'AM';
        var display = h % 12;
        if (display === 0) display = 12;
        return display + ':' + m + ' ' + suffix;
    }

    function renderAvailability(rows) {
        if (!Array.isArray(rows) || rows.length === 0) {
            availabilityContainer.innerHTML = '<div class="empty">No availability submitted.</div>';
            return;
        }

        function toMinutes(timeValue) {
            var raw = String(timeValue || '').slice(0, 5);
            if (!/^\d{2}:\d{2}$/.test(raw)) {
                return -1;
            }
            var parts = raw.split(':');
            var h = parseInt(parts[0], 10);
            var m = parseInt(parts[1], 10);
            if (!Number.isFinite(h) || !Number.isFinite(m)) {
                return -1;
            }
            return (h * 60) + m;
        }

        function getSlotPeriod(row) {
            var startValue = row.time_start || row.start_time;
            var startMinutes = toMinutes(startValue);
            if (startMinutes >= 0 && startMinutes < 12 * 60) {
                return 'morning';
            }
            return 'afternoon';
        }

        function renderSlot(day, row) {
            var startLabel = to12Hour(row.time_start || row.start_time);
            var endLabel = to12Hour(row.time_end || row.end_time);
            var noteValue = row.notes === null || row.notes === undefined
                ? ''
                : String(row.notes).trim();
            var slotType = noteValue !== '' || String(row.is_available) === '0'
                ? 'Personal Reason'
                : 'Duty Hours';

            return '<div class="slot-item">' +
                '<span class="slot-item__type">' + escapeHtml(slotType) + '</span>' +
                '<div class="slot-item__time">' + escapeHtml(startLabel) + ' to ' + escapeHtml(endLabel) + '</div>' +
                (noteValue !== ''
                    ? '<div class="slot-item__note"><strong>Note:</strong> ' + escapeHtml(noteValue) + '</div>'
                    : '') +
            '</div>';
        }

        var grouped = {};
        rows.forEach(function (row) {
            var day = text(row.day_of_week, '-');
            if (!grouped[day]) {
                grouped[day] = [];
            }
            grouped[day].push(row);
        });

        var dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var orderedDays = dayOrder.filter(function (day) {
            return Object.prototype.hasOwnProperty.call(grouped, day);
        });

        Object.keys(grouped)
            .filter(function (day) { return dayOrder.indexOf(day) === -1; })
            .sort()
            .forEach(function (day) {
                orderedDays.push(day);
            });

        var html = orderedDays.map(function (day) {
            var dayRows = grouped[day].slice().sort(function (a, b) {
                return toMinutes(a.time_start || a.start_time) - toMinutes(b.time_start || b.start_time);
            });

            var morningSlots = dayRows.filter(function (row) { return getSlotPeriod(row) === 'morning'; });
            var afternoonSlots = dayRows.filter(function (row) { return getSlotPeriod(row) === 'afternoon'; });

            var morningHtml = morningSlots.length
                ? morningSlots.map(function (row) { return renderSlot(day, row); }).join('')
                : '<div class="slot-empty"><strong>Class Schedule</strong><br>Unavailable for duty during this period.</div>';

            var afternoonHtml = afternoonSlots.length
                ? afternoonSlots.map(function (row) { return renderSlot(day, row); }).join('')
                : '<div class="slot-empty"><strong>Class Schedule</strong><br>Unavailable for duty during this period.</div>';

            return '<article class="day-card">' +
                '<div class="day-card__label">' + escapeHtml(day) + '</div>' +
                '<div class="day-card__slots">' +
                    '<section class="slot-column">' +
                        '<div class="slot-column__title">Morning</div>' +
                        morningHtml +
                    '</section>' +
                    '<section class="slot-column">' +
                        '<div class="slot-column__title">Afternoon</div>' +
                        afternoonHtml +
                    '</section>' +
                '</div>' +
            '</article>';
        }).join('');

        availabilityContainer.innerHTML = '<div class="availability-days">' + html + '</div>';
    }

    function renderError(message) {
        var errorHtml = '<div class="error">' + escapeHtml(message) + '</div>';
        infoContainer.innerHTML = errorHtml;
        docsContainer.innerHTML = errorHtml;
        availabilityContainer.innerHTML = errorHtml;
        statusPill.textContent = 'Error';
        statusPill.className = 'status-pill status-rejected';
    }

    fetch('application_detail.php?application_id=' + encodeURIComponent(String(applicationId)), { credentials: 'same-origin' })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Unable to load application details.');
            }
            return response.json();
        })
        .then(function (json) {
            if (!json || json.success !== true) {
                throw new Error((json && json.error) ? json.error : 'Application details not found.');
            }

            renderInfo(json.application || {});
            renderDocs(json.documents || []);
            renderAvailability(json.availability || []);
        })
        .catch(function (error) {
            renderError(error.message || 'Failed to load application details.');
        });
})();
</script>
</body>
</html>
