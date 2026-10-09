<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function sams_report_time_label(?string $time): string
{
		if ($time === null || trim($time) === '') {
				return '-';
		}

		$timestamp = strtotime($time);
		return $timestamp ? date('g:i A', $timestamp) : $time;
}

function sams_report_hours_label(float $hours): string
{
		return sams_attendance_format_duration((int) round(max(0, $hours) * 3600));
}

function sams_report_status_label(string $status): string
{
		return match ($status) {
				'present', 'active', 'completed' => 'Present',
				'late' => 'Late',
				'excused' => 'Excused',
				'absent' => 'Absent',
				'incomplete' => 'Absent',
				default => ucfirst($status),
		};
}

function sams_report_status_class(string $status): string
{
		return match ($status) {
				'present', 'active', 'completed' => 'badge badge--green',
				'late' => 'badge badge--yellow',
				'excused' => 'badge badge--yellow',
				'absent' => 'badge badge--red',
				'incomplete' => 'badge badge--gray',
				default => 'badge badge--gray',
		};
}

function sams_report_first_existing_column(PDO $pdo, string $table, array $columns): ?string
{
		foreach ($columns as $column) {
				$stmt = $pdo->prepare(
						'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
				);
				$stmt->execute([
						'table_name' => $table,
						'column_name' => $column,
				]);

				if ((int) $stmt->fetchColumn() > 0) {
						return $column;
				}
		}

		return null;
}

function sams_report_hours_from_row(array $row): float
{
		$timeIn = !empty($row['time_in']) ? strtotime((string) $row['time_in']) : false;
		$timeOut = !empty($row['time_out']) ? strtotime((string) $row['time_out']) : false;
		if ($timeIn !== false && $timeOut !== false && $timeOut > $timeIn) {
				return ($timeOut - $timeIn) / 3600;
		}

		$hoursColumn = $row['rendered_hours'] ?? null;
		if ($hoursColumn !== null && $hoursColumn !== '') {
				return (float) $hoursColumn;
		}

		return 0.0;
}

function sams_report_status_priority(string $status): int
{
		return match ($status) {
				'late' => 3,
				'present', 'active', 'completed' => 2,
				'absent', 'incomplete' => 1,
				default => 0,
		};
}

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
		header('Location: ../login.php');
		exit;
}

$pdo = sams_pdo();
$userId = (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0);


		function sams_report_day_rank(string $day): int
		{
			return match ($day) {
				'Monday' => 1,
				'Tuesday' => 2,
				'Wednesday' => 3,
				'Thursday' => 4,
				'Friday' => 5,
				'Saturday' => 6,
				default => 7,
			};
		}

		function sams_report_date_for_day(string $day): DateTimeImmutable
		{
			$map = [
				'Monday' => 1,
				'Tuesday' => 2,
				'Wednesday' => 3,
				'Thursday' => 4,
				'Friday' => 5,
				'Saturday' => 6,
			];

			$monday = new DateTimeImmutable('monday this week');
			$offset = ($map[$day] ?? 1) - 1;
			return $monday->modify('+' . $offset . ' days');
		}
$studentStmt = $pdo->prepare(
		'SELECT s.student_id AS student_db_id, s.student_id_number, s.program, s.year_level, u.first_name, u.last_name, u.email
		 FROM students s
		 INNER JOIN users u ON u.user_id = s.user_id
		 WHERE s.user_id = :user_id
		 LIMIT 1'
);
$studentStmt->execute(['user_id' => $userId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
		$scheduleRows = [];

if (empty($student)) {
		header('Location: ../login.php');
		exit;
}

$studentDbId = (int) ($student['student_db_id'] ?? 0);
$studentName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
if ($studentName === '') {
		$studentName = (string) ($currentUser['name'] ?? 'Student');
}
$studentCode = (string) ($student['student_id_number'] ?? '');

$termIdColumn = sams_report_first_existing_column($pdo, 'terms', ['term_id', 'id']);
$activeTerm = null;
if ($termIdColumn !== null) {
		$activeTermStmt = $pdo->query(
				'SELECT term_id, term_name, term_year, is_active
				 FROM terms
				 WHERE is_active = 1
				 ORDER BY term_id DESC
				 LIMIT 1'
		);
		$activeTerm = $activeTermStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$application = null;
$applicationQuery = 'SELECT application_id, term_id, preferred_office, status, submitted_at FROM applications WHERE student_id = :student_id';
$applicationParams = ['student_id' => $studentDbId];

if (!empty($activeTerm['term_id'])) {
		$applicationQuery .= ' AND term_id = :term_id';
		$applicationParams['term_id'] = (int) $activeTerm['term_id'];
}

$applicationQuery .= ' ORDER BY submitted_at DESC, application_id DESC LIMIT 1';
$applicationStmt = $pdo->prepare($applicationQuery);
$applicationStmt->execute($applicationParams);
$application = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$application) {
		$applicationStmt = $pdo->prepare(
				'SELECT application_id, term_id, preferred_office, status, submitted_at
				 FROM applications
				 WHERE student_id = :student_id
				 ORDER BY submitted_at DESC, application_id DESC
				 LIMIT 1'
		);
		$applicationStmt->execute(['student_id' => $studentDbId]);
		$application = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$reportTitle = 'Individual duty-hour report';
$reportSubtitle = 'Student view';
$reportTermLabel = 'No term available';
if (!empty($activeTerm)) {
		$reportTermLabel = trim((string) ($activeTerm['term_name'] ?? '')) . ' ' . (string) ($activeTerm['term_year'] ?? '');
}
if (!empty($application['term_id'])) {
		$termStmt = $pdo->prepare('SELECT term_name, term_year FROM terms WHERE term_id = :term_id LIMIT 1');
		$termStmt->execute(['term_id' => (int) $application['term_id']]);
		$termRow = $termStmt->fetch(PDO::FETCH_ASSOC) ?: [];
		$reportTermLabel = trim((string) ($termRow['term_name'] ?? '')) . ' ' . (string) ($termRow['term_year'] ?? '');
}

$summary = [
		'active' => 0,
		'late' => 0,
		'excused' => 0,
		'absent' => 0,
		'incomplete' => 0,
		'total' => 0,
];
$logs = [];
$renderedHours = 0.0;
$recentLabel = 'No attendance logs yet';

if (!empty($application['application_id'])) {
		$logs = sams_attendance_normalize_student_logs($pdo, (int) $application['application_id'], !empty($application['term_id']) ? (int) $application['term_id'] : null);

		foreach ($logs as $log) {
				$status = sams_attendance_display_status((string) ($log['status'] ?? ''));
				if ($status === 'present' || $status === 'active' || $status === 'completed') {
						$summary['active']++;
				} elseif ($status === 'late') {
						$summary['late']++;
				} elseif ($status === 'excused') {
						$summary['excused']++;
				} elseif ($status === 'absent' || $status === 'incomplete') {
						$summary['absent']++;
				}

				$summary['total']++;
				$renderedHours += sams_report_hours_from_row($log);
			}

		if (!empty($logs[0])) {
				$recentLabel = sams_report_status_label((string) ($logs[0]['status'] ?? '')) . ' on ' . date('M d, Y', strtotime((string) ($logs[0]['created_at'] ?? 'now')));
		}
}

$hasEvaluationData = false;
$pageTitle = 'Duty-Hour Report – SAMS Student Portal';

function h(?string $value): string
{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title><?php echo h($pageTitle); ?></title>
	<style>
		:root {
			/* Primary Colors */
			--color-primary: #003087;
			--color-primary-2: #0047ab;
			--color-white: #ffffff;
			--color-dark: #101828;
			--color-body: #364153;
			--color-muted: #4a5565;
			--color-muted-light: #99a1af;
			--color-bg: #f9fafb;
			--color-card-border: #f3f4f6;
			--color-border: #e5e7eb;
			--color-input-border: #d1d5dc;

			/* Accent Colors */
			--color-blue-pale: #bedbff;
			--color-blue-light: #dbeafe;
			--color-blue: #155dfc;
			--color-green: #00c950;
			--color-green-dark: #00a63e;
			--color-green-light: #dcfce7;
			--color-green-pale: #f0fdf4;
			--color-yellow: #e17100;
			--color-yellow-bg: #fffbeb;
			--color-yellow-border: #ffb81c;
			--color-red-dot: #fb2c36;

			/* Sidebar */
			--sidebar-w: 288px;
			--color-sidebar-start: #003087;
			--color-sidebar-end: #0047ab;

			/* Gradients */
			--grad-sidebar: linear-gradient(180deg, #003087 0%, #0047ab 100%);
			--grad-primary-135: linear-gradient(135deg, #003087 0%, #0047ab 100%);
			--grad-page: linear-gradient(135deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);

			/* Shadows */
			--shadow-card: 0 10px 15px 0 rgba(0,0,0,.10), 0 4px 6px 0 rgba(0,0,0,.10);
			--shadow-nav: 0 1px 3px 0 rgba(0,0,0,.10), 0 1px 2px 0 rgba(0,0,0,.10);
			--shadow-sidebar: 0 25px 50px 0 rgba(0,0,0,.25);

			/* Radii */
			--radius-sm: 10px;
			--radius-md: 14px;
			--radius-lg: 16px;
			--radius-pill: 9999px;

			/* Font sizes */
			--font-xs: 12px;
			--font-sm: 14px;
			--font-base: 16px;
			--font-lg: 18px;
			--font-xl: 20px;
			--font-2xl: 24px;
			--font-3xl: 30px;
			--font-4xl: 36px;

			/* Spacing */
			--space-1: 4px;
			--space-2: 8px;
			--space-3: 12px;
			--space-4: 16px;
			--space-5: 20px;
			--space-6: 24px;
			--space-8: 32px;
		}

		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		html, body { height: 100%; }
		body {
			font-family: 'Inter', Arial, sans-serif;
			font-size: var(--font-base);
			color: var(--color-dark);
			background: var(--grad-page);
			-webkit-font-smoothing: antialiased;
		}
		a { text-decoration: none; color: inherit; }
		img { display: block; }
		button { font-family: inherit; cursor: pointer; }

		/* APP SHELL */
		.app { display: flex; height: 100vh; overflow: hidden; }

		/* SIDEBAR */
		.sidebar {
			width: var(--sidebar-w);
			flex-shrink: 0;
			background: var(--grad-sidebar);
			box-shadow: var(--shadow-sidebar);
			display: flex;
			flex-direction: column;
			height: 100%;
			overflow: hidden;
		}

		.sidebar__brand {
			border-bottom: 1px solid rgba(255,255,255,.20);
			padding: var(--space-6) var(--space-6) var(--space-5);
			display: flex;
			align-items: center;
			gap: var(--space-3);
			flex-shrink: 0;
		}
		.sidebar__logo {
			width: 48px; height: 48px;
			border-radius: var(--radius-md);
			background: var(--color-white);
			display: flex; align-items: center; justify-content: center;
			font-size: var(--font-2xl);
			font-weight: 900;
			color: var(--color-primary);
			flex-shrink: 0;
		}
		.sidebar__brand-name {
			font-size: var(--font-xl);
			font-weight: 900;
			color: var(--color-white);
			line-height: 1.4;
		}
		.sidebar__brand-sub {
			font-size: var(--font-xs);
			font-weight: 400;
			color: var(--color-blue-pale);
		}

		/* Nav */
		.sidebar__nav {
			flex: 1;
			overflow-y: auto;
			padding: var(--space-6) var(--space-4) 0;
			display: flex;
			flex-direction: column;
			gap: var(--space-2);
		}
		.nav-item {
			display: flex;
			align-items: center;
			gap: var(--space-3);
			height: 48px;
			padding-left: var(--space-4);
			border-radius: var(--radius-md);
			font-size: var(--font-base);
			font-weight: 700;
			color: var(--color-white);
			cursor: pointer;
			transition: background .15s;
		}
		.nav-item:hover { background: rgba(255,255,255,.10); }
		.nav-item--active {
			background: var(--color-white);
			color: var(--color-primary);
			box-shadow: var(--shadow-card);
		}
		.nav-item--active:hover { background: var(--color-white); }
		.nav-item__icon { width: 20px; height: 20px; flex-shrink: 0; }

		/* Sidebar footer */
		.sidebar__footer {
			border-top: 1px solid rgba(255,255,255,.20);
			padding: 17px var(--space-4) var(--space-4);
			display: flex;
			flex-direction: column;
			gap: var(--space-3);
			flex-shrink: 0;
			background: #003087;
			position: relative;
			z-index: 2;
		}

		.sidebar__user {
			background: #0047ab;
			border: 1px solid rgba(255,255,255,.22);
			border-radius: var(--radius-md);
			padding: var(--space-4);
			display: flex;
			flex-direction: column;
			gap: 2px;
		}
		.sidebar__user-label { font-size: var(--font-sm); font-weight: 600; color: #dbeafe; }
		.sidebar__user-name { font-size: var(--font-base); font-weight: 900; color: var(--color-white); }
		.sidebar__user-id    { font-size: var(--font-xs);   font-weight: 500; color: #dbeafe; }

		.sidebar__logout {
			display: flex;
			align-items: center;
			gap: var(--space-2);
			height: 48px;
			padding-left: var(--space-4);
			border-radius: var(--radius-md);
			background: rgba(255,255,255,.10);
			border: none;
			font-size: var(--font-base);
			font-weight: 700;
			color: var(--color-white);
			cursor: pointer;
			transition: background .15s;
			width: 100%;
			text-align: left;
		}
		.sidebar__logout:hover { background: rgba(255,255,255,.18); }

		/* MAIN AREA */
		.layout { display: flex; height: 100vh; overflow: hidden; }
		.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

		/* Content area */
		.content {
			flex: 1;
			overflow-y: auto;
			padding: var(--space-8);
			display: flex;
			flex-direction: column;
			gap: var(--space-8);
		}

		/* Overview Card (Hero) */
		.overview-card {
			background: var(--grad-primary-135);
			color: var(--color-white);
			border-radius: var(--radius-lg);
			padding: var(--space-8);
			box-shadow: var(--shadow-card);
		}
		.overview-card__eyebrow {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-size: 11px;
			font-weight: 900;
			letter-spacing: .14em;
			text-transform: uppercase;
			color: rgba(255,255,255,.80);
			margin-bottom: var(--space-2);
		}
		.overview-card__title {
			font-size: var(--font-3xl);
			font-weight: 900;
			line-height: 1.1;
			margin-bottom: var(--space-2);
		}
		.overview-card__desc {
			max-width: 760px;
			font-size: var(--font-base);
			line-height: 1.6;
			color: rgba(255,255,255,.86);
			margin-bottom: var(--space-5);
		}
		.overview-card__chips {
			display: flex;
			flex-wrap: wrap;
			gap: var(--space-2);
		}
		.overview-card__chip {
			display: inline-flex;
			align-items: center;
			height: 36px;
			padding: 0 14px;
			border-radius: var(--radius-pill);
			background: rgba(255,255,255,.10);
			border: 1px solid rgba(255,255,255,.16);
			color: #fff;
			font-size: var(--font-sm);
			font-weight: 700;
			white-space: nowrap;
		}

		/* Overview Grid */
		.overview-grid {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: var(--space-4);
		}

		/* Card Styles */
		.card {
			background: var(--color-white);
			border: 1px solid var(--color-card-border);
			border-radius: var(--radius-lg);
			padding: var(--space-6);
			box-shadow: var(--shadow-card);
		}

		.card__header {
			margin-bottom: var(--space-5);
		}
		.card__title {
			font-size: var(--font-xl);
			font-weight: 900;
			color: var(--color-dark);
			margin-bottom: var(--space-2);
		}
		.card__sub {
			font-size: var(--font-sm);
			color: var(--color-body);
			line-height: 1.6;
		}

		/* Metric Card */
		.metric {
			background: var(--color-white);
			border: 1px solid var(--color-card-border);
			border-radius: var(--radius-lg);
			padding: var(--space-6);
			box-shadow: var(--shadow-card);
		}
		.metric__label {
			font-size: var(--font-xs);
			font-weight: 900;
			letter-spacing: .12em;
			text-transform: uppercase;
			color: var(--color-muted);
		}
		.metric__value {
			font-size: var(--font-4xl);
			font-weight: 900;
			line-height: 1.2;
			margin: var(--space-3) 0;
		}
		.metric__sub {
			font-size: var(--font-sm);
			color: var(--color-body);
			line-height: 1.5;
		}
		.metric--primary .metric__value { color: var(--color-primary); }
		.metric--green .metric__value { color: var(--color-green-dark); }
		.metric--yellow .metric__value { color: var(--color-yellow); }
		.metric--red .metric__value { color: var(--color-red-dot); }

		/* Section Styles */
		.section-title {
			font-size: var(--font-xl);
			font-weight: 900;
			color: var(--color-dark);
			margin: 0;
		}
		.section-sub {
			font-size: var(--font-sm);
			color: var(--color-body);
			line-height: 1.6;
			margin: var(--space-2) 0 var(--space-3);
		}

		/* Table Styles */
		.table-wrap {
			overflow-x: auto;
			border-radius: var(--radius-md);
			border: 1px solid var(--color-border);
		}
		table {
			width: 100%;
			border-collapse: collapse;
		}
		th {
			background: var(--color-bg);
			font-size: var(--font-xs);
			font-weight: 900;
			letter-spacing: .12em;
			text-transform: uppercase;
			color: var(--color-muted);
			padding: var(--space-4);
			text-align: left;
			border-bottom: 1px solid var(--color-border);
		}
		td {
			padding: var(--space-4);
			border-bottom: 1px solid var(--color-border);
			color: var(--color-body);
			font-size: var(--font-sm);
		}
		tr:last-child td { border-bottom: none; }

		/* Badge Styles */
		.badge {
			display: inline-flex;
			align-items: center;
			min-height: 28px;
			padding: var(--space-1) var(--space-3);
			border-radius: var(--radius-pill);
			font-size: var(--font-xs);
			font-weight: 900;
			letter-spacing: .02em;
			white-space: nowrap;
		}
		.badge--green { background: var(--color-green-light); color: #166534; }
		.badge--yellow { background: var(--color-yellow-bg); color: #92400e; }
		.badge--red { background: #fee2e2; color: #991b1b; }
		.badge--gray { background: #e2e8f0; color: #334155; }

		/* Empty State */
		.empty {
			border: 2px dashed var(--color-border);
			border-radius: var(--radius-lg);
			background: var(--color-bg);
			padding: var(--space-8);
			text-align: center;
			color: var(--color-body);
			line-height: 1.6;
		}

		/* Notes/Pills */
		.note {
			display: inline-flex;
			align-items: center;
			gap: var(--space-2);
			margin-top: var(--space-2);
			color: var(--color-body);
			font-size: var(--font-sm);
		}
		.note .pill {
			display: inline-flex;
			align-items: center;
			min-height: 30px;
			padding: 0 var(--space-3);
			border-radius: var(--radius-pill);
			background: var(--color-blue-light);
			color: var(--color-primary);
			font-weight: 900;
			font-size: var(--font-xs);
		}

		/* Responsive */
		@media (max-width: 1400px) {
			.overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
		}
		@media (max-width: 1100px) {
			.layout { flex-direction: column; }
			.sidebar { width: 100%; height: auto; flex-direction: row; }
			.sidebar__nav { flex-direction: row; gap: var(--space-4); }
			.sidebar__footer { display: none; }
			.overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
			.content { padding: var(--space-6); }
		}
		@media (max-width: 700px) {
			.overview-card { padding: var(--space-5); }
			.overview-card__title { font-size: var(--font-2xl); }
			.overview-grid { grid-template-columns: 1fr; }
			.content { padding: var(--space-4); gap: var(--space-4); }
		}
	</style>
	<link rel="stylesheet" href="../assets/css/sams-shell.css" />
</head>
<body>
	<div class="layout">
		<aside class="sidebar">
			<div class="sidebar__brand">
				<div class="sidebar__logo">NU</div>
				<div>
					<div class="sidebar__brand-name">SAMS</div>
					<div class="sidebar__brand-sub">Student Assistant Management</div>
				</div>
			</div>

			<nav class="sidebar__nav" aria-label="Student navigation">
				<a href="dashboard.php" class="nav-item">
					<svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M3 11.5L12 4l9 7.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
						<path d="M5 10.5V20h5v-5h4v5h5v-9.5" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
					</svg>
					Dashboard
				</a>
				<a href="schedule.php" class="nav-item">
					<svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<rect x="4" y="5" width="16" height="15" rx="2" stroke="#101828" stroke-width="1.8" fill="#ffffff" />
						<path d="M8 3v4M16 3v4M4 9h16" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="none" />
					</svg>
					My Schedule
				</a>
				<a href="temporary_duty_request.php" class="nav-item">
					<svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M12 4v16M4 12h16" stroke="#101828" stroke-width="1.8" stroke-linecap="round" />
					</svg>
					Temporary Duty Request
				</a>
				<a href="duty_excuse.php" class="nav-item">
					<svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M9 12h6M9 16h4M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="#ffffff" />
						<path d="M9 7h2" stroke="#101828" stroke-width="1.8" stroke-linecap="round" />
					</svg>
					Duty Excuse
				</a>
				<a href="attendance_history.php" class="nav-item nav-item--active" aria-current="page">
					<svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M5 4h10l4 4v12H5z" stroke="#101828" stroke-width="1.8" stroke-linejoin="round" fill="#ffffff" />
						<path d="M15 4v4h4" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
						<path d="M8 11h8M8 15h8" stroke="#101828" stroke-width="1.8" stroke-linecap="round" fill="none" />
					</svg>
					Duty-Hour Report
				</a>
				<a href="profile.php" class="nav-item">
					<svg class="nav-item__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<circle cx="12" cy="8" r="3.2" stroke="#101828" stroke-width="1.8" fill="#ffffff" />
						<path d="M6.5 19c1.4-3.1 4-4.8 5.5-4.8S15.6 15.9 17 19" stroke="#101828" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="#ffffff" />
					</svg>
					Profile
				</a>
			</nav>

			<div class="sidebar__footer">
				<div class="sidebar__user">
					<span class="sidebar__user-label">Logged in as</span>
					<span class="sidebar__user-name"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></span>
					<span class="sidebar__user-id">Student ID: <?php echo htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8'); ?></span>
				</div>
				<button class="sidebar__logout" type="button" onclick="window.location.href='logout.php'">Logout</button>
			</div>
		</aside>

		<main class="main">
			<div class="content">
				<!-- Overview Card (Hero) -->
				<section class="overview-card">
					<div class="overview-card__eyebrow">📊 Individual duty-hour report</div>
					<h2 class="overview-card__title"><?php echo h($studentName); ?></h2>
					<p class="overview-card__desc">This web report shows your rendered duty hours for the term and an attendance summary limited to Active, Late, and Absent counts. Evaluation data is intentionally excluded from the student portal.</p>
					<div class="overview-card__chips">
						<span class="overview-card__chip">📈 Rendered hours</span>
						<span class="overview-card__chip">📋 Attendance summary</span>
						<span class="overview-card__chip">🔒 No evaluation data</span>
						<span class="overview-card__chip">📅 <?php echo h($reportTermLabel); ?></span>
					</div>
				</section>

				<!-- Metrics Grid -->
				<section class="overview-grid">
					<div class="metric metric--primary">
						<div class="metric__label">📌 Rendered Hours</div>
						<div class="metric__value"><?php echo h(sams_report_hours_label($renderedHours)); ?></div>
						<div class="metric__sub">Total rendered time from your attendance logs.</div>
					</div>
					<div class="metric metric--green">
						<div class="metric__label">✓ Active (Present)</div>
						<div class="metric__value"><?php echo (int) $summary['active']; ?></div>
						<div class="metric__sub">Successfully clocked in on time.</div>
					</div>
					<div class="metric metric--yellow">
						<div class="metric__label">⏰ Late</div>
						<div class="metric__value"><?php echo (int) $summary['late']; ?></div>
						<div class="metric__sub">Clocked in after grace period.</div>
					</div>
					<div class="metric metric--red">
						<div class="metric__label">✗ Absent</div>
						<div class="metric__value"><?php echo (int) $summary['absent']; ?></div>
						<div class="metric__sub">No clock-in by schedule end.</div>
					</div>
				</section>

				<!-- Attendance Summary Card -->
				<section class="card">
					<div class="card__header">
						<h3 class="card__title">Attendance Summary</h3>
						<p class="card__sub">Summary of your attendance record for the current term. This view excludes evaluation data.</p>
					</div>
					<div style="display: flex; flex-direction: column; gap: var(--space-3);">
						<div class="note"><span class="pill">Current term</span> <strong><?php echo h($reportTermLabel); ?></strong></div>
						<div class="note"><span class="pill">Latest log</span> <?php echo h($recentLabel); ?></div>
						<div class="note"><span class="pill">Total records</span> <strong><?php echo (int) $summary['total']; ?> logs</strong></div>
					</div>
				</section>

				<!-- Recent Attendance Logs Card -->
				<section class="card">
					<div class="card__header">
						<h3 class="card__title">Recent Attendance Logs</h3>
						<p class="card__sub">Your attendance records for the current term, showing date, time, status, and remarks.</p>
					</div>
					<?php if (empty($logs)): ?>
						<div class="empty">
							<p style="margin: 0; font-size: var(--font-base); font-weight: 500;">
								<strong>No attendance records found</strong><br>
								<span style="color: var(--color-muted); font-size: var(--font-sm);">You haven't logged any attendance yet, or your schedules haven't started.</span>
							</p>
						</div>
					<?php else: ?>
						<div class="table-wrap">
							<table>
								<thead>
									<tr>
										<th>Date</th>
										<th>Time In</th>
										<th>Time Out</th>
										<th>Rendered Hours</th>
										<th>Status</th>
										<th>Remarks</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($logs as $log): ?>
										<tr>
											<td><?php echo h(date('M d, Y', strtotime((string) ($log['created_at'] ?? 'now')))); ?></td>
											<td><?php echo h(sams_report_time_label($log['time_in'] ?? null)); ?></td>
											<td><?php echo h(sams_report_time_label($log['time_out'] ?? null)); ?></td>
											<td><?php echo h(sams_report_hours_label(sams_report_hours_from_row($log))); ?></td>
											<td><span class="<?php echo h(sams_report_status_class((string) ($log['status'] ?? ''))); ?>"><?php echo h(sams_report_status_label((string) ($log['status'] ?? ''))); ?></span></td>
											<td><?php echo h((string) ($log['notes'] ?? '-')); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>
			</div>
		</main>
	</div>
</body>
<!-- SAMS Student Portal Attendance History -->
</html>
