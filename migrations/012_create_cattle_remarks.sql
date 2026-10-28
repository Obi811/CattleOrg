-- =====================================================================
-- Migration 012: `cattle_remarks` – Freitext-Bemerkungen je Tier
-- ---------------------------------------------------------------------
--  Betriebsbezogen (farm_id). Erlaubt freie Notizen mit Datum zu einem
--  Tier (z. B. Beobachtungen, Auffaelligkeiten). Newest-first-Anzeige
--  auf der Tier-Profilseite.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `cattle_remarks` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`     INT NOT NULL,
  `cattle_id`   INT NOT NULL,
  `remark_date` DATE NOT NULL,
  `body`        TEXT NOT NULL,
  `created_by`  INT DEFAULT NULL,                 -- Benutzer, der die Bemerkung angelegt hat
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_remarks_farm_cattle` (`farm_id`,`cattle_id`),
  KEY `idx_remarks_date`        (`farm_id`,`remark_date`),
  CONSTRAINT `fk_remarks_farm`    FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_remarks_cattle`  FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_remarks_user`    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
