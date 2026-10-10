<?php
declare(strict_types=1);

$activeStudentNav = (string) ($activeStudentNav ?? '');
if ($activeStudentNav === '') {
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $activeStudentNav = match ($scriptName) {
        'dashboard.php' => 'dashboard',
        'schedule.php' => 'schedule',
        'availability.php' => 'availability',
        'attendance_history.php', 'attendance.php' => 'attendance_history',
        'duty_excuse.php' => 'duty_excuse',
        'temporary_duty_request.php' => 'temporary_duty_request',
        'announcements.php' => 'announcements',
        'profile.php' => 'profile',
        default => '',
    };
}

$sidebarStudentName = trim((string) ($studentName ?? ($user['name'] ?? 'Student Assistant')));
$sidebarStudentCode = trim((string) ($studentCode ?? ($student['student_id_number'] ?? '')));
$sidebarAvatarUrl = $studentAvatarUrl ?? $avatarUrl ?? null;
if (!$sidebarAvatarUrl && isset($user['profile_image'])) {
    $sidebarAvatarUrl = sams_user_avatar_url($user['profile_image'], '../');
}

if (!function_exists('sams_student_sidebar_icon')) {
    function sams_student_sidebar_icon(string $key, bool $active): string
    {
        $stroke = $active ? '#ffffff' : '#364153';
        $fill   = $active ? '#ffffff' : '#364153';

        return match ($key) {
            'dashboard' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.5" y="2.5" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/><rect x="11" y="2.5" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/><rect x="2.5" y="11" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/><rect x="11" y="11" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/></svg>',
            'schedule' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.5" y="3.5" width="15" height="14" rx="2" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M6 1.5v4M14 1.5v4" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/><path d="M2.5 8h15" stroke="' . $stroke . '" stroke-width="1.3"/></svg>',
            'availability' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="7.5" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M10 5.5V10l3 2" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'attendance_history' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 3h12a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2z" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M6.5 10l2.5 2.5 4.5-5" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'duty_excuse' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7.5L12.5 3H5z" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M12 3v5h5M7 11h6M7 14h4" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'temporary_duty_request' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="14" height="14" rx="2" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M10 6v8M6 10h8" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'announcements' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" stroke="' . $stroke . '" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'profile' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="6.5" r="3.2" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M4 16.5c0-3.2 2.7-5.5 6-5.5s6 2.3 6 5.5" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'logout' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/><path d="M13 14l3-4-3-4M16 10H7" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            default => '',
        };
    }
}

$studentSidebarSections = [
    'MAIN MENU' => [
        ['key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard'],
    ],
    'ACADEMICS & SCHEDULE' => [
        ['key' => 'schedule', 'href' => 'schedule.php', 'label' => 'My Duty Schedule'],
        ['key' => 'availability', 'href' => 'availability.php', 'label' => 'Class Availability'],
    ],
    'DUTY & ATTENDANCE' => [
        ['key' => 'attendance_history', 'href' => 'attendance_history.php', 'label' => 'Attendance History'],
        ['key' => 'duty_excuse', 'href' => 'duty_excuse.php', 'label' => 'Duty Excuse Slip'],
        ['key' => 'temporary_duty_request', 'href' => 'temporary_duty_request.php', 'label' => 'Temporary Duty Request'],
    ],
    'COMMUNICATION' => [
        ['key' => 'announcements', 'href' => 'announcements.php', 'label' => 'Announcements'],
    ],
];

$footerNavItems = [
    ['key' => 'profile', 'href' => 'profile.php', 'label' => 'My Profile'],
    ['key' => 'logout', 'href' => 'logout.php', 'label' => 'Sign Out'],
];
?>
<style>
    /* Dedicated Student Sidebar Styles */
    .sidebar {
        width: 260px;
        min-height: 100vh;
        background: #ffffff;
        border-right: 1px solid #e5e7eb;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        position: sticky;
        top: 0;
        height: 100vh;
        overflow-y: auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        box-shadow: 1px 0 3px rgba(0,0,0,0.02);
        z-index: 40;
    }

    .sidebar__brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 22px 20px 18px;
        border-bottom: 1px solid #f1f5f9;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    .sidebar__logo {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: linear-gradient(135deg, #003087 0%, #155dfc 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 8px 16px rgba(0, 48, 135, 0.2);
    }

    .sidebar__logo-text {
        font-size: 17px;
        font-weight: 800;
        color: #ffffff;
        letter-spacing: 0.5px;
        line-height: 1;
    }

    .sidebar__brand-info {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .sidebar__app-name {
        font-size: 15px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.2;
        letter-spacing: -0.01em;
    }

    .sidebar__app-sub {
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
        line-height: 1.3;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .sidebar__app-sub::after {
        content: "•";
        color: #10b981;
    }

    .sidebar__nav {
        flex: 1;
        overflow-y: auto;
        padding: 16px 12px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .sidebar__section {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .sidebar__section-label {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #94a3b8;
        padding: 0 12px 6px;
        line-height: 1.2;
    }

    .sidebar .nav__list {
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 3px;
        margin: 0;
        padding: 0;
    }

    .sidebar .nav__item {
        margin: 0;
        padding: 0;
    }

    .sidebar .nav__link {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 42px;
        padding: 8px 12px;
        border-radius: 9px;
        color: #334155;
        font-size: 13.5px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.16s ease;
        border: 1px solid transparent;
        line-height: 1.3;
    }

    .sidebar .nav__link:hover {
        background: #f1f5f9;
        color: #0f172a;
        transform: translateX(2px);
    }

    .sidebar .nav__link--active {
        background: linear-gradient(135deg, #003087 0%, #155dfc 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 6px 14px rgba(21, 93, 252, 0.22);
        font-weight: 700;
    }

    .sidebar .nav__link--active:hover {
        transform: none;
        opacity: 0.96;
    }

    .sidebar .nav__icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .sidebar .nav__icon svg {
        width: 100%;
        height: 100%;
        display: block;
    }

    .sidebar .nav__label {
        flex: 1;
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar .nav__link--active .nav__label {
        color: #ffffff !important;
    }

    .sidebar__footer {
        border-top: 1px solid #f1f5f9;
        padding: 14px 12px;
        background: #fafafa;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .sidebar__footer .sidebar__section-label {
        padding-left: 12px;
        margin-bottom: 4px;
    }

    .sidebar__user-card {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        margin-top: 6px;
    }

    .sidebar__user-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        object-fit: cover;
        border: 1.5px solid #003087;
        flex-shrink: 0;
    }

    .sidebar__user-avatar-initials {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #003087;
        color: #ffffff;
        font-size: 13px;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .sidebar__user-info {
        flex: 1;
        min-width: 0;
    }

    .sidebar__user-name {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.2;
    }

    .sidebar__user-id {
        font-size: 11px;
        color: #64748b;
        line-height: 1.2;
        margin-top: 2px;
    }

    @media (max-width: 900px) {
        .sidebar {
            width: 100%;
            height: auto;
            position: relative;
            border-right: none;
            border-bottom: 1px solid #e5e7eb;
        }
    }
</style>

<aside class="sidebar" id="student-sidebar" aria-label="Student Assistant navigation">
    <div class="sidebar__brand">
        <div class="sidebar__logo" aria-hidden="true">
            <span class="sidebar__logo-text">NU</span>
        </div>
        <div class="sidebar__brand-info">
            <span class="sidebar__app-name">SA System</span>
            <span class="sidebar__app-sub">Student Portal</span>
        </div>
    </div>

    <nav class="sidebar__nav" aria-label="Student primary navigation">
        <?php foreach ($studentSidebarSections as $sectionLabel => $items): ?>
            <div class="sidebar__section">
                <div class="sidebar__section-label"><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                <ul class="nav__list">
                    <?php foreach ($items as $item): ?>
                        <?php $isActive = ($activeStudentNav === $item['key']); ?>
                        <li class="nav__item">
                            <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" 
                               class="nav__link<?= $isActive ? ' nav__link--active' : '' ?>"
                               <?= $isActive ? ' aria-current="page"' : '' ?>>
                                <span class="nav__icon" aria-hidden="true"><?= sams_student_sidebar_icon($item['key'], $isActive) ?></span>
                                <span class="nav__label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <div class="sidebar__section-label">ACCOUNT</div>
        <ul class="nav__list">
            <?php foreach ($footerNavItems as $item): ?>
                <?php $isActive = ($activeStudentNav === $item['key']); ?>
                <li class="nav__item">
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" 
                       class="nav__link<?= $isActive ? ' nav__link--active' : '' ?>"
                       <?= $isActive ? ' aria-current="page"' : '' ?>>
                        <span class="nav__icon" aria-hidden="true"><?= sams_student_sidebar_icon($item['key'], $isActive) ?></span>
                        <span class="nav__label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($sidebarStudentName !== ''): ?>
            <div class="sidebar__user-card">
                <?php if ($sidebarAvatarUrl): ?>
                    <img src="<?= htmlspecialchars($sidebarAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="sidebar__user-avatar">
                <?php else: ?>
                    <div class="sidebar__user-avatar-initials">
                        <?= htmlspecialchars(strtoupper(substr($sidebarStudentName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <div class="sidebar__user-info">
                    <div class="sidebar__user-name" title="<?= htmlspecialchars($sidebarStudentName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sidebarStudentName, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($sidebarStudentCode !== ''): ?>
                        <div class="sidebar__user-id"><?= htmlspecialchars($sidebarStudentCode, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</aside>
