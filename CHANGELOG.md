# Änderungsverlauf

Alle nennenswerten Änderungen an CattleOrg werden hier dokumentiert.
Format in Anlehnung an [Keep a Changelog](https://keepachangelog.com/de/).

## [Unveröffentlicht] – Planung, Gruppenaktionen, Trockensteher

### Neu
- **Geplante Behandlungen** (`planned_treatments`, Migration 015): Behandlungen für
  ein festes Datum einplanen. Fällige geplante Behandlungen erscheinen an dem Tag
  in den Aufgaben und im Kalender. Über „Erledigt" wird daraus automatisch eine
  echte Behandlung erzeugt. Planen direkt aus der Nachbehandlung, aus dem
  Tierprofil und als Gruppenaktion möglich.
- **Gruppenaktionen pro Box** (`public/group_actions.php`): mehrere Tiere einer Box
  auf einmal umstallen, inaktivieren (Abgang) oder behandeln bzw. eine Behandlung
  für sie einplanen. Neuer Navigationspunkt „Gruppenaktionen" (nur mit
  `cattle.write`).
- **Startseiten-Kachel „Trockensteher"** statt „Offene Trächtigkeit": aktive, weibliche
  Tiere, die einen Trockensteller/Versiegler erhalten haben und seitdem nicht
  gekalbt haben. Kachel verlinkt auf die gefilterte Tierliste (`trockensteher=1`).
- **Tierliste**: neuer Filter „Nur Trockensteher".
- **API (additiv, bricht bestehende Endpunkte nicht):**
  - `GET/POST/PUT/DELETE /api/v1/planned-treatments` und
    `POST /api/v1/planned-treatments/complete`
  - `GET/POST/DELETE /api/v1/stillbirths`
  - `GET/POST /api/v1/group-actions` (Box-Tiere + Aktionen move/inactivate/treat/plan)
  - `GET /api/v1/dashboard`: zusätzliche Kategorie `geplante_faellig` sowie
    `kennzahlen.milchkuehe` und `kennzahlen.trockensteher`
  - `GET /api/v1/cattle`: neue Filter `trockensteher=1` und `milchkuh=1`;
    Tier-Detail um `stillbirths[]` ergänzt (zusätzlich zu den Kalbungen)

### Geändert
- „Offene Trächtigkeit" (Singular) überall als Listen-Überschrift/Bezeichnung zu
  „Offene Trächtigkeiten" (Plural) korrigiert.

### Migration
- `migrations/015_create_planned_treatments.sql` (idempotent, Rollback unter
  `migrations/rollback/015_rollback.sql`).

## [V0.9.0]
- Sicherheitsupdates dompdf 3.1.6 und PhpSpreadsheet 1.30.7.
- Milchkühe-Kennzahl, Totgeburten-Erfassung, Datumsformat TT.MM.JJJJ.
