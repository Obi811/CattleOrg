-- ==========================================================
-- CattleOrg – Komplettes Installations-SQL (phpMyAdmin Import)
-- Reihenfolge: 001 bis 010. Vor dem Import die DB auswaehlen.
-- ==========================================================

-- ============ 001_create_farms.sql ============
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


-- ============ 002_create_users_and_farm_users.sql ============
-- =====================================================================
-- Migration 002: `users` (mit Audit-Feldern) und `farm_users` (n:m + Rolle)
-- =====================================================================
SET NAMES utf8mb4;

-- users: is_admin = plattformweiter System-Admin
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(50) NOT NULL,
  `email`         VARCHAR(255) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_admin`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login`    TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- farm_users: Zuordnung User <-> Betrieb mit Rolle
CREATE TABLE IF NOT EXISTS `farm_users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`    INT NOT NULL,
  `user_id`    INT NOT NULL,
  `role`       ENUM('owner','manager','worker','viewer') NOT NULL DEFAULT 'worker',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_farm_user` (`farm_id`,`user_id`),
  KEY `idx_farm_users_user` (`user_id`),
  CONSTRAINT `fk_farm_users_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_farm_users_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============ 003_create_core_tables.sql ============
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
  `abgang_grund` VARCHAR(255) DEFAULT NULL,           -- Grund/Bemerkung bei Abgang/Verendung (Migration 010)
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
  `result_at`  DATETIME DEFAULT NULL,                  -- Zeitpunkt der Ergebniserfassung (Migration 011)
  `result_by`  INT      DEFAULT NULL,                  -- Benutzer, der das Ergebnis erfasst hat (Migration 011)
  `sent_at`    DATETIME DEFAULT NULL,                  -- Zeitpunkt des Wegschickens (Migration 011)
  `sent_by`    INT      DEFAULT NULL,                  -- Benutzer, der den Versand erfasst hat (Migration 011)
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_hemm_farm_cattle` (`farm_id`,`cattle_id`),
  KEY `idx_hemm_result_at` (`farm_id`,`result_at`),
  KEY `idx_hemm_sent_at`   (`farm_id`,`sent_at`),
  CONSTRAINT `fk_hemm_farm`   FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_hemm_cattle` FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hemm_result_by` FOREIGN KEY (`result_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hemm_sent_by`   FOREIGN KEY (`sent_by`)   REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============ 004_create_medications_global.sql ============
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


-- ============ 005_create_stalls.sql ============
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


-- ============ 006_create_permissions.sql ============
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


-- ============ 007_create_api_tokens.sql ============
-- =====================================================================
-- Migration 007: `api_tokens` – Token-Authentifizierung fuer Mobile-App
-- Es wird nur der SHA-256-Hash des Tokens gespeichert.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT NOT NULL,
  `token_hash`   VARCHAR(255) NOT NULL,
  `device_name`  VARCHAR(100) DEFAULT NULL,
  `expires_at`   DATETIME NOT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_api_token_hash` (`token_hash`),
  KEY `idx_api_tokens_user` (`user_id`),
  CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============ 009_create_device_tokens.sql ============
-- =====================================================================
-- Migration 009: Geraete-Tokens (Push) und Push-Log (Doppel-Versand-Schutz)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT NOT NULL,
  `api_token_id`  INT DEFAULT NULL,                      -- anmeldendes API-Token (Migration 010, beim Logout entfernt)
  `farm_id`       INT DEFAULT NULL,
  `platform`      ENUM('ios','android') NOT NULL DEFAULT 'ios',
  `device_name`   VARCHAR(255) DEFAULT NULL,             -- sprechender Geraetename (Migration 010)
  `app_version`   VARCHAR(50)  DEFAULT NULL,             -- installierte App-Version (Migration 010)
  `token`         VARCHAR(255) NOT NULL,
  `environment`   ENUM('sandbox','production') NOT NULL DEFAULT 'production',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `push_weiterbehandlung` TINYINT(1) NOT NULL DEFAULT 1,
  `push_wartezeit`        TINYINT(1) NOT NULL DEFAULT 1,
  `push_traechtigkeit`    TINYINT(1) NOT NULL DEFAULT 1,
  `push_hemmstoff`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_used_at`  DATETIME DEFAULT NULL,
  `last_seen_at`  DATETIME DEFAULT NULL,                 -- letzter Kontakt des Geraets (Migration 010)
  UNIQUE KEY `uq_device_token` (`token`),
  KEY `idx_device_user`   (`user_id`),
  KEY `idx_device_farm`   (`farm_id`),
  KEY `idx_device_active` (`is_active`),
  KEY `idx_device_apitoken` (`api_token_id`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_device_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_device_apitoken` FOREIGN KEY (`api_token_id`) REFERENCES `api_tokens`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `push_log` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`           INT NOT NULL,
  `farm_id`           INT NOT NULL,
  `notification_type` VARCHAR(40) NOT NULL,
  `ref_key`           VARCHAR(191) NOT NULL,
  `cattle_id`         INT DEFAULT NULL,
  `status`            VARCHAR(40) NOT NULL DEFAULT 'sent',
  `sent_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_push_dedupe` (`user_id`,`notification_type`,`ref_key`),
  KEY `idx_push_farm` (`farm_id`),
  KEY `idx_push_sent` (`sent_at`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_push_farm` FOREIGN KEY (`farm_id`) REFERENCES `farms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============ 012_create_cattle_remarks.sql ============
-- =====================================================================
-- Migration 012: `cattle_remarks` – Freitext-Bemerkungen je Tier
-- =====================================================================
CREATE TABLE IF NOT EXISTS `cattle_remarks` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`     INT NOT NULL,
  `cattle_id`   INT NOT NULL,
  `remark_date` DATE NOT NULL,
  `body`        TEXT NOT NULL,
  `created_by`  INT DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_remarks_farm_cattle` (`farm_id`,`cattle_id`),
  KEY `idx_remarks_date`        (`farm_id`,`remark_date`),
  CONSTRAINT `fk_remarks_farm`    FOREIGN KEY (`farm_id`)   REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_remarks_cattle`  FOREIGN KEY (`cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_remarks_user`    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============ 013_create_followup_dismissals.sql ============
-- =====================================================================
-- Migration 013: `followup_dismissals` – "Nachbehandlung nicht noetig"
-- =====================================================================
CREATE TABLE IF NOT EXISTS `followup_dismissals` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`       INT NOT NULL,
  `cattle_id`     INT NOT NULL,
  `treatment_id`  INT NOT NULL,
  `dismiss_date`  DATE NOT NULL,
  `dismissed_by`  INT DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_followup_dismiss` (`farm_id`,`treatment_id`,`dismiss_date`),
  KEY `idx_followup_farm_date` (`farm_id`,`dismiss_date`),
  CONSTRAINT `fk_followup_farm`      FOREIGN KEY (`farm_id`)      REFERENCES `farms`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_followup_cattle`    FOREIGN KEY (`cattle_id`)    REFERENCES `cattle`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_followup_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `treatments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_followup_user`      FOREIGN KEY (`dismissed_by`) REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============ 014_create_stillbirths.sql ============
-- =====================================================================
-- Migration 014: `stillbirths` – Totgeburten je Kuh (ohne Ohrmarke)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `stillbirths` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`          INT NOT NULL,
  `mother_cattle_id` INT NOT NULL,
  `birth_date`       DATE NOT NULL,
  `sex`              ENUM('maennlich','weiblich','unbekannt') DEFAULT 'unbekannt',
  `comment`          TEXT DEFAULT NULL,
  `created_by`       INT DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_stillbirths_farm_mother` (`farm_id`,`mother_cattle_id`),
  KEY `idx_stillbirths_date`        (`farm_id`,`birth_date`),
  CONSTRAINT `fk_stillbirths_farm`   FOREIGN KEY (`farm_id`)          REFERENCES `farms`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_stillbirths_mother` FOREIGN KEY (`mother_cattle_id`) REFERENCES `cattle`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stillbirths_user`   FOREIGN KEY (`created_by`)       REFERENCES `users`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============ 015_create_planned_treatments.sql ============
-- =====================================================================
-- Migration 015: `planned_treatments` – geplante (Nach-)Behandlungen
-- =====================================================================
CREATE TABLE IF NOT EXISTS `planned_treatments` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `farm_id`       INT NOT NULL,
  `cattle_id`     INT NOT NULL,
  `planned_date`  DATE NOT NULL,
  `medication`    VARCHAR(255) NOT NULL,
  `dosage`        VARCHAR(100) DEFAULT NULL,
  `type`          ENUM('Injektion','drenchen','Eutertube','Trockenstellen','Hemmstoff Probe','Klauenpflege','Sonstige')
                    NOT NULL DEFAULT 'Sonstige',
  `notes`         TEXT DEFAULT NULL,
  `status`        ENUM('geplant','erledigt','verworfen') NOT NULL DEFAULT 'geplant',
  `treatment_id`  INT DEFAULT NULL,
  `created_by`    INT DEFAULT NULL,
  `done_by`       INT DEFAULT NULL,
  `done_at`       TIMESTAMP NULL DEFAULT NULL,
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


-- ============ 008_seed_demo_and_admin.sql ============
-- =====================================================================
-- Migration 008: Seed-Daten
--   - System-Admin-Account
--   - Demo-Betrieb (is_demo=1) mit Demo-User (viewer) + Beispieldaten
--   - Globaler Medikamentenstamm (Basis)
--
-- WICHTIG: Die Passwoerter sind nur fuer die Erstinbetriebnahme/Demo.
--   admin / admin1234   -> nach erstem Login aendern!
--   demo  / demo2024    -> reiner Nur-Lese-Demo-Zugang
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- System-Admin
-- ---------------------------------------------------------------------
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `is_admin`)
VALUES (1, 'admin', 'admin@example.com',
        '$2y$10$lS69LM3zZ815FZxJzpj.beqNQ4Pjj1/.wjB62TlszclwTt.Cxyfqm', 1)
ON DUPLICATE KEY UPDATE username = username;

-- ---------------------------------------------------------------------
-- Demo-User (viewer)
-- ---------------------------------------------------------------------
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `is_admin`)
VALUES (2, 'demo', 'demo@example.com',
        '$2y$10$heP9CoF9HtH1D.EK4j1cH.TW5uP1pKSWnDjrdl2TNmQ3czEc6TfM.', 0)
ON DUPLICATE KEY UPDATE username = username;

-- ---------------------------------------------------------------------
-- Globaler Medikamentenstamm (Basis-Beispiele)
-- ---------------------------------------------------------------------
INSERT INTO `medications` (`name`, `active_substance`, `dosage`, `wz_milk`, `wz_fleisch`) VALUES
  ('Penicillin G',      'Benzylpenicillin', '10 ml',  '3', '10'),
  ('Metacam',           'Meloxicam',        '20 ml',  '5', '15'),
  ('Ubrolexin',         'Cefalexin',        '1 Tube', '4', '10'),
  ('Selectan',          'Florfenicol',      '15 ml',  '0', '37'),
  ('Cobactan',          'Cefquinom',        '10 ml',  '1', '4')
ON DUPLICATE KEY UPDATE name = name;

-- ---------------------------------------------------------------------
-- Demo-Betrieb
-- ---------------------------------------------------------------------
INSERT INTO `farms` (`id`, `name`, `betriebsnummer`, `address`, `is_demo`, `is_active`)
VALUES (1, 'Demo-Betrieb', 'DEMO-001', 'Musterweg 1, 00000 Musterhausen', 1, 1)
ON DUPLICATE KEY UPDATE name = name;

-- Demo-User dem Demo-Betrieb als viewer zuordnen
INSERT INTO `farm_users` (`farm_id`, `user_id`, `role`)
VALUES (1, 2, 'viewer')
ON DUPLICATE KEY UPDATE role = 'viewer';

-- Staelle des Demo-Betriebs
INSERT INTO `stalls` (`farm_id`, `name`) VALUES
  (1, 'Kälberbox innen'), (1, 'Kälberbox außen'),
  (1, 'Kälberlaufstall klein'), (1, 'Kälberlaufstall Tenne'),
  (1, 'Kälberlaufstall Spalten'), (1, 'Anbindestall'),
  (1, 'Box 1'), (1, 'Box 2'), (1, 'Box 3'),
  (1, 'Box 4'), (1, 'Box 5'), (1, 'Box 6'),
  (1, 'Box Tenne vorne'), (1, 'Box Tenne hinten'),
  (1, 'Laufstall'), (1, 'Kalbinnenlaufstall')
ON DUPLICATE KEY UPDATE name = name;

-- ---------------------------------------------------------------------
-- Beispiel-Rinder im Demo-Betrieb
-- ---------------------------------------------------------------------
INSERT INTO `cattle` (`farm_id`,`ear_tag`,`birth_date`,`status`,`mutter`,`zugang`,`rasse`,`geschlecht`,`mehrling`) VALUES
  (1,'276010001001','2021-03-12','Aktiv',NULL,'2021-03-12','11 FL Fleckvieh','W','Nein'),
  (1,'276010001002','2021-05-20','Aktiv',NULL,'2021-05-20','11 FL Fleckvieh','W','Nein'),
  (1,'276010001003','2022-01-08','Aktiv','276010001001','2022-01-08','11 FL Fleckvieh','W','Nein'),
  (1,'276010001004','2022-02-15','Aktiv','276010001002','2022-02-15','1 SBT Holstein-Sbt','M','Nein'),
  (1,'276010001005','2022-06-30','Aktiv',NULL,'2022-06-30','1 SBT Holstein-Sbt','W','Nein'),
  (1,'276010001006','2023-03-01','Aktiv','276010001001','2023-03-01','11 FL Fleckvieh','W','Ja'),
  (1,'276010001007','2023-03-01','Aktiv','276010001001','2023-03-01','11 FL Fleckvieh','M','Ja'),
  (1,'276010001008','2023-07-19','Aktiv',NULL,'2023-07-19','2 RBT Holstein-Rbt','W','Nein'),
  (1,'276010001009','2024-01-25','Aktiv','276010001005','2024-01-25','1 SBT Holstein-Sbt','W','Nein'),
  (1,'276010001010','2024-04-10','Aktiv','276010001002','2024-04-10','11 FL Fleckvieh','W','Nein'),
  (1,'276010001011','2020-11-05','Inaktiv',NULL,'2020-11-05','11 FL Fleckvieh','W','Nein'),
  (1,'276010001012','2024-09-14','Aktiv','276010001008','2024-09-14','2 RBT Holstein-Rbt','M','Nein')
ON DUPLICATE KEY UPDATE ear_tag = ear_tag;

-- Abgang fuer das inaktive Tier
UPDATE `cattle` SET `abgang` = '2024-12-01' WHERE `farm_id` = 1 AND `ear_tag` = '276010001011';

-- ---------------------------------------------------------------------
-- Beispiel-Behandlungen (verknuepft ueber cattle_id-Subselect)
-- ---------------------------------------------------------------------
INSERT INTO `treatments` (`farm_id`,`cattle_id`,`treatment_date`,`medication`,`dosage`,`type`,`notes`)
SELECT 1, c.id, '2025-02-10', 'Metacam', '20 ml', 'Injektion', 'Fiebersenkung'
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001003';

INSERT INTO `treatments` (`farm_id`,`cattle_id`,`treatment_date`,`medication`,`dosage`,`type`,`notes`)
SELECT 1, c.id, '2025-03-05', 'Penicillin G', '10 ml', 'Injektion', 'Mastitis-Behandlung'
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001005';

INSERT INTO `treatments` (`farm_id`,`cattle_id`,`treatment_date`,`medication`,`dosage`,`type`,`notes`)
SELECT 1, c.id, '2025-04-01', 'Ubrolexin', '1 Tube', 'Eutertube', 'Trockenstellen'
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001001';

-- ---------------------------------------------------------------------
-- Beispiel-Besamungen
-- ---------------------------------------------------------------------
INSERT INTO `inseminations` (`farm_id`,`cattle_id`,`date`,`bull_name`,`type`)
SELECT 1, c.id, '2025-01-15', 'Malibu', 'besamt'
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001001';

INSERT INTO `inseminations` (`farm_id`,`cattle_id`,`date`,`bull_name`,`type`)
SELECT 1, c.id, '2025-02-20', 'Watnson', 'besamt'
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001002';

-- ---------------------------------------------------------------------
-- Beispiel-Traechtigkeitsuntersuchungen
-- ---------------------------------------------------------------------
INSERT INTO `pregnancy_checks` (`farm_id`,`cattle_id`,`check_date`,`result`,`comment`)
SELECT 1, c.id, '2025-03-15', '+', 'Traechtig bestaetigt'
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001001';

-- ---------------------------------------------------------------------
-- Beispiel-Stallhistorie (aktuelle Belegung: end_date IS NULL)
-- ---------------------------------------------------------------------
INSERT INTO `stall_history` (`farm_id`,`cattle_id`,`stall_name`,`start_date`,`end_date`)
SELECT 1, c.id, 'Laufstall', '2024-01-01', NULL
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001001';

INSERT INTO `stall_history` (`farm_id`,`cattle_id`,`stall_name`,`start_date`,`end_date`)
SELECT 1, c.id, 'Box 1', '2024-01-01', NULL
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001002';

INSERT INTO `stall_history` (`farm_id`,`cattle_id`,`stall_name`,`start_date`,`end_date`)
SELECT 1, c.id, 'Kälberbox innen', '2024-09-14', NULL
FROM `cattle` c WHERE c.farm_id=1 AND c.ear_tag='276010001012';


