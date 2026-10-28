-- Rollback Migration 012: Tabelle cattle_remarks entfernen
SET NAMES utf8mb4;
DROP TABLE IF EXISTS `cattle_remarks`;
