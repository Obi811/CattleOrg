-- Rollback Migration 011: Hemmstoff-Ergebnis-/Versand-Meta entfernen
SET NAMES utf8mb4;

ALTER TABLE `hemmstoff_probes` DROP FOREIGN KEY IF EXISTS `fk_hemm_result_by`;
ALTER TABLE `hemmstoff_probes` DROP FOREIGN KEY IF EXISTS `fk_hemm_sent_by`;
ALTER TABLE `hemmstoff_probes` DROP KEY IF EXISTS `idx_hemm_result_at`;
ALTER TABLE `hemmstoff_probes` DROP KEY IF EXISTS `idx_hemm_sent_at`;
ALTER TABLE `hemmstoff_probes`
  DROP COLUMN IF EXISTS `result_at`,
  DROP COLUMN IF EXISTS `result_by`,
  DROP COLUMN IF EXISTS `sent_at`,
  DROP COLUMN IF EXISTS `sent_by`;
