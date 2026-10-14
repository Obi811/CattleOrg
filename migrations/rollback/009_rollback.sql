-- Rollback Migration 009: Geraete-Tokens und Push-Log entfernen
SET NAMES utf8mb4;
DROP TABLE IF EXISTS `push_log`;
DROP TABLE IF EXISTS `device_tokens`;
