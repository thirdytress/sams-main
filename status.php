<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$pdo = sams_pdo();

// Fetch dynamic admin / SDAO contact info from the database
$adminContact = [
    'name'  => 'SDAO Administrator',
    'email' => 'sdao@nu-lipa.edu.ph',
    'phone' => '(043) 723-0706',
];

try {
    $adminStmt = $pdo->query(
        "SELECT first_name, last_name, email, phone_number 
         FROM users 
         WHERE role = 'admin' AND is_active = 1 
         ORDER BY user_id ASC 
         LIMIT 1"
    );
    $adminRow = $adminStmt ? $adminStmt->fetch(PDO::FETCH_ASSOC) : null;
    if ($adminRow) {
        $admName = trim((string)($adminRow['first_name'] ?? '') . ' ' . (string)($adminRow['last_name'] ?? ''));
        if ($admName !== '') {
            $adminContact['name'] = $admName;
        }
        if (!empty($adminRow['email'])) {
            $adminContact['email'] = (string) $adminRow['email'];
        }
        if (!empty($adminRow['phone_number'])) {
            $adminContact['phone'] = (string) $adminRow['phone_number'];
        }
    }
} catch (Throwable $e) {
    // fallback
}

$submission = $_SESSION['registration_submission'] ?? [];
$applicationId = isset($_GET['application_id']) ? (int)$_GET['application_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($applicationId <= 0 && isset($submission['application_id'])) {
    $applicationId = (int) $submission['application_id'];
}

$currentUser = sams_authenticated_user();
if ($applicationId <= 0 && $currentUser && ($currentUser['role'] ?? '') === 'student') {
    $statusStatement = $pdo->prepare(
        'SELECT a.application_id
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         WHERE s.user_id = :user_id
         ORDER BY a.application_id DESC
         LIMIT 1'
    );
    $statusStatement->execute(['user_id' => (int) ($currentUser['user_id'] ?? 0)]);
    $row = $statusStatement->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $applicationId = (int) ($row['application_id'] ?? 0);
    }
}

// Fetch live application details if ID is available
if ($applicationId > 0) {
    $statusStatement = $pdo->prepare(
        'SELECT
            a.application_id,
            a.status,
            a.preferred_office,
            COALESCE(a.submitted_at, a.created_at) AS date_submitted,
            s.student_id_number AS student_number,
            s.program AS course,
            s.year_level,
            u.first_name,
            u.last_name
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.application_id = :application_id
         LIMIT 1'
    );
    $statusStatement->execute(['application_id' => $applicationId]);
    $liveApp = $statusStatement->fetch(PDO::FETCH_ASSOC);
    if ($liveApp) {
        $submission = [
            'application_id' => (int) $liveApp['application_id'],
            'student_name' => trim((string) $liveApp['first_name'] . ' ' . (string) $liveApp['last_name']),
            'student_number' => (string) $liveApp['student_number'],
            'course' => (string) $liveApp['course'],
            'year_level' => (string) $liveApp['year_level'],
            'date_submitted' => (string) $liveApp['date_submitted'],
            'status' => (string) $liveApp['status'],
            'preferred_office' => (string) ($liveApp['preferred_office'] ?? ''),
        ];
    }
}

$student_name   = (string) ($submission['student_name'] ?? 'Juan Dela Cruz');
$student_id     = (string) ($submission['student_number'] ?? '2024-12345');
$course         = (string) ($submission['course'] ?? 'BS Information Technology');
$year_level     = (string) ($submission['year_level'] ?? '1st Year');
$date_submitted = (string) ($submission['date_submitted'] ?? date('F j, Y'));
$status         = strtoupper((string) ($submission['status'] ?? 'PENDING'));

$status_title   = '⏳ Application Under Review';
$status_sub     = "Your application is currently being reviewed by {$adminContact['name']} (SDAO). This typically takes 1-3 business days.";
$showAvailabilityCta = !empty($submission['success']) && empty($submission['availability_complete']);

if ($status === 'APPROVED') {
    $status_title = '🎉 Application Approved!';
    $status_sub   = "Congratulations! Your application has been approved by {$adminContact['name']}. You can now log in to the Student Assistant portal to view your duty schedule and attendance.";
} elseif ($status === 'REJECTED') {
    $status_title = 'Application Status Update';
    $status_sub   = "Your application has been reviewed and was declined for this term. For further details, please contact {$adminContact['name']} at SDAO.";
} elseif ($status === 'DRAFT') {
    $status_title = '📝 Application Saved as Draft';
    $status_sub   = (string) ($submission['message'] ?? 'Your application has been saved as a draft. Please complete your weekly time availability to submit your application.');
    $showAvailabilityCta = true;
} elseif (!empty($submission['success'])) {
    $status_title = '✅ Application Submitted Successfully';
    $status_sub   = (string) ($submission['message'] ?? 'Your application has been submitted and is now in the review queue.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NU SAMS – Application Status</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        /* =============================================
           CSS VARIABLES – Design System
        ============================================= */
        :root {
            /* Brand */
            --color-primary:        #003087;
            --color-primary-light:  #0047ab;

            /* Neutral */
            --color-heading:        #101828;
            --color-body:           #4a5565;
            --color-label:          #364153;
            --color-muted:          #6a7282;
            --color-border:         #d1d5dc;
            --color-border-card:    #f3f4f6;
            --color-white:          #ffffff;

            /* Status — Pending / Yellow */
            --color-yellow:         #ffb81c;
            --color-yellow-dark:    #bb4d00;
            --color-yellow-border:  #fee685;
            --color-yellow-badge-bg:#fef3c6;
            --color-yellow-bg:      #fffbeb;
            --color-yellow-step-bg: #fef3c6;
            --color-yellow-step-bd: #fe9a00;
            --color-orange-btn:     #e17100;

            /* Status — Step colours */
            --color-green:          #00c950;
            --color-green-bg:       #dcfce7;
            --color-gray-step-bg:   #f3f4f6;
            --color-gray-step-bd:   #d1d5dc;

            /* Info card */
            --color-blue-muted:     #bedbff;
            --color-blue-light:     #dbeafe;

            /* Gradients */
            --gradient-page:    linear-gradient(132deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);
            --gradient-icon:    linear-gradient(135deg, #003087 0%, #0047ab 100%);
            --gradient-help:    linear-gradient(163.66deg, #003087 0%, #0047ab 100%);

            /* Radii */
            --radius-card:   16px;
            --radius-inner:  14px;
            --radius-badge:  9999px;
            --radius-btn:    14px;
            --radius-icon:   16px;

            /* Shadows */
            --shadow-card: 0 10px 15px rgba(0,0,0,.10), 0 4px 6px rgba(0,0,0,.10);

            /* Typography */
            --font-xs:   12px;
            --font-sm:   14px;
            --font-base: 16px;
            --font-md:   18px;
            --font-lg:   24px;
            --font-xl:   30px;
            --font-2xl:  36px;

            --lh-xs:   16px;
            --lh-sm:   20px;
            --lh-base: 24px;
            --lh-md:   28px;
            --lh-lg:   32px;
            --lh-xl:   36px;
            --lh-2xl:  40px;
        }

        /* =============================================
           RESET & BASE
        ============================================= */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { min-height: 100%; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gradient-page);
            min-height: 100vh;
            color: var(--color-heading);
        }
        a { text-decoration: none; color: inherit; }
        img { display: block; max-width: 100%; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; }

        /* =============================================
           PAGE WRAPPER
        ============================================= */
        .page {
            min-height: 100vh;
            padding: 48px 0 64px;
        }

        /* =============================================
           CONTENT CONTAINER
        ============================================= */
        .container {
            max-width: 896px; /* 832px content + 32px each side */
            width: 100%;
            margin: 0 auto;
            padding: 0 32px;
            display: flex;
            flex-direction: column;
            gap: 0; /* gaps handled per section */
        }

        /* =============================================
           HERO / PAGE HEADING
        ============================================= */
        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 32px;
        }
        .hero__icon-wrap {
            width: 64px;
            height: 64px;
            background: var(--gradient-icon);
            border-radius: var(--radius-icon);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .hero__icon-wrap svg { width: 32px; height: 32px; }
        .hero__title {
            font-size: var(--font-2xl);
            font-weight: 900;
            line-height: var(--lh-2xl);
            color: var(--color-heading);
            text-align: center;
            margin-bottom: 4px;
        }
        .hero__sub {
            font-size: var(--font-md);
            font-weight: 500;
            line-height: var(--lh-md);
            color: var(--color-body);
            text-align: center;
        }

        /* =============================================
           STATUS CARD (yellow / pending)
        ============================================= */
        .status-card {
            background: var(--color-yellow-bg);
            border: 2px solid var(--color-yellow-border);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 34px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            margin-bottom: 32px;
        }

        /* Status hero block */
        .status-card__hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        .status-card__clock {
            width: 96px;
            height: 96px;
            background: var(--color-yellow-bg);
            border: 4px solid var(--color-yellow-border);
            border-radius: var(--radius-badge);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .status-card__clock svg { width: 48px; height: 48px; }
        .status-card__state-title {
            font-size: var(--font-xl);
            font-weight: 900;
            line-height: var(--lh-xl);
            color: var(--color-heading);
            text-align: center;
        }
        .status-card__state-sub {
            font-size: var(--font-md);
            font-weight: 500;
            line-height: var(--lh-md);
            color: var(--color-label);
            text-align: center;
            max-width: 645px;
        }

        /* Application details inner card */
        .details-card {
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            border: 1px solid rgba(209, 213, 220, 0.85);
            border-radius: var(--radius-inner);
            padding: 24px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }
        .details-card__title {
            font-size: var(--font-md);
            font-weight: 900;
            line-height: var(--lh-md);
            color: var(--color-heading);
            margin: 0;
        }
        .details-card__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 16px;
            margin-bottom: 18px;
            border-bottom: 1px solid rgba(209, 213, 220, 0.7);
        }
        .details-card__eyebrow {
            margin: 0 0 4px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--color-primary);
        }
        .details-card__sub {
            margin: 6px 0 0;
            font-size: var(--font-sm);
            line-height: var(--lh-sm);
            color: var(--color-muted);
            max-width: 560px;
        }
        .details-card__meta {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid rgba(209, 213, 220, 0.85);
            color: var(--color-body);
            font-size: 13px;
            font-weight: 700;
        }
        .details-card__grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .detail-field {
            padding: 14px 16px;
            border: 1px solid #edf2f7;
            border-radius: 14px;
            background: #fff;
        }
        .detail-field__label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--color-muted);
            display: block;
            margin-bottom: 8px;
        }
        .detail-field__value {
            font-size: 15px;
            font-weight: 800;
            line-height: 1.4;
            color: var(--color-heading);
        }
        .detail-field__value--empty {
            color: var(--color-muted);
            font-weight: 500;
        }

        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            height: 30px;
            padding: 4px 12px;
            border-radius: var(--radius-badge);
            font-size: var(--font-sm);
            font-weight: 900;
            line-height: var(--lh-sm);
            white-space: nowrap;
            letter-spacing: .02em;
        }
        .status-badge--pending  { background: var(--color-yellow-badge-bg); color: var(--color-yellow-dark); }
        .status-badge--approved { background: #dcfce7; color: #00a63e; }
        .status-badge--rejected { background: #fee2e2; color: #dc2626; }

        /* Refresh button */
        .status-card__btn-row {
            display: flex;
            justify-content: center;
        }
        .btn-refresh {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 56px;
            padding: 0 32px;
            background: var(--color-orange-btn);
            border: none;
            border-radius: var(--radius-btn);
            box-shadow: var(--shadow-card);
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-white);
            cursor: pointer;
            transition: opacity .15s;
            text-decoration: none;
        }
        .btn-refresh:hover { opacity: .9; }
        .btn-refresh__icon { width: 20px; height: 20px; flex-shrink: 0; }
        .btn--loading { opacity: .85; }
        .btn-spinner { display: inline-block; width: 16px; height: 16px; margin-right: 8px; vertical-align: -2px; animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* =============================================
           WHAT HAPPENS NEXT CARD
        ============================================= */
        .next-card {
            background: var(--color-white);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px;
            margin-bottom: 32px;
        }
        .next-card__header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .next-card__header-icon { width: 28px; height: 28px; flex-shrink: 0; }
        .next-card__title {
            font-size: var(--font-lg);
            font-weight: 900;
            line-height: var(--lh-lg);
            color: var(--color-heading);
        }
        .next-steps {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .next-step {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .next-step__icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-badge);
            border: 2px solid;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .next-step__icon-wrap svg { width: 24px; height: 24px; }
        .next-step__icon-wrap--done   { background: var(--color-green-bg);       border-color: var(--color-green); }
        .next-step__icon-wrap--active { background: var(--color-yellow-step-bg); border-color: var(--color-yellow-step-bd); opacity: .95; }
        .next-step__icon-wrap--future { background: var(--color-gray-step-bg);   border-color: var(--color-gray-step-bd); }

        .next-step__info {}
        .next-step__title {
            font-size: var(--font-md);
            font-weight: 900;
            line-height: var(--lh-md);
            color: var(--color-heading);
            margin-bottom: 4px;
        }
        .next-step__desc {
            font-size: var(--font-base);
            font-weight: 500;
            line-height: var(--lh-base);
            color: var(--color-body);
        }

        /* =============================================
           NEED HELP CARD
        ============================================= */
        .help-card {
            background: var(--gradient-help);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px;
            margin-bottom: 36px;
        }
        .help-card__title {
            font-size: var(--font-lg);
            font-weight: 900;
            line-height: var(--lh-lg);
            color: var(--color-white);
            margin-bottom: 12px;
        }
        .help-card__sub {
            font-size: var(--font-base);
            font-weight: 500;
            line-height: var(--lh-base);
            color: var(--color-blue-light);
            margin-bottom: 24px;
        }
        .help-card__contacts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .help-contact {
            background: rgba(255,255,255,.10);
            border-radius: var(--radius-inner);
            height: 76px;
            padding: 0 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .help-contact__icon { width: 24px; height: 24px; flex-shrink: 0; }
        .help-contact__label {
            font-size: var(--font-sm);
            font-weight: 500;
            line-height: var(--lh-sm);
            color: var(--color-blue-muted);
            display: block;
            margin-bottom: 4px;
        }
        .help-contact__value {
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-white);
            white-space: nowrap;
        }

        /* =============================================
           BACK LINK
        ============================================= */
        .back-link {
            display: block;
            text-align: center;
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-primary);
            transition: opacity .15s;
        }
        .back-link:hover { opacity: .75; }

        /* =============================================
           RESPONSIVE – TABLET (≤1024px)
        ============================================= */
        @media (max-width: 1024px) {
            .container { padding: 0 24px; }
            .status-card { padding: 24px; }
            .details-card { padding: 20px; }
            .next-card { padding: 24px; }
            .help-card { padding: 24px; }
        }

        /* =============================================
           RESPONSIVE – MOBILE (≤768px)
        ============================================= */
        @media (max-width: 768px) {
            .page { padding: 32px 0 48px; }
            .container { padding: 0 16px; }

            .hero__title { font-size: 28px; }
            .hero__sub   { font-size: var(--font-base); }

            .status-card { padding: 20px; gap: 20px; }
            .status-card__state-title { font-size: var(--font-lg); }
            .status-card__clock { width: 72px; height: 72px; }

            .details-card__grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .details-card__header {
                flex-direction: column;
                align-items: flex-start;
            }
            .details-card__meta {
                width: 100%;
                justify-content: space-between;
            }

            .next-card { padding: 20px; }
            .next-card__title { font-size: var(--font-md); }
            .next-step__title { font-size: var(--font-base); }
            .next-step__desc  { font-size: var(--font-sm); }

            .help-card { padding: 20px; }
            .help-card__contacts { grid-template-columns: 1fr; }
            .help-card__title { font-size: var(--font-md); }

            .hero__icon-wrap { width: 52px; height: 52px; border-radius: 12px; }
        }
    </style>
</head>
<body>

<main class="page" id="main-content">
    <div class="container">

        <!-- ── HERO ── -->
        <header class="hero">
            <div class="hero__icon-wrap" aria-hidden="true">
                <!-- Clock icon -->
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="16" cy="16" r="12" stroke="white" stroke-width="2.5"/>
                    <path d="M16 9V16L20 20" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h1 class="hero__title">Application Status</h1>
            <p class="hero__sub">Track your student assistant application</p>
        </header>

        <!-- ── STATUS CARD ── -->
        <section class="status-card" aria-labelledby="status-state-title">

            <!-- Status hero -->
            <div class="status-card__hero">
                <div class="status-card__clock" aria-hidden="true">
                    <!-- Hourglass / clock icon -->
                    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="24" cy="24" r="18" stroke="#e17100" stroke-width="3"/>
                        <path d="M24 13V24L30 30" stroke="#e17100" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h2 class="status-card__state-title" id="status-state-title"><?= htmlspecialchars($status_title) ?></h2>
                <p class="status-card__state-sub" id="status-state-sub">
                    <?= htmlspecialchars($status_sub) ?>
                </p>
            </div>

            <!-- Application Details -->
            <div class="details-card">
                <div class="details-card__header">
                    <div>
                        <p class="details-card__eyebrow">Application Details</p>
                        <h3 class="details-card__title">Submission Summary</h3>
                        <p class="details-card__sub">A clean snapshot of the information you submitted for review.</p>
                    </div>
                    <div class="details-card__meta">
                        <span>Application ID</span>
                        <strong><?= $applicationId > 0 ? htmlspecialchars((string) $applicationId) : 'Pending' ?></strong>
                    </div>
                </div>

                <div class="detail-field" style="margin-bottom:12px;">
                    <span class="detail-field__label">Applicant</span>
                    <span class="detail-field__value" id="detail-fullname"><?= htmlspecialchars($student_name) ?></span>
                </div>

                <div class="details-card__grid">

                    <div class="detail-field">
                        <span class="detail-field__label">Student ID</span>
                        <span class="detail-field__value" id="detail-studentid"><?= htmlspecialchars($student_id) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Course</span>
                        <span class="detail-field__value" id="detail-course"><?= htmlspecialchars($course) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Year Level</span>
                        <span class="detail-field__value" id="detail-year"><?= htmlspecialchars($year_level) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Date Submitted</span>
                        <span class="detail-field__value" id="detail-datesub"><?= htmlspecialchars($date_submitted) ?></span>
                    </div>

                    <div class="detail-field">
                        <span class="detail-field__label">Status</span>
                        <?php
                        $badge_class = 'status-badge--pending';
                        if ($status === 'APPROVED') $badge_class = 'status-badge--approved';
                        if ($status === 'REJECTED') $badge_class = 'status-badge--rejected';
                        ?>
                        <span class="status-badge <?= $badge_class ?>" role="status" id="detail-status-badge">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </div>

                </div>
            </div>

            <!-- Refresh button -->
            <div class="status-card__btn-row">
                <button id="btn-refresh" class="btn-refresh" type="button" aria-label="Refresh application status">
                    <!-- Refresh icon -->
                    <svg class="btn-refresh__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M3.33 8.33A6.67 6.67 0 0 1 16.5 6.5" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M3 5V9H7" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16.67 11.67A6.67 6.67 0 0 1 3.5 13.5" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M17 15v-4h-4" stroke="white" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Refresh Status
                </button>
                <?php if ($showAvailabilityCta): ?>
                <a id="btn-continue-availability" href="students/availability.php" class="btn-refresh" role="button" aria-label="Continue to availability">
                    Continue to Availability
                </a>
                <?php endif; ?>
            </div>

        </section>

        <!-- ── WHAT HAPPENS NEXT ── -->
        <section class="next-card" aria-labelledby="next-heading">
            <div class="next-card__header">
                <!-- Calendar/arrow icon -->
                <svg class="next-card__header-icon" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="3.5" y="4" width="21" height="20" rx="3" stroke="#003087" stroke-width="2"/>
                    <path d="M3.5 10H24.5" stroke="#003087" stroke-width="2"/>
                    <path d="M9 2V6" stroke="#003087" stroke-width="2" stroke-linecap="round"/>
                    <path d="M19 2V6" stroke="#003087" stroke-width="2" stroke-linecap="round"/>
                    <path d="M10 17l2.5 2.5 5-5" stroke="#003087" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h2 class="next-card__title" id="next-heading">What Happens Next?</h2>
            </div>

            <ol class="next-steps" aria-label="Application process steps">

                <!-- Step 1 – Done -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--done" aria-label="Completed">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="#00c950" stroke-width="2"/>
                            <path d="M8 12L11 15L16 9" stroke="#00c950" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 1: Application Submitted ✅</p>
                        <p class="next-step__desc">Your application has been successfully submitted and is now in the review queue.</p>
                    </div>
                </li>

                <!-- Step 2 – Active (Under Review) -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--<?= $status === 'PENDING' ? 'active' : ($status === 'APPROVED' || $status === 'REJECTED' ? 'done' : 'future') ?>" aria-label="In progress">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="<?= $status === 'APPROVED' || $status === 'REJECTED' ? '#00c950' : '#fe9a00' ?>" stroke-width="2"/>
                            <path d="M12 7V12L15 15" stroke="<?= $status === 'APPROVED' || $status === 'REJECTED' ? '#00c950' : '#fe9a00' ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 2: Under Review ⏳</p>
                        <p class="next-step__desc" id="step2-desc">SDAO Head (<?= htmlspecialchars($adminContact['name']) ?>) is currently reviewing your application and documents. Expected: 1-3 business days.</p>
                    </div>
                </li>

                <!-- Step 3 – Future -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--<?= $status === 'APPROVED' || $status === 'REJECTED' ? 'done' : 'future' ?>" aria-label="Pending">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="<?= $status === 'APPROVED' || $status === 'REJECTED' ? '#00c950' : '#d1d5dc' ?>" stroke-width="2"/>
                            <path d="M9 10.5C9 9.12 10.12 8 11.5 8h.5a2 2 0 0 1 1 3.73L12 12.5V14" stroke="<?= $status === 'APPROVED' || $status === 'REJECTED' ? '#00c950' : '#9ca3af' ?>" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="16.5" r=".75" fill="<?= $status === 'APPROVED' || $status === 'REJECTED' ? '#00c950' : '#9ca3af' ?>"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 3: Decision &amp; Notification</p>
                        <p class="next-step__desc">You'll receive an email notification once your application is reviewed and approved by <?= htmlspecialchars($adminContact['name']) ?>.</p>
                    </div>
                </li>

                <!-- Step 4 – Future -->
                <li class="next-step">
                    <div class="next-step__icon-wrap next-step__icon-wrap--<?= $status === 'APPROVED' ? 'active' : 'future' ?>" aria-label="Pending">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="3" stroke="<?= $status === 'APPROVED' ? '#003087' : '#d1d5dc' ?>" stroke-width="2"/>
                            <path d="M8 9H16M8 13H14M8 17H11" stroke="<?= $status === 'APPROVED' ? '#003087' : '#9ca3af' ?>" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="next-step__info">
                        <p class="next-step__title">Step 4: Access Dashboard</p>
                        <p class="next-step__desc">Once approved, you can log in to your Student Assistant dashboard using your student credentials to view your duty schedule and tap at the kiosk.</p>
                    </div>
                </li>

            </ol>
        </section>

        <!-- ── NEED HELP ── -->
        <section class="help-card" aria-labelledby="help-heading">
            <h2 class="help-card__title" id="help-heading">Need Help?</h2>
            <p class="help-card__sub">
                If you have questions about your application, requirements, or need assistance, please contact our SDAO Administrator:
            </p>
            <div class="help-card__contacts">

                <div class="help-contact">
                    <!-- Email icon -->
                    <svg class="help-contact__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect x="2" y="4" width="20" height="16" rx="3" stroke="#bedbff" stroke-width="1.67"/>
                        <path d="M2 7l10 7 10-7" stroke="#bedbff" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <div>
                        <span class="help-contact__label">Admin Email (<?= htmlspecialchars($adminContact['name']) ?>)</span>
                        <a href="mailto:<?= htmlspecialchars($adminContact['email']) ?>" class="help-contact__value" id="help-admin-email"><?= htmlspecialchars($adminContact['email']) ?></a>
                    </div>
                </div>

                <div class="help-contact">
                    <!-- Phone icon -->
                    <svg class="help-contact__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 12l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2Z" stroke="#bedbff" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <div>
                        <span class="help-contact__label">Contact Number</span>
                        <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $adminContact['phone'])) ?>" class="help-contact__value" id="help-admin-phone"><?= htmlspecialchars($adminContact['phone']) ?></a>
                    </div>
                </div>

            </div>
        </section>

        <!-- ── BACK LINK ── -->
        <a href="logout.php" class="back-link" aria-label="Back to Home">← Back to Home</a>

    </div>
</main>

<script>
(function () {
    'use strict';

    var btn = document.getElementById('btn-refresh');
    var continueBtn = document.getElementById('btn-continue-availability');
    var titleEl = document.getElementById('status-state-title');
    var subEl = document.getElementById('status-state-sub');
    var fullnameEl = document.getElementById('detail-fullname');
    var studentidEl = document.getElementById('detail-studentid');
    var courseEl = document.getElementById('detail-course');
    var yearEl = document.getElementById('detail-year');
    var dateEl = document.getElementById('detail-datesub');
    var statusBadgeEl = document.getElementById('detail-status-badge');
    var adminEmailEl = document.getElementById('help-admin-email');
    var adminPhoneEl = document.getElementById('help-admin-phone');

    function safeText(el, value){ if(!el) return; el.textContent = value == null ? '' : value; }
    function setBadge(status){ if(!statusBadgeEl) return; status = (status||'').toUpperCase(); statusBadgeEl.textContent = status || 'PENDING'; statusBadgeEl.className = 'status-badge';
        if(status === 'APPROVED') statusBadgeEl.classList.add('status-badge--approved');
        else if(status === 'REJECTED') statusBadgeEl.classList.add('status-badge--rejected');
        else statusBadgeEl.classList.add('status-badge--pending');
    }
    function toggleContinue(visible){ if(!continueBtn) return; continueBtn.style.display = visible ? '' : 'none'; }

    function fetchStatus(){
        if(!btn) return;
        var originalText = btn.dataset.origText || btn.textContent;
        btn.dataset.origText = originalText;
        btn.disabled = true;
        btn.classList.add('btn--loading');
        btn.setAttribute('aria-busy','true');
        btn.textContent = 'Refreshing…';

        fetch('api/application_status.php' + window.location.search, { credentials: 'same-origin', cache: 'no-store' })
            .then(function(resp){ if(!resp.ok) throw new Error('network'); return resp.json(); })
            .then(function(data){
                if(!data) { return; }
                var d = data.item || {};
                var admin = data.admin_contact || d.admin_contact || {};

                if (d.title) safeText(titleEl, d.title);
                if (d.sub) safeText(subEl, d.sub);
                if (d.full_name) safeText(fullnameEl, d.full_name);
                if (d.student_id) safeText(studentidEl, d.student_id);
                if (d.course) safeText(courseEl, d.course);
                if (d.year_level) safeText(yearEl, d.year_level);
                if (d.date_submitted) safeText(dateEl, d.date_submitted);
                if (d.status) setBadge(d.status);
                toggleContinue(!!d.show_availability);

                if (admin.email && adminEmailEl) {
                    adminEmailEl.textContent = admin.email;
                    adminEmailEl.setAttribute('href', 'mailto:' + admin.email);
                }
                if (admin.phone && adminPhoneEl) {
                    adminPhoneEl.textContent = admin.phone;
                    adminPhoneEl.setAttribute('href', 'tel:' + admin.phone.replace(/[^0-9+]/g, ''));
                }
            })
            .catch(function(){ /* ignore */ })
            .finally(function(){ btn.disabled = false; btn.removeAttribute('aria-busy'); btn.classList.remove('btn--loading'); btn.textContent = btn.dataset.origText || 'Refresh Status'; });
    }

    if(btn){ btn.addEventListener('click', function(){ fetchStatus(); }); }

    // Auto Refresh every 20 seconds for real-time status updates
    try {
        setInterval(fetchStatus, 20000);
    } catch (e) { /* ignore */ }

    // When user clicks Continue, clear the session payload then navigate
    if (continueBtn) {
        continueBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var href = continueBtn.getAttribute('href');
            window.location.href = href;
        });
    }
})();
</script>

</body>
</html>