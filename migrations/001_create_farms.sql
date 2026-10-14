-- =====================================================================
-- Migration 001: Tabelle `farms` (Betriebsstammdaten)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `farms` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(100) NOT NULL,
  `betriebsnummer` VARCHAR(50) DEFAULT NULL,
  `address`       TEXT DEFAULT NULL,
  `is_demo`       TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_farms_betriebsnummer` (`betriebsnummer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
