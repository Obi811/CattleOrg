# Änderungsverlauf

Alle nennenswerten Änderungen an CattleOrg werden hier dokumentiert.
Format in Anlehnung an [Keep a Changelog](https://keepachangelog.com/de/).

## [Unveröffentlicht] – API-Fehlerbehandlung: globaler Handler (Fix HTTP 500 ohne JSON)

### Behoben
- **Nackter HTTP-500 ohne JSON-Body** bei unerwarteten Serverfehlern. Da PDO im
  `ERRMODE_EXCEPTION` läuft, wurde eine nicht abgefangene Ausnahme (z. B. ein
  SQL-Fehler in `/api/v1/dashboard/` oder `/api/v1/user/`) zu einem PHP-Fatal.
  Mit `display_errors=0` in Produktion erhielt der Client dadurch einen leeren
  HTTP-500 – die iOS-App konnte nur „Serverfehler 500" anzeigen, nicht die
  eigentliche Ursache.

### Neu
- `public/api/v1/bootstrap_api.php`: globaler `set_exception_handler` und
  `register_shutdown_function`. Unerwartete Ausnahmen/Fatals werden jetzt als
  JSON-Fehler im Standardformat ausgeliefert
  (`{"success":false,"error":{"code":"INTERNAL_ERROR"|"FATAL_ERROR","message":...}}`)
  und zusätzlich mit Datei:Zeile ins PHP-Fehlerprotokoll geschrieben.
- Dadurch zeigt die App die technische Ursache direkt an, und das Fehlerprotokoll
  nennt die genaue Stelle – unabhängig davon, welcher Endpunkt betroffen ist.

### Dokumentation
- `docs/API_IOS.md`: Fehlercodes `INTERNAL_ERROR` und `FATAL_ERROR` ergänzt,
  Hinweis zum globalen Fehler-Handler.

### Hinweis
- Die `message` enthält die technische Fehlerursache. Die API ist token-geschützt
  (nur authentifizierte Clients). Soll die Meldung in Produktion generischer sein,
  kann der Handler später hinter ein Debug-Flag gelegt werden.

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
