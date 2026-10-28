-- =====================================================================
-- Migration 011 (MySQL-Variante): Hemmstoffproben – Ergebnis-/Versand-Meta
-- ---------------------------------------------------------------------
--  Inhaltlich identisch zu 011_hemmstoff_result_meta.sql, aber OHNE
--  "IF NOT EXISTS" bei ALTER TABLE (das unterstuetzt nur MariaDB).
--  Diese Variante NUR auf echtem Oracle-MySQL (>= 8.0) verwenden und
--  nur einmalig ausfuehren (nicht idempotent).
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `hemmstoff_probes`
  ADD COLUMN `result_at` DATETIME DEFAULT NULL AFTER `nummer`,
  ADD COLUMN `result_by` INT      DEFAULT NULL AFTER `result_at`,
  ADD COLUMN `sent_at`   DATETIME DEFAULT NULL AFTER `result_by`,
  ADD COLUMN `sent_by`   INT      DEFAULT NULL AFTER `sent_at`;

ALTER TABLE `hemmstoff_probes`
  ADD KEY `idx_hemm_result_at` (`farm_id`,`result_at`),
  ADD KEY `idx_hemm_sent_at`   (`farm_id`,`sent_at`);

ALTER TABLE `hemmstoff_probes`
  ADD CONSTRAINT `fk_hemm_result_by`
    FOREIGN KEY (`result_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hemm_sent_by`
    FOREIGN KEY (`sent_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
