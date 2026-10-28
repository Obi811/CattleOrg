-- Rollback Migration 013: Tabelle followup_dismissals entfernen
SET NAMES utf8mb4;
DROP TABLE IF EXISTS `followup_dismissals`;
