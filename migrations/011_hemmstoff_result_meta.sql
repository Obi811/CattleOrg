-- =====================================================================
-- Migration 011: Hemmstoffproben – Metadaten zu Ergebnis und Versand
-- ---------------------------------------------------------------------
--  Fuer die neue Inline-Erfassung in der Aufgabenliste:
--   - result_at : Zeitpunkt, zu dem das Ergebnis (frei / nicht frei)
--                 vor Ort erfasst wurde. Erlaubt die Anzeige
--                 "Ergebnis heute: ..." am Erfassungstag.
--   - result_by : Benutzer, der das Ergebnis erfasst hat.
--   - sent_at   : Zeitpunkt, zu dem die Probe als "weggeschickt"
--                 markiert wurde (Versand nach Ablauf der Wartezeit).
--   - sent_by   : Benutzer, der den Versand erfasst hat.
--
--  Alle Aenderungen sind additiv und idempotent (IF NOT EXISTS),
--  damit die Migration auf bestehenden Installationen (MariaDB)
--  gefahrlos laeuft. Eine MySQL-kompatible Variante ohne IF NOT EXISTS
--  liegt unter 011_hemmstoff_result_meta.mysql.sql.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `hemmstoff_probes`
  ADD COLUMN IF NOT EXISTS `result_at` DATETIME DEFAULT NULL AFTER `nummer`,
  ADD COLUMN IF NOT EXISTS `result_by` INT      DEFAULT NULL AFTER `result_at`,
  ADD COLUMN IF NOT EXISTS `sent_at`   DATETIME DEFAULT NULL AFTER `result_by`,
  ADD COLUMN IF NOT EXISTS `sent_by`   INT      DEFAULT NULL AFTER `sent_at`;

ALTER TABLE `hemmstoff_probes`
  ADD KEY IF NOT EXISTS `idx_hemm_result_at` (`farm_id`,`result_at`),
  ADD KEY IF NOT EXISTS `idx_hemm_sent_at`   (`farm_id`,`sent_at`);

-- Benutzer-Referenzen: beim Loeschen des Benutzers nur die Referenz
-- entfernen (Ergebnis-/Versanddaten bleiben erhalten).
ALTER TABLE `hemmstoff_probes`
  ADD CONSTRAINT `fk_hemm_result_by`
    FOREIGN KEY IF NOT EXISTS (`result_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hemm_sent_by`
    FOREIGN KEY IF NOT EXISTS (`sent_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
