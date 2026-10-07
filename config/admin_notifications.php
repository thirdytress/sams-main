<?php
declare(strict_types=1);

function sams_admin_application_notifications_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_application_notifications (
            notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            application_id INT UNSIGNED NOT NULL,
            admin_user_id INT UNSIGNED NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_admin_application_notification (application_id, admin_user_id),
            KEY idx_admin_application_unread (admin_user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_application_notification_state (
            admin_user_id INT UNSIGNED PRIMARY KEY,
            initialized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function sams_admin_application_notifications_sync(PDO $pdo, int $adminUserId): void
{
    if ($adminUserId <= 0) {
        return;
    }

    sams_admin_application_notifications_ensure_schema($pdo);

    $state = $pdo->prepare(
        'SELECT admin_user_id FROM admin_application_notification_state WHERE admin_user_id = :admin_user_id'
    );
    $state->execute(['admin_user_id' => $adminUserId]);
    if (!$state->fetchColumn()) {
        $seed = $pdo->prepare(
            "INSERT IGNORE INTO admin_application_notifications
                (application_id, admin_user_id, is_read, read_at)
             SELECT application_id, :admin_user_id, 1, NOW()
             FROM applications"
        );
        $seed->execute(['admin_user_id' => $adminUserId]);
        $initialize = $pdo->prepare(
            'INSERT INTO admin_application_notification_state (admin_user_id) VALUES (:admin_user_id)'
        );
        $initialize->execute(['admin_user_id' => $adminUserId]);
        return;
    }

    $newApplications = $pdo->prepare(
        "INSERT INTO admin_application_notifications
            (application_id, admin_user_id, is_read)
         SELECT a.application_id, :admin_user_id, 0
         FROM applications a
         LEFT JOIN admin_application_notifications n
           ON n.application_id = a.application_id
          AND n.admin_user_id = :join_admin_user_id
         WHERE n.notification_id IS NULL"
    );
    $newApplications->execute([
        'admin_user_id' => $adminUserId,
        'join_admin_user_id' => $adminUserId,
    ]);
}
