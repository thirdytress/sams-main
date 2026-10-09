<?php
// index.php - NU SAMS Landing Page
// Student Assistant Management System - National University Lipa

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/audit.php';

$pdo = sams_pdo();

// Ensure inquiries table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inquiries (
        inquiry_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL,
        student_id VARCHAR(50) DEFAULT NULL,
        subject VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(30) DEFAULT 'unread',
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // ignore
}

$contactSuccess = '';
$contactError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'contact_submit') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals(sams_csrf_token(), $token)) {
        $contactError = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $studentId = trim((string) ($_POST['student_id'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? 'General Inquiry'));
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($name === '' || $email === '' || $message === '') {
            $contactError = 'Please fill in all required fields (Full Name, Email, and Message).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $contactError = 'Please provide a valid email address.';
        } else {
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ins = $pdo->prepare('INSERT INTO inquiries (full_name, email, student_id, subject, message, ip_address, created_at) VALUES (:name, :email, :sid, :subject, :msg, :ip, NOW())');
                $ins->execute([
                    'name' => $name,
                    'email' => $email,
                    'sid' => $studentId !== '' ? $studentId : null,
                    'subject' => $subject,
                    'message' => $message,
                    'ip' => $ip,
                ]);
                $inquiryId = (int) $pdo->lastInsertId();

                // Notify all active admins
                $admins = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($admins)) {
                    $hasTypeCol = sams_column_exists($pdo, 'notifications', 'notification_type') ? 'notification_type' : (sams_column_exists($pdo, 'notifications', 'type') ? 'type' : null);
                    $notifSql = $hasTypeCol 
                        ? "INSERT INTO notifications (user_id, title, message, {$hasTypeCol}, created_at) VALUES (:uid, :title, :msg, 'inquiry', NOW())"
                        : "INSERT INTO notifications (user_id, title, message, created_at) VALUES (:uid, :title, :msg, NOW())";
                    $notifStmt = $pdo->prepare($notifSql);
                    foreach ($admins as $adminUid) {
                        $notifStmt->execute([
                            'uid' => (int) $adminUid,
                            'title' => "New Inquiry: {$subject} ({$name})",
                            'msg' => "From: {$name} ({$email})" . ($studentId ? " [ID: {$studentId}]" : "") . "\n\n" . mb_strimwidth($message, 0, 160, '...'),
                        ]);
                    }
                }

                // Log to Audit Trail
                sams_log_audit(
                    'INQUIRY_SUBMIT',
                    'CONTACT',
                    "User {$name} ({$email}) sent an inquiry to SDAO: '{$subject}'.",
                    'inquiry',
                    $inquiryId,
                    [
                        'name' => $name,
                        'email' => $email,
                        'student_id' => $studentId,
                        'subject' => $subject,
                    ]
                );

                $contactSuccess = 'Thank you for reaching out! Your inquiry has been sent to the SDAO team. We will get back to you shortly.';
            } catch (Throwable $e) {
                $contactError = 'Failed to submit your message: ' . $e->getMessage();
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
  <title>SAMS – Student Assistant Management System | NU Lipa</title>
  <meta name="description" content="The official Student Assistant Management System for NU Lipa's Student Development and Activities Office (SDAO)." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/sams-design-system.css" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      --color-primary:         #003087;
      --color-primary-end:     #004aab;
      --color-gold:            #ffb81c;
      --color-gold-end:        #ffa500;
      --color-dark:            #101828;
      --color-body:            #364153;
      --color-muted:           #4a5565;
      --color-muted-light:     #99a1af;
      --color-blue-light:      #dbeafe;
      --color-white:           #ffffff;
      --color-border:          #e5e7eb;
      --color-card-border:     #f3f4f6;
      --color-footer-bg:       #101828;
      --color-footer-divider:  #1e2939;
      --color-hero-bg-start:   #eff6ff;
      --color-hero-bg-mid:     #ffffff;
      --color-hero-bg-end:     #fffbeb;
      --color-steps-bg-start:  #dbeafe;
      --color-steps-bg-end:    #e0e7ff;

      --grad-primary:          linear-gradient(90deg, var(--color-primary) 0%, var(--color-primary-end) 100%);
      --grad-primary-135:      linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-end) 100%);
      --grad-primary-163:      linear-gradient(163deg, var(--color-primary) 0%, var(--color-primary-end) 100%);
      --grad-gold:             linear-gradient(135deg, var(--color-gold) 0%, var(--color-gold-end) 100%);
      --grad-hero:             linear-gradient(119deg, var(--color-hero-bg-start) 0%, var(--color-hero-bg-mid) 50%, var(--color-hero-bg-end) 100%);
      --grad-steps:            linear-gradient(161deg, var(--color-steps-bg-start) 0%, var(--color-steps-bg-end) 100%);

      --shadow-card:           0 10px 15px 0 rgba(0,0,0,.08), 0 4px 6px 0 rgba(0,0,0,.04);
      --shadow-hero-card:      0 25px 50px 0 rgba(0,0,0,.18);
      --shadow-nav:            0 1px 3px 0 rgba(0,0,0,.08), 0 1px 2px 0 rgba(0,0,0,.06);

      --radius-sm:    10px;
      --radius-md:    14px;
      --radius-lg:    16px;
      --radius-xl:    24px;
      --radius-pill:  9999px;

      --font-xs:   12px;
      --font-sm:   14px;
      --font-base: 16px;
      --font-lg:   18px;
      --font-xl:   20px;
      --font-2xl:  24px;
      --font-3xl:  30px;
      --font-4xl:  36px;
      --font-5xl:  48px;
      --font-6xl:  60px;

      --space-1:   4px;
      --space-2:   8px;
      --space-3:   12px;
      --space-4:   16px;
      --space-5:   20px;
      --space-6:   24px;
      --space-8:   32px;
      --space-10:  40px;
      --space-12:  48px;
      --space-14:  56px;
      --space-16:  64px;
      --space-20:  80px;

      --nav-height: 80px;
    }

    /* =============================================
       RESET & BASE
    ============================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Inter', sans-serif;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--grad-hero);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; max-width: 100%; }
    ul { list-style: none; }

    /* =============================================
       LAYOUT HELPERS
    ============================================= */
    .container {
      width: 100%;
      max-width: 1280px;
      margin-inline: auto;
      padding-inline: var(--space-8);
    }

    /* =============================================
       NAVIGATION
    ============================================= */
    .nav {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      z-index: 100;
      background: rgba(255,255,255,.92);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--color-border);
      box-shadow: var(--shadow-nav);
    }
    .nav__inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: var(--nav-height);
      gap: var(--space-6);
    }
    .nav__brand {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      text-decoration: none;
    }
    .nav__logo {
      width: 46px;
      height: 46px;
      border-radius: var(--radius-md);
      background: var(--grad-primary-135);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-white);
      flex-shrink: 0;
      box-shadow: 0 4px 10px rgba(0,48,135,.25);
    }
    .nav__brand-name {
      font-size: var(--font-xl);
      font-weight: 800;
      color: var(--color-dark);
      line-height: 1.2;
    }
    .nav__brand-sub {
      font-size: var(--font-xs);
      font-weight: 500;
      color: var(--color-muted);
      white-space: nowrap;
    }
    .nav__links {
      display: flex;
      align-items: center;
      gap: var(--space-6);
    }
    .nav__link {
      font-size: var(--font-base);
      font-weight: 600;
      color: var(--color-body);
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-sm);
      transition: color .2s, background .2s;
    }
    .nav__link:hover {
      color: var(--color-primary);
      background: rgba(0,48,135,.04);
    }
    .nav__actions {
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }
    .nav__link--login {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-primary);
      padding: var(--space-2) var(--space-5);
      border-radius: var(--radius-md);
      transition: background .2s;
    }
    .nav__link--login:hover { background: rgba(0,48,135,.06); }
    .nav__link--cta {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      padding: var(--space-2) var(--space-6);
      border-radius: var(--radius-md);
      background: var(--grad-primary);
      box-shadow: 0 4px 12px rgba(0,48,135,.25);
      transition: transform .2s, box-shadow .2s;
    }
    .nav__link--cta:hover { 
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(0,48,135,.35); 
    }

    /* Hamburger */
    .nav__hamburger {
      display: none;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      gap: 5px;
      width: 40px;
      height: 40px;
      background: none;
      border: none;
      cursor: pointer;
      padding: 4px;
      border-radius: var(--radius-sm);
      transition: background .2s;
    }
    .nav__hamburger:hover { background: rgba(0,48,135,.07); }
    .nav__hamburger-bar {
      display: block;
      width: 22px;
      height: 2px;
      background: var(--color-dark);
      border-radius: 2px;
      transition: transform .3s, opacity .3s;
    }
    .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(2) { opacity: 0; }
    .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    /* Mobile menu */
    .nav__mobile-menu {
      display: none;
      flex-direction: column;
      gap: var(--space-2);
      padding: var(--space-4) 0 var(--space-4);
      border-top: 1px solid var(--color-border);
    }
    .nav__mobile-menu.is-open { display: flex; }
    .nav__mobile-menu a {
      font-size: var(--font-base);
      font-weight: 600;
      color: var(--color-dark);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-sm);
      transition: background .2s;
    }
    .nav__mobile-menu a:hover { background: rgba(0,48,135,.06); }
    .nav__mobile-menu .nav__mobile-cta {
      background: var(--grad-primary);
      color: var(--color-white);
      text-align: center;
      font-weight: 700;
    }

    /* =============================================
       HERO SECTION
    ============================================= */
    .hero {
      padding-top: calc(var(--nav-height) + var(--space-16));
      padding-bottom: var(--space-20);
      background: var(--grad-hero);
    }
    .hero__inner {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: var(--space-10);
      align-items: center;
    }
    .hero__badge {
      display: inline-flex;
      align-items: center;
      gap: var(--space-3);
      border: 2px solid var(--color-gold);
      background: rgba(255,184,28,.18);
      border-radius: var(--radius-pill);
      padding: var(--space-2) var(--space-4);
      margin-bottom: var(--space-4);
    }
    .hero__badge-icon {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
    }
    .hero__badge-text {
      font-size: var(--font-base);
      font-weight: 900;
      color: var(--color-primary);
      white-space: nowrap;
    }
    .hero__heading {
      font-size: var(--font-6xl);
      font-weight: 800;
      color: var(--color-dark);
      line-height: 1.2;
      margin-bottom: var(--space-4);
      letter-spacing: -0.02em;
    }
    .hero__heading-accent {
      background: var(--grad-primary);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .hero__subheading {
      font-size: var(--font-xl);
      font-weight: 500;
      color: var(--color-body);
      line-height: 1.6;
      margin-bottom: var(--space-4);
    }
    .hero__subheading strong {
      font-weight: 700;
      color: var(--color-primary);
    }
    .hero__desc {
      font-size: var(--font-lg);
      font-weight: 400;
      color: var(--color-muted);
      line-height: 1.6;
      margin-bottom: var(--space-8);
    }
    .hero__actions {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      flex-wrap: wrap;
    }
    .hero__btn--apply {
      display: inline-flex;
      align-items: center;
      gap: var(--space-3);
      background: var(--grad-primary);
      color: var(--color-white);
      font-size: var(--font-lg);
      font-weight: 700;
      padding: 0 var(--space-8);
      height: 60px;
      border-radius: var(--radius-md);
      box-shadow: 0 8px 20px rgba(0,48,135,.28);
      transition: transform .2s, box-shadow .2s;
    }
    .hero__btn--apply:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(0,48,135,.35);
    }
    .hero__btn--login {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: var(--color-white);
      color: var(--color-dark);
      font-size: var(--font-lg);
      font-weight: 700;
      padding: 0 var(--space-8);
      height: 60px;
      border-radius: var(--radius-md);
      border: 2px solid var(--color-border);
      transition: border-color .2s, background .2s;
    }
    .hero__btn--login:hover {
      border-color: var(--color-primary);
      color: var(--color-primary);
      background: rgba(0,48,135,.03);
    }

    /* Hero right card */
    .hero__card {
      background: var(--grad-primary-163);
      border: 4px solid var(--color-gold);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-hero-card);
      padding: var(--space-8);
      position: relative;
      overflow: hidden;
    }
    .hero__card::before {
      content: '';
      position: absolute;
      top: -40px;
      right: -40px;
      width: 140px;
      height: 140px;
      background: radial-gradient(circle, rgba(255,184,28,.25) 0%, rgba(255,184,28,0) 70%);
      border-radius: 50%;
    }
    .hero__card-inner {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .hero__card-title {
      font-size: var(--font-3xl);
      font-weight: 800;
      color: var(--color-white);
      margin-bottom: var(--space-2);
    }
    .hero__card-desc {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-blue-light);
      line-height: 1.5;
      margin-bottom: var(--space-6);
    }
    .hero__card-perks {
      display: flex;
      flex-direction: column;
      gap: var(--space-3);
      width: 100%;
    }
    .hero__card-perk {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      background: rgba(255,255,255,.12);
      border-radius: var(--radius-md);
      padding: var(--space-3) var(--space-4);
      backdrop-filter: blur(8px);
      text-align: left;
    }
    .hero__card-perk-text {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
    }

    /* =============================================
       SECTION HEADERS
    ============================================= */
    .section__header {
      text-align: center;
      margin-bottom: var(--space-12);
    }
    .section__badge {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      border: 1px solid rgba(0,48,135,.2);
      background: rgba(0,48,135,.06);
      color: var(--color-primary);
      font-size: var(--font-xs);
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      border-radius: var(--radius-pill);
      padding: var(--space-1) var(--space-3);
      margin-bottom: var(--space-3);
    }
    .section__title {
      font-size: var(--font-4xl);
      font-weight: 800;
      color: var(--color-dark);
      margin-bottom: var(--space-3);
      letter-spacing: -0.01em;
    }
    .section__subtitle {
      font-size: var(--font-lg);
      font-weight: 400;
      color: var(--color-muted);
      line-height: 1.5;
      max-width: 680px;
      margin-inline: auto;
    }

    /* =============================================
       FEATURES SECTION
    ============================================= */
    .features {
      padding: var(--space-20) 0;
      background: var(--color-white);
      border-top: 1px solid var(--color-border);
      border-bottom: 1px solid var(--color-border);
    }
    .features__grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-6);
    }
    .feature-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      padding: var(--space-6);
      box-shadow: var(--shadow-card);
      transition: transform .2s, box-shadow .2s, border-color .2s;
    }
    .feature-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 16px 24px -4px rgba(0,48,135,.12);
      border-color: rgba(0,48,135,.25);
    }
    .feature-card__icon-wrap {
      width: 56px;
      height: 56px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: var(--space-4);
    }
    .feature-card__icon-wrap--blue { background: var(--grad-primary-135); }
    .feature-card__icon-wrap--gold { background: var(--grad-gold); }
    .feature-card__title {
      font-size: var(--font-xl);
      font-weight: 800;
      color: var(--color-dark);
      margin-bottom: var(--space-2);
    }
    .feature-card__desc {
      font-size: var(--font-base);
      font-weight: 400;
      color: var(--color-muted);
      line-height: 1.5;
    }

    /* =============================================
       HOW IT WORKS SECTION
    ============================================= */
    .steps {
      padding: var(--space-20) 0;
      background: var(--grad-steps);
    }
    .steps__grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-6);
    }
    .step {
      text-align: center;
      position: relative;
    }
    .step__number {
      width: 56px;
      height: 56px;
      border-radius: var(--radius-pill);
      background: var(--grad-primary-135);
      color: var(--color-white);
      font-size: var(--font-2xl);
      font-weight: 900;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-inline: auto;
      margin-bottom: var(--space-4);
      box-shadow: 0 6px 16px rgba(0,48,135,.25);
    }
    .step__title {
      font-size: var(--font-xl);
      font-weight: 800;
      color: var(--color-dark);
      margin-bottom: var(--space-2);
    }
    .step__desc {
      font-size: var(--font-base);
      font-weight: 400;
      color: var(--color-body);
      line-height: 1.5;
    }

    /* =============================================
       ABOUT SECTION
    ============================================= */
    .about {
      padding: var(--space-20) 0;
      background: var(--color-white);
      border-top: 1px solid var(--color-border);
      border-bottom: 1px solid var(--color-border);
    }
    .about__lead-box {
      background: linear-gradient(135deg, #003087 0%, #004aab 100%);
      color: var(--color-white);
      border-radius: var(--radius-xl);
      padding: var(--space-10) var(--space-12);
      margin-bottom: var(--space-12);
      box-shadow: var(--shadow-hero-card);
      border: 3px solid var(--color-gold);
      position: relative;
      overflow: hidden;
    }
    .about__lead-title {
      font-size: var(--font-3xl);
      font-weight: 800;
      color: var(--color-gold);
      margin-bottom: var(--space-3);
    }
    .about__lead-text {
      font-size: var(--font-lg);
      line-height: 1.65;
      color: rgba(255,255,255,.94);
      max-width: 980px;
    }
    .about__grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: var(--space-8);
      margin-bottom: var(--space-12);
    }
    .about-card {
      background: #f8fafc;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      padding: var(--space-8);
      display: flex;
      gap: var(--space-5);
      align-items: flex-start;
      transition: transform .2s, box-shadow .2s;
    }
    .about-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-card);
      border-color: rgba(0,48,135,.2);
    }
    .about-card__icon {
      width: 52px;
      height: 52px;
      border-radius: var(--radius-md);
      background: rgba(0,48,135,.1);
      color: var(--color-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .about-card__title {
      font-size: var(--font-xl);
      font-weight: 800;
      color: var(--color-dark);
      margin-bottom: var(--space-2);
    }
    .about-card__desc {
      font-size: var(--font-base);
      color: var(--color-muted);
      line-height: 1.55;
    }
    .about__stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--space-6);
      background: #f1f5f9;
      border-radius: var(--radius-lg);
      padding: var(--space-8);
      text-align: center;
    }
    .about-stat__value {
      font-size: var(--font-4xl);
      font-weight: 900;
      color: var(--color-primary);
      line-height: 1.1;
      margin-bottom: var(--space-1);
    }
    .about-stat__label {
      font-size: var(--font-sm);
      font-weight: 600;
      color: var(--color-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    /* =============================================
       CONTACT SECTION
    ============================================= */
    .contact {
      padding: var(--space-20) 0;
      background: linear-gradient(180deg, #f8fafc 0%, #eff6ff 100%);
    }
    .contact__layout {
      display: grid;
      grid-template-columns: 1fr 1.1fr;
      gap: var(--space-10);
      align-items: flex-start;
    }
    .contact__info-box {
      display: flex;
      flex-direction: column;
      gap: var(--space-6);
    }
    .contact-item {
      display: flex;
      gap: var(--space-4);
      background: var(--color-white);
      padding: var(--space-5);
      border-radius: var(--radius-md);
      border: 1px solid var(--color-border);
      box-shadow: 0 2px 6px rgba(0,0,0,.03);
    }
    .contact-item__icon {
      width: 44px;
      height: 44px;
      border-radius: var(--radius-md);
      background: rgba(255,184,28,.2);
      color: #9a6500;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .contact-item__title {
      font-size: var(--font-base);
      font-weight: 800;
      color: var(--color-dark);
      margin-bottom: 2px;
    }
    .contact-item__text {
      font-size: var(--font-sm);
      color: var(--color-body);
      line-height: 1.5;
    }
    .contact-item__link {
      color: var(--color-primary);
      font-weight: 700;
    }
    .contact-item__link:hover {
      text-decoration: underline;
    }

    /* FAQs */
    .faq-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      padding: var(--space-4) var(--space-5);
      cursor: pointer;
      transition: border-color .2s;
    }
    .faq-card:hover {
      border-color: var(--color-primary);
    }
    .faq-question {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-dark);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .faq-answer {
      font-size: var(--font-sm);
      color: var(--color-muted);
      line-height: 1.5;
      margin-top: var(--space-2);
      display: none;
      padding-top: var(--space-2);
      border-top: 1px dashed var(--color-border);
    }
    .faq-card.active .faq-answer {
      display: block;
    }
    .faq-card.active .faq-icon {
      transform: rotate(180deg);
    }
    .faq-icon {
      transition: transform .2s ease;
      color: var(--color-primary);
    }

    /* Contact Form */
    .contact__form-card {
      background: var(--color-white);
      border-radius: var(--radius-xl);
      padding: var(--space-8);
      border: 1px solid var(--color-border);
      box-shadow: var(--shadow-hero-card);
    }
    .contact__form-title {
      font-size: var(--font-2xl);
      font-weight: 800;
      color: var(--color-dark);
      margin-bottom: var(--space-2);
    }
    .contact__form-sub {
      font-size: var(--font-sm);
      color: var(--color-muted);
      margin-bottom: var(--space-6);
    }
    .form-group {
      margin-bottom: var(--space-4);
    }
    .form-label {
      display: block;
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-dark);
      margin-bottom: var(--space-1);
    }
    .form-input, .form-select, .form-textarea {
      width: 100%;
      padding: 12px 16px;
      font-size: var(--font-base);
      font-family: inherit;
      border: 1.5px solid var(--color-border);
      border-radius: var(--radius-md);
      background: #ffffff;
      color: var(--color-dark);
      transition: border-color .2s, box-shadow .2s;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
      outline: none;
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(0,48,135,.12);
    }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-4);
    }
    .btn-submit {
      width: 100%;
      height: 52px;
      border: none;
      background: var(--grad-primary);
      color: var(--color-white);
      font-size: var(--font-base);
      font-weight: 800;
      border-radius: var(--radius-md);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-2);
      box-shadow: 0 4px 12px rgba(0,48,135,.25);
      transition: transform .2s, box-shadow .2s;
    }
    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(0,48,135,.35);
    }
    .alert-banner {
      padding: var(--space-4);
      border-radius: var(--radius-md);
      font-size: var(--font-sm);
      font-weight: 600;
      margin-bottom: var(--space-5);
      line-height: 1.4;
    }
    .alert-banner--success {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }
    .alert-banner--error {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    /* =============================================
       CTA SECTION
    ============================================= */
    .cta {
      padding: var(--space-20) 0;
      background: var(--grad-hero);
    }
    .cta__inner {
      background: var(--grad-primary-163);
      border: 4px solid var(--color-gold);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-hero-card);
      padding: var(--space-16) var(--space-16);
      text-align: center;
    }
    .cta__title {
      font-size: var(--font-5xl);
      font-weight: 900;
      color: var(--color-white);
      margin-bottom: var(--space-4);
    }
    .cta__desc {
      font-size: var(--font-xl);
      font-weight: 500;
      color: var(--color-blue-light);
      line-height: 1.5;
      max-width: 560px;
      margin-inline: auto;
      margin-bottom: var(--space-10);
    }
    .cta__btn {
      display: inline-flex;
      align-items: center;
      gap: var(--space-3);
      background: var(--color-gold);
      color: var(--color-primary);
      font-size: var(--font-xl);
      font-weight: 900;
      padding: 0 var(--space-12);
      height: 68px;
      border-radius: var(--radius-lg);
      box-shadow: 0 8px 24px rgba(255,184,28,.4);
      transition: transform .2s, box-shadow .2s;
    }
    .cta__btn:hover { 
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(255,184,28,.5);
    }

    /* =============================================
       FOOTER
    ============================================= */
    .footer {
      background: var(--color-footer-bg);
      padding: var(--space-16) 0 0;
    }
    .footer__grid {
      display: grid;
      grid-template-columns: 1.5fr 1fr 1fr;
      gap: var(--space-8);
      padding-bottom: var(--space-12);
    }
    .footer__brand {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-bottom: var(--space-4);
    }
    .footer__logo {
      width: 48px;
      height: 48px;
      border-radius: var(--radius-md);
      background: var(--color-gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-primary);
      flex-shrink: 0;
    }
    .footer__brand-name {
      font-size: var(--font-lg);
      font-weight: 900;
      color: var(--color-white);
    }
    .footer__brand-sub {
      font-size: var(--font-sm);
      font-weight: 400;
      color: var(--color-muted-light);
    }
    .footer__desc {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
      line-height: 1.5;
      max-width: 380px;
    }
    .footer__col-title {
      font-size: var(--font-lg);
      font-weight: 900;
      color: var(--color-white);
      margin-bottom: var(--space-4);
    }
    .footer__links {
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .footer__links a {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
      line-height: 1.5;
      transition: color .2s;
    }
    .footer__links a:hover { color: var(--color-white); }
    .footer__contact p {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
      line-height: 1.5;
      margin-bottom: var(--space-2);
    }
    .footer__bottom {
      border-top: 1px solid var(--color-footer-divider);
      padding: var(--space-8) 0;
      text-align: center;
    }
    .footer__copy {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
    }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .nav__links { display: none; }
      .nav__actions { display: none; }
      .nav__hamburger { display: flex; }

      .hero__inner {
        grid-template-columns: 1fr;
        gap: var(--space-10);
      }
      .hero__heading { font-size: 44px; }
      .hero__card { max-width: 540px; margin-inline: auto; width: 100%; }

      .features__grid { grid-template-columns: repeat(2, 1fr); }
      .steps__grid    { grid-template-columns: repeat(2, 1fr); }
      .about__grid    { grid-template-columns: 1fr; }
      .contact__layout { grid-template-columns: 1fr; }
      .footer__grid   { grid-template-columns: 1fr 1fr; }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      :root { --nav-height: 68px; }

      .hero { padding-top: calc(var(--nav-height) + var(--space-8)); }
      .hero__heading { font-size: 32px; }
      .hero__subheading { font-size: var(--font-base); }
      .hero__desc { font-size: var(--font-base); }
      .hero__btn--apply,
      .hero__btn--login { font-size: var(--font-base); height: 52px; padding: 0 var(--space-6); width: 100%; justify-content: center; }
      .hero__actions { flex-direction: column; align-items: stretch; width: 100%; }

      .section__title { font-size: 28px; }
      .section__subtitle { font-size: var(--font-base); }

      .features__grid { grid-template-columns: 1fr; }
      .steps__grid    { grid-template-columns: 1fr; }
      .about__stats   { grid-template-columns: 1fr; gap: var(--space-4); }
      .form-row       { grid-template-columns: 1fr; }

      .about__lead-box { padding: var(--space-6); }
      .about__lead-title { font-size: var(--font-2xl); }

      .cta__inner { padding: var(--space-8) var(--space-6); }
      .cta__title { font-size: 28px; }
      .cta__desc  { font-size: var(--font-base); }
      .cta__btn   { font-size: var(--font-base); height: 56px; padding: 0 var(--space-8); width: 100%; justify-content: center; }

      .footer__grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <!-- ======================================================
       NAVIGATION
  ====================================================== -->
  <header class="nav" role="banner">
    <div class="container">
      <nav class="nav__inner" aria-label="Main navigation">
        <!-- Brand -->
        <a class="nav__brand" href="index.php" aria-label="SAMS Home">
          <div class="nav__logo" aria-hidden="true">NU</div>
          <div class="nav__brand-text">
            <div class="nav__brand-name">SAMS</div>
            <div class="nav__brand-sub">Student Assistant Management</div>
          </div>
        </a>

        <!-- Desktop Navigation Links -->
        <div class="nav__links">
          <a href="#features" class="nav__link">Features</a>
          <a href="#how-it-works" class="nav__link">How It Works</a>
          <a href="#about" class="nav__link">About SDAO</a>
          <a href="#contact" class="nav__link">Contact Us</a>
        </div>

        <!-- Desktop actions -->
        <div class="nav__actions">
          <a class="nav__link--login" href="login.php">Login</a>
          <a class="nav__link--cta" href="register.php">Get Started</a>
        </div>

        <!-- Hamburger (tablet / mobile) -->
        <button
          class="nav__hamburger"
          aria-expanded="false"
          aria-controls="mobile-menu"
          aria-label="Toggle navigation menu"
        >
          <span class="nav__hamburger-bar"></span>
          <span class="nav__hamburger-bar"></span>
          <span class="nav__hamburger-bar"></span>
        </button>
      </nav>

      <!-- Mobile menu -->
      <div id="mobile-menu" class="nav__mobile-menu" role="menu">
        <a href="#features" role="menuitem">Features</a>
        <a href="#how-it-works" role="menuitem">How It Works</a>
        <a href="#about" role="menuitem">About SDAO</a>
        <a href="#contact" role="menuitem">Contact Us</a>
        <a href="login.php" role="menuitem">Login</a>
        <a href="register.php" class="nav__mobile-cta" role="menuitem">Get Started</a>
      </div>
    </div>
  </header>

  <main>

    <!-- ====================================================
         HERO SECTION
    ==================================================== -->
    <section class="hero" aria-labelledby="hero-heading">
      <div class="container">
        <div class="hero__inner">

          <!-- Left: text -->
          <div class="hero__content">
            <!-- Badge -->
            <div class="hero__badge">
              <svg class="hero__badge-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 18px; height: 18px; color: var(--color-primary);">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
              </svg>
              <span class="hero__badge-text">National University – Lipa Campus</span>
            </div>

            <!-- Heading -->
            <h1 class="hero__heading" id="hero-heading">
              Welcome to <span class="hero__heading-accent">SAMS</span>
            </h1>

            <!-- Subheading -->
            <p class="hero__subheading">
              The official <strong>Student Assistant Management System</strong> for NU Lipa's Student Development and Activities Office (SDAO).
            </p>

            <!-- Description -->
            <p class="hero__desc">
              An integrated mobile and web-based platform that automates application processing, scheduling with algorithm-guided assignment, and secure attendance monitoring by tapping your existing school ID at the SDAO kiosk - all managed through a real-time administrative dashboard.
            </p>

            <!-- CTAs -->
            <div class="hero__actions">
              <a class="hero__btn--apply" href="register.php">
                Apply as Student Assistant
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 20px; height: 20px;">
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                  <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
              </a>
              <a class="hero__btn--login" href="login.php">Login</a>
            </div>
          </div>

          <!-- Right: card -->
          <div class="hero__card" aria-label="Join SDAO Today">
            <div class="hero__card-inner">
              <svg class="hero__card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 56px; height: 56px; color: var(--color-gold); margin-bottom: var(--space-5);">
                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"></path>
              </svg>
              <h2 class="hero__card-title">Join SDAO Today!</h2>
              <p class="hero__card-desc">Be part of the team that shapes student life at NU Lipa</p>
              <ul class="hero__card-perks">
                <li class="hero__card-perk">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 20px; height: 20px; color: var(--color-gold); flex-shrink: 0;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                  </svg>
                  <span class="hero__card-perk-text">Flexible Schedule Around Classes</span>
                </li>
                <li class="hero__card-perk">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 20px; height: 20px; color: var(--color-gold); flex-shrink: 0;">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                  </svg>
                  <span class="hero__card-perk-text">Gain Leadership &amp; Office Experience</span>
                </li>
                <li class="hero__card-perk">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 20px; height: 20px; color: var(--color-gold); flex-shrink: 0;">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                  </svg>
                  <span class="hero__card-perk-text">Scholarship &amp; Financial Support</span>
                </li>
              </ul>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- ====================================================
         FEATURES SECTION
    ==================================================== -->
    <section class="features" id="features" aria-labelledby="features-heading">
      <div class="container">
        <header class="section__header">
          <div class="section__badge">Features</div>
          <h2 class="section__title" id="features-heading">Powerful Features for Students &amp; Admin</h2>
          <p class="section__subtitle">Everything you need to manage student assistant duties efficiently</p>
        </header>

        <div class="features__grid">
          <!-- Card 1 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 30px; height: 30px; color: white;">
                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="8.5" cy="7" r="4"></circle>
                <line x1="20" y1="8" x2="20" y2="14"></line>
                <line x1="23" y1="11" x2="17" y2="11"></line>
              </svg>
            </div>
            <h3 class="feature-card__title">Easy Registration</h3>
            <p class="feature-card__desc">4-step application process with auto-filtering questions and instant submission</p>
          </article>

          <!-- Card 2 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 30px; height: 30px; color: white;">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
              </svg>
            </div>
            <h3 class="feature-card__title">Smart Scheduling</h3>
            <p class="feature-card__desc">View your duty schedule in calendar or list format with real-time updates</p>
          </article>

          <!-- Card 3 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 30px; height: 30px; color: white;">
                <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                <line x1="3" y1="10" x2="21" y2="10"></line>
                <path d="M7 15h.01"></path>
                <path d="M11 15h2"></path>
                <path d="M17 14a3 3 0 0 1 0 2"></path>
              </svg>
            </div>
            <h3 class="feature-card__title">NFC Attendance</h3>
            <p class="feature-card__desc">Tap your school ID at the SDAO kiosk for secure time-in and time-out.</p>
          </article>

          <!-- Card 4 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--gold">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 30px; height: 30px; color: white;">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
              </svg>
            </div>
            <h3 class="feature-card__title">Real-time Updates</h3>
            <p class="feature-card__desc">Get instant notifications about schedule changes and announcements</p>
          </article>
        </div>
      </div>
    </section>

    <!-- ====================================================
         HOW IT WORKS SECTION
    ==================================================== -->
    <section class="steps" id="how-it-works" aria-labelledby="steps-heading">
      <div class="container">
        <header class="section__header">
          <div class="section__badge">Workflow</div>
          <h2 class="section__title" id="steps-heading">How SAMS Works</h2>
          <p class="section__subtitle">Get started in 4 simple steps</p>
        </header>

        <div class="steps__grid">
          <div class="step">
            <div class="step__number" aria-hidden="true">1</div>
            <h3 class="step__title">Register</h3>
            <p class="step__desc">Complete the 4-step registration process with your information and documents</p>
          </div>
          <div class="step">
            <div class="step__number" aria-hidden="true">2</div>
            <h3 class="step__title">Wait for Approval</h3>
            <p class="step__desc">SDAO heads review your application and approve qualified students</p>
          </div>
          <div class="step">
            <div class="step__number" aria-hidden="true">3</div>
            <h3 class="step__title">Get Scheduled</h3>
            <p class="step__desc">Receive your duty schedule and view it in your dashboard</p>
          </div>
          <div class="step">
            <div class="step__number" aria-hidden="true">4</div>
            <h3 class="step__title">Start Working</h3>
            <p class="step__desc">Tap your school ID at the SDAO kiosk and complete your assigned duties</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ====================================================
         ABOUT SECTION (ABOUT SDAO & SAMS)
    ==================================================== -->
    <section class="about" id="about" aria-labelledby="about-heading">
      <div class="container">
        <header class="section__header">
          <div class="section__badge">About SDAO &amp; SAMS</div>
          <h2 class="section__title" id="about-heading">Empowering Nationalians Through Service</h2>
          <p class="section__subtitle">
            The Student Development and Activities Office (SDAO) bridges academic learning with real-world campus leadership and professional training.
          </p>
        </header>

        <!-- Lead Mission Box -->
        <div class="about__lead-box">
          <h3 class="about__lead-title">The Student Assistantship Program</h3>
          <p class="about__lead-text">
            SAMS is the dedicated platform developed for National University – Lipa to modernize, streamline, and humanize the Student Assistantship experience. By combining automated availability-based scheduling, instant NFC ID attendance logging, duty excuse transparency, and cross-office reshuffling, we cultivate accountability, career-ready skills, and student success.
          </p>
        </div>

        <!-- 4 Core Pillars Grid -->
        <div class="about__grid">
          <!-- Pillar 1 -->
          <div class="about-card">
            <div class="about-card__icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 28px; height: 28px;">
                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"></path>
              </svg>
            </div>
            <div>
              <h3 class="about-card__title">Academic-First Duty Scheduling</h3>
              <p class="about-card__desc">
                Our algorithm matches student assistants with available office shifts based entirely on their Certificate of Registration (COR) free time, ensuring your studies remain top priority.
              </p>
            </div>
          </div>

          <!-- Pillar 2 -->
          <div class="about-card">
            <div class="about-card__icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 28px; height: 28px;">
                <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                <line x1="2" y1="10" x2="22" y2="10"></line>
                <path d="M6 15h2"></path>
                <path d="M16 14a2 2 0 0 1 0 3"></path>
              </svg>
            </div>
            <div>
              <h3 class="about-card__title">NFC Contactless Kiosk</h3>
              <p class="about-card__desc">
                No messy paper logbooks. Simply tap your registered NU Lipa student ID at the SDAO kiosk for lightning-fast, verified time-in and time-out recordings.
              </p>
            </div>
          </div>

          <!-- Pillar 3 -->
          <div class="about-card">
            <div class="about-card__icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 28px; height: 28px;">
                <polyline points="16 3 21 3 21 8"></polyline>
                <line x1="4" y1="20" x2="21" y2="3"></line>
                <polyline points="21 16 21 21 16 21"></polyline>
                <line x1="15" y1="15" x2="21" y2="21"></line>
                <line x1="4" y1="4" x2="9" y2="9"></line>
              </svg>
            </div>
            <div>
              <h3 class="about-card__title">Cross-Training &amp; Office Reshuffle</h3>
              <p class="about-card__desc">
                After term evaluations, supervisors can retain or request reshuffling (up to 3 times) to broaden each student's exposure across various campus departments like Registrar, Library, Admissions, and SDAO.
              </p>
            </div>
          </div>

          <!-- Pillar 4 -->
          <div class="about-card">
            <div class="about-card__icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 28px; height: 28px;">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
              </svg>
            </div>
            <div>
              <h3 class="about-card__title">Duty Excuse &amp; Transparent Records</h3>
              <p class="about-card__desc">
                Students can formally submit medical or academic excuse letters with file proofs directly in their portal, notifying admins and supervisors immediately.
              </p>
            </div>
          </div>
        </div>

        <!-- Highlights Bar -->
        <div class="about__stats">
          <div>
            <div class="about-stat__value">100%</div>
            <div class="about-stat__label">Digital Attendance &amp; Scheduling</div>
          </div>
          <div>
            <div class="about-stat__value">Multi-Office</div>
            <div class="about-stat__label">Campus Department Placements</div>
          </div>
          <div>
            <div class="about-stat__value">Leadership</div>
            <div class="about-stat__label">Career-Ready Experience</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====================================================
         CONTACT SECTION (CONTACT US & DIRECT INQUIRY)
    ==================================================== -->
    <section class="contact" id="contact" aria-labelledby="contact-heading">
      <div class="container">
        <header class="section__header">
          <div class="section__badge">Get in Touch</div>
          <h2 class="section__title" id="contact-heading">Contact the SDAO Office</h2>
          <p class="section__subtitle">
            Have questions regarding the Student Assistantship application, kiosk NFC attendance, or duty schedules? Send us a message or visit our campus office.
          </p>
        </header>

        <div class="contact__layout">
          <!-- Left Column: Details + FAQs -->
          <div class="contact__info-box">
            
            <!-- Item 1: Location -->
            <div class="contact-item">
              <div class="contact-item__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 22px; height: 22px;">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                  <circle cx="12" cy="10" r="3"></circle>
                </svg>
              </div>
              <div>
                <h3 class="contact-item__title">Office Location</h3>
                <p class="contact-item__text">
                  Student Development &amp; Activities Office (SDAO)<br>
                  Ground Floor, National University – Lipa Campus<br>
                  Tambo, Lipa City, Batangas 4217
                </p>
              </div>
            </div>

            <!-- Item 2: Email -->
            <div class="contact-item">
              <div class="contact-item__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 22px; height: 22px;">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                  <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
              </div>
              <div>
                <h3 class="contact-item__title">Official Email</h3>
                <p class="contact-item__text">
                  <a href="mailto:sdao@nu-lipa.edu.ph" class="contact-item__link">sdao@nu-lipa.edu.ph</a><br>
                  <span style="font-size:12px;color:#64748b;">Inquiries are answered within 24–48 hours</span>
                </p>
              </div>
            </div>

            <!-- Item 3: Telephone & Hours -->
            <div class="contact-item">
              <div class="contact-item__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 22px; height: 22px;">
                  <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                </svg>
              </div>
              <div>
                <h3 class="contact-item__title">Phone &amp; Office Hours</h3>
                <p class="contact-item__text">
                  <a href="tel:0437230706" class="contact-item__link">(043) 723-0706</a> (Local SDAO)<br>
                  <strong>Mon – Fri:</strong> 8:00 AM – 5:00 PM | <strong>Sat:</strong> 8:00 AM – 12:00 PM
                </p>
              </div>
            </div>

            <!-- Frequently Asked Questions -->
            <div style="margin-top: 8px;">
              <h4 style="font-size:16px;font-weight:800;color:var(--color-dark);margin-bottom:12px;">Frequently Asked Questions</h4>
              <div style="display:flex;flex-direction:column;gap:10px;">
                <div class="faq-card" onclick="toggleFaq(this)">
                  <div class="faq-question">
                    <span>Who can apply as a Student Assistant?</span>
                    <svg class="faq-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                  </div>
                  <div class="faq-answer">
                    Enrolled NU Lipa undergraduate students in good academic standing who meet the GPA threshold and have available hours between their class schedules.
                  </div>
                </div>

                <div class="faq-card" onclick="toggleFaq(this)">
                  <div class="faq-question">
                    <span>How does NFC attendance work at the kiosk?</span>
                    <svg class="faq-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                  </div>
                  <div class="faq-answer">
                    Once approved, your student ID NFC chip is linked. Tap your physical ID card onto the kiosk reader at the start and end of your scheduled shift for instant verification.
                  </div>
                </div>

                <div class="faq-card" onclick="toggleFaq(this)">
                  <div class="faq-question">
                    <span>What should I do if I cannot attend my duty?</span>
                    <svg class="faq-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                  </div>
                  <div class="faq-answer">
                    Log in to your Student Portal and navigate to "Duty Excuse". Fill out the reason and upload supporting documents (medical certificate or academic excuse) to notify your supervisor.
                  </div>
                </div>
              </div>
            </div>

          </div>

          <!-- Right Column: Direct Message Form -->
          <div class="contact__form-card">
            <h3 class="contact__form-title">Send a Message</h3>
            <p class="contact__form-sub">Fill in your inquiry below and our SDAO administrative officers will respond to your email.</p>

            <?php if ($contactSuccess !== ''): ?>
              <div class="alert-banner alert-banner--success">
                ✅ <?php echo htmlspecialchars($contactSuccess, ENT_QUOTES, 'UTF-8'); ?>
              </div>
            <?php endif; ?>

            <?php if ($contactError !== ''): ?>
              <div class="alert-banner alert-banner--error">
                ⚠️ <?php echo htmlspecialchars($contactError, ENT_QUOTES, 'UTF-8'); ?>
              </div>
            <?php endif; ?>

            <form action="index.php#contact" method="POST" autocomplete="off">
              <input type="hidden" name="action" value="contact_submit" />
              <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(sams_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="contact_name">Full Name <span style="color:#ef4444;">*</span></label>
                  <input type="text" id="contact_name" name="name" class="form-input" placeholder="e.g., Juan Dela Cruz" required value="<?php echo htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                </div>
                <div class="form-group">
                  <label class="form-label" for="contact_email">Email Address <span style="color:#ef4444;">*</span></label>
                  <input type="email" id="contact_email" name="email" class="form-input" placeholder="e.g., student@students.nu-lipa.edu.ph" required value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="contact_sid">Student ID Number <span style="font-weight:400;color:#64748b;">(Optional)</span></label>
                  <input type="text" id="contact_sid" name="student_id" class="form-input" placeholder="e.g., 2024-123456" value="<?php echo htmlspecialchars($_POST['student_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                </div>
                <div class="form-group">
                  <label class="form-label" for="contact_subj">Category / Subject</label>
                  <select id="contact_subj" name="subject" class="form-select">
                    <option value="Application Inquiry" <?php echo (($_POST['subject'] ?? '') === 'Application Inquiry') ? 'selected' : ''; ?>>Application Inquiry</option>
                    <option value="Duty Schedule &amp; Availability" <?php echo (($_POST['subject'] ?? '') === 'Duty Schedule & Availability') ? 'selected' : ''; ?>>Duty Schedule &amp; Availability</option>
                    <option value="NFC Attendance Kiosk Issue" <?php echo (($_POST['subject'] ?? '') === 'NFC Attendance Kiosk Issue') ? 'selected' : ''; ?>>NFC Attendance Kiosk Issue</option>
                    <option value="Duty Excuse Follow-up" <?php echo (($_POST['subject'] ?? '') === 'Duty Excuse Follow-up') ? 'selected' : ''; ?>>Duty Excuse Follow-up</option>
                    <option value="General Inquiry" <?php echo (($_POST['subject'] ?? '') === 'General Inquiry' || empty($_POST['subject'])) ? 'selected' : ''; ?>>General Inquiry</option>
                  </select>
                </div>
              </div>

              <div class="form-group">
                <label class="form-label" for="contact_msg">Message / Concern <span style="color:#ef4444;">*</span></label>
                <textarea id="contact_msg" name="message" class="form-textarea" rows="4" placeholder="How can the SDAO team assist you today?" required><?php echo htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>

              <button type="submit" class="btn-submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;">
                  <line x1="22" y1="2" x2="11" y2="13"></line>
                  <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
                Send Message to SDAO
              </button>
            </form>
          </div>
        </div>
      </div>
    </section>

    <!-- ====================================================
         CTA SECTION
    ==================================================== -->
    <section class="cta" aria-labelledby="cta-heading">
      <div class="container">
        <div class="cta__inner">
          <h2 class="cta__title" id="cta-heading">Ready to Join SDAO?</h2>
          <p class="cta__desc">Apply now and become part of the team that creates amazing experiences for NU Lipa students!</p>
          <a class="cta__btn" href="register.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 24px; height: 24px; color: var(--color-primary); flex-shrink: 0;">
              <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
              <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"></path>
            </svg>
            Apply Now
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" style="width: 24px; height: 24px; color: var(--color-primary); flex-shrink: 0;">
              <line x1="5" y1="12" x2="19" y2="12"></line>
              <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
          </a>
        </div>
      </div>
    </section>

  </main>

  <!-- ======================================================
       FOOTER
  ====================================================== -->
  <footer class="footer" role="contentinfo">
    <div class="container">
      <div class="footer__grid">

        <!-- Brand column -->
        <div>
          <div class="footer__brand">
            <div class="footer__logo" aria-hidden="true">NU</div>
            <div>
              <div class="footer__brand-name">SAMS</div>
              <div class="footer__brand-sub">Student Assistant Management</div>
            </div>
          </div>
          <p class="footer__desc">The official Student Assistant Management System of the Student Development and Activities Office at National University – Lipa.</p>
        </div>

        <!-- Quick links column -->
        <div>
          <h3 class="footer__col-title">Quick Links</h3>
          <nav class="footer__links" aria-label="Footer navigation">
            <a href="#features">Features</a>
            <a href="#how-it-works">How It Works</a>
            <a href="#about">About SDAO</a>
            <a href="#contact">Contact Us</a>
            <a href="login.php">Login</a>
            <a href="register.php">Apply as Student Assistant</a>
          </nav>
        </div>

        <!-- Contact column -->
        <div>
          <h3 class="footer__col-title">Contact SDAO</h3>
          <div class="footer__contact">
            <p><strong>Student Development &amp; Activities Office</strong></p>
            <p>National University – Lipa Campus</p>
            <p>Tambo, Lipa City, Batangas 4217</p>
            <p style="margin-top:8px;"><a href="mailto:sdao@nu-lipa.edu.ph" style="color:var(--color-gold);font-weight:600;">sdao@nu-lipa.edu.ph</a></p>
          </div>
        </div>

      </div>

      <!-- Bottom bar -->
      <div class="footer__bottom">
        <p class="footer__copy">
          &copy; <?php echo date('Y'); ?> National University – Lipa Campus. All rights reserved.
        </p>
      </div>
    </div>
  </footer>

  <script>
    (function () {
      'use strict';

      /* ---- Hamburger toggle ---- */
      var btn  = document.querySelector('.nav__hamburger');
      var menu = document.getElementById('mobile-menu');

      if (btn && menu) {
        btn.addEventListener('click', function () {
          var isOpen = menu.classList.toggle('is-open');
          btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        /* Close menu on link click */
        menu.querySelectorAll('a').forEach(function (link) {
          link.addEventListener('click', function () {
            menu.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
          });
        });

        /* Close menu on outside click */
        document.addEventListener('click', function (e) {
          if (!btn.contains(e.target) && !menu.contains(e.target)) {
            menu.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
          }
        });
      }
    })();

    /* ---- FAQ Accordion Toggle ---- */
    function toggleFaq(card) {
      if (!card) return;
      card.classList.toggle('active');
    }
  </script>

</body>
</html>