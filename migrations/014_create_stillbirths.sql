-- =====================================================================
-- Migration 014: `stillbirths` – Totgeburten je Kuh
-- ---------------------------------------------------------------------
--  Betriebsbezogen (farm_id). Totgeburten bekommen KEINE Ohrmarke und
--  stehen daher NICHT in der Tabelle `cattle`. Sie werden der Mutter-Kuh
--  zugeordnet (mother_cattle_id) und auf deren Profilseite zusammen mit
--  den lebenden Kaelbern in der Kalbungs-Liste angezeigt. Eine Totgeburt
--  zaehlt wie eine Kalbung (z. B. fuer die Kennzahl "Milchkuehe").
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `stillbirths` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`          INT NOT NULL,
  `mother_cattle_id` INT NOT NULL,                 -- Mutter-Kuh (FK cattle)
  `birth_date`       DATE NOT NULL,                -- Datum der Totgeburt
  `sex`              ENUM('maennlich','weiblich','unbekannt') DEFAULT 'unbekannt',
  `comment`          TEXT DEFAULT NULL,            -- optionale Bemerkung
  `created_by`       INT DEFAULT NULL,             -- Benutzer, der die Totgeburt erfasst hat
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_stillbirths_farm_mother` (`farm_id`,`mother_cattle_id`),
  KEY `idx_stillbirths_date`        (`farm_id`,`birth_date`),
  CONSTRAINT `fk_stillbirths_farm`   FOREIGN KEY (`farm_id`)          REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_stillbirths_mother` FOREIGN KEY (`mother_cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stillbirths_user`   FOREIGN KEY (`created_by`)       REFERENCES `users`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
