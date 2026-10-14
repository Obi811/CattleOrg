-- =====================================================================
-- Migration 009: Geraete-Tokens (Push) und Push-Log (Doppel-Versand-Schutz)
-- ---------------------------------------------------------------------
--  device_tokens : registrierte Endgeraete (derzeit iOS/APNs) je Benutzer
--                  und optional je Betrieb. Einzelne Mitteilungsarten
--                  lassen sich pro Geraet abschalten.
--  push_log      : protokolliert bereits gesendete Mitteilungen, damit
--                  dieselbe Mitteilung nicht doppelt zugestellt wird.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT NOT NULL,
  `farm_id`       INT DEFAULT NULL,                       -- optionaler Betriebs-Kontext (NULL = alle Betriebe des Users)
  `platform`      ENUM('ios','android') NOT NULL DEFAULT 'ios',
  `token`         VARCHAR(255) NOT NULL,                  -- APNs Device-Token (Hex) bzw. FCM-Token
  `environment`   ENUM('sandbox','production') NOT NULL DEFAULT 'production',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  -- Opt-out je Mitteilungsart (1 = aktiv)
  `push_weiterbehandlung` TINYINT(1) NOT NULL DEFAULT 1,
  `push_wartezeit`        TINYINT(1) NOT NULL DEFAULT 1,  -- Hemmstoffprobe faellig (Wartezeit-Ende)
  `push_traechtigkeit`    TINYINT(1) NOT NULL DEFAULT 1,
  `push_hemmstoff`        TINYINT(1) NOT NULL DEFAULT 1,  -- Hemmstoffprobe wartet auf Ergebnis
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_used_at`  DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_device_token` (`token`),
  KEY `idx_device_user`   (`user_id`),
  KEY `idx_device_farm`   (`farm_id`),
  KEY `idx_device_active` (`is_active`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_device_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `push_log` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`           INT NOT NULL,
  `farm_id`           INT NOT NULL,
  `notification_type` VARCHAR(40) NOT NULL,               -- weiterbehandlung | wartezeit | traechtigkeit | hemmstoff
  `ref_key`           VARCHAR(191) NOT NULL,              -- eindeutiger Bezug (z. B. Datum oder cattle:ID:datum)
  `cattle_id`         INT DEFAULT NULL,
  `status`            VARCHAR(40) NOT NULL DEFAULT 'sent',-- sent | dry-run | failed
  `sent_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- verhindert Doppel-Versand: pro Benutzer, Art und Bezug nur einmal
  UNIQUE KEY `uq_push_dedupe` (`user_id`,`notification_type`,`ref_key`),
  KEY `idx_push_farm` (`farm_id`),
  KEY `idx_push_sent` (`sent_at`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_push_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
