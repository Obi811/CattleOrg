-- =====================================================================
-- Migration 005: `stalls` – Staelle pro Betrieb (ersetzt hardcodierte Liste)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `stalls` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`   INT NOT NULL,
  `name`      VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_stalls_farm_name` (`farm_id`,`name`),
  CONSTRAINT `fk_stalls_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
