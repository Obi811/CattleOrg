-- =====================================================================
-- Migration 002: `users` (mit Audit-Feldern) und `farm_users` (n:m + Rolle)
-- =====================================================================
SET NAMES utf8mb4;

-- users: is_admin = plattformweiter System-Admin
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(50) NOT NULL,
  `email`         VARCHAR(255) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_admin`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login`    TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- farm_users: Zuordnung User <-> Betrieb mit Rolle
CREATE TABLE IF NOT EXISTS `farm_users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`    INT NOT NULL,
  `user_id`    INT NOT NULL,
  `role`       ENUM('owner','manager','worker','viewer') NOT NULL DEFAULT 'worker',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_farm_user` (`farm_id`,`user_id`),
  KEY `idx_farm_users_user` (`user_id`),
  CONSTRAINT `fk_farm_users_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_farm_users_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
