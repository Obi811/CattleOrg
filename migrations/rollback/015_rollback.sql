-- Rollback Migration 015: Tabelle fuer geplante Behandlungen entfernen
DROP TABLE IF EXISTS `planned_treatments`;
