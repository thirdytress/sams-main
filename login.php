<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mail.php';

$currentUser = sams_authenticated_user();
if ($currentUser) {
  header('Location: ' . sams_dashboard_for_role($currentUser['role']));
  exit;
}

$error = '';
$success = '';
$showOtpModal = false;
$identifier = trim($_POST['identifier'] ?? '');

function sams_login_pending_student_otp(): ?array
{
  $pending = $_SESSION['sams_pending_student_login'] ?? null;
  return is_array($pending) ? $pending : null;
}

function sams_send_student_otp_from_user(array $user): void
{
  $otpCode = (string) random_int(100000, 999999);
  $_SESSION['sams_pending_student_login'] = [
    'user' => [
      'id' => (int) $user['id'],
      'email' => (string) $user['email'],
      'role' => (string) $user['role'],
      'first_name' => (string) ($user['first_name'] ?? ''),
      'last_name' => (string) ($user['last_name'] ?? ''),
      'student_id' => (string) ($user['student_id'] ?? ''),
      'must_change_password' => (int) ($user['must_change_password'] ?? 0),
      'application_status' => (string) ($user['application_status'] ?? ''),
    ],
    'otp_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'expires_at' => time() + 600,
    'created_at' => time(),
  ];

  sams_send_otp_email(
    (string) $user['email'],
    trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')),
    $otpCode
  );
}

function sams_finish_student_login(array $pendingUser): void
{
  unset($_SESSION['sams_pending_student_login']);
  sams_login($pendingUser);

  if ((int) ($pendingUser['must_change_password'] ?? 0) === 1) {
    header('Location: change_password.php');
    exit;
  }

  if (in_array(strtolower((string) ($pendingUser['application_status'] ?? '')), ['pending', 'rejected'], true)) {
    header('Location: status.php');
    exit;
  }

  header('Location: ' . sams_dashboard_for_role('student'));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string) ($_POST['action'] ?? 'login');

  if ($action === 'cancel_otp') {
    unset($_SESSION['sams_pending_student_login']);
    header('Location: login.php');
    exit;
  }

  if ($action === 'verify_otp') {
    $pending = sams_login_pending_student_otp();
    $otp = preg_replace('/\D+/', '', trim((string) ($_POST['otp'] ?? '')));

    if (!$pending || empty($pending['user']) || empty($pending['otp_hash']) || empty($pending['expires_at'])) {
      $error = 'Your OTP session expired. Please log in again.';
    } elseif ($otp === '') {
      $error = 'Please enter the OTP code.';
      $showOtpModal = true;
    } elseif ((int) $pending['expires_at'] < time()) {
      $error = 'OTP expired. Please resend a new code.';
      $showOtpModal = true;
    } elseif (!password_verify($otp, (string) $pending['otp_hash'])) {
      $error = 'Invalid OTP. Please try again.';
      $showOtpModal = true;
    } else {
      sams_finish_student_login($pending['user']);
    }
  }

  if ($action === 'resend_otp') {
    $pending = sams_login_pending_student_otp();
    if (!$pending || empty($pending['user'])) {
      $error = 'Your OTP session expired. Please log in again.';
    } else {
      try {
        sams_send_student_otp_from_user($pending['user']);
        $success = 'A new OTP has been sent to your email.';
        $showOtpModal = true;
      } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $showOtpModal = true;
      }
    }
  }

  if ($action === 'login') {
    $password = trim($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
      $error = 'Please fill in all fields.';
    } else {
      try {
        $user = sams_authenticate($identifier, $password);

        if (($user['role'] ?? null) === 'student') {
          sams_send_student_otp_from_user($user);
          $success = 'We sent a one-time password to your email. Enter it below to continue.';
          $showOtpModal = true;
        } else {
          sams_login($user);

          if ((int) ($user['must_change_password'] ?? 0) === 1) {
            header('Location: change_password.php');
            exit;
          }

          header('Location: ' . sams_dashboard_for_role($user['role']));
          exit;
        }
      } catch (Throwable $exception) {
        $error = $exception->getMessage();
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login – SAMS | NU Lipa</title>
  <meta name="description" content="Login to SAMS – the Student Assistant Management System for National University Lipa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/sams-design-system.css" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      --color-primary:        #003087;
      --color-primary-end:    #004aab;
      --color-gold:           #ffb81c;
      --color-dark:           #101828;
      --color-body:           #364153;
      --color-muted:          #4a5565;
      --color-muted-2:        #6a7282;
      --color-blue-light:     #dbeafe;
      --color-blue-pale:      #bedbff;
      --color-white:          #ffffff;
      --color-border:         #e5e7eb;
      --color-input-border:   #d1d5dc;
      --color-placeholder:    rgba(10,10,10,0.5);
      --color-page-bg-start:  #eff6ff;
      --color-page-bg-mid:    #ffffff;
      --color-page-bg-end:    #fffbeb;

      --grad-primary:         linear-gradient(90deg, #003087 0%, #004aab 100%);
      --grad-primary-134:     linear-gradient(134deg, #003087 0%, #004aab 100%);
      --grad-page:            linear-gradient(149deg, var(--color-page-bg-start) 0%, var(--color-page-bg-mid) 50%, var(--color-page-bg-end) 100%);

      --shadow-card:          0 25px 50px 0 rgba(0,0,0,.25);

      --radius-sm:   10px;
      --radius-md:   14px;
      --radius-lg:   16px;
      --radius-xl:   24px;

      --font-xs:   12px;
      --font-sm:   14px;
      --font-base: 16px;
      --font-lg:   18px;
      --font-xl:   20px;
      --font-2xl:  24px;
      --font-3xl:  30px;
      --font-4xl:  36px;

      --space-1:   4px;
      --space-2:   8px;
      --space-3:   12px;
      --space-4:   16px;
      --space-5:   20px;
      --space-6:   24px;
      --space-7:   28px;
      --space-8:   32px;
      --space-10:  40px;
      --space-12:  48px;
    }

    /* =============================================
       RESET & BASE
    ============================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      height: 100%;
    }
    body {
      font-family: 'Inter', sans-serif;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--grad-page);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; }
    button, input { font-family: inherit; }

    /* =============================================
       PAGE WRAPPER – centres the two-card layout
    ============================================= */
    .page {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: var(--space-12) var(--space-4);
    }

    /* =============================================
       TWO-CARD CONTAINER
    ============================================= */
    .login-wrap {
      display: grid;
      grid-template-columns: 560px 560px;
      gap: 32px;
      width: 100%;
      max-width: 1152px;
    }

    /* =============================================
       LEFT PANEL  (blue brand card)
    ============================================= */
    .brand-card {
      background: var(--grad-primary-134);
      border: 4px solid var(--color-gold);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-card);
      padding: var(--space-12);
      display: flex;
      flex-direction: column;
      gap: 0;
      min-height: 581px;
    }

    /* Brand row */
    .brand-card__header {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-bottom: 32px;        /* 144px top - 48px padding - 64px row ≈ 32px gap to heading */
    }
    .brand-card__logo {
      width: 64px;
      height: 64px;
      border-radius: var(--radius-lg);
      background: var(--color-white);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-3xl);
      font-weight: 900;
      color: var(--color-primary);
      flex-shrink: 0;
    }
    .brand-card__title {
      font-family: var(--font-display, 'Poppins', sans-serif);
      font-size: var(--font-3xl);
      font-weight: 800;
      color: var(--color-white);
      line-height: 1.2;
    }
    .brand-card__subtitle {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-blue-pale);
      line-height: 1.5;
    }

    /* Heading & paragraph */
    .brand-card__heading {
      font-family: var(--font-display, 'Poppins', sans-serif);
      font-size: var(--font-4xl);
      font-weight: 800;
      color: var(--color-white);
      line-height: 1.25;
      margin-bottom: var(--space-4);
    }
    .brand-card__desc {
      font-size: var(--font-xl);
      font-weight: 500;
      color: var(--color-blue-light);
      line-height: 1.4;
      margin-bottom: var(--space-8);
      max-width: 437px;
    }

    /* Features list */
    .brand-card__features {
      background: rgba(255,255,255,.10);
      border-radius: var(--radius-lg);
      padding: var(--space-6) var(--space-6) var(--space-6);
      display: flex;
      flex-direction: column;
      gap: var(--space-4);
    }
    .brand-card__feature {
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      min-height: 48px;
    }
    .brand-card__feature-icon {
      width: 32px;
      height: 32px;
      border-radius: var(--radius-sm);
      background: var(--color-gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
      margin-top: 4px;
    }
    .brand-card__feature-title {
      font-size: var(--font-base);
      font-weight: 900;
      color: var(--color-white);
      line-height: 1.5;
    }
    .brand-card__feature-sub {
      font-size: var(--font-sm);
      font-weight: 500;
      color: var(--color-blue-pale);
      line-height: 1.43;
    }

    /* =============================================
       RIGHT PANEL  (white login card)
    ============================================= */
    .login-card {
      background: var(--color-white);
      border: 2px solid var(--color-border);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-card);
      padding: var(--space-12);
      display: flex;
      flex-direction: column;
      min-height: 581px;
    }

    /* Card heading */
    .login-card__heading {
      font-family: var(--font-display, 'Poppins', sans-serif);
      font-size: var(--font-4xl);
      font-weight: 800;
      color: var(--color-dark);
      line-height: 1.15;
      margin-bottom: var(--space-2);
    }
    .login-card__tagline {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted);
      line-height: 1.5;
      margin-bottom: var(--space-10);
    }

    /* Alert (PHP error feedback) */
    .login-card__alert {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #b91c1c;
      border-radius: var(--radius-sm);
      padding: var(--space-3) var(--space-4);
      font-size: var(--font-sm);
      font-weight: 500;
      margin-bottom: var(--space-6);
    }

    /* Form */
    .login-form {
      display: flex;
      flex-direction: column;
      gap: var(--space-6);
    }
    .login-form__group {
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .login-form__label {
      font-size: var(--font-sm);
      font-weight: 900;
      color: var(--color-body);
      line-height: 1.43;
    }
    .login-form__input {
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
    .login-form__input::placeholder { color: var(--color-placeholder); }
    .login-form__input:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(0,48,135,.12);
    }

    /* Password wrapper */
    .login-form__password-wrap {
      position: relative;
    }
    .login-form__password-wrap .login-form__input {
      padding-right: 48px;
    }
    .login-form__toggle-pw {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      padding: 0;
      display: flex;
      align-items: center;
      color: var(--color-muted);
      line-height: 0;
    }
    .login-form__toggle-pw svg { width: 20px; height: 20px; }

    /* Remember + Forgot row */
    .login-form__row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .login-form__remember {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      cursor: pointer;
    }
    .login-form__remember input[type="checkbox"] {
      width: 16px;
      height: 16px;
      accent-color: var(--color-primary);
      cursor: pointer;
      flex-shrink: 0;
    }
    .login-form__remember-text {
      font-size: var(--font-sm);
      font-weight: 500;
      color: var(--color-body);
      line-height: 1.43;
    }
    .login-form__forgot {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-primary);
      line-height: 1.43;
      transition: opacity .2s;
    }
    .login-form__forgot:hover { opacity: .75; }

    /* Submit button */
    .login-form__submit {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-3);
      width: 100%;
      height: 60px;
      border: none;
      border-radius: var(--radius-md);
      background: var(--grad-primary);
      color: var(--color-white);
      font-size: var(--font-lg);
      font-weight: 900;
      cursor: pointer;
      transition: opacity .2s;
      line-height: 1;
    }
    .login-form__submit:hover { opacity: .88; }
    .login-form__submit img,
    .login-form__submit svg {
      width: 24px;
      height: 24px;
      flex-shrink: 0;
      display: block;
    }

    /* Footer links below form */
    .login-card__register {
      margin-top: var(--space-6);
      text-align: center;
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted);
      line-height: 1.5;
    }
    .login-card__register a {
      font-weight: 900;
      color: var(--color-primary);
    }
    .login-card__register a:hover { text-decoration: underline; }

    .login-card__back {
      margin-top: var(--space-4);
      text-align: center;
    }
    .login-card__back a {
      font-size: var(--font-sm);
      font-weight: 500;
      color: var(--color-muted-2);
      transition: color .2s;
    }
    .login-card__back a:hover { color: var(--color-primary); }

    /* =============================================
       OTP MODAL
    ============================================= */
    .otp-modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(16, 24, 40, .55);
      backdrop-filter: blur(6px);
      z-index: 9999;
    }
    .otp-modal--visible { display: flex; }
    .otp-modal__card {
      width: min(100%, 520px);
      background: #fff;
      border-radius: 24px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 24px 60px rgba(0,0,0,.22);
      overflow: hidden;
    }
    .otp-modal__header {
      padding: 24px 24px 0;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
    }
    .otp-modal__title {
      font-family: var(--font-display, 'Poppins', sans-serif);
      margin: 0 0 8px;
      font-size: 26px;
      line-height: 1.2;
      font-weight: 700;
      color: var(--color-dark);
    }
    .otp-modal__subtitle {
      margin: 0;
      color: var(--color-muted);
      line-height: 1.6;
    }
    .otp-modal__close {
      width: 40px;
      height: 40px;
      border-radius: 9999px;
      background: #f3f4f6;
      color: #374151;
      font-size: 20px;
      line-height: 1;
      flex-shrink: 0;
    }
    .otp-modal__body { padding: 24px; }
    .otp-modal__meta {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 12px;
      padding: 14px;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      background: #f8fafc;
      margin-bottom: 18px;
      color: #4a5565;
      font-size: 14px;
    }
    .otp-modal__meta strong { color: var(--color-dark); }
    .otp-modal__otp {
      display: flex;
      justify-content: center;
      gap: 10px;
      margin: 10px 0 20px;
      width: 100%;
    }
    .otp-modal__otp input {
      width: 100%;
      max-width: 100%;
      height: 84px;
      border: 2px solid #d1d5dc;
      border-radius: 18px;
      text-align: center;
      font-size: 36px;
      font-weight: 900;
      letter-spacing: .12em;
      outline: none;
      box-sizing: border-box;
    }
    .otp-modal__otp input:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(0,48,135,.12);
    }
    .otp-modal__actions {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .otp-modal__btn {
      height: 52px;
      border-radius: 14px;
      border: none;
      font-size: 16px;
      font-weight: 900;
      cursor: pointer;
    }
    .otp-modal__btn--primary {
      background: var(--grad-primary);
      color: #fff;
    }
    .otp-modal__btn--secondary {
      background: #f3f4f6;
      color: #374151;
    }
    .otp-modal__hint {
      margin-top: 16px;
      font-size: 14px;
      color: var(--color-muted);
      text-align: center;
    }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .login-wrap {
        grid-template-columns: 1fr;
        max-width: 560px;
      }
      .brand-card { min-height: auto; }
      .login-card { min-height: auto; }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .page { padding: var(--space-6) var(--space-4); }

      .brand-card {
        padding: var(--space-8);
      }
      .brand-card__heading { font-size: 26px; }
      .brand-card__desc    { font-size: var(--font-base); }

      .login-card {
        padding: var(--space-8);
      }
      .login-card__heading { font-size: 26px; }
    }
  </style>
</head>
<body>

  <main class="page" role="main">
    <div class="login-wrap">

      <!-- ============================================
           LEFT – Brand / info card
      ============================================= -->
      <aside class="brand-card" aria-label="SAMS information panel">

        <!-- Logo row -->
        <div class="brand-card__header">
          <div class="brand-card__logo" aria-hidden="true">NU</div>
          <div>
            <div class="brand-card__title">SAMS</div>
            <div class="brand-card__subtitle">Student Assistant Management System</div>
          </div>
        </div>

        <!-- Heading -->
        <h1 class="brand-card__heading">Welcome Back! 👋</h1>

        <!-- Description -->
        <p class="brand-card__desc">Login to access your dashboard, view schedules, and manage your duties at SDAO. Student logins now require email OTP verification.</p>

        <!-- Features -->
        <div class="brand-card__features">

          <div class="brand-card__feature">
            <div class="brand-card__feature-icon" aria-hidden="true">📅</div>
            <div>
              <div class="brand-card__feature-title">View Your Schedule</div>
              <div class="brand-card__feature-sub">Access your duty schedule anytime, anywhere</div>
            </div>
          </div>

          <div class="brand-card__feature">
            <div class="brand-card__feature-icon" aria-hidden="true">📷</div>
            <div>
              <div class="brand-card__feature-title">OTP Protected Login</div>
              <div class="brand-card__feature-sub">Student accounts verify login through email before access is granted</div>
            </div>
          </div>

          <div class="brand-card__feature">
            <div class="brand-card__feature-icon" aria-hidden="true">🔔</div>
            <div>
              <div class="brand-card__feature-title">Stay Updated</div>
              <div class="brand-card__feature-sub">Get notifications for schedule changes</div>
            </div>
          </div>

        </div>
      </aside>

      <!-- ============================================
           RIGHT – Login form card
      ============================================= -->
      <section class="login-card" aria-labelledby="login-heading">

        <h2 class="login-card__heading" id="login-heading">Login</h2>
          <p class="login-card__tagline">Enter your email to access SAMS</p>

        <?php if ($error): ?>
          <div class="login-card__alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form class="login-form" method="POST" action="" novalidate>

          <!-- Email -->
          <div class="login-form__group">
            <label class="login-form__label" for="identifier">Email</label>
            <input
              class="login-form__input"
              type="text"
              id="identifier"
              name="identifier"
              placeholder="student@nu-lipa.edu.ph"
              autocomplete="username"
              value="<?php echo htmlspecialchars($identifier); ?>"
              required
            />
          </div>

          <!-- Password -->
          <div class="login-form__group">
            <label class="login-form__label" for="password">Password</label>
            <div class="login-form__password-wrap">
              <input
                class="login-form__input"
                type="password"
                id="password"
                name="password"
                placeholder="password"
                autocomplete="current-password"
                required
              />
              <button
                type="button"
                class="login-form__toggle-pw"
                aria-label="Toggle password visibility"
                id="toggle-pw"
              >
                <!-- Eye icon (show) -->
                <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
                <!-- Eye-off icon (hide) – hidden by default -->
                <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" style="display:none;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 012.168-3.857M6.53 6.53A9.956 9.956 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.965 9.965 0 01-4.293 5.214M3 3l18 18" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Remember me + Forgot password -->
          <div class="login-form__row">
            <label class="login-form__remember">
              <input type="checkbox" name="remember" id="remember" <?php echo isset($_POST['remember']) ? 'checked' : ''; ?> />
              <span class="login-form__remember-text">Remember me</span>
            </label>
            <a class="login-form__forgot" href="forgot_password.php">Forgot Password?</a>
          </div>

          <!-- Submit -->
          <button class="login-form__submit" type="submit">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Login
          </button>

        </form>

        <!-- Register link -->
        <p class="login-card__register">
          Don't have an account? <a href="register.php">Apply as Student Assistant</a>
        </p>

        <!-- Back to home -->
        <div class="login-card__back">
          <a href="index.php">← Back to Home</a>
        </div>

      </section>

    </div>
  </main>

  <?php $pendingOtp = sams_login_pending_student_otp(); ?>
  <div class="otp-modal <?php echo ($showOtpModal || $pendingOtp) ? 'otp-modal--visible' : ''; ?>" id="otpModal" aria-hidden="<?php echo ($showOtpModal || $pendingOtp) ? 'false' : 'true'; ?>">
    <div class="otp-modal__card" role="dialog" aria-modal="true" aria-labelledby="otp-modal-title">
      <div class="otp-modal__header">
        <div>
          <h2 class="otp-modal__title" id="otp-modal-title">Verify your login</h2>
          <p class="otp-modal__subtitle">Enter the 6-digit code we sent to your student email to complete sign-in.</p>
        </div>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="cancel_otp" />
          <button type="submit" class="otp-modal__close" id="otpModalClose" aria-label="Close OTP modal">×</button>
        </form>
      </div>

      <div class="otp-modal__body">
        <?php if ($error): ?>
          <div class="login-card__alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div class="login-card__alert" style="background:#ecfdf5;border-color:#a7f3d0;color:#047857;" role="status"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="otp-modal__meta">
          <div><span>Email</span><br><strong><?php echo htmlspecialchars((string) ($pendingOtp['user']['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
          <div><span>Expires in</span><br><strong><?php echo $pendingOtp ? (int) ceil(max(0, ((int) ($pendingOtp['expires_at'] ?? time())) - time()) / 60) : 0; ?> min</strong></div>
        </div>

        <form method="POST" autocomplete="off">
          <input type="hidden" name="action" value="verify_otp" />
          <div class="otp-modal__otp" id="otpSlots">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-slot" aria-label="Digit 1" autocomplete="one-time-code" />
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-slot" aria-label="Digit 2" />
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-slot" aria-label="Digit 3" />
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-slot" aria-label="Digit 4" />
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-slot" aria-label="Digit 5" />
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-slot" aria-label="Digit 6" />
            <input type="hidden" name="otp" id="otpHidden" />
          </div>
          <div class="otp-modal__actions">
            <button class="otp-modal__btn otp-modal__btn--primary" type="submit">Verify OTP</button>
          </div>
        </form>

        <form method="POST" style="margin-top:12px;">
          <input type="hidden" name="action" value="resend_otp" />
          <div class="otp-modal__actions">
            <button class="otp-modal__btn otp-modal__btn--secondary" type="submit">Resend OTP</button>
          </div>
        </form>

        <div class="otp-modal__hint">You can close this modal and log in again if needed.</div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      'use strict';

      /* ---- Password visibility toggle ---- */
      var toggleBtn  = document.getElementById('toggle-pw');
      var pwInput    = document.getElementById('password');
      var iconEye    = document.getElementById('icon-eye');
      var iconEyeOff = document.getElementById('icon-eye-off');

      if (toggleBtn && pwInput) {
        toggleBtn.addEventListener('click', function () {
          var isPassword = pwInput.type === 'password';
          pwInput.type        = isPassword ? 'text' : 'password';
          iconEye.style.display    = isPassword ? 'none'  : '';
          iconEyeOff.style.display = isPassword ? ''      : 'none';
          toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
      }

      /* ---- Dynamic Student ID Password Label Check ---- */
      var identifierInput = document.getElementById('identifier');
      var passwordLabel = document.querySelector('label[for="password"]');

      if (identifierInput && passwordLabel && pwInput) {
        var lastCheckedVal = '';
        var checkTimeout = null;

        function checkFirstLoginStatus() {
          var val = identifierInput.value.trim();
          if (val === lastCheckedVal) return;
          lastCheckedVal = val;

          if (val === '') {
            passwordLabel.textContent = 'Password';
            pwInput.placeholder = 'password';
            return;
          }

          fetch('check_first_login.php?identifier=' + encodeURIComponent(val))
            .then(function(r) { return r.json(); })
            .then(function(d) {
              if (d && d.is_first_login) {
                passwordLabel.textContent = 'Student ID';
                pwInput.placeholder = 'Enter Student ID';
              } else {
                passwordLabel.textContent = 'Password';
                pwInput.placeholder = 'password';
              }
            })
            .catch(function() {
              passwordLabel.textContent = 'Password';
              pwInput.placeholder = 'password';
            });
        }

        identifierInput.addEventListener('blur', checkFirstLoginStatus);

        identifierInput.addEventListener('input', function() {
          clearTimeout(checkTimeout);
          checkTimeout = setTimeout(checkFirstLoginStatus, 500);
        });

        // Run once on load to handle autofill or remembered values
        setTimeout(checkFirstLoginStatus, 300);
      }

      // ---- OTP 6-slot logic ----
      var otpSlots = document.querySelectorAll('.otp-slot');
      var otpHidden = document.getElementById('otpHidden');
      if (otpSlots.length === 6 && otpHidden) {
        otpSlots[0].focus();
        otpSlots.forEach(function(input, idx) {
          input.addEventListener('input', function(e) {
            var v = input.value.replace(/\D/g, '');
            if (v.length > 1) {
              v.split('').slice(0, 6).forEach(function(digit, digitIdx) {
                if (otpSlots[idx + digitIdx]) otpSlots[idx + digitIdx].value = digit;
              });
              updateOtpHidden();
              if (otpSlots[Math.min(idx + v.length, 5)]) otpSlots[Math.min(idx + v.length, 5)].focus();
              return;
            }
            input.value = v;
            if (v && idx < 5) otpSlots[idx+1].focus();
            updateOtpHidden();
          });
          input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !input.value && idx > 0) {
              otpSlots[idx-1].focus();
            }
          });
        });
        function updateOtpHidden() {
          var code = Array.from(otpSlots).map(function(i){return i.value;}).join('');
          otpHidden.value = code;
        }
        // On form submit, combine digits
        var otpForm = otpHidden.closest('form');
        if (otpForm) {
          otpForm.addEventListener('submit', function() {
            updateOtpHidden();
          });
        }
      }

      var otpModal = document.getElementById('otpModal');
      var otpClose = document.getElementById('otpModalClose');
      var otpInput = otpModal ? otpModal.querySelector('input[name="otp"]') : null;

      function openOtpModal() {
        if (!otpModal) return;
        otpModal.classList.add('otp-modal--visible');
        otpModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (otpInput) {
          otpInput.focus();
        }
      }

      function closeOtpModal() {
        if (!otpModal) return;
        var cancelForm = otpClose ? otpClose.closest('form') : null;
        if (cancelForm) {
          cancelForm.submit();
        }
      }

      if (otpModal && otpClose) {
        otpClose.addEventListener('click', closeOtpModal);
        otpModal.addEventListener('click', function (event) {
          if (event.target === otpModal) {
            closeOtpModal();
          }
        });
      }

      if (otpModal && otpModal.classList.contains('otp-modal--visible')) {
        openOtpModal();
      }
    })();
  </script>

</body>
</html>