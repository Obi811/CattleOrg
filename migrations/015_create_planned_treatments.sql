-- =====================================================================
-- Migration 015: `planned_treatments` – geplante (Nach-)Behandlungen
-- ---------------------------------------------------------------------
--  Betriebsbezogen (farm_id). Eine geplante Behandlung wird fuer ein Tier
--  auf ein zukuenftiges (oder heutiges) Datum angelegt und erscheint an
--  diesem Tag (sowie ueberfaellig) in den Aufgaben und im Kalender. Wird
--  die geplante Behandlung als "erledigt" markiert, entsteht daraus eine
--  echte Behandlung in `treatments` (Verweis ueber treatment_id).
--
--  Die Spalten lehnen sich bewusst an `treatments` an (medication, dosage,
--  type, notes), ergaenzt um planned_date, status und die Verknuepfung zur
--  erzeugten Behandlung. Idempotent (CREATE TABLE IF NOT EXISTS).
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `planned_treatments` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`       INT NOT NULL,
  `cattle_id`     INT NOT NULL,
  `planned_date`  DATE NOT NULL,                    -- geplanter Behandlungstag
  `medication`    VARCHAR(255) NOT NULL,            -- Name (global), siehe medications
  `dosage`        VARCHAR(100) DEFAULT NULL,
  `type`          ENUM('Injektion','drenchen','Eutertube','Trockenstellen','Hemmstoff Probe','Klauenpflege','Sonstige')
                    NOT NULL DEFAULT 'Sonstige',
  `notes`         TEXT DEFAULT NULL,
  `status`        ENUM('geplant','erledigt','verworfen') NOT NULL DEFAULT 'geplant',
  `treatment_id`  INT DEFAULT NULL,                 -- erzeugte Behandlung (bei status=erledigt)
  `created_by`    INT DEFAULT NULL,                 -- Benutzer, der geplant hat
  `done_by`       INT DEFAULT NULL,                 -- Benutzer, der erledigt/verworfen hat
  `done_at`       TIMESTAMP NULL DEFAULT NULL,      -- Zeitpunkt erledigt/verworfen
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_planned_farm_cattle` (`farm_id`,`cattle_id`),
  KEY `idx_planned_farm_date`   (`farm_id`,`planned_date`),
  KEY `idx_planned_farm_status` (`farm_id`,`status`),
  CONSTRAINT `fk_planned_farm`      FOREIGN KEY (`farm_id`)      REFERENCES `farms`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_planned_cattle`    FOREIGN KEY (`cattle_id`)    REFERENCES `cattle`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_planned_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `treatments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planned_createdby` FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`)      ON DELETE SET NULL,
  CONSTRAINT `fk_planned_doneby`    FOREIGN KEY (`done_by`)      REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
