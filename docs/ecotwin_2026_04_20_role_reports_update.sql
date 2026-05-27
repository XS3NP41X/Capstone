-- EcoTwin migration/export
-- Date: 2026-04-20
-- Purpose:
-- 1. Remove the student role and migrate existing student users to researcher
-- 2. Add session/activity log tables used by the new admin reports
-- 3. Clean up obsolete automation settings removed from the UI

START TRANSACTION;

ALTER TABLE `users`
    MODIFY COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'researcher';

UPDATE `users`
SET `role` = 'researcher'
WHERE `role` = 'student';

CREATE TABLE IF NOT EXISTS `session_log` (
    `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `detail` VARCHAR(200) NULL,
    `logged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`log_id`),
    KEY `idx_session_log_user_time` (`user_id`, `logged_at`),
    KEY `idx_session_log_action_time` (`action`, `logged_at`),
    CONSTRAINT `fk_session_log_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_log` (
    `activity_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL,
    `category` VARCHAR(50) NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `detail` TEXT NULL,
    `target_type` VARCHAR(50) NULL,
    `target_id` BIGINT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`activity_id`),
    KEY `idx_activity_log_category_time` (`category`, `created_at`),
    KEY `idx_activity_log_user_time` (`user_id`, `created_at`),
    CONSTRAINT `fk_activity_log_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM `system_settings`
WHERE `setting_key` = 'auto_ph_correction';

DELETE FROM `system_config`
WHERE `config_key` = 'auto_ph_correction';

COMMIT;
