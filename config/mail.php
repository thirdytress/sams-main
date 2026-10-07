<?php
declare(strict_types=1);

function sams_send_schedule_email(string $toEmail, string $toName, string $action, array $details = []): void
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $config = sams_mail_config();
    $subject = 'SAMS Schedule Notification';
    $eyebrow = 'SAMS Schedule Update';
    $heading = 'Schedule Update';
    $message = 'Hello ' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ',<br><br>Your schedule has been updated.';
    $accent = '#155dfc';
    $ctaLabel = 'View Schedule';
    $ctaUrl = 'http://localhost/samss-main/students/schedule.php';

    if ($action === 'edit') {
        $subject = 'Your schedule was edited';
        $heading = 'Schedule Edited';
        $message = 'Your schedule has been updated by the admin.';
        $accent = '#f59e42';
    } elseif ($action === 'accept') {
        $subject = 'Your schedule was accepted';
        $heading = 'Schedule Accepted';
        $message = 'Your schedule has been accepted. Please check your account for details.';
        $accent = '#008236';
    } elseif ($action === 'approve') {
        $subject = 'Your schedule was approved';
        $heading = 'Schedule Approved';
        $message = 'Your schedule has been approved. Please check your account for details.';
        $accent = '#155dfc';
    } elseif ($action === 'deploy') {
        $subject = 'You have been deployed!';
        $heading = 'Deployment Notice';
        $message = 'Congratulations! You have been deployed. Your schedule is now final and cannot be edited.';
        $accent = '#00a63e';
    }

    // Optionally add schedule details
    if (!empty($details)) {
        $message .= '<br><br><strong>Schedule Details:</strong><br>';
        foreach ($details as $k => $v) {
            $label = (string) $k;
            if ($label === 'Admin Explanation') {
                $message .= '<br><strong>Why a Personal Reason was overridden:</strong><br>';
                $message .= htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '<br>';
                continue;
            }
            $message .= ucfirst($label) . ': ' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '<br>';
        }
    }

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    $mailer->addAddress($toEmail, $toName);
    sams_configure_mailer($mailer, $config);
    $mailer->isHTML(true);
    $mailer->Subject = $subject;
    $mailer->Body = sams_build_branded_email(
        $eyebrow,
        $heading,
        $message,
        $accent,
        $ctaLabel,
        $ctaUrl,
        true
    );
    $mailer->AltBody = strip_tags(str_replace('<br>', "\n", $message));
    try {
        $mailer->send();
    } catch (Throwable $e) {
        error_log('[sams] Schedule mailer failed: ' . $e->getMessage());
        throw $e;
    }
}

function sams_send_supervisor_account_email(
    string $toEmail,
    string $toName,
    string $temporaryPassword,
    string $officeName,
    int $maxStudents
): void {
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();
    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($temporaryPassword, ENT_QUOTES, 'UTF-8');
    $safeOffice = htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8');
    $loginUrl = 'http://localhost/samss-main/login.php';
    $message = 'Hello ' . $safeName . ',<br><br>'
        . 'An administrator created your SAMS Supervisor account.<br><br>'
        . '<strong>Email:</strong> ' . $safeEmail . '<br>'
        . '<strong>Temporary password:</strong> ' . $safePassword . '<br>'
        . '<strong>Assigned office:</strong> ' . $safeOffice . '<br>'
        . '<strong>Student capacity:</strong> ' . $maxStudents . '<br><br>'
        . 'For security, you must change this temporary password after your first login.';

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    $mailer->addAddress($toEmail, $toName);
    sams_configure_mailer($mailer, $config);
    $mailer->isHTML(true);
    $mailer->Subject = 'Your SAMS Supervisor account';
    $mailer->Body = sams_build_branded_email(
        'SAMS Supervisor Access',
        'Your account is ready',
        $message,
        '#003087',
        'Open SAMS Login',
        $loginUrl,
        true
    );
    $mailer->AltBody = "Hello {$toName},\n\nYour SAMS Supervisor account is ready.\nEmail: {$toEmail}\nTemporary password: {$temporaryPassword}\nAssigned office: {$officeName}\nStudent capacity: {$maxStudents}\n\nChange the temporary password after your first login: {$loginUrl}";

    try {
        $mailer->send();
    } catch (Throwable $exception) {
        error_log('[sams] Supervisor account mailer failed: ' . $exception->getMessage());
        throw $exception;
    }
}

function sams_mail_config(): array
{
    $username = trim((string) ($_ENV['SAMS_MAIL_USERNAME'] ?? getenv('SAMS_MAIL_USERNAME') ?: ''));
    $fromEmail = trim((string) ($_ENV['SAMS_MAIL_FROM_EMAIL'] ?? getenv('SAMS_MAIL_FROM_EMAIL') ?: ''));
    if ($fromEmail === '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = $username;
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Set SAMS_MAIL_FROM_EMAIL to a valid email address in your .env file.');
    }

    return [
        'mode' => $_ENV['SAMS_MAIL_MODE'] ?? getenv('SAMS_MAIL_MODE') ?: 'smtp',
        'host' => $_ENV['SAMS_MAIL_HOST'] ?? getenv('SAMS_MAIL_HOST') ?: 'smtp.gmail.com',
        'port' => (int) ($_ENV['SAMS_MAIL_PORT'] ?? getenv('SAMS_MAIL_PORT') ?: 587),
        'username' => $username,
        'password' => $_ENV['SAMS_MAIL_PASSWORD'] ?? getenv('SAMS_MAIL_PASSWORD') ?: '',
        'encryption' => $_ENV['SAMS_MAIL_ENCRYPTION'] ?? getenv('SAMS_MAIL_ENCRYPTION') ?: 'tls',
        'from_email' => $fromEmail,
        'from_name' => $_ENV['SAMS_MAIL_FROM_NAME'] ?? getenv('SAMS_MAIL_FROM_NAME') ?: 'SAMS Notifications',
        'test_to_email' => $_ENV['SAMS_MAIL_TEST_TO_EMAIL'] ?? getenv('SAMS_MAIL_TEST_TO_EMAIL') ?: '',
    ];
}

function sams_configure_mailer(PHPMailer\PHPMailer\PHPMailer $mailer, array $config): void
{
    // If explicitly configured for SMTP, use it — but fall back to PHP mail
    // when credentials are clearly not configured to avoid hard failures on
    // local/dev environments. This preserves correct behavior in production
    // while keeping local setups working without env changes.
    if ($config['mode'] === 'smtp') {
        $username = trim((string)($config['username'] ?? ''));
        if ($username === '') {
            error_log('[sams] SMTP username missing or default — falling back to PHP mail()');
            $mailer->isMail();
            return;
        }

        $mailer->isSMTP();
        $mailer->Host = $config['host'];
        $mailer->Port = $config['port'];
        $mailer->SMTPAuth = $username !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $username;
            $mailer->Password = (string)($config['password'] ?? '');
        }
        if (!empty($config['encryption'])) {
            $mailer->SMTPSecure = $config['encryption'];
        }
        return;
    }

    $mailer->isMail();
}

function sams_build_branded_email(
        string $eyebrow,
        string $heading,
        string $message,
        string $accent = '#003087',
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
        bool $isHtmlMessage = false
): string {
        $brandPrimary = '#003087';
        $brandAction = '#155dfc';
        $safeEyebrow = htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8');
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeMessage = $isHtmlMessage ? $message : nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $safeCtaLabel = $ctaLabel !== null ? htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8') : '';
        $safeCtaUrl = $ctaUrl !== null ? htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8') : '';

        $ctaHtml = '';
        if ($ctaLabel !== null && $ctaUrl !== null) {
                $ctaHtml = '
                    <tr>
                        <td style="padding: 8px 0 0;">
                            <a href="' . $safeCtaUrl . '" style="display:inline-block;background:' . $brandAction . ';color:#fff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:10px;">' . $safeCtaLabel . '</a>
                        </td>
                    </tr>';
        }

        return '
            <div style="margin:0;padding:0;background:#eff6ff;font-family:Arial,Helvetica,sans-serif;color:#101828;">
                <div style="max-width:640px;margin:0 auto;padding:32px 16px;">
                    <div style="background:#ffffff;border:1px solid #dbeafe;border-radius:18px;overflow:hidden;box-shadow:0 16px 40px rgba(0,48,135,.12);">
                        <div style="background:' . $brandPrimary . ';padding:24px 28px;color:#ffffff;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;opacity:.85;">' . $safeEyebrow . '</div>
                            <div style="font-size:28px;line-height:1.15;font-weight:900;margin-top:8px;">' . $safeHeading . '</div>
                        </div>
                        <div style="padding:28px;">
                            <div style="font-size:16px;line-height:1.7;color:#364153;">' . $safeMessage . '</div>
                            <table role="presentation" style="width:100%;border-collapse:collapse;margin-top:24px;">
                                <tr>
                                    <td style="border-top:1px solid #e5e7eb;padding-top:20px;font-size:13px;color:#6b7280;line-height:1.6;">
                                        If you did not expect this email, you can safely ignore it.
                                    </td>
                                </tr>' . $ctaHtml . '
                                <tr>
                                    <td style="padding-top:24px;font-size:12px;color:#6a7282;line-height:1.6;">
                                        SAMS &middot; Student Assistant Management System &middot; NU Lipa
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>';
}

function sams_send_otp_email(string $toEmail, string $toName, string $otpCode): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    // In normal use send to the actual recipient. Only override when running in
    // explicit "test" mode and a test address is configured. This prevents OTPs
    // being sent to a developer/test address during production or SMTP mode.
    if ($config['mode'] === 'test' && $config['test_to_email'] !== '') {
        $recipientEmail = $config['test_to_email'];
    } else {
        $recipientEmail = $toEmail;
    }
    $recipientName = $toName;
    $mailer->addAddress($recipientEmail, $recipientName);

        sams_configure_mailer($mailer, $config);

    $mailer->isHTML(true);
    $mailer->Subject = 'Your SAMS login OTP';
        $mailer->Body = sams_build_branded_email(
                'SAMS Login OTP',
                'Verify your login',
                'Hello ' . $toName . ",\n\nYour SAMS login OTP is " . $otpCode . ".\n\nThis code expires in 10 minutes.",
                '#003087'
        );
        $mailer->AltBody = 'Hello ' . $toName . ",\n\nYour SAMS login OTP is " . $otpCode . ".\n\nThis code expires in 10 minutes.";

    $mailer->send();
}

function sams_send_application_review_email(string $toEmail, string $toName, string $status): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();
    $isApproved = $status === 'approved';
    $subject = $isApproved
        ? 'Your SAMS application has been approved'
        : 'Your SAMS application has been declined';
    $message = $isApproved
        ? 'Congratulations! Your application has been approved. Please log in to SAMS to continue your student assistant onboarding.'
        : 'Your application has been declined. You may contact the admin office if you need more information.';

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    $mailer->addAddress($toEmail, $toName);

    sams_configure_mailer($mailer, $config);

    $mailer->isHTML(true);
    $mailer->Subject = $subject;
    $mailer->Body = sams_build_branded_email(
        'SAMS Application Update',
        $isApproved ? 'Application Approved' : 'Application Declined',
        'Hello ' . $toName . ",\n\n" . $message,
        $isApproved ? '#008236' : '#b91c1c',
        $isApproved ? 'Log in to SAMS' : null,
        $isApproved ? 'http://localhost/samss-main/login.php' : null
    );
    $mailer->AltBody = 'Hello ' . $toName . ",\n\n" . $message;

    try {
        $mailer->send();
    } catch (Throwable $e) {
        // Attempt a safe fallback with PHP mail() in case SMTP fails. Log both
        // failures and rethrow so callers can surface an admin-visible error.
        error_log('[sams] Primary mailer failed: ' . $e->getMessage());
        try {
            $mailer->clearAllRecipients();
            $mailer->isMail();
            $mailer->addAddress($toEmail, $toName);
            $mailer->send();
            error_log('[sams] Fallback PHP mail() succeeded for ' . $toEmail);
            return;
        } catch (Throwable $e2) {
            error_log('[sams] Fallback mail failed: ' . $e2->getMessage());
            throw $e2;
        }

    }
}

function sams_send_application_submitted_email(
    string $toEmail,
    string $toName,
    string $applicationReference,
    string $termLabel
): void {
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();
    $message = 'Hello ' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ',<br><br>'
        . 'Your Student Assistant application has been submitted successfully and is now pending review.<br><br>'
        . '<strong>Application reference:</strong> ' . htmlspecialchars($applicationReference, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Term:</strong> ' . htmlspecialchars($termLabel, ENT_QUOTES, 'UTF-8') . '<br><br>'
        . 'You may log in to SAMS to view your application status.';

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    $mailer->addAddress($toEmail, $toName);
    sams_configure_mailer($mailer, $config);
    $mailer->isHTML(true);
    $mailer->Subject = 'Your SAMS application was submitted';
    $mailer->Body = sams_build_branded_email(
        'SAMS Application Received',
        'Application Submitted',
        $message,
        '#003087',
        'Log in to SAMS',
        'http://localhost/samss-main/login.php',
        true
    );
    $mailer->AltBody = "Hello {$toName},\n\nYour Student Assistant application was submitted successfully.\nApplication reference: {$applicationReference}\nTerm: {$termLabel}\n\nLog in to SAMS to view your application status.";
    $mailer->send();
}

function sams_send_password_reset_email(string $toEmail, string $toName, string $resetLink): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    $mailer->addAddress($toEmail, $toName);

    sams_configure_mailer($mailer, $config);

    $mailer->isHTML(true);
    $mailer->Subject = 'Reset your SAMS password';
    $mailer->Body = sams_build_branded_email(
        'SAMS Password Reset',
        'Reset your password',
        'Hello ' . $toName . ",\n\nWe received a request to reset your SAMS password. Click the button below to create a new password. This link will expire in 1 hour.",
        '#003087',
        'Reset Password',
        $resetLink
    );
    $mailer->AltBody = 'Hello ' . $toName . ",\n\nWe received a request to reset your SAMS password. Open this link to create a new password: " . $resetLink . "\n\nThis link will expire in 1 hour.";

    $mailer->send();
}
