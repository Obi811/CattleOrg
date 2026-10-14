-- =====================================================================
-- Migration 010: Erweiterungen fuer die native iOS-App (Token-API)
-- ---------------------------------------------------------------------
--  1. cattle.abgang_grund : optionaler Grund/Bemerkung beim Abgang bzw.
--                           bei der Verendung eines Tieres.
--  2. device_tokens        : zusaetzliche Spalten fuer die Geraete-
--                           verwaltung der iOS-App (device-tokens-Alias):
--                             - api_token_id : Verknuepfung mit dem API-Token,
--                               ueber das sich das Geraet angemeldet hat
--                               (wird beim Logout mit entfernt).
--                             - device_name  : sprechender Geraetename.
--                             - app_version  : installierte App-Version.
--                             - last_seen_at : letzter Kontakt des Geraets.
--
--  Alle Aenderungen sind additiv und idempotent (IF NOT EXISTS), damit
--  die Migration auf bestehenden Installationen gefahrlos laeuft.
-- =====================================================================
SET NAMES utf8mb4;

-- 1. Abgangsgrund fuer Tiere ------------------------------------------
ALTER TABLE `cattle`
  ADD COLUMN IF NOT EXISTS `abgang_grund` VARCHAR(255) DEFAULT NULL AFTER `abgang`;

-- 2. Zusatzspalten fuer device_tokens ---------------------------------
ALTER TABLE `device_tokens`
  ADD COLUMN IF NOT EXISTS `api_token_id` INT DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `device_name`  VARCHAR(255) DEFAULT NULL AFTER `platform`,
  ADD COLUMN IF NOT EXISTS `app_version`  VARCHAR(50)  DEFAULT NULL AFTER `device_name`,
  ADD COLUMN IF NOT EXISTS `last_seen_at` DATETIME     DEFAULT NULL AFTER `last_used_at`;

ALTER TABLE `device_tokens`
  ADD KEY IF NOT EXISTS `idx_device_apitoken` (`api_token_id`);

-- Fremdschluessel auf api_tokens: beim Loeschen des Tokens (Logout) wird
-- auch der Geraete-Datensatz entfernt. ON DELETE CASCADE.
ALTER TABLE `device_tokens`
  ADD CONSTRAINT `fk_device_apitoken`
  FOREIGN KEY IF NOT EXISTS (`api_token_id`) REFERENCES `api_tokens`(`id`) ON DELETE CASCADE;
