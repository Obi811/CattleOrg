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
