# Datenmigrationen (betriebsspezifisch)

Dieses Verzeichnis ist für **einmalige Datenübernahmen** aus Altsystemen gedacht.

**Wichtig:** Migrationsskripte mit echten Betriebsdaten (Rinder, Behandlungen,
Benutzer inkl. Passwort-Hashes usw.) werden **nicht** im Repository gespeichert.
Sie sind per `.gitignore` ausgeschlossen (`data_migrations/*.sql`) und werden
nur lokal bzw. als Upload-Paket weitergegeben.

## Vorgehen bei künftigen Übernahmen

1. Skript lokal erzeugen und gegen eine frische Test-DB (`migrations/install_all.sql`) prüfen.
2. Vor dem Live-Import eine **Datensicherung** der Datenbank anlegen.
3. Import in phpMyAdmin (Reiter „Importieren").
4. Skript nach erfolgreichem Import sicher archivieren – **nicht** committen.

Schema-Änderungen gehören dagegen weiterhin nach `migrations/`.
