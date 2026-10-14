<!--
  Bitte diese Vorlage ausfuellen. Punkte, die nicht zutreffen, mit "entfaellt"
  kennzeichnen. Ziel: nachvollziehbare, sichere Aenderungen ueber Pull Requests.
-->

## Was wird geaendert?

<!-- Kurze Beschreibung der Aenderung und des Grunds. -->

## Checkliste

- [ ] **Art der Aenderung:** <!-- z. B. Feature / Bugfix / Doku / CI / Refactoring -->
- [ ] **Datenbank-Migration noetig?**
  - [ ] Nein
  - [ ] Ja – Datei(en): `migrations/____`
- [ ] **Neue `.env`-Werte?**
  - [ ] Nein
  - [ ] Ja – welche: `____` (auch in `.env.example` ergaenzt)
- [ ] **Wie getestet?**
  - [ ] Lokal
  - [ ] Live-Server
  - [ ] Dry-Run (z. B. Cron `--dry-run`)
  - Kurzbeschreibung: `____`
- [ ] **cPanel-Upload:** welche Dateien muessen hochgeladen werden?
  - Dateien/Pfade: `____`
- [ ] **Auswirkung auf iOS-App / API?**
  - [ ] Keine
  - [ ] Ja – API-Doku (`docs/API_IOS.md`) aktualisiert
- [ ] `php -l` lokal fehlerfrei (CI "PHP Syntax-Check" muss gruen sein)
- [ ] Keine Secrets/`.env`/Logs/`tmp`/PDFs/DOCX im Commit

## Hinweise fuer den Review

<!-- Optional: Besonderheiten, bewusste Abweichungen, Rollback-Hinweise. -->
