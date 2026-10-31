# Änderungsverlauf

Alle nennenswerten Änderungen an CattleOrg werden hier dokumentiert.
Format in Anlehnung an [Keep a Changelog](https://keepachangelog.com/de/).

## [Unveröffentlicht] – Hotfix: Dashboard-API HTTP 500

### Behoben
- **`GET /api/v1/dashboard/` lieferte nach dem Server-Update ausnahmslos HTTP 500.**
  Die beiden additiven Blöcke des Endpunkts – fällige geplante Behandlungen
  (`loadPlannedDue`) sowie die Kennzahlen Milchkühe/Trockensteher
  (`milchkuhCattleCondition`/`trockensteherCattleCondition`) – wurden
  bedingungslos aufgerufen. Fehlte eine dieser Funktionen (z. B. weil auf dem
  Server eine Zwischenversion der `includes/functions.php` lag) oder schlug ein
  SQL-Teil fehl, führte das zu einem PHP-Fatal und damit zu einem nackten 500 –
  obwohl die Kern-Aufgaben (Weiterbehandlungen, Hemmstoff, Trächtigkeits­kontrolle,
  Wartezeit) problemlos ermittelbar waren.
- **Härtung:** Beide Zusatzblöcke sind jetzt in `try { … } catch (\Throwable)`
  gekapselt, mit `function_exists`-Prüfung und defensivem `require_once` der
  benötigten Includes. Fällt ein Block aus, greift ein Fallback und der Grund wird
  ins PHP-Fehlerprotokoll geschrieben. Das Dashboard antwortet dadurch wieder mit
  HTTP 200 und den Kern-Aufgaben.
- **Cattle-API** (`GET /api/v1/cattle/`): die Filter `milchkuh=1`/`trockensteher=1`
  sind analog abgesichert – fehlt der Helper, wird der Filter ignoriert statt
  einen 500 auszulösen.

### Behoben (Nachtrag) – Client-Decodierungsfehler
- **App meldete nach dem Fix „Datenverarbeitungsfehler / data couldn't be read".**
  Ursache: Der Fallback lieferte `kennzahlen.milchkuehe`/`trockensteher` als `null`.
  Ein striktes Client-Modell (Swift `Codable`) kann ein `null` an einer Zahl-Position
  nicht decodieren → HTTP 200, aber Decodierungsfehler auf App-Seite.
- **Lösung:** Die Antwort ist jetzt **typ-stabil**. Die Kennzahlen sind im Fehlerfall
  `0` (eine Ganzzahl) statt `null`, `geplante_faellig` bleibt ein leeres Array (`[]`).
  Die Datentypen der JSON-Antwort bleiben damit unabhängig vom Server-Zustand konstant.

### Hinweis
- Rückwärtskompatibel: nur additive Änderungen, gleiche JSON-Schlüssel. Die
  Datentypen der Antwort sind konstant (Zahlen bleiben Zahlen, Listen bleiben Listen).

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
