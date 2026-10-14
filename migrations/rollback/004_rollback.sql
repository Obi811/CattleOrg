-- Rollback 004
ALTER TABLE `treatments` DROP FOREIGN KEY `fk_treatments_medication`;
DROP TABLE IF EXISTS `medications`;
