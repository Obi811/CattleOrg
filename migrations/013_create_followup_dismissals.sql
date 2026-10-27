-- =====================================================================
-- Migration 013: `followup_dismissals` – "Nachbehandlung nicht noetig"
-- ---------------------------------------------------------------------
--  Merkt sich pro Betrieb, dass fuer eine gestrige Behandlung heute
--  KEINE Nachbehandlung noetig ist ("Nicht noetig"). So verschwindet
--  der Vorschlag aus der Aufgabenliste, ohne eine Behandlung anzulegen.
--  Der Eintrag ist tagesbezogen (dismiss_date), damit der Vorschlag an
--  einem Folgetag erneut erscheinen koennte, falls erneut relevant.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `followup_dismissals` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`       INT NOT NULL,
  `cattle_id`     INT NOT NULL,
  `treatment_id`  INT NOT NULL,                   -- die gestrige Behandlung, deren Vorschlag abgelehnt wurde
  `dismiss_date`  DATE NOT NULL,                  -- Tag, fuer den "nicht noetig" gilt (i. d. R. heute)
  `dismissed_by`  INT DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_followup_dismiss` (`farm_id`,`treatment_id`,`dismiss_date`),
  KEY `idx_followup_farm_date` (`farm_id`,`dismiss_date`),
  CONSTRAINT `fk_followup_farm`      FOREIGN KEY (`farm_id`)      REFERENCES `farms`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_followup_cattle`    FOREIGN KEY (`cattle_id`)    REFERENCES `cattle`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_followup_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `treatments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_followup_user`      FOREIGN KEY (`dismissed_by`) REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
