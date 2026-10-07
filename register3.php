<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mail.php';

if (empty($_SESSION['sams_registration']['step1'])) {
    header('Location: register.php');
    exit;
}
if (empty($_SESSION['sams_registration']['step2'])) {
    header('Location: register1.php');
    exit;
}

function sams_split_full_name(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];

    if (count($parts) === 0) {
        return ['', ''];
    }

    if (count($parts) === 1) {
        return [$parts[0], ''];
    }

    $firstName = array_shift($parts);
    $lastName = implode(' ', $parts);

    return [$firstName, $lastName];
}

function sams_map_year_level(string $value): string
{
    return match ($value) {
        '1' => '1st',
        '2' => '2nd',
        '3' => '3rd',
        '4' => '4th',
        '5' => '4th',
        default => '1st',
    };
}

function sams_registration_data(): array
{
    return $_SESSION['sams_registration'] ?? [];
}



$errors  = [];
$success = false;

$registration = sams_registration_data();
$step1 = $registration['step1'] ?? [];
$step2 = $registration['step2'] ?? [];
$step3 = $registration['step3'] ?? [];
$step4 = $registration['step4'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['finalize'])) {
    $work_location = trim($_POST['work_location'] ?? '');
    $skills        = trim($_POST['skills'] ?? '');
    $availabilityForm = $_POST['availability'] ?? [];
    $availabilityEntries = [];
    $totalAvailabilityHours = 0.0;
    $dailyAvailabilityHours = [];
    $availabilitySlotErrors = [];
    $availabilityDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $availabilityPeriods = ['morning', 'afternoon'];

    if ($work_location === '') {
        $errors['work_location'] = 'Preferred Work Location is required.';
    }
    foreach ($availabilityDays as $day) {
        foreach ($availabilityPeriods as $period) {
            $slot = $availabilityForm[$day][$period] ?? [];
            $slotNote = substr(trim((string) ($slot['notes'] ?? '')), 0, 500);
            if (empty($slot['enabled']) && $slotNote === '') {
                continue;
            }

            $timeStart = trim((string) ($slot['start'] ?? ''));
            $timeEnd = trim((string) ($slot['end'] ?? ''));
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timeStart) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timeEnd)) {
                $errors['availability'] = 'Enter a valid start and end time for each selected slot.';
                $availabilitySlotErrors[$day][$period] = 'Enter a valid start and end time.';
                continue;
            }

            $latestEndTime = $day === 'Saturday' ? '12:00' : '17:00';
            if ($timeStart < '08:00' || $timeEnd > $latestEndTime) {
                $errors['availability'] = $day === 'Saturday'
                    ? 'Saturday availability must be within office hours, up to 12:00 PM only.'
                    : 'Weekday availability must be within office hours, up to 5:00 PM only.';
                $availabilitySlotErrors[$day][$period] = $errors['availability'];
                continue;
            }

            $startTimestamp = strtotime('1970-01-01 ' . $timeStart);
            $endTimestamp = strtotime('1970-01-01 ' . $timeEnd);
            $hours = ($endTimestamp - $startTimestamp) / 3600;
            if ($hours < 2) {
                $errors['availability'] = 'Each availability slot must be at least 2 hours, with the end time after the start time.';
                $availabilitySlotErrors[$day][$period] = 'This selected slot is only ' . rtrim(rtrim(number_format($hours, 1), '0'), '.') . ' hour(s). Minimum duty time is 2 hours.';
                continue;
            }

            $totalAvailabilityHours += $hours;
            $dailyAvailabilityHours[$day] = ($dailyAvailabilityHours[$day] ?? 0.0) + $hours;
            $availabilityEntries[] = [
                'day_of_week' => $day,
                'time_start' => $timeStart,
                'time_end' => $timeEnd,
                'notes' => $slotNote,
                'is_available' => !empty($slot['enabled']),
            ];
        }
    }

    if (count($availabilityEntries) === 0) {
        $errors['availability'] = 'Please choose at least one availability slot.';
    } elseif (array_filter($dailyAvailabilityHours, static fn (float $hours): bool => $hours < 2.0) !== []) {
        $errors['availability'] = 'Each day with availability must have at least 2 duty hours.';
    } elseif ($totalAvailabilityHours < 10) {
        $errors['availability'] = 'Minimum required availability is 10 hours per week.';
    }

    if (empty($step1) || empty($step2)) {
        $errors['flow'] = 'Your registration session is incomplete. Please start again from Step 1.';
    }

    if (empty($errors)) {
        $_SESSION['sams_registration'] = array_merge($_SESSION['sams_registration'] ?? [], [
            'step3' => [
                'work_location' => $work_location,
                'skills' => $skills,
                'availability' => $availabilityEntries,
                'availability_form' => $availabilityForm,
            ],
        ]);

        header('Location: register2.php');
        exit;
    }
}

if (isset($_GET['finalize'])) {
    $work_location = trim((string) ($step3['work_location'] ?? ''));
    $skills = trim((string) ($step3['skills'] ?? ''));

    if (empty($step1) || empty($step2) || empty($step3) || empty($step4)) {
        $errors['flow'] = 'Your registration session is incomplete. Please complete all four steps.';
    }
    if (empty($step4['agree_terms']) || empty($step4['agree_privacy'])) {
        $errors['flow'] = 'Please agree to the Terms and Conditions and Data Privacy Act before submitting.';
    }

    if (empty($errors)) {
        $pdo = sams_pdo();

        try {
            $email = trim((string) ($step1['email'] ?? ''));
            $studentCode = trim((string) ($step1['student_id'] ?? ''));
            $contactNumber = preg_replace('/\D+/', '', (string) ($step1['contact_number'] ?? ''));

            if (!sams_column_exists($pdo, 'users', 'phone_number')) {
                throw new RuntimeException('Users table must have phone_number column. Please apply the latest database migration.');
            }

            if (!sams_column_exists($pdo, 'students', 'student_id_number')) {
                throw new RuntimeException('Students table must have student_id_number column.');
            }

            $duplicateCheck = $pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM users WHERE email = :email) AS email_count,
                    (SELECT COUNT(*) FROM users WHERE phone_number = :phone_number) AS phone_count,
                    (SELECT COUNT(*) FROM students WHERE student_id_number = :student_code) AS student_count"
            );
            $duplicateCheck->execute([
                'email' => $email,
                'phone_number' => $contactNumber,
                'student_code' => $studentCode,
            ]);
            $duplicateCounts = $duplicateCheck->fetch() ?: ['email_count' => 0, 'student_count' => 0];

            if ((int) ($duplicateCounts['email_count'] ?? 0) > 0) {
                throw new RuntimeException('This email is already registered. Please use a different email or log in.');
            }

            if ((int) ($duplicateCounts['phone_count'] ?? 0) > 0) {
                throw new RuntimeException('This phone number is already registered. Please use a different phone number.');
            }

            if ((int) ($duplicateCounts['student_count'] ?? 0) > 0) {
                throw new RuntimeException('This student ID is already registered. Please use a different student ID or log in.');
            }

            $pdo->beginTransaction();

            $currentTerm = sams_current_term($pdo);
            $activeTermId = $currentTerm['term_id'] ?? false;

            if ($activeTermId === false) {
                throw new RuntimeException('No active term is configured yet. Please activate a term before accepting applications.');
            }

            $fullName = trim((string) ($step1['full_name'] ?? ''));
            [$firstName, $lastName] = sams_split_full_name($fullName);
            $course = trim((string) ($step2['course'] ?? ''));
            $submittedYearLevel = (string) ($step2['year_level'] ?? '');
            if (!in_array($submittedYearLevel, ['1', '2', '3', '4'], true)) {
                throw new RuntimeException('Invalid year level. Please return to Academic Information and choose 1st Year to 4th Year.');
            }
            $yearLevel = sams_map_year_level($submittedYearLevel);
            $gpa = trim((string) ($step2['gpa'] ?? ''));

            // Default password is the student ID. Student can change it later if you add that feature.
            $userPasswordHash = password_hash($studentCode, PASSWORD_DEFAULT);
            $passwordColumn = sams_first_existing_column($pdo, 'users', ['password', 'password_hash']);
            if ($passwordColumn === null) {
                throw new RuntimeException('Users table must have password or password_hash column.');
            }

            $userColumns = ['email', $passwordColumn, 'role', 'first_name', 'last_name', 'is_active'];
            $userParams = [':email', ':password_value', ':role', ':first_name', ':last_name', ':is_active'];
            $userValues = [
                'email' => $email,
                'password_value' => $userPasswordHash,
                'role' => 'student',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => 0,
            ];

            if (sams_column_exists($pdo, 'users', 'must_change_password')) {
                $userColumns[] = 'must_change_password';
                $userParams[] = ':must_change_password';
                $userValues['must_change_password'] = 1;
            }

            $userColumns[] = 'phone_number';
            $userParams[] = ':phone_number';
            $userValues['phone_number'] = $contactNumber;

            $userStatement = $pdo->prepare(
                'INSERT INTO users (' . implode(', ', $userColumns) . ')
                 VALUES (' . implode(', ', $userParams) . ')'
            );
            $userStatement->execute($userValues);

            $userId = (int) $pdo->lastInsertId();

            $studentColumns = ['user_id', 'student_id_number', 'program', 'year_level', 'current_gpa', 'is_enrolled', 'is_good_standing'];
            $studentValues = [':user_id', ':student_code', ':program', ':year_level', ':current_gpa', ':is_enrolled', ':is_good_standing'];
            $studentParams = [
                'user_id' => $userId, 'student_code' => $studentCode, 'program' => $course,
                'year_level' => $yearLevel, 'current_gpa' => $gpa !== '' ? $gpa : null,
                'is_enrolled' => 1, 'is_good_standing' => 1,
            ];
            if (sams_column_exists($pdo, 'students', 'units')) {
                $studentColumns[] = 'units';
                $studentValues[] = ':units';
                $studentParams['units'] = (int) ($step2['units'] ?? 0);
            }
            $studentStatement = $pdo->prepare(
                'INSERT INTO students (' . implode(', ', $studentColumns) . ')
                 VALUES (' . implode(', ', $studentValues) . ')'
            );
            $studentStatement->execute([
                ...$studentParams,
            ]);

            $studentId = (int) $pdo->lastInsertId();

            $applicationStatement = $pdo->prepare(
                'INSERT INTO applications (
                    student_id, term_id, status, preferred_office, skills, submitted_at
                 ) VALUES (
                    :student_id, :term_id, :status, :preferred_office, :skills, NOW()
                 )'
            );
            $applicationStatement->execute([
                'student_id' => $studentId,
                'term_id' => (int) $activeTermId,
                'status' => 'draft',
                'preferred_office' => $work_location,
                'skills' => $skills,
            ]);

            $applicationId = (int) $pdo->lastInsertId();

            $tempFolder = $step4['temp_folder'] ?? '';
            $storedFiles = $step4['files'] ?? [];
            $uploadBase = __DIR__ . '/uploads/documents/student_' . $studentId . '/application_' . $applicationId;

            if ($tempFolder !== '' && is_dir($tempFolder) && !is_dir($uploadBase)) {
                mkdir($uploadBase, 0777, true);
            }

            $documentMap = [
                'resume' => 'resume',
                'intent_letter' => 'letter_of_intent',
                'parent_consent' => 'parent_consent',
                'recommendation' => 'recommendation_letter',
                'grades' => 'grades',
                'class_schedule' => 'class_schedule',
                'good_moral' => 'good_moral',
            ];

            if (is_array($storedFiles) && sams_column_exists($pdo, 'document_uploads', 'application_id')) {
                $documentUserColumn = sams_first_existing_column($pdo, 'document_uploads', ['user_id', 'student_id']);

                $documentColumns = [];
                $documentValues = [];

                if ($documentUserColumn !== null) {
                    $documentColumns[] = $documentUserColumn;
                    $documentValues[] = ':' . $documentUserColumn;
                }

                $requiredDocumentColumns = [
                    'application_id', 'document_type', 'original_filename',
                    'stored_filename', 'file_path', 'file_size', 'mime_type'
                ];

                foreach ($requiredDocumentColumns as $column) {
                    if (sams_column_exists($pdo, 'document_uploads', $column)) {
                        $documentColumns[] = $column;
                        $documentValues[] = ':' . $column;
                    }
                }

                if (sams_column_exists($pdo, 'document_uploads', 'uploaded_at')) {
                    $documentColumns[] = 'uploaded_at';
                    $documentValues[] = 'NOW()';
                }

                if (count($documentColumns) > 0) {
                    $documentStatement = $pdo->prepare(
                        'INSERT INTO document_uploads (' . implode(', ', $documentColumns) . ')
                         VALUES (' . implode(', ', $documentValues) . ')'
                    );

                    foreach ($storedFiles as $fileKey => $fileMeta) {
                        $tempPath = $fileMeta['stored_path'] ?? '';
                        if ($tempPath === '' || !file_exists($tempPath)) {
                            continue;
                        }

                        if (!is_dir($uploadBase)) {
                            mkdir($uploadBase, 0777, true);
                        }

                        $originalName = (string) ($fileMeta['original_name'] ?? basename($tempPath));
                        $targetPath = $uploadBase . '/' . basename((string) ($fileMeta['stored_name'] ?? basename($tempPath)));

                        if (!rename($tempPath, $targetPath)) {
                            throw new RuntimeException('Unable to finalize uploaded file: ' . $originalName);
                        }

                        $documentData = [];
                        if ($documentUserColumn === 'user_id') {
                            $documentData['user_id'] = $userId;
                        } elseif ($documentUserColumn === 'student_id') {
                            $documentData['student_id'] = $studentId;
                        }

                        $possibleDocumentData = [
                            'application_id' => $applicationId,
                            'document_type' => $documentMap[$fileKey] ?? 'other',
                            'original_filename' => $originalName,
                            'stored_filename' => basename($targetPath),
                            'file_path' => 'uploads/documents/student_' . $studentId . '/application_' . $applicationId . '/' . basename($targetPath),
                            'file_size' => (int) ($fileMeta['size'] ?? 0),
                            'mime_type' => (string) ($fileMeta['mime_type'] ?? 'application/octet-stream'),
                        ];

                        foreach ($possibleDocumentData as $column => $value) {
                            if (in_array($column, $documentColumns, true)) {
                                $documentData[$column] = $value;
                            }
                        }

                        $documentStatement->execute($documentData);
                    }
                }
            }

            $hasAvailabilityFlag = sams_column_exists($pdo, 'availability', 'is_available');
            $availabilityStatement = $pdo->prepare(
                $hasAvailabilityFlag
                    ? 'INSERT INTO availability
                        (application_id, term_id, day_of_week, start_time, end_time, notes, is_available)
                       VALUES
                        (:application_id, :term_id, :day_of_week, :start_time, :end_time, :notes, :is_available)'
                    : 'INSERT INTO availability
                        (application_id, term_id, day_of_week, start_time, end_time, notes)
                       VALUES
                        (:application_id, :term_id, :day_of_week, :start_time, :end_time, :notes)'
            );
            $totalAvailabilityHours = 0.0;
            $dailyAvailabilityHours = [];
            foreach (($step3['availability'] ?? []) as $entry) {
                $startTimestamp = strtotime('1970-01-01 ' . (string) ($entry['time_start'] ?? ''));
                $endTimestamp = strtotime('1970-01-01 ' . (string) ($entry['time_end'] ?? ''));
                if ($startTimestamp === false || $endTimestamp === false || $endTimestamp <= $startTimestamp) {
                    throw new RuntimeException('An availability time slot is invalid. Please review your schedule.');
                }

                $day = (string) ($entry['day_of_week'] ?? '');
                $isAvailable = array_key_exists('is_available', $entry) ? (bool) $entry['is_available'] : true;
                $hours = ($endTimestamp - $startTimestamp) / 3600;
                if ($isAvailable) {
                    $dailyAvailabilityHours[$day] = ($dailyAvailabilityHours[$day] ?? 0.0) + $hours;
                    $totalAvailabilityHours += $hours;
                }
                $availabilityParams = [
                    'application_id' => $applicationId,
                    'term_id' => (int) $activeTermId,
                    'day_of_week' => (string) ($entry['day_of_week'] ?? ''),
                    'start_time' => (string) ($entry['time_start'] ?? ''),
                    'end_time' => (string) ($entry['time_end'] ?? ''),
                    'notes' => ($entry['notes'] ?? '') !== '' ? (string) $entry['notes'] : null,
                ];
                if ($hasAvailabilityFlag) {
                    $availabilityParams['is_available'] = $isAvailable ? 1 : 0;
                }
                $availabilityStatement->execute($availabilityParams);
            }

            if (array_filter($dailyAvailabilityHours, static fn (float $hours): bool => $hours < 2.0) !== []) {
                throw new RuntimeException('Each day with availability must have at least 2 duty hours. Please review your schedule.');
            }

            if ($totalAvailabilityHours < 10) {
                throw new RuntimeException('Minimum required availability is 10 hours per week. Please review your schedule.');
            }

            $updateApplicationStatement = $pdo->prepare(
                "UPDATE applications
                 SET available_hours_per_week = :available_hours,
                     submitted_at = NOW(),
                     status = 'pending'
                 WHERE application_id = :application_id"
            );
            $updateApplicationStatement->execute([
                'available_hours' => (int) round($totalAvailabilityHours),
                'application_id' => $applicationId,
            ]);

            $pdo->commit();

            try {
                $termLabel = trim((string) ($currentTerm['term_name'] ?? '') . ' ' . (string) ($currentTerm['term_year'] ?? ''));
                sams_send_application_submitted_email(
                    $email,
                    $fullName !== '' ? $fullName : $email,
                    'APP-' . $applicationId,
                    $termLabel !== '' ? $termLabel : 'Current Term'
                );
            } catch (Throwable $mailException) {
                error_log('[sams] Registration submission mailer failed: ' . $mailException->getMessage());
            }

            unset($_SESSION['sams_registration']);
            $_SESSION['registration_submission'] = [
                'success' => true,
                'message' => 'Your application and weekly availability have been submitted successfully.',
                'availability_complete' => true,
                'application_id' => $applicationId,
                'student_id' => $studentId,
                'term_id' => (int) $activeTermId,
                'student_name' => $fullName,
                'student_number' => $studentCode,
                'course' => $course,
                'year_level' => $yearLevel,
                'date_submitted' => date('F j, Y'),
                'status' => 'PENDING',
            ];

            header('Location: register2.php?submitted=1');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors['flow'] = $exception->getMessage();
        }
    }
}

if (!empty($errors)) {
    error_log('register3: errors=' . json_encode($errors));
    error_log('register3: session=' . json_encode($_SESSION['sams_registration'] ?? []));
}

$availabilityDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$availabilityForm = $_POST['availability'] ?? ($step3['availability_form'] ?? []);
$selectedLocation = (string) ($_POST['work_location'] ?? ($step3['work_location'] ?? ''));
$val_location = htmlspecialchars($selectedLocation, ENT_QUOTES, 'UTF-8');
$val_skills = htmlspecialchars((string) ($_POST['skills'] ?? ($step3['skills'] ?? '')), ENT_QUOTES, 'UTF-8');

$existingCor = $_SESSION['sams_registration']['step4']['files']['class_schedule'] ?? null;
$hasExistingCor = !empty($existingCor['stored_path']) && file_exists($existingCor['stored_path']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Assistant Application – Step 3 (Preferences &amp; Availability)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/sams-design-system.css" />
    <script src="assets/js/pdf.min.js"></script>
    <script src="assets/js/nuis-cor-parser.js"></script>
    <style>
        /* =============================================
           CSS VARIABLES – Design System
        ============================================= */
        :root {
            --color-primary:        #003087;
            --color-heading:        #101828;
            --color-body:           #4a5565;
            --color-label:          #364153;
            --color-dark:           #1e2939;
            --color-muted:          #6a7282;
            --color-border:         #d1d5dc;
            --color-bg-info:        #f9fafb;
            --color-bg-warn:        #fffbeb;
            --color-border-warn:    #ffb81c;
            --color-white:          #ffffff;
            --color-placeholder:    rgba(10,10,10,0.5);
            --color-error:          #dc2626;
            --color-submit-text:    #003087;

            --gradient-bg:          linear-gradient(138.29deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%);
            --gradient-primary:     linear-gradient(135deg, #003087 0%, #0047ab 100%);
            --gradient-steps:       linear-gradient(159.33deg, #003087 0%, #0047ab 100%);
            --gradient-progress:    linear-gradient(90deg, #003087 0%, #ffb81c 100%);
            --gradient-submit:      linear-gradient(90deg, #ffb81c 0%, #ffa500 100%);

            --radius-card:  16px;
            --radius-step:  14px;
            --radius-input: 14px;
            --radius-info:  14px;
            --radius-btn:   14px;
            --radius-pill:  9999px;

            --shadow-card: 0 10px 15px rgba(0,0,0,.10), 0 4px 6px rgba(0,0,0,.10);

            --font-xs:   12px;
            --font-sm:   14px;
            --font-base: 16px;
            --font-md:   18px;
            --font-lg:   24px;
            --font-xl:   36px;

            --lh-xs:   16px;
            --lh-sm:   20px;
            --lh-base: 24px;
            --lh-md:   28px;
            --lh-lg:   32px;
            --lh-xl:   40px;
        }

        /* =============================================
           RESET & BASE
        ============================================= */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gradient-bg);
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
            position: relative;
            min-height: 100vh;
            padding: 32px 0 64px;
        }

        /* =============================================
           TOP BAR
        ============================================= */
        .page__top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            min-height: 32px;
            margin-bottom: 0;
        }

        /* =============================================
           BACK LINK
        ============================================= */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-primary);
            white-space: nowrap;
        }
        .back-link__icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        /* =============================================
           HAMBURGER NAV (tablet / mobile only)
        ============================================= */
        .nav { display: none; }

        /* =============================================
           MAIN CONTAINER
        ============================================= */
        .container {
            max-width: 1024px;
            width: 100%;
            margin: 0 auto;
            padding: 60px 32px 0;
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        /* =============================================
           HERO
        ============================================= */
        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .hero__icon-wrap {
            width: 64px;
            height: 64px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .hero__icon-wrap img { width: 32px; height: 32px; }
        .hero__title {
            font-size: var(--font-xl);
            font-weight: 900;
            line-height: var(--lh-xl);
            color: var(--color-heading);
            text-align: center;
            margin-bottom: 4px;
        }
        .hero__subtitle {
            font-size: var(--font-md);
            font-weight: 500;
            line-height: var(--lh-md);
            color: var(--color-body);
            text-align: center;
        }

        /* =============================================
           PROGRESS CARD
        ============================================= */
        .progress-card {
            background: var(--color-white);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px 32px 24px;
        }
        .progress-card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .progress-card__step-label {
            font-size: var(--font-sm);
            font-weight: 700;
            line-height: var(--lh-sm);
            color: var(--color-body);
        }
        .progress-card__pct-label {
            font-size: var(--font-sm);
            font-weight: 700;
            line-height: var(--lh-sm);
            color: var(--color-primary);
        }
        .progress-card__bar-track {
            height: 12px;
            background: #e5e7eb;
            border-radius: var(--radius-pill);
            overflow: hidden;
            margin-bottom: 16px;
        }
        .progress-card__bar-fill {
            height: 100%;
            width: 100%;
            border-radius: var(--radius-pill);
            background: var(--gradient-progress);
        }

        /* =============================================
           STEP TABS
        ============================================= */
        .steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px;
            border-radius: var(--radius-step);
            text-decoration: none;
        }
        .step--active   { background: var(--gradient-steps); }
        .step--inactive { background: #f3f4f6; }
        .step__icon { width: 24px; height: 24px; flex-shrink: 0; }
        .step__label {
            font-size: var(--font-xs);
            font-weight: 700;
            line-height: var(--lh-xs);
            text-align: center;
            white-space: nowrap;
        }
        .step--active   .step__label { color: var(--color-white); }
        .step--inactive .step__label { color: #99a1af; }

        /* =============================================
           FORM CARD
        ============================================= */
        .form-card {
            background: var(--color-white);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 32px;
        }

        /* =============================================
           SECTION HEADING
        ============================================= */
        .section-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .section-heading__icon { width: 32px; height: 32px; flex-shrink: 0; }
        .section-heading__title {
            font-size: var(--font-lg);
            font-weight: 900;
            line-height: var(--lh-lg);
            color: var(--color-heading);
            white-space: nowrap;
        }

        /* =============================================
           FORM FIELDS
        ============================================= */
        .form-fields {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .field {}
        .availability-section {
            display: grid;
            gap: 16px;
        }
        .availability-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .availability-day {
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 14px;
            background: #fff;
        }
        .availability-day h3 {
            margin: 0 0 12px;
            font-size: var(--font-base);
        }
        .availability-slot {
            display: grid;
            grid-template-columns: auto 1fr 1fr;
            gap: 8px;
            align-items: center;
            padding: 10px 0;
            border-top: 1px solid var(--color-border);
        }
        .availability-slot label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: var(--font-sm);
            font-weight: 700;
        }
        .availability-slot input[type="time"] {
            width: 100%;
            min-width: 0;
            padding: 8px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            font: inherit;
        }
        .availability-slot textarea {
            grid-column: 2 / -1;
            width: 100%;
            min-height: 48px;
            padding: 8px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            font: inherit;
            resize: vertical;
        }
        .slot-conflict-container {
            grid-column: 2 / -1;
            min-width: 0;
        }
        .availability-error {
            color: #b91c1c;
            font-weight: 700;
        }
        .availability-slot-error {
            display: block;
            grid-column: 1 / -1;
            margin-top: 6px;
            color: #b91c1c;
            font-size: 12px;
            font-weight: 700;
        }
        .field__label {
            display: block;
            font-size: var(--font-sm);
            font-weight: 700;
            line-height: var(--lh-sm);
            color: var(--color-label);
            margin-bottom: 8px;
        }
        .field__select,
        .field__textarea {
            width: 100%;
            border: 2px solid var(--color-border);
            border-radius: var(--radius-input);
            font-family: 'Inter', sans-serif;
            font-size: var(--font-base);
            font-weight: 500;
            line-height: var(--lh-base);
            color: var(--color-heading);
            background: var(--color-white);
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }
        .field__select {
            height: 51px;
            padding: 0 16px;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M6 9l6 6 6-6' stroke='%234a5565' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            cursor: pointer;
        }
        .field__textarea {
            height: 128px;
            padding: 12px 16px;
            resize: vertical;
        }
        .field__select:focus,
        .field__textarea:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(0,48,135,.12);
        }
        .field__select--error,
        .field__textarea--error {
            border-color: var(--color-error);
        }
        .field__select option[value=""] { color: var(--color-placeholder); }

        /* Placeholder color */
        .field__textarea::placeholder {
            color: var(--color-placeholder);
            font-weight: 500;
        }

        /* =============================================
           SKILL TAGS SELECTOR
         ============================================= */
        .skills-hint {
            font-size: var(--font-xs);
            color: var(--color-muted);
            margin-bottom: 12px;
        }
        .skill-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
        }
        .skill-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            font-size: var(--font-sm);
            font-weight: 700;
            color: var(--color-label);
            background: var(--color-white);
            border: 2px solid var(--color-border);
            border-radius: var(--radius-pill);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            user-select: none;
        }
        .skill-tag:hover {
            color: var(--color-primary);
            border-color: var(--color-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 48, 135, 0.08);
        }
        .skill-tag:active {
            transform: translateY(0) scale(0.96);
        }
        .skill-tag--active {
            color: var(--color-white) !important;
            background: var(--gradient-primary) !important;
            border-color: var(--color-primary) !important;
            box-shadow: 0 4px 12px rgba(0, 48, 135, 0.2) !important;
        }

        .field__error {
            display: block;
            font-size: var(--font-xs);
            font-weight: 400;
            line-height: var(--lh-xs);
            color: var(--color-error);
            margin-top: 6px;
        }

        /* =============================================
           CHECKBOXES BLOCK
        ============================================= */
        .checkboxes {
            background: var(--color-bg-info);
            border-radius: var(--radius-info);
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .checkbox-row__input {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            accent-color: var(--color-primary);
            cursor: pointer;
        }
        .checkbox-row__label {
            font-size: var(--font-sm);
            font-weight: 500;
            line-height: var(--lh-sm);
            color: var(--color-label);
            cursor: pointer;
        }
        .checkbox-row__label strong {
            font-weight: 700;
        }
        .checkbox-error {
            font-size: var(--font-xs);
            color: var(--color-error);
            margin-top: 4px;
            display: block;
        }

        /* =============================================
           AUTO-RECOMMENDATION BANNER
        ============================================= */
        .auto-rec {
            background: var(--color-bg-warn);
            border: 2px solid var(--color-border-warn);
            border-radius: var(--radius-info);
            padding: 18px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .auto-rec__icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .auto-rec__text {
            font-size: var(--font-sm);
            line-height: var(--lh-sm);
            color: var(--color-dark);
        }
        .auto-rec__text strong {
            font-weight: 700;
        }

        /* =============================================
           NUIS COR SCANNER COMPONENT STYLES
        ============================================= */
        .cor-scanner-card {
            background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%);
            border: 2px dashed #93c5fd;
            border-radius: var(--radius-card);
            padding: 22px 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 14px rgba(0, 48, 135, 0.04);
            transition: all 0.25s ease;
        }
        .cor-scanner-card:hover {
            border-color: var(--color-primary);
            box-shadow: 0 6px 18px rgba(0, 48, 135, 0.08);
        }
        .cor-scanner-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }
        .cor-scanner-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(0, 48, 135, 0.2);
        }
        .cor-scanner-title {
            font-size: var(--font-base);
            font-weight: 700;
            color: var(--color-heading);
            margin-bottom: 3px;
        }
        .cor-scanner-desc {
            font-size: var(--font-sm);
            color: var(--color-body);
            line-height: var(--lh-sm);
        }
        .cor-dropzone {
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: var(--radius-step);
            padding: 20px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            outline: none;
        }
        .cor-dropzone:hover, .cor-dropzone--dragover, .cor-dropzone:focus-visible {
            border-color: var(--color-primary);
            background: #eff6ff;
        }
        .cor-dropzone-icon {
            margin: 0 auto 8px;
            display: block;
        }
        .cor-dropzone-title {
            font-size: var(--font-sm);
            font-weight: 700;
            color: var(--color-primary);
        }
        .cor-dropzone-hint {
            font-size: var(--font-xs);
            color: var(--color-muted);
            margin-top: 4px;
        }
        .cor-loading-banner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: var(--radius-step);
            color: var(--color-primary);
            font-weight: 600;
            font-size: var(--font-sm);
            margin-top: 12px;
        }
        .cor-spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0,48,135,0.2);
            border-top-color: var(--color-primary);
            border-radius: 50%;
            animation: cor-spin 0.8s linear infinite;
        }
        @keyframes cor-spin {
            to { transform: rotate(360deg); }
        }
        .cor-success-panel {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: var(--radius-step);
            padding: 16px;
            margin-top: 14px;
        }
        .cor-success-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }
        .cor-badge-student {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dcfce7;
            color: #166534;
            padding: 4px 10px;
            border-radius: var(--radius-pill);
            font-size: var(--font-xs);
            font-weight: 700;
        }
        .cor-schedule-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }
        .cor-chip {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            color: #1e293b;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .cor-chip strong {
            color: var(--color-primary);
        }
        .cor-adjustment-notice {
            background: #fffbeb;
            border: 1.5px solid #fde047;
            border-radius: var(--radius-step);
            padding: 14px 18px;
            margin-top: 14px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: var(--font-sm);
            color: #854d0e;
            line-height: var(--lh-sm);
        }
        .slot-conflict-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 3px 8px;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        .slot-free-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 3px 8px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        .availability-slot--modified {
            border: 2px solid #f59e0b !important;
            border-radius: 10px;
            padding: 8px;
            background: #fffdf5;
        }
        .availability-slot--modified textarea {
            border-color: #f59e0b !important;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2) !important;
        }

        /* =============================================
           ACTION BUTTONS ROW
        ============================================= */
        .actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 60px;
            padding: 0 24px;
            background: var(--color-white);
            border: 2px solid var(--color-primary);
            border-radius: var(--radius-btn);
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-primary);
            text-decoration: none;
            white-space: nowrap;
            transition: background .2s;
        }
        .btn-back:hover { background: rgba(0,48,135,.05); }
        .btn-back__icon { width: 20px; height: 20px; flex-shrink: 0; }

        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 60px;
            padding: 0 28px;
            background: var(--gradient-submit);
            border: none;
            border-radius: var(--radius-btn);
            font-size: var(--font-base);
            font-weight: 700;
            line-height: var(--lh-base);
            color: var(--color-submit-text);
            cursor: pointer;
            white-space: nowrap;
            transition: opacity .2s;
        }
        .btn-submit:hover { opacity: .9; }
        .btn-submit__icon { width: 20px; height: 20px; flex-shrink: 0; }

        /* =============================================
           SUCCESS BANNER
        ============================================= */
        .success-banner {
            background: #dcfce7;
            border: 2px solid #22c55e;
            border-radius: var(--radius-card);
            padding: 16px 24px;
            font-size: var(--font-sm);
            font-weight: 700;
            color: #15803d;
            text-align: center;
        }

        /* =============================================
           RESPONSIVE – TABLET (≤1024px)
        ============================================= */
        @media (max-width: 1024px) {
            .page__top-bar { padding: 0 24px; }

            .nav {
                display: flex;
                align-items: center;
                position: relative;
            }
            .nav__hamburger {
                display: flex;
                flex-direction: column;
                gap: 5px;
                width: 32px;
                height: 32px;
                justify-content: center;
                align-items: center;
                padding: 0;
            }
            .nav__hamburger-bar {
                display: block;
                width: 22px;
                height: 2px;
                background: var(--color-primary);
                border-radius: 2px;
                transition: transform .3s, opacity .3s;
            }
            .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
            .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(2) { opacity: 0; }
            .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

            .nav__menu {
                display: none;
                position: absolute;
                top: 40px;
                right: 0;
                background: var(--color-white);
                border-radius: var(--radius-card);
                box-shadow: var(--shadow-card);
                padding: 12px 0;
                min-width: 200px;
                z-index: 100;
            }
            .nav__menu--open { display: block; }
            .nav__item {
                display: block;
                padding: 12px 24px;
                font-size: var(--font-sm);
                font-weight: 700;
                color: var(--color-label);
                text-decoration: none;
                transition: background .2s;
            }
            .nav__item:hover { background: #f3f4f6; }
            .nav__item--active { color: var(--color-primary); }

            .container { padding: 24px 24px 0; }

            .steps { gap: 8px; }
            .step { padding: 12px 8px; }
            .step__label { font-size: 10px; }

            .hero__title { font-size: 28px; line-height: 36px; }
        }

        /* =============================================
           RESPONSIVE – MOBILE (≤768px)
        ============================================= */
        @media (max-width: 768px) {
            .page__top-bar { padding: 0 16px; }
            .container { padding: 20px 16px 0; gap: 20px; }

            .hero__title { font-size: 22px; line-height: 30px; }
            .hero__subtitle { font-size: var(--font-base); line-height: var(--lh-base); }
            .hero__icon-wrap { width: 52px; height: 52px; border-radius: 12px; }

            .progress-card { padding: 20px 16px 16px; }
            .steps { grid-template-columns: repeat(2, 1fr); }
            .availability-grid { grid-template-columns: 1fr; }

            .form-card { padding: 20px 16px; }
            .section-heading__title { font-size: 18px; }

            .actions { gap: 12px; }
            .btn-back,
            .btn-submit {
                flex: 1;
                justify-content: center;
                height: 52px;
                font-size: var(--font-sm);
                padding: 0 16px;
            }
        }
    </style>
</head>
<body>

<main class="page">

    <!-- ── TOP BAR ── -->
    <div class="page__top-bar">
        <a href="index.php" class="back-link" aria-label="Back to Home">
            <svg class="back-link__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M15.8333 10H4.16667M4.16667 10L10 15.8333M4.16667 10L10 4.16667" stroke="#003087" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Back to Home
        </a>

        <nav class="nav" aria-label="Main navigation">
            <button class="nav__hamburger" aria-expanded="false" aria-controls="nav-menu" aria-label="Toggle navigation">
                <span class="nav__hamburger-bar"></span>
                <span class="nav__hamburger-bar"></span>
                <span class="nav__hamburger-bar"></span>
            </button>
            <ul id="nav-menu" class="nav__menu" role="list">
                <li><a href="index.php"     class="nav__item">Home</a></li>
                <li><a href="register.php"  class="nav__item">Personal Info</a></li>
                <li><a href="register1.php" class="nav__item">Academic Info</a></li>
                <li><a href="register3.php" class="nav__item nav__item--active" aria-current="page">Assessment</a></li>
                <li><a href="register2.php" class="nav__item">Requirements</a></li>
            </ul>
        </nav>
    </div>

    <div class="container">

        <?php if ($success): ?>
        <div class="success-banner" role="alert">
            ✓ Application submitted successfully! We will review your application and get back to you.
        </div>
        <?php endif; ?>

                <?php if (!empty($errors['flow'])): ?>
                    <div style="background:#fff0f0;border:2px solid #fca5a5;color:#881818;border-radius:12px;padding:12px 16px;margin-bottom:16px;" role="alert">
                        <?= htmlspecialchars($errors['flow']) ?>
                    </div>
                <?php endif; ?>

        <!-- ── HERO ── -->
        <header class="hero">
            <div class="hero__icon-wrap" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M16 4L2 11.2727L16 18.5455L30 11.2727L16 4Z" stroke="white" stroke-width="2" stroke-linejoin="round"/>
                    <path d="M7.27271 15.2727V22.5454C7.27271 22.5454 10.9091 26.1818 16 26.1818C21.0909 26.1818 24.7272 22.5454 24.7272 22.5454V15.2727" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M30 11.2727V18.5454" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="hero__title">Student Assistant Application</h1>
            <p class="hero__subtitle">Complete the 4-step process to apply</p>
        </header>

        <!-- ── PROGRESS CARD ── -->
        <section class="progress-card" aria-label="Application progress">
            <div class="progress-card__header">
                <span class="progress-card__step-label">Step 3 of 4</span>
                <span class="progress-card__pct-label">75% Complete</span>
            </div>
            <div class="progress-card__bar-track" role="progressbar" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100" aria-label="75% complete">
                <div class="progress-card__bar-fill"></div>
            </div>

            <div class="steps" role="list">
                <!-- Step 1 – Personal Info (completed) -->
                <a href="register.php" class="step step--active" role="listitem">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="step__label">Personal Info</span>
                </a>

                <!-- Step 2 – Academic Info (completed) -->
                <a href="register1.php" class="step step--active" role="listitem">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M4 19.5V4.5C4 3.4 4.9 2.5 6 2.5H18C19.1 2.5 20 3.4 20 4.5V19.5L12 15.5L4 19.5Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="step__label">Academic Info</span>
                </a>

                <!-- Step 3 – Assessment (current) -->
                <div class="step step--active" role="listitem" aria-current="step">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M14 2V8H20" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 13H8" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 17H8" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 9H8" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="step__label">Assessment</span>
                </div>

                <!-- Step 4 – Requirements (upcoming) -->
                <a href="register2.php" class="step step--inactive" role="listitem">
                    <svg class="step__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2" stroke="white" stroke-width="2"/>
                        <path d="M9 9H15" stroke="white" stroke-width="2" stroke-linecap="round"/>
                        <path d="M9 12H15" stroke="white" stroke-width="2" stroke-linecap="round"/>
                        <path d="M9 15H12" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="step__label">Requirements</span>
                </a>
            </div>
        </section>

        <!-- ── FORM CARD ── -->
        <section class="form-card" aria-labelledby="form-heading">
            <div class="section-heading">
                <!-- Clipboard-check icon matching Figma imgIcon3 -->
                <svg class="section-heading__icon" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="6" y="5" width="20" height="22" rx="3" stroke="#003087" stroke-width="2"/>
                    <path d="M12 3h8a1 1 0 0 1 1 1v2H11V4a1 1 0 0 1 1-1Z" stroke="#003087" stroke-width="2"/>
                    <path d="M11 16l3 3 6-6" stroke="#003087" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h2 class="section-heading__title" id="form-heading">Assessment &amp; Preferences</h2>
            </div>

            <form method="POST" action="" novalidate>
                <div class="form-fields">

                    <!-- Preferred Work Location -->
                    <div class="field">
                        <label class="field__label" for="work_location">Preferred Office Assignment *</label>
                        <select
    class="field__select<?= !empty($errors['work_location']) ? ' field__select--error' : '' ?>"
    id="work_location"
    name="work_location"
    aria-required="true"
>

<option value="">Select office...</option>

<?php foreach (sams_office_options() as $officeOption): ?>
<option value="<?= htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedLocation === $officeOption ? 'selected' : '' ?>><?= htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>

</select>
                        <?php if (!empty($errors['work_location'])): ?>
                            <span class="field__error" id="work-location-error" role="alert"><?= htmlspecialchars($errors['work_location']) ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Preferred Work Schedule removed per request -->

                    <!-- ── NUIS Lipa COR Scanner Card ── -->
                    <div class="cor-scanner-card" id="cor-scanner-card">
                        <div class="cor-scanner-header">
                            <div class="cor-scanner-icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                            </div>
                            <div>
                                <h3 class="cor-scanner-title">Auto-Detect Availability from NUIS Lipa COR</h3>
                                <p class="cor-scanner-desc">
                                    Upload your downloaded <strong>Certificate of Registration (PDF)</strong> from NUIS Lipa.
                                    The system will automatically detect your class schedule, identify your free hours, and check your availability slots below.
                                </p>
                            </div>
                        </div>

                        <!-- Dropzone -->
                        <div class="cor-dropzone" id="cor-dropzone" tabindex="0" role="button" aria-label="Upload COR PDF to detect schedule">
                            <input type="file" id="cor-file-input" accept="application/pdf" style="display:none;" />
                            <svg class="cor-dropzone-icon" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#003087" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                            <div class="cor-dropzone-title">Click to upload your NUIS Lipa COR PDF (or drag and drop here)</div>
                            <div class="cor-dropzone-hint">Official NUIS Lipa COR PDF documents (Max 5MB)</div>
                            <?php if ($hasExistingCor): ?>
                                <div style="margin-top:8px;font-size:12px;font-weight:700;color:#166534;" id="cor-existing-label">
                                    ✓ Saved in session: <?= htmlspecialchars($existingCor['original_name'] ?? 'COR.pdf') ?> (Click to upload different COR)
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Loading indicator -->
                        <div id="cor-loading" style="display:none;" class="cor-loading-banner">
                            <span class="cor-spinner"></span>
                            <span>Reading NUIS Lipa COR and calculating available hours...</span>
                        </div>

                        <!-- Success result panel -->
                        <div id="cor-success-panel" style="display:none;" class="cor-success-panel">
                            <div class="cor-success-header">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="4 11 8 15 16 6"/></svg>
                                    <strong style="color:#166534;font-size:14px;">COR Schedule Detected &amp; Applied!</strong>
                                </div>
                                <div id="cor-student-badge" class="cor-badge-student"></div>
                            </div>
                            <div style="font-size:13px;color:#334155;margin-bottom:6px;">
                                Found <strong id="cor-class-count">0</strong> scheduled class sessions:
                            </div>
                            <div id="cor-chips-list" class="cor-schedule-chips"></div>
                            <div style="margin-top:12px;font-size:12px;color:#166534;font-weight:600;">
                                ✓ Your free time slots have been automatically checked below. You can still modify them if needed.
                            </div>
                        </div>

                        <!-- Schedule Adjustment / Reason Notice -->
                        <div id="cor-adjustment-notice" style="display:none;" class="cor-adjustment-notice">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" style="flex-shrink:0;margin-top:2px;">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <div>
                                <strong>Schedule Adjusted from COR:</strong>
                                <span>You modified your availability from your official COR schedule. Please explain your reason in the <strong>Notes</strong> box of the adjusted day (e.g. commute/travel time, part-time job, organization duties).</span>
                            </div>
                        </div>
                    </div>

                    <div class="field availability-section">
                        <div>
                            <label class="field__label">Weekly Availability *</label>
                            <p>Select your available morning and/or afternoon hours. Each selected day must have at least 2 duty hours, with at least 10 hours total per week.</p>
                            <p id="monthly-hours-preview" style="margin-top:8px;font-weight:700;color:#1e3a8a;" role="status"></p>
                        </div>
                        <?php if (!empty($errors['availability'])): ?>
                            <span class="availability-error" role="alert"><?= htmlspecialchars($errors['availability'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <div class="availability-grid">
                            <?php foreach ($availabilityDays as $day): ?>
                                <?php $dayValues = $availabilityForm[$day] ?? []; ?>
                                <section class="availability-day" aria-label="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?> availability">
                                    <h3><?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?></h3>
                                    <?php foreach (['morning' => ['Morning', '08:00', '12:00'], 'afternoon' => ['Afternoon', '13:00', $day === 'Saturday' ? '12:00' : '17:00']] as $period => [$periodLabel, $defaultStart, $defaultEnd]): ?>
                                        <?php
                                            $slotValues = $dayValues[$period] ?? [];
                                            $slotEnabled = array_key_exists('enabled', $slotValues)
                                                ? !empty($slotValues['enabled'])
                                                : ($period === 'morning' && empty($availabilityForm));
                                            $slotUnavailable = $day === 'Saturday' && $period === 'afternoon';
                                        ?>
                                        <div class="availability-slot" data-day="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>" data-period="<?= $period ?>">
                                            <label>
                                                <input type="checkbox" name="availability[<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>][<?= $period ?>][enabled]" value="1" <?= $slotEnabled ? 'checked' : '' ?> <?= $slotUnavailable ? 'disabled' : '' ?> />
                                                <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </label>
                                            <div class="slot-conflict-container" id="conflict-tag-<?= strtolower($day) ?>-<?= $period ?>"></div>
                                            <input type="time" name="availability[<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>][<?= $period ?>][start]" value="<?= htmlspecialchars((string) ($slotValues['start'] ?? $defaultStart), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($day . ' ' . $periodLabel . ' start time', ENT_QUOTES, 'UTF-8') ?>" />
                                            <input type="time" name="availability[<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>][<?= $period ?>][end]" value="<?= htmlspecialchars((string) ($slotValues['end'] ?? $defaultEnd), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($day . ' ' . $periodLabel . ' end time', ENT_QUOTES, 'UTF-8') ?>" />
                                            <textarea name="availability[<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>][<?= $period ?>][notes]" maxlength="500" placeholder="Notes / reason for adjustment (if schedule differs from COR)" aria-label="<?= htmlspecialchars($day . ' ' . $periodLabel . ' note', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($slotValues['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                            <?php if (!empty($availabilitySlotErrors[$day][$period])): ?>
                                                <span class="availability-slot-error" role="alert"><?= htmlspecialchars($availabilitySlotErrors[$day][$period], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Special Skills or Talents -->
                    <div class="field">
                        <label class="field__label">Special Skills or Talents</label>
                        <div class="skills-hint">Select the skills that apply to you:</div>
                        <input type="hidden" name="skills" id="skills" value="<?= $val_skills ?>">
                        <div class="skill-tags" role="group" aria-label="Skills selector">
                            <?php
                            $available_skills = [
                                'Time Management',
                                'Teamwork',
                                'Leadership',
                                'Problem Solving',
                                'Adaptability',
                                'Attention to Detail',
                                'Multitasking',
                                'Organization'
                            ];
                            
                            // Parse currently selected skills
                            $selected_skills = [];
                            if ($val_skills !== '') {
                                $selected_skills = array_map('trim', explode(',', $val_skills));
                            }
                            
                            foreach ($available_skills as $skill):
                                $isActive = in_array($skill, $selected_skills, true);
                            ?>
                                <button 
                                    type="button" 
                                    class="skill-tag<?= $isActive ? ' skill-tag--active' : '' ?>" 
                                    data-skill="<?= htmlspecialchars($skill, ENT_QUOTES, 'UTF-8') ?>"
                                    aria-pressed="<?= $isActive ? 'true' : 'false' ?>"
                                >
                                    <?= htmlspecialchars($skill, ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Auto-recommendation banner -->
                    <div class="auto-rec" role="note" aria-label="Auto-recommendation notice">
                        <!-- Star/sparkle icon matching Figma imgIcon4 -->
                        <svg class="auto-rec__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M10 2L11.8 7.8L17.6 7.8L12.9 11.4L14.7 17.2L10 13.6L5.3 17.2L7.1 11.4L2.4 7.8L8.2 7.8L10 2Z" stroke="#ffb81c" stroke-width="1.5" stroke-linejoin="round" fill="#ffb81c"/>
                        </svg>
                        <p class="auto-rec__text">
                            <strong>Auto-recommendation:</strong> Based on your responses, you'll be recommended for roles that match your skills and availability!
                        </p>
                    </div>

                </div><!-- /form-fields -->

                <!-- ── ACTIONS ── -->
                <div class="actions" style="margin-top: 32px;">
                    <a href="register1.php" class="btn-back">
                        <svg class="btn-back__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M15.8333 10H4.16667M4.16667 10L10 15.8333M4.16667 10L10 4.16667" stroke="#003087" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Back
                    </a>

                    <button type="submit" class="btn-submit">
                        Next
                        <svg class="btn-submit__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M4.16667 10H15.8333M15.8333 10L10 4.16667M15.8333 10L10 15.8333" stroke="#003087" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

            </form>
        </section>

    </div><!-- /container -->

</main>

<script>
(function () {
    'use strict';

    /* ── Hamburger menu toggle ── */
    var hamburger = document.querySelector('.nav__hamburger');
    var navMenu   = document.getElementById('nav-menu');

    if (hamburger && navMenu) {
        hamburger.addEventListener('click', function () {
            var expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            navMenu.classList.toggle('nav__menu--open', !expanded);
        });

        document.addEventListener('click', function (e) {
            if (!hamburger.contains(e.target) && !navMenu.contains(e.target)) {
                hamburger.setAttribute('aria-expanded', 'false');
                navMenu.classList.remove('nav__menu--open');
            }
        });
    }

    /* ── Skill Tags Toggle ── */
    var skillInput = document.getElementById('skills');
    var skillTags  = document.querySelectorAll('.skill-tag');

    if (skillInput && skillTags.length > 0) {
        skillTags.forEach(function (tag) {
            tag.addEventListener('click', function () {
                var isPressed = this.getAttribute('aria-pressed') === 'true';
                this.setAttribute('aria-pressed', String(!isPressed));
                this.classList.toggle('skill-tag--active', !isPressed);

                // Update hidden input value
                var selected = [];
                document.querySelectorAll('.skill-tag--active').forEach(function (activeTag) {
                    selected.push(activeTag.getAttribute('data-skill'));
                });
                skillInput.value = selected.join(', ');
            });
        });
    }

})();
</script>

<script>
(function () {
    var preview = document.getElementById('monthly-hours-preview');
    var availabilityForm = document.querySelector('form[method="POST"]');
    var units = <?= (int) ($step2['units'] ?? 0) ?>;
    var minimum = units <= 14 ? 100 : (units <= 18 ? 75 : 50);

    function updatePreview() {
        if (!preview) return;
        var total = 0;
        document.querySelectorAll('.availability-slot').forEach(function (slot) {
            var checkbox = slot.querySelector('input[type="checkbox"]');
            var times = slot.querySelectorAll('input[type="time"]');
            if (!checkbox || !checkbox.checked || times.length !== 2) return;
            var start = times[0].value.split(':');
            var end = times[1].value.split(':');
            if (start.length !== 2 || end.length !== 2) return;
            var hours = ((parseInt(end[0], 10) * 60 + parseInt(end[1], 10)) -
                (parseInt(start[0], 10) * 60 + parseInt(start[1], 10))) / 60;
            if (hours > 0) total += hours;
        });
        var monthly = total * 4.33;
        preview.textContent = 'Possible duty: ' + monthly.toFixed(1) + ' hours/month. Minimum for ' + units + ' units: ' + minimum + ' hours/month' + (monthly < minimum ? ' - warning: below minimum.' : ' - meets minimum.');
        preview.style.color = monthly < minimum ? '#991b1b' : '#166534';
    }

    document.querySelectorAll('.availability-slot input').forEach(function (input) {
        input.addEventListener('input', updatePreview);
        input.addEventListener('change', updatePreview);
    });
    updatePreview();

    if (availabilityForm) {
        availabilityForm.addEventListener('submit', function (event) {
            var firstInvalidSlot = null;

            document.querySelectorAll('.availability-slot').forEach(function (slot) {
                var checkbox = slot.querySelector('input[type="checkbox"]');
                var times = slot.querySelectorAll('input[type="time"]');
                var clientError = slot.querySelector('.availability-slot-error[data-client-error="true"]');

                if (clientError) {
                    clientError.remove();
                }
                if (!checkbox || !checkbox.checked || times.length !== 2) return;

                var startParts = times[0].value.split(':');
                var endParts = times[1].value.split(':');
                if (startParts.length !== 2 || endParts.length !== 2) return;

                var startMinutes = parseInt(startParts[0], 10) * 60 + parseInt(startParts[1], 10);
                var endMinutes = parseInt(endParts[0], 10) * 60 + parseInt(endParts[1], 10);
                var durationHours = (endMinutes - startMinutes) / 60;

                if (durationHours < 2) {
                    var message = document.createElement('span');
                    message.className = 'availability-slot-error';
                    message.dataset.clientError = 'true';
                    message.setAttribute('role', 'alert');
                    message.textContent = 'This selected slot is only ' + (durationHours > 0 ? durationHours : 0) + ' hour(s). Minimum duty time is 2 hours.';
                    slot.appendChild(message);
                    if (!firstInvalidSlot) firstInvalidSlot = slot;
                }
            });

            if (firstInvalidSlot) {
                event.preventDefault();
                firstInvalidSlot.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }

    /* ── NUIS COR Scanner Integration ── */
    var corDropzone = document.getElementById('cor-dropzone');
    var corFileInput = document.getElementById('cor-file-input');
    var corLoading = document.getElementById('cor-loading');
    var corSuccessPanel = document.getElementById('cor-success-panel');
    var corStudentBadge = document.getElementById('cor-student-badge');
    var corClassCount = document.getElementById('cor-class-count');
    var corChipsList = document.getElementById('cor-chips-list');
    var corAdjustmentNotice = document.getElementById('cor-adjustment-notice');

    var corBaseline = null; // Stores parsed baseline { Day: { morning: { enabled, start, end }, afternoon: { ... } } }
    var corClassSchedules = []; // Stores all detected classes

    function formatTime12(timeStr) {
        if (!timeStr) return '';
        var parts = timeStr.split(':');
        if (parts.length < 2) return timeStr;
        var h = parseInt(parts[0], 10);
        var m = parts[1];
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        if (h === 0) h = 12;
        return (h < 10 ? '0' + h : h) + ':' + m + ' ' + ampm;
    }

    /**
     * Dynamically recalculate slot conflict against class schedules in real-time
     */
    function updateSlotConflictBadge(slot) {
        if (!slot) return;
        var day = slot.getAttribute('data-day');
        var period = slot.getAttribute('data-period');
        var conflictContainer = document.getElementById('conflict-tag-' + day.toLowerCase() + '-' + period);
        if (!conflictContainer) return;

        // If no COR has been uploaded yet, don't show badges
        if (!corClassSchedules || corClassSchedules.length === 0) {
            conflictContainer.innerHTML = '';
            return;
        }

        var times = slot.querySelectorAll('input[type="time"]');
        if (times.length < 2) return;
        var startTime = times[0].value;
        var endTime = times[1].value;

        if (!window.NuisCorParser || !window.NuisCorParser.checkSlotConflict) return;

        var check = window.NuisCorParser.checkSlotConflict(day, startTime, endTime, corClassSchedules);
        if (!check.valid) {
            conflictContainer.innerHTML = '<span class="slot-conflict-badge">⚠️ ' + check.message + '</span>';
            return;
        }

        if (check.isFree) {
            // TURNS GREEN WHEN NO OVERLAP!
            if (check.hasMinDuration) {
                conflictContainer.innerHTML = '<span class="slot-free-badge">✓ Free (No class ' + formatTime12(startTime) + ' - ' + formatTime12(endTime) + ')</span>';
            } else {
                conflictContainer.innerHTML = '<span class="slot-conflict-badge" style="background:#fef3c7;color:#92400e;border-color:#fde68a;">⚠️ ' + check.durationHours.toFixed(1) + ' hrs (Min. 2 hrs required)</span>';
            }
        } else {
            // TURNS RED IF OVERLAPS WITH A CLASS
            conflictContainer.innerHTML = '<span class="slot-conflict-badge">⚠️ Class: ' + check.conflicts.join(', ') + '</span>';
        }
    }

    if (corDropzone && corFileInput) {
        corDropzone.addEventListener('click', function () {
            corFileInput.click();
        });
        corDropzone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                corFileInput.click();
            }
        });

        corDropzone.addEventListener('dragover', function (e) {
            e.preventDefault();
            this.classList.add('cor-dropzone--dragover');
        });
        corDropzone.addEventListener('dragleave', function () {
            this.classList.remove('cor-dropzone--dragover');
        });
        corDropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            this.classList.remove('cor-dropzone--dragover');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                handleCorFile(e.dataTransfer.files[0]);
            }
        });

        corFileInput.addEventListener('change', function () {
            if (this.files && this.files.length > 0) {
                handleCorFile(this.files[0]);
            }
        });
    }

    async function handleCorFile(file) {
        if (!file) return;
        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            alert('Please select a valid PDF file of your NUIS Lipa Certificate of Registration.');
            return;
        }

        corLoading.style.display = 'flex';
        corSuccessPanel.style.display = 'none';

        try {
            // 1. Upload to server session for Step 4 preservation
            var uploadFormData = new FormData();
            uploadFormData.append('cor_file', file);
            fetch('api/upload_cor.php', {
                method: 'POST',
                body: uploadFormData
            }).catch(function (err) {
                console.warn('Background COR session save failed:', err);
            });

            // 2. Parse client-side via NuisCorParser
            if (!window.NuisCorParser) {
                throw new Error('COR Parser script not loaded.');
            }

            var result = await window.NuisCorParser.parseNuisCorPdf(file);
            applyCorResult(result, file.name);

        } catch (err) {
            console.error('Failed to parse COR PDF:', err);
            alert('Could not parse COR schedule: ' + err.message + '\nPlease verify you uploaded an official NUIS Lipa Certificate of Registration PDF.');
        } finally {
            corLoading.style.display = 'none';
        }
    }

    function applyCorResult(result, filename) {
        corBaseline = {};
        corClassSchedules = result.classSchedules || [];

        // Display student badge
        var studentText = '';
        if (result.studentName) studentText += result.studentName;
        if (result.studentId) studentText += (studentText ? ' (' : '') + result.studentId + (studentText ? ')' : '');
        corStudentBadge.textContent = studentText || filename;

        // Render schedule chips
        corClassCount.textContent = String(corClassSchedules.length);
        corChipsList.innerHTML = '';
        corClassSchedules.forEach(function (c) {
            var chip = document.createElement('div');
            chip.className = 'cor-chip';
            chip.innerHTML = '<strong>' + (c.subjectCode || c.dayCode) + '</strong>: ' + c.days.join('/') + ' ' + c.displayTime;
            corChipsList.appendChild(chip);
        });

        // Apply to each day and period in the availability grid
        var days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        days.forEach(function (day) {
            corBaseline[day] = {};
            ['morning', 'afternoon'].forEach(function (period) {
                var availData = result.availability[day] ? result.availability[day][period] : {
                    free: day !== 'Saturday',
                    start: period === 'morning' ? '08:00' : (day === 'Saturday' ? '12:00' : '13:00'),
                    end: period === 'morning' ? '12:00' : (day === 'Saturday' ? '12:00' : '17:00'),
                    conflicts: []
                };
                var isFree = availData.free;

                var slot = document.querySelector('.availability-slot[data-day="' + day + '"][data-period="' + period + '"]');
                if (slot) {
                    var cb = slot.querySelector('input[type="checkbox"]');
                    var times = slot.querySelectorAll('input[type="time"]');

                    if (times.length >= 2) {
                        if (availData.start) times[0].value = availData.start;
                        if (availData.end) times[1].value = availData.end;
                    }

                    if (cb) {
                        cb.checked = isFree;
                    }

                    // Save baseline
                    corBaseline[day][period] = {
                        enabled: isFree,
                        start: times.length >= 2 ? times[0].value : availData.start,
                        end: times.length >= 2 ? times[1].value : availData.end
                    };

                    updateSlotConflictBadge(slot);
                }
            });
        });

        corSuccessPanel.style.display = 'block';
        updatePreview();
        checkModificationsAgainstBaseline();
    }

    function checkModificationsAgainstBaseline() {
        if (!corBaseline) return;
        var hasDifferences = false;

        var days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        days.forEach(function (day) {
            ['morning', 'afternoon'].forEach(function (period) {
                var slot = document.querySelector('.availability-slot[data-day="' + day + '"][data-period="' + period + '"]');
                if (!slot) return;
                var cb = slot.querySelector('input[type="checkbox"]');
                var times = slot.querySelectorAll('input[type="time"]');
                var base = corBaseline[day] ? corBaseline[day][period] : null;

                if (cb && times.length >= 2 && base) {
                    var isModified = (cb.checked !== base.enabled) ||
                                     (times[0].value !== base.start) ||
                                     (times[1].value !== base.end);
                    if (isModified) {
                        hasDifferences = true;
                        slot.classList.add('availability-slot--modified');
                    } else {
                        slot.classList.remove('availability-slot--modified');
                    }
                }
            });
        });

        if (corAdjustmentNotice) {
            corAdjustmentNotice.style.display = hasDifferences ? 'flex' : 'none';
        }
    }

    // Attach listeners to recalculate conflicts and modifications on time or checkbox change
    document.querySelectorAll('.availability-slot').forEach(function (slot) {
        var cb = slot.querySelector('input[type="checkbox"]');
        var times = slot.querySelectorAll('input[type="time"]');

        if (cb) {
            cb.addEventListener('change', function () {
                updateSlotConflictBadge(slot);
                checkModificationsAgainstBaseline();
            });
        }

        times.forEach(function (t) {
            t.addEventListener('input', function () {
                updateSlotConflictBadge(slot);
                checkModificationsAgainstBaseline();
            });
            t.addEventListener('change', function () {
                updateSlotConflictBadge(slot);
                checkModificationsAgainstBaseline();
            });
        });
    });

}());
</script>

</body>
</html>