-- =====================================================================
-- Migration 006: `permissions` – granulare Overrides je farm_user
--   granted=1 -> Recht zusaetzlich gewaehren
--   granted=0 -> Rollen-Default-Recht entziehen
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `farm_user_id` INT NOT NULL,
  `permission`   VARCHAR(50) NOT NULL,
  `granted`      TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_perm_user_permission` (`farm_user_id`,`permission`),
  CONSTRAINT `fk_perm_farm_user` FOREIGN KEY (`farm_user_id`) REFERENCES `farm_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
