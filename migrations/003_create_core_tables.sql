-- =====================================================================
-- Migration 003: Fachliche Kerntabellen MIT farm_id (Mandantentrennung)
--   cattle, treatments, inseminations, pregnancy_checks,
--   stall_history, hemmstoff_probes
--   medications bleibt GLOBAL (siehe Migration 005)
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- cattle
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cattle` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`    INT NOT NULL,
  `ear_tag`    VARCHAR(50) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `verendung`  DATE DEFAULT NULL,
  `status`     ENUM('Aktiv','Inaktiv') NOT NULL DEFAULT 'Aktiv',
  `mutter`     VARCHAR(50) DEFAULT NULL,            -- Ohrmarke des Muttertiers (betriebsintern)
  `mutter_id`  INT DEFAULT NULL,                    -- optionale FK-Referenz auf cattle.id
  `zugang`     DATE DEFAULT NULL,
  `abgang`     DATE DEFAULT NULL,
  `rasse`      ENUM(
                 '1 SBT Holstein-Sbt','2 RBT Holstein-Rbt','9 RDN Doppelnutzung Rotbunt',
                 '11 FL Fleckvieh','18 BVA Braunvieh alter Zuchtrichtung','90 SON Sonstige Rassen',
                 '97 XFF Kreuzung Fleischrind x Fleischrind','98 XFM Kreuzung Fleischrind x Milchrind',
                 '99 XMM Kreuzung Milchrind x Milchrind'
               ) DEFAULT NULL,
  `geschlecht` ENUM('M','W') DEFAULT NULL,
  `mehrling`   ENUM('Ja','Nein') NOT NULL DEFAULT 'Nein',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_cattle_farm_eartag` (`farm_id`,`ear_tag`),
  KEY `idx_cattle_farm_status` (`farm_id`,`status`),
  KEY `idx_cattle_farm_birth`  (`farm_id`,`birth_date`),
  KEY `idx_cattle_mutter`      (`farm_id`,`mutter`),
  KEY `idx_cattle_mutter_id`   (`mutter_id`),
  CONSTRAINT `fk_cattle_farm`   FOREIGN KEY (`farm_id`)  REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_cattle_mutter` FOREIGN KEY (`mutter_id`) REFERENCES `cattle`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- treatments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `treatments` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`        INT NOT NULL,
  `cattle_id`      INT NOT NULL,
  `treatment_date` DATE DEFAULT NULL,
  `medication`     VARCHAR(255) NOT NULL,            -- Name (global), siehe medications
  `dosage`         VARCHAR(100) DEFAULT NULL,
  `type`           ENUM('Injektion','drenchen','Eutertube','Trockenstellen','Hemmstoff Probe','Klauenpflege','Sonstige')
                     NOT NULL DEFAULT 'Sonstige',
  `notes`          TEXT DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_treatments_farm_cattle` (`farm_id`,`cattle_id`),
  KEY `idx_treatments_farm_date`   (`farm_id`,`treatment_date`),
  CONSTRAINT `fk_treatments_farm`   FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_treatments_cattle` FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- inseminations
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inseminations` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`    INT NOT NULL,
  `cattle_id`  INT NOT NULL,
  `date`       DATE NOT NULL,
  `bull_name`  VARCHAR(255) DEFAULT NULL,
  `type`       ENUM('besamt','gestiert') NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_insem_farm_cattle` (`farm_id`,`cattle_id`),
  KEY `idx_insem_farm_date`   (`farm_id`,`date`),
  CONSTRAINT `fk_insem_farm`   FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_insem_cattle` FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- pregnancy_checks
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pregnancy_checks` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`    INT NOT NULL,
  `cattle_id`  INT NOT NULL,
  `check_date` DATE NOT NULL,
  `result`     ENUM('+','-','?') NOT NULL,
  `comment`    VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_preg_farm_cattle` (`farm_id`,`cattle_id`),
  CONSTRAINT `fk_preg_farm`   FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_preg_cattle` FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- stall_history
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stall_history` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`    INT NOT NULL,
  `cattle_id`  INT NOT NULL,
  `stall_name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date`   DATE DEFAULT NULL,
  KEY `idx_stallhist_farm_cattle` (`farm_id`,`cattle_id`),
  KEY `idx_stallhist_open` (`farm_id`,`cattle_id`,`end_date`),
  CONSTRAINT `fk_stallhist_farm`   FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_stallhist_cattle` FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- hemmstoff_probes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hemmstoff_probes` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`    INT NOT NULL,
  `cattle_id`  INT NOT NULL,
  `probe_date` DATE NOT NULL,
  `status`     ENUM('frei','nicht frei','versendet') NOT NULL,
  `nummer`     VARCHAR(50) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_hemm_farm_cattle` (`farm_id`,`cattle_id`),
  CONSTRAINT `fk_hemm_farm`   FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_hemm_cattle` FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
