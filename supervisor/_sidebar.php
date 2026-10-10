<?php
declare(strict_types=1);

$activeSupervisorNav = (string) ($activeSupervisorNav ?? '');
if ($activeSupervisorNav === '') {
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $activeSupervisorNav = match ($scriptName) {
        'dashboard.php' => 'dashboard',
        'students.php', 'student_profile.php' => 'students',
        'attendance.php' => 'attendance',
        'duty_excuses.php' => 'duty_excuses',
        'evaluation.php' => 'evaluation',
        'reports.php' => 'reports',
        'announcements.php' => 'announcements',
        'profile.php' => 'profile',
        default => '',
    };
}

if (!isset($pendingExcusesCount) && isset($pdo)) {
    try {
        $officeForExcuses = $officeName ?? $supervisorOffice ?? '';
        if ($officeForExcuses === '' && isset($user)) {
            $officeForExcuses = (string) ($user['office_name'] ?? '');
        }
        if ($officeForExcuses !== '') {
            $badgeStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM duty_excuses WHERE status = 'pending' AND office_name = :office"
            );
            $badgeStmt->execute(['office' => $officeForExcuses]);
            $pendingExcusesCount = (int) $badgeStmt->fetchColumn();
        } else {
            $pendingExcusesCount = 0;
        }
    } catch (Throwable $e) {
        $pendingExcusesCount = 0;
    }
} else {
    $pendingExcusesCount = (int) ($pendingExcusesCount ?? 0);
}

if (!function_exists('sams_supervisor_sidebar_icon')) {
    function sams_supervisor_sidebar_icon(string $key, bool $active): string
    {
        $stroke = $active ? '#ffffff' : '#364153';
        $fill   = $active ? '#ffffff' : '#364153';

        return match ($key) {
            'dashboard' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.5" y="2.5" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/><rect x="11" y="2.5" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/><rect x="2.5" y="11" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/><rect x="11" y="11" width="6.5" height="6.5" rx="1.5" fill="' . $fill . '"/></svg>',
            'students' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="6.5" r="3" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'attendance' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="7.5" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M10 5.5V10l3 2" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'duty_excuses' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7.5L12.5 3H5z" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M12 3v5h5M7 11h6M7 14h4" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'evaluation' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2l2.09 4.26L17 7.27l-3.5 3.41.83 4.82L10 13.27l-4.33 2.23.83-4.82L3 7.27l4.91-.71L10 2z" stroke="' . $stroke . '" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'reports' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="12" width="3" height="6" rx="1" fill="' . $fill . '"/><rect x="8.5" y="8" width="3" height="10" rx="1" fill="' . $fill . '"/><rect x="14" y="4" width="3" height="14" rx="1" fill="' . $fill . '"/></svg>',
            'announcements' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" stroke="' . $stroke . '" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'profile' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="6.5" r="3.2" stroke="' . $stroke . '" stroke-width="1.5"/><path d="M4 16.5c0-3.2 2.7-5.5 6-5.5s6 2.3 6 5.5" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'logout' => '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round"/><path d="M13 14l3-4-3-4M16 10H7" stroke="' . $stroke . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            default => '',
        };
    }
}

$sidebarSections = [
    'MAIN MENU' => [
        ['key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard'],
    ],
    'STUDENT MANAGEMENT' => [
        ['key' => 'students', 'href' => 'students.php', 'label' => 'Assigned Students'],
        ['key' => 'attendance', 'href' => 'attendance.php', 'label' => 'Attendance Tracking'],
        ['key' => 'duty_excuses', 'href' => 'duty_excuses.php', 'label' => 'Duty & Excuses', 'badge' => $pendingExcusesCount],
        ['key' => 'evaluation', 'href' => 'evaluation.php', 'label' => 'Evaluation'],
    ],
    'RECORDS & COMMS' => [
        ['key' => 'reports', 'href' => 'reports.php', 'label' => 'Accomplishment Reports'],
        ['key' => 'announcements', 'href' => 'announcements.php', 'label' => 'Announcements'],
    ],
];

$footerNavItems = [
    ['key' => 'profile', 'href' => 'profile.php', 'label' => 'My Profile'],
    ['key' => 'logout', 'href' => 'logout.php', 'label' => 'Sign Out'],
];
?>
<style>
    /* Dedicated Supervisor Sidebar Styles */
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
        color: #3b82f6;
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

    .sidebar .nav__badge {
        margin-left: auto;
        padding: 2px 7px;
        border-radius: 999px;
        background: #ef4444;
        color: #ffffff;
        font-size: 11px;
        font-weight: 800;
        line-height: 1.2;
        box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
    }

    .sidebar .nav__link--active .nav__badge {
        background: #ffffff;
        color: #ef4444;
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

<aside class="sidebar" id="supervisor-sidebar" aria-label="Supervisor navigation">
    <div class="sidebar__brand">
        <div class="sidebar__logo" aria-hidden="true">
            <span class="sidebar__logo-text">NU</span>
        </div>
        <div class="sidebar__brand-info">
            <span class="sidebar__app-name">SA System</span>
            <span class="sidebar__app-sub">Supervisor Portal</span>
        </div>
    </div>

    <nav class="sidebar__nav" aria-label="Supervisor primary navigation">
        <?php foreach ($sidebarSections as $sectionLabel => $items): ?>
            <div class="sidebar__section">
                <div class="sidebar__section-label"><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                <ul class="nav__list">
                    <?php foreach ($items as $item): ?>
                        <?php 
                            $isActive = ($activeSupervisorNav === $item['key']); 
                            $badge = (int) ($item['badge'] ?? 0);
                        ?>
                        <li class="nav__item">
                            <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" 
                               class="nav__link<?= $isActive ? ' nav__link--active' : '' ?>"
                               <?= $isActive ? ' aria-current="page"' : '' ?>>
                                <span class="nav__icon" aria-hidden="true"><?= sams_supervisor_sidebar_icon($item['key'], $isActive) ?></span>
                                <span class="nav__label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($badge > 0): ?>
                                    <span class="nav__badge" title="<?= $badge ?> pending"><?= $badge ?></span>
                                <?php endif; ?>
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
                <?php $isActive = ($activeSupervisorNav === $item['key']); ?>
                <li class="nav__item">
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" 
                       class="nav__link<?= $isActive ? ' nav__link--active' : '' ?>"
                       <?= $isActive ? ' aria-current="page"' : '' ?>>
                        <span class="nav__icon" aria-hidden="true"><?= sams_supervisor_sidebar_icon($item['key'], $isActive) ?></span>
                        <span class="nav__label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>
