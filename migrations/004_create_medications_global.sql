-- =====================================================================
-- Migration 004: `medications` – GLOBAL (kein farm_id), ein UNIQUE-Index
-- Behebt die 4 redundanten UNIQUE-Indizes der Altanwendung.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `medications` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(100) NOT NULL,
  `active_substance` VARCHAR(100) DEFAULT NULL,
  `dosage`          VARCHAR(100) DEFAULT NULL,
  `wz_milk`         VARCHAR(100) DEFAULT NULL,    -- Wartezeit Milch (Tage)
  `wz_fleisch`      VARCHAR(100) DEFAULT NULL,    -- Wartezeit Fleisch (Tage)
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_medications_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Verknuepfung treatments.medication -> medications.name (global, ON UPDATE CASCADE)
ALTER TABLE `treatments`
  ADD CONSTRAINT `fk_treatments_medication`
  FOREIGN KEY (`medication`) REFERENCES `medications`(`name`) ON UPDATE CASCADE;
