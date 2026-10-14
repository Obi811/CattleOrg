-- Rollback Migration 010: iOS-API-Erweiterungen entfernen
SET NAMES utf8mb4;

-- device_tokens: Fremdschluessel, Index und Zusatzspalten entfernen
ALTER TABLE `device_tokens` DROP FOREIGN KEY IF EXISTS `fk_device_apitoken`;
ALTER TABLE `device_tokens` DROP KEY IF EXISTS `idx_device_apitoken`;
ALTER TABLE `device_tokens`
  DROP COLUMN IF EXISTS `api_token_id`,
  DROP COLUMN IF EXISTS `device_name`,
  DROP COLUMN IF EXISTS `app_version`,
  DROP COLUMN IF EXISTS `last_seen_at`;

-- cattle: Abgangsgrund entfernen
ALTER TABLE `cattle` DROP COLUMN IF EXISTS `abgang_grund`;
