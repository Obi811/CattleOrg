-- Rollback 008: Seed-Daten entfernen (Demo-Betrieb + Demo/Admin-User + Basis-Medikamente)
SET NAMES utf8mb4;
DELETE FROM `farms` WHERE `id` = 1;            -- CASCADE entfernt cattle/treatments/... des Demo-Betriebs
DELETE FROM `users` WHERE `username` IN ('admin','demo');
DELETE FROM `medications` WHERE `name` IN ('Penicillin G','Metacam','Ubrolexin','Selectan','Cobactan');
