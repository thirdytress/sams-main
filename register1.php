<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

if (empty($_SESSION['sams_registration']['step1'])) {
    header('Location: register.php');
    exit;
}

$step1_link = 'register.php';
$step2_link = 'register1.php';
$step3_link = (!empty($_SESSION['sams_registration']['step1']) && !empty($_SESSION['sams_registration']['step2'])) ? 'register3.php' : '#';
$step4_link = (!empty($_SESSION['sams_registration']['step1']) && !empty($_SESSION['sams_registration']['step2']) && !empty($_SESSION['sams_registration']['step3'])) ? 'register2.php' : '#';

$errors = [];
$values = [
    'course'          => '',
    'year_level'      => '',
    'units'           => '',
    'gpa'             => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['course']          = trim($_POST['course']          ?? '');
    $values['year_level']      = trim($_POST['year_level']      ?? '');
    $values['units']           = trim($_POST['units']           ?? '');
    $values['gpa']             = trim($_POST['gpa']             ?? '');

    // Required fields validation
    if ($values['course']          === '') $errors['course']          = 'Course/Program is required.';
    if (!in_array($values['year_level'], ['1', '2', '3', '4'], true)) {
        $errors['year_level'] = 'Choose a valid year level from 1st Year to 4th Year.';
    }
    if ($values['units'] === '' || filter_var($values['units'], FILTER_VALIDATE_INT) === false || (int) $values['units'] < 1 || (int) $values['units'] > 40) {
        $errors['units'] = 'Enter your current units (1-40).';
    }

    // Optional GPA – validate format if provided
    if ($values['gpa'] !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', $values['gpa'])) {
        $errors['gpa'] = 'Enter a valid GPA/GWA (e.g. 1.75).';
    }

    if (empty($errors)) {
      $_SESSION['sams_registration'] = array_merge($_SESSION['sams_registration'] ?? [], [
        'step2' => $values,
      ]);

      header('Location: register3.php');
      exit;
    }
}

function val(string $key, array $values): string {
    return htmlspecialchars($values[$key] ?? '');
}
function err(string $key, array $errors): string {
    return isset($errors[$key])
        ? '<p class="form__error" role="alert">' . htmlspecialchars($errors[$key]) . '</p>'
        : '';
}
function inputClass(string $key, array $errors): string {
    return isset($errors[$key]) ? 'form__input form__input--error' : 'form__input';
}
function selectClass(string $key, array $errors): string {
    return isset($errors[$key]) ? 'form__select form__select--error' : 'form__select';
}
function isSelected(string $key, string $option, array $values): string {
    return $values[$key] === $option ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Apply – Academic Info | SAMS NU Lipa</title>
  <meta name="description" content="Step 2 of the Student Assistant application – Academic Information." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/sams-design-system.css" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      --color-primary:         #003087;
      --color-primary-end:     #004aab;
      --color-gold:            #ffb81c;
      --color-dark:            #101828;
      --color-body:            #364153;
      --color-muted:           #4a5565;
      --color-muted-light:     #99a1af;
      --color-white:           #ffffff;
      --color-border:          #e5e7eb;
      --color-input-border:    #d1d5dc;
      --color-input-ph:        rgba(10,10,10,.50);
      --color-step-off-bg:     #f3f4f6;
      --color-info-bg:         #eff6ff;
      --color-info-border:     #bedbff;
      --color-info-text:       #1c398e;
      --color-error:           #b91c1c;
      --color-error-bg:        #fef2f2;
      --color-error-border:    #fecaca;
      --color-page-bg-start:   #eff6ff;
      --color-page-bg-mid:     #ffffff;
      --color-page-bg-end:     #fffbeb;

      --grad-primary:          linear-gradient(90deg,  #003087 0%, #004aab 100%);
      --grad-primary-135:      linear-gradient(135deg, #003087 0%, #004aab 100%);
      --grad-primary-159:      linear-gradient(159deg, #003087 0%, #004aab 100%);
      --grad-progress:         linear-gradient(90deg,  #003087 0%, #ffb81c 100%);
      --grad-page:             linear-gradient(143deg, var(--color-page-bg-start) 0%, var(--color-page-bg-mid) 50%, var(--color-page-bg-end) 100%);

      --shadow-card:  0 10px 15px 0 rgba(0,0,0,.10), 0 4px 6px 0 rgba(0,0,0,.10);

      --radius-sm:  10px;
      --radius-md:  14px;
      --radius-lg:  16px;

      --font-xs:    12px;
      --font-sm:    14px;
      --font-base:  16px;
      --font-lg:    18px;
      --font-xl:    24px;
      --font-2xl:   36px;

      --space-2:    8px;
      --space-3:    12px;
      --space-4:    16px;
      --space-5:    20px;
      --space-6:    24px;
      --space-8:    32px;
      --space-10:   40px;
      --space-12:   48px;
    }

    /* =============================================
       RESET & BASE
    ============================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { min-height: 100%; }
    body {
      font-family: 'Inter', sans-serif;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--grad-page);
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; }
    button, input, select { font-family: inherit; }

    /* =============================================
       PAGE LAYOUT
    ============================================= */
    .page {
      max-width: 1024px;
      margin-inline: auto;
      padding: var(--space-8) var(--space-8) var(--space-12);
    }

    /* =============================================
       BACK LINK
    ============================================= */
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-primary);
      margin-bottom: var(--space-8);
      transition: opacity .2s;
    }
    .back-link:hover { opacity: .75; }
    .back-link__icon { width: 20px; height: 20px; flex-shrink: 0; }

    /* =============================================
       PAGE HEADER
    ============================================= */
    .page-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: var(--space-8);
    }
    .page-header__icon-wrap {
      width: 64px;
      height: 64px;
      border-radius: var(--radius-lg);
      background: var(--grad-primary-135);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: var(--space-5);
    }
    .page-header__icon-wrap img, .page-header__icon-wrap svg { width: 32px; height: 32px; }
    .page-header__title {
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-dark);
      text-align: center;
      line-height: 1.1;
      margin-bottom: var(--space-2);
    }
    .page-header__subtitle {
      font-size: var(--font-lg);
      font-weight: 500;
      color: var(--color-muted);
      text-align: center;
    }

    /* =============================================
       PROGRESS CARD
    ============================================= */
    .progress-card {
      background: var(--color-white);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: var(--space-8);
      margin-bottom: var(--space-8);
    }
    .progress-card__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: var(--space-5);
    }
    .progress-card__step-label {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-muted);
    }
    .progress-card__pct-label {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-primary);
    }
    .progress-card__bar-track {
      height: 12px;
      background: var(--color-border);
      border-radius: 9999px;
      overflow: hidden;
      margin-bottom: var(--space-6);
    }
    .progress-card__bar-fill {
      height: 100%;
      width: 50%;
      background: var(--grad-progress);
      border-radius: 9999px;
    }

    /* Step tabs */
    .progress-card__steps {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-4);
    }
    .step-tab {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: var(--space-2);
      padding: var(--space-4);
      border-radius: var(--radius-md);
      background: var(--color-step-off-bg);
      cursor: pointer;
      border: none;
      text-decoration: none;
      transition: background .2s;
    }
    .step-tab--active {
      background: var(--grad-primary-159);
    }
    .step-tab__svg {
      width: 24px;
      height: 24px;
      flex-shrink: 0;
    }
    .step-tab--active .step-tab__svg  { color: var(--color-white); }
    .step-tab:not(.step-tab--active) .step-tab__svg { color: var(--color-muted-light); }
    .step-tab__label {
      font-size: var(--font-xs);
      font-weight: 700;
      text-align: center;
      white-space: nowrap;
      color: var(--color-muted-light);
    }
    .step-tab--active .step-tab__label { color: var(--color-white); }

    /* =============================================
       FORM CARD
    ============================================= */
    .form-card {
      background: var(--color-white);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: var(--space-8);
      margin-bottom: var(--space-8);
    }
    .form-card__heading {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-bottom: var(--space-6);
    }
    .form-card__heading-icon { width: 32px; height: 32px; flex-shrink: 0; }
    .form-card__heading-text {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
    }

    /* Two-column field grid */
    .form__grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-6);
    }

    /* Full-width span */
    .form__group--full { grid-column: 1 / -1; }

    /* Field group */
    .form__group {
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .form__label {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-body);
      line-height: 1.43;
    }

    /* Text input */
    .form__input {
      width: 100%;
      height: 52px;
      border: 2px solid var(--color-input-border);
      border-radius: var(--radius-md);
      padding: 12px 16px;
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-dark);
      background: var(--color-white);
      outline: none;
      transition: border-color .2s, box-shadow .2s;
      line-height: normal;
    }
    .form__input::placeholder { color: var(--color-input-ph); }
    .form__input:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(0,48,135,.12);
    }
    .form__input--error { border-color: var(--color-error); }
    .form__input--error:focus {
      border-color: var(--color-error);
      box-shadow: 0 0 0 3px rgba(185,28,28,.12);
    }

    /* Select wrapper + custom arrow */
    .form__select-wrap {
      position: relative;
    }
    .form__select-wrap::after {
      content: '';
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      width: 0;
      height: 0;
      border-left: 5px solid transparent;
      border-right: 5px solid transparent;
      border-top: 6px solid var(--color-muted);
      pointer-events: none;
    }
    .form__select {
      width: 100%;
      height: 52px;
      border: 2px solid var(--color-input-border);
      border-radius: var(--radius-md);
      padding: 12px 36px 12px 16px;
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-dark);
      background: var(--color-white);
      outline: none;
      appearance: none;
      -webkit-appearance: none;
      cursor: pointer;
      transition: border-color .2s, box-shadow .2s;
    }
    .form__select:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(0,48,135,.12);
    }
    .form__select--error { border-color: var(--color-error); }
    .form__select--error:focus {
      border-color: var(--color-error);
      box-shadow: 0 0 0 3px rgba(185,28,28,.12);
    }
    .form__select option[value=""] { color: var(--color-input-ph); }

    /* Error message */
    .form__error {
      font-size: var(--font-xs);
      font-weight: 500;
      color: var(--color-error);
      line-height: 1.4;
    }

    /* Info banner */
    .form__info-banner {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      background: var(--color-info-bg);
      border: 2px solid var(--color-info-border);
      border-radius: var(--radius-md);
      padding: 16px 18px;
      margin-top: var(--space-6);
      font-size: var(--font-sm);
      font-weight: 500;
      color: var(--color-info-text);
      line-height: 1.43;
    }
    .form__info-banner strong { font-weight: 700; }

    /* =============================================
       NAV BUTTONS
    ============================================= */
    .form-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .form-nav__back {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 60px;
      padding: 0 var(--space-8);
      border-radius: var(--radius-md);
      background: var(--color-white);
      border: 2px solid var(--color-primary);
      cursor: pointer;
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-primary);
      text-decoration: none;
      transition: background .2s;
    }
    .form-nav__back:hover { background: rgba(0,48,135,.05); }
    .form-nav__back img, .form-nav__back svg { width: 20px; height: 20px; display: block; }

    .form-nav__next {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 56px;
      padding: 0 var(--space-8);
      border-radius: var(--radius-md);
      background: var(--grad-primary);
      border: none;
      cursor: pointer;
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      text-decoration: none;
      transition: opacity .2s;
    }
    .form-nav__next:hover { opacity: .88; }
    .form-nav__next img, .form-nav__next svg { width: 20px; height: 20px; display: block; }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .page { padding-inline: var(--space-6); }
      .progress-card__steps { gap: var(--space-2); }
      .step-tab { padding: var(--space-3) var(--space-2); }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .page { padding: var(--space-4) var(--space-4) var(--space-10); }
      .page-header__title    { font-size: 26px; }
      .page-header__subtitle { font-size: var(--font-base); }

      .progress-card__steps {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-2);
      }
      .form__grid { grid-template-columns: 1fr; }
      .form__group--full { grid-column: 1; }

      .form-nav__back,
      .form-nav__next { padding: 0 var(--space-6); }
    }
  </style>
</head>
<body>

  <main class="page" role="main">

    <!-- Back to Home -->
    <a class="back-link" href="index.php">
      <svg class="back-link__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Back to Home
    </a>

    <!-- Page header -->
    <header class="page-header">
      <div class="page-header__icon-wrap" aria-hidden="true">
        <svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="m3 12 13-7 13 7-13 7L3 12Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 15v6c3 3 13 3 16 0v-6M29 12v7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </div>
      <h1 class="page-header__title">Student Assistant Application</h1>
      <p class="page-header__subtitle">Complete the 4-step process to apply</p>
    </header>

    <!-- Progress card -->
    <div class="progress-card" aria-label="Application progress">
      <div class="progress-card__meta">
        <span class="progress-card__step-label">Step 2 of 4</span>
        <span class="progress-card__pct-label">50% Complete</span>
      </div>

      <div
        class="progress-card__bar-track"
        role="progressbar"
        aria-valuenow="50"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label="Application progress 50%"
      >
        <div class="progress-card__bar-fill"></div>
      </div>

      <!-- Step tabs -->
      <nav class="progress-card__steps" aria-label="Application steps">

        <!-- Step 1 – completed / active highlight -->
        <a class="step-tab step-tab--active" href="<?= $step1_link ?>" aria-label="Step 1: Personal Info (completed)">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
          </svg>
          <span class="step-tab__label">Personal Info</span>
        </a>

        <!-- Step 2 – current (active) -->
        <div class="step-tab step-tab--active" aria-current="step" aria-label="Step 2: Academic Info (current)">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
          </svg>
          <span class="step-tab__label">Academic Info</span>
        </div>

        <!-- Step 3 – locked -->
        <a class="step-tab" href="<?= $step3_link ?>" aria-label="Step 3: Assessment">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <span class="step-tab__label">Assessment</span>
        </a>

        <!-- Step 4 – locked -->
        <a class="step-tab" href="<?= $step4_link ?>" aria-label="Step 4: Requirements">
          <svg class="step-tab__svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
          </svg>
          <span class="step-tab__label">Requirements</span>
        </a>

      </nav>
    </div>

    <!-- Academic Information form card -->
    <section class="form-card" aria-labelledby="academic-info-heading">

      <div class="form-card__heading">
        <svg class="form-card__heading-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16l-8-4-8 4V5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        <h2 class="form-card__heading-text" id="academic-info-heading">Academic Information</h2>
      </div>

      <form id="academic-form" method="POST" action="" novalidate>

        <div class="form__grid">

          <!-- Course / Program -->
          <div class="form__group">
            <label class="form__label" for="course">Course/Program *</label>
            <div class="form__select-wrap">
              <select
                class="<?php echo selectClass('course', $errors); ?>"
                id="course"
                name="course"
                required
                aria-required="true"
              >
                <option value="" <?php echo isSelected('course', '', $values); ?>>Select course</option>
                <?php foreach (sams_course_options() as $courseCode => $courseLabel): ?>
                  <option value="<?= htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8') ?>" <?= isSelected('course', $courseCode, $values) ?>><?= htmlspecialchars($courseLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php echo err('course', $errors); ?>
          </div>

          <!-- Year Level -->
          <div class="form__group">
            <label class="form__label" for="year_level">Year Level *</label>
            <div class="form__select-wrap">
              <select
                class="<?php echo selectClass('year_level', $errors); ?>"
                id="year_level"
                name="year_level"
                required
                aria-required="true"
              >
                <option value="" <?php echo isSelected('year_level', '', $values); ?>>Select year level</option>
                <option value="1" <?php echo isSelected('year_level', '1', $values); ?>>1st Year</option>
                <option value="2" <?php echo isSelected('year_level', '2', $values); ?>>2nd Year</option>
                <option value="3" <?php echo isSelected('year_level', '3', $values); ?>>3rd Year</option>
                <option value="4" <?php echo isSelected('year_level', '4', $values); ?>>4th Year</option>
              </select>
            </div>
            <?php echo err('year_level', $errors); ?>
          </div>

          <!-- Current GPA/GWA (optional) -->
          <div class="form__group">
            <label class="form__label" for="gpa">Current GPA/GWA</label>
            <input
              class="<?php echo inputClass('gpa', $errors); ?>"
              type="text"
              id="gpa"
              name="gpa"
              placeholder="e.g., 1.75"
              value="<?php echo val('gpa', $values); ?>"
              inputmode="decimal"
              autocomplete="off"
            />
            <?php echo err('gpa', $errors); ?>
          </div>

          <div class="form__group">
            <label class="form__label" for="units">Current Units *</label>
            <input
              class="<?php echo inputClass('units', $errors); ?>"
              type="number"
              id="units"
              name="units"
              min="1"
              max="40"
              step="1"
              placeholder="e.g., 18"
              value="<?php echo val('units', $values); ?>"
              required
              aria-required="true"
            />
            <?php echo err('units', $errors); ?>
          </div>



        </div><!-- /.form__grid -->

        <!-- Info banner -->
        <div class="form__info-banner" role="note" aria-label="Auto-filtering note">
          ℹ️&nbsp;<strong>Auto-filtering:</strong> Questions adjust based on your year level to ensure relevance.
        </div>

      </form>
    </section>

    <!-- Navigation buttons -->
    <div class="form-nav">

      <a class="form-nav__back" href="register.php" aria-label="Go back to Step 1: Personal Info">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back
      </a>

      <button
        class="form-nav__next"
        type="submit"
        form="academic-form"
        aria-label="Proceed to Step 3: Assessment"
      >
        Next
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>

    </div>

  </main>

  <script>
    (function () {
      'use strict';

      var form = document.getElementById('academic-form');
      if (!form) return;

      form.addEventListener('submit', function (e) {
        var valid = true;

        /* Clear previous client-side errors */
        form.querySelectorAll('.form__client-error').forEach(function (el) { el.remove(); });
        form.querySelectorAll('.form__input--error, .form__select--error').forEach(function (el) {
          el.classList.remove('form__input--error', 'form__select--error');
        });

        /* Validate required fields */
        var requiredFields = form.querySelectorAll('[required]');
        requiredFields.forEach(function (field) {
          if (!field.value.trim()) {
            valid = false;
            var errClass = field.tagName === 'SELECT' ? 'form__select--error' : 'form__input--error';
            field.classList.add(errClass);

            var group = field.closest('.form__group');
            if (group) {
              var p = document.createElement('p');
              p.className = 'form__error form__client-error';
              p.setAttribute('role', 'alert');
              p.textContent = 'This field is required.';
              group.appendChild(p);
            }
          }
        });

        /* Validate optional GPA format */
        var gpaInput = form.querySelector('#gpa');
        if (gpaInput && gpaInput.value.trim() !== '' && !/^\d+(\.\d{1,2})?$/.test(gpaInput.value.trim())) {
          valid = false;
          gpaInput.classList.add('form__input--error');
          var gpaGroup = gpaInput.closest('.form__group');
          if (gpaGroup) {
            var gpaErr = document.createElement('p');
            gpaErr.className = 'form__error form__client-error';
            gpaErr.setAttribute('role', 'alert');
            gpaErr.textContent = 'Enter a valid GPA/GWA (e.g. 1.75).';
            gpaGroup.appendChild(gpaErr);
          }
        }

        if (!valid) {
          e.preventDefault();
          var firstErr = form.querySelector('.form__input--error, .form__select--error');
          if (firstErr) firstErr.focus();
        }
      });

      /* Auto-filtering hint: highlight the info banner when year level changes */
      var yearSelect   = document.getElementById('year_level');
      var infoBanner   = document.querySelector('.form__info-banner');
      if (yearSelect && infoBanner) {
        yearSelect.addEventListener('change', function () {
          infoBanner.style.transition = 'background .3s';
          infoBanner.style.background = '#dbeafe';
          setTimeout(function () { infoBanner.style.background = ''; }, 800);
        });
      }

    })();
  </script>

</body>
</html>