ALTER TABLE duty_schedules
    ADD COLUMN IF NOT EXISTS scheduled_date DATE NULL AFTER end_time;

CREATE TABLE IF NOT EXISTS temporary_duty_requests (
    request_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    student_id INT NOT NULL,
    term_id INT NOT NULL,
    duty_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    office_name VARCHAR(150) NOT NULL,
    reason TEXT NOT NULL,
    proof_original_name VARCHAR(255) NOT NULL,
    proof_stored_name VARCHAR(255) NOT NULL,
    proof_path VARCHAR(500) NOT NULL,
    proof_mime VARCHAR(100) NOT NULL,
    proof_size INT NOT NULL,
    status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    review_note VARCHAR(500) NULL,
    duty_id INT NULL,
    INDEX idx_temporary_duty_status_date (status, duty_date),
    INDEX idx_temporary_duty_student (student_id, duty_date),
    UNIQUE KEY uq_temporary_duty_student_date (student_id, duty_date, status)
);

ALTER TABLE temporary_duty_requests
    ADD COLUMN IF NOT EXISTS duty_date DATE NULL AFTER term_id,
    ADD COLUMN IF NOT EXISTS office_name VARCHAR(150) NULL AFTER end_time,
    ADD COLUMN IF NOT EXISTS proof_stored_name VARCHAR(255) NULL AFTER proof_original_name,
    ADD COLUMN IF NOT EXISTS proof_path VARCHAR(500) NULL AFTER proof_stored_name,
    ADD COLUMN IF NOT EXISTS proof_mime VARCHAR(100) NULL AFTER proof_path,
    ADD COLUMN IF NOT EXISTS proof_size INT NULL AFTER proof_mime,
    ADD COLUMN IF NOT EXISTS duty_id INT NULL AFTER review_note;

ALTER TABLE temporary_duty_requests
    MODIFY request_date DATE NULL,
    MODIFY proof_file_path VARCHAR(255) NULL;

UPDATE temporary_duty_requests
SET duty_date = request_date
WHERE duty_date IS NULL AND request_date IS NOT NULL;

UPDATE temporary_duty_requests
SET proof_path = proof_file_path
WHERE (proof_path IS NULL OR proof_path = '')
  AND proof_file_path IS NOT NULL;

CREATE TABLE IF NOT EXISTS class_schedules (
    class_schedule_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    term_id INT NOT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    subject_code VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_class_schedule_application (application_id, term_id, day_of_week)
);
