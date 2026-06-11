-- EcoTwin migration
-- Date: 2026-06-01
-- Purpose: Support pending account requests for admin approval.

ALTER TABLE `users`
    MODIFY COLUMN `status` ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'pending';

UPDATE `users`
SET `status` = 'pending'
WHERE `status` = '';
