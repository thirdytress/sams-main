<?php
$activeAdminNav = (string) ($activeAdminNav ?? '');
$pendingApplications = (int) ($pendingApplications ?? 0);

if (!function_exists('sams_admin_sidebar_icon')) {
    function sams_admin_sidebar_icon(string $key, bool $active): string
    {
        $stroke = $active ? 'white' : '#364153';
        $fill = $active ? 'white' : '#364153';

        return match ($key) {
            'dashboard' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="7" height="7" rx="1.5" fill="' . $fill . '"/><rect x="11" y="2" width="7" height="7" rx="1.5" fill="' . $fill . '"/><rect x="2" y="11" width="7" height="7" rx="1.5" fill="' . $fill . '"/><rect x="11" y="11" width="7" height="7" rx="1.5" fill="' . $fill . '"/></svg>',
            'applications' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 2h8a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2z" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M7 7h6M7 10h6M7 13h4" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'scheduling' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="4" width="16" height="14" rx="2" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M6 2v4M14 2v4" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/><path d="M2 9h16" stroke="' . $stroke . '" stroke-width="1.2"/></svg>',
            'temporary_duty' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="14" height="14" rx="2" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M10 6v8M6 10h8" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'duty_excuses' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7.5L12.5 3H5z" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M12 3v5h5M7 11h6M7 14h4" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'attendance' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="8" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'nfc_kiosk' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="5" width="14" height="10" rx="1.5" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M8 8a2 2 0 012.83 0M6.5 6.5a4 4 0 017 0" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'evaluation' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2l2.09 4.26L17 7.27l-3.5 3.41.83 4.82L10 13.27l-4.33 2.23.83-4.82L3 7.27l4.91-.71L10 2z" stroke="' . $stroke . '" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'reports' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="12" width="3" height="6" rx="1" fill="' . $fill . '"/><rect x="8.5" y="8" width="3" height="10" rx="1" fill="' . $fill . '"/><rect x="14" y="4" width="3" height="14" rx="1" fill="' . $fill . '"/></svg>',
            'announcements' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 1c-1.5 0-2.5 1.5-2.5 3v4H4c-1.1 0-2 .9-2 2v4c0 1.1.9 2 2 2h1v2c0 1.1.9 2 2 2s2-.9 2-2v-2h4v2c0 1.1.9 2 2 2s2-.9 2-2v-2h1c1.1 0 2-.9 2-2v-4c0-1.1-.9-2-2-2h-3.5V4c0-1.5-1-3-2.5-3Z" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'supervisors' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="7" r="3" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'meetings' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.5" y="3.5" width="15" height="14" rx="1.5" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M2.5 6h15M7 1v4M13 1v4" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'students' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="6.5" r="3" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'profile' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="6.5" r="3" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'shuffle_requests' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 5h2.5c1.4 0 2.2.7 3 2l3 6c.8 1.3 1.6 2 3 2H17M14 3l3 2-3 2M14 13l3 2-3 2" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 15h2.5c.8 0 1.5-.3 2.1-1" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'audit_logs' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 3h12a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2z" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M7 7h6M7 10h6M7 13h3" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/><circle cx="14" cy="13" r="2" stroke="' . $stroke . '" stroke-width="1.2"/><path d="M15.5 14.5l2 2" stroke="' . $stroke . '" stroke-width="1.2" stroke-linecap="round"/></svg>',
            'settings' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.325 2.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37a1.724 1.724 0 002.572-1.065z" stroke="' . $stroke . '" stroke-width="1.3"/><circle cx="10" cy="10" r="3" stroke="' . $stroke . '" stroke-width="1.3"/></svg>',
            'logout' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/><path d="M13 14l3-4-3-4M16 10H7" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            default => '',
        };
    }
}

$mainNavItems = [
    ['key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard'],
    ['key' => 'applications', 'href' => 'applications.php', 'label' => 'Applications'],
    ['key' => 'scheduling', 'href' => 'scheduling.php', 'label' => 'Scheduling'],
    ['key' => 'temporary_duty', 'href' => 'temporary_duty_requests.php', 'label' => 'Temporary Duty Requests'],
    ['key' => 'duty_excuses', 'href' => 'duty_excuses.php', 'label' => 'Duty Excuses'],
    ['key' => 'attendance', 'href' => 'attendance.php', 'label' => 'Attendance'],
    ['key' => 'nfc_kiosk', 'href' => 'nfc_kiosk.php', 'label' => 'NFC Kiosk'],
    ['key' => 'evaluation', 'href' => 'evaluation.php', 'label' => 'Evaluation'],
    ['key' => 'reports', 'href' => 'reports.php', 'label' => 'Reports'],
    ['key' => 'announcements', 'href' => 'announcements.php', 'label' => 'Announcements'],
    ['key' => 'supervisors', 'href' => 'supervisors.php', 'label' => 'Supervisors'],
    ['key' => 'meetings', 'href' => 'meetings.php', 'label' => 'Meetings'],
    ['key' => 'students', 'href' => 'students.php', 'label' => 'Students'],
    ['key' => 'shuffle_requests', 'href' => 'shuffle_requests.php', 'label' => 'Shuffle Requests'],
    ['key' => 'audit_logs', 'href' => 'audit_logs.php', 'label' => 'Audit Logs'],
];

$footerNavItems = [
    ['key' => 'profile', 'href' => 'profile.php', 'label' => 'Profile'],
    ['key' => 'logout', 'href' => 'logout.php', 'label' => 'Sign Out'],
];
?>
<link rel="stylesheet" href="../assets/css/admin-shell.css?v=20260922" />
<style>
    .topbar__notif,
    .topbar__notif-btn {
        position: relative !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 40px !important;
        height: 40px !important;
        padding: 0 !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 10px !important;
        background: #ffffff !important;
        color: #364153 !important;
        cursor: pointer !important;
        text-decoration: none !important;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .05) !important;
    }

    .topbar__notif:hover,
    .topbar__notif-btn:hover {
        background: #f3f7ff !important;
        border-color: #bfd3ff !important;
        color: #155dfc !important;
    }

    .topbar__notif-icon,
    .topbar__notif-btn svg {
        width: 21px !important;
        height: 21px !important;
        display: block !important;
    }

    .topbar__notif-dot {
        position: absolute !important;
        display: none;
        top: 5px !important;
        right: 5px !important;
        min-width: 16px !important;
        height: 16px !important;
        padding: 0 3px !important;
        border: 2px solid #ffffff !important;
        border-radius: 999px !important;
        background: #dc2626 !important;
        color: #ffffff !important;
        font-size: 9px !important;
        font-weight: 800 !important;
        line-height: 12px !important;
        text-align: center !important;
    }

    /* Hard fallback to keep admin sidebar typography consistent across pages */
    .sidebar,
    .sidebar * {
        font-family: 'Inter', Arial, sans-serif !important;
    }

    .sidebar .nav__link {
        font-size: 14px !important;
        font-weight: 500 !important;
        line-height: 1.2 !important;
        text-decoration: none !important;
    }

    .sidebar .nav__label {
        font-size: 14px !important;
        font-weight: 500 !important;
        line-height: 1.2 !important;
    }

    .sidebar .sidebar__section-label {
        font-size: 11px !important;
        font-weight: 800 !important;
        line-height: 1.2 !important;
        letter-spacing: .10em !important;
        text-transform: uppercase !important;
    }

    .sidebar .sidebar__app-name {
        font-size: 16px !important;
        font-weight: 700 !important;
        line-height: 1.2 !important;
    }

    .sidebar .sidebar__app-sub {
        font-size: 12px !important;
        font-weight: 400 !important;
        line-height: 1.2 !important;
    }
</style>
<aside class="sidebar" id="sidebar" aria-label="Admin navigation">
    <div class="sidebar__header">
        <div class="sidebar__brand">
            <div class="sidebar__logo" aria-hidden="true">
                <span class="sidebar__logo-text">NU</span>
            </div>
            <div class="sidebar__brand-info">
                <span class="sidebar__app-name">SA System</span>
                <span class="sidebar__app-sub">Admin Panel</span>
            </div>
        </div>
    </div>

    <nav class="sidebar__nav" aria-label="Main menu">
        <div class="sidebar__section-label">Main menu</div>
        <ul class="nav__list">
            <?php foreach ($mainNavItems as $item): ?>
                <?php $isActive = $activeAdminNav === $item['key']; ?>
                <li class="nav__item">
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="nav__link<?= $isActive ? ' nav__link--active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                        <span class="nav__icon" aria-hidden="true"><?= sams_admin_sidebar_icon($item['key'], $isActive) ?></span>
                        <span class="nav__label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($item['key'] === 'applications' && $pendingApplications > 0): ?><span class="nav__badge" aria-label="<?= (int) $pendingApplications ?> pending"><?= (int) $pendingApplications ?></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar__footer">
        <div class="sidebar__section-label">Account</div>
        <ul class="nav__list">
            <?php foreach ($footerNavItems as $item): ?>
                <?php $isActive = $activeAdminNav === $item['key']; ?>
                <li class="nav__item">
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="nav__link<?= $isActive ? ' nav__link--active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                        <span class="nav__icon" aria-hidden="true"><?= sams_admin_sidebar_icon($item['key'], $isActive) ?></span>
                        <span class="nav__label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>
<script src="../assets/js/admin-notifications.js?v=20261007" defer></script>