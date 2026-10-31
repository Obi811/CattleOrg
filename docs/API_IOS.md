# CattleOrg Token-API fuer die native iOS-App ("Muh App")

Diese Dokumentation beschreibt die REST-API (JSON) fuer die native iOS-App.
Sie erweitert die bestehende Token-API um die fuer die App benoetigten
Endpunkte. Es kommt **kein Framework, kein Composer, kein Router** zum Einsatz:
Das Routing erfolgt ueber das Dateisystem unter `public/api/v1/<ressource>/index.php`.

- **Basis-Pfad:** `/api/v1`
- **Pfad-Konvention:** Alle Endpunkte im **Ordner-Stil mit abschliessendem Slash**
  aufrufen, z. B. `POST /api/v1/auth/login/` und `POST /api/v1/auth/logout/`.
  Das Routing erfolgt ueber `<ressource>/index.php`. Fuer `auth/login` und
  `auth/logout` bleiben zusaetzlich die Dateipfade `…/login.php` bzw.
  `…/logout.php` gueltig (Abwaertskompatibilitaet).
- **Authentifizierung:** `Authorization: Bearer <token>`
- **Betriebskontext:** Header `X-Farm-Id: <id>` **oder** Query-Parameter `?farm_id=<id>`
- **Datenformat:** JSON (Request-Body und Response)
- **Mandantenfaehigkeit:** Jede Abfrage ist streng auf den Betrieb (`farm_id`)
  eingeschraenkt. Schreibzugriffe auf Demo-Betriebe sind gesperrt.

---

## 1. Antwortformat

**Erfolg**

```json
{
  "success": true,
  "data": { ... },
  "meta": { "page": 1, "per_page": 20, "total": 42, "total_pages": 3 }
}
```

`meta` wird nur bei paginierten Listen gesetzt.

**Fehler**

```json
{
  "success": false,
  "error": { "code": "VALIDATION", "message": "Beschreibung des Fehlers" }
}
```

### Fehlercodes

| Code                | HTTP | Bedeutung                                              |
|---------------------|------|--------------------------------------------------------|
| `UNAUTHORIZED`      | 401  | Kein/ungueltiges Token                                  |
| `TOKEN_EXPIRED`     | 401  | Token abgelaufen                                        |
| `FORBIDDEN`         | 403  | Keine Berechtigung fuer die Aktion                      |
| `DEMO_READONLY`     | 403  | Schreibzugriff auf einen Demo-Betrieb                   |
| `FARM_REQUIRED`     | 400  | Betriebskontext (X-Farm-Id/farm_id) fehlt              |
| `NOT_FOUND`         | 404  | Ressource nicht gefunden                               |
| `METHOD_NOT_ALLOWED`| 405  | HTTP-Methode nicht erlaubt                              |
| `DUPLICATE`         | 409  | Eindeutigkeitskonflikt (z. B. Ohrmarke existiert)      |
| `VALIDATION`        | 422  | Eingabe-/Validierungsfehler                            |
| `RATE_LIMITED`      | 429  | Rate-Limit ueberschritten                              |
| `INTERNAL_ERROR`    | 500  | Unerwartete Ausnahme (z. B. SQL-Fehler); Details in `message` und im PHP-Fehlerprotokoll |
| `FATAL_ERROR`       | 500  | PHP-Fatal; Details in `message` und im PHP-Fehlerprotokoll |

> Seit dem globalen Fehler-Handler liefert jeder Endpunkt auch bei unerwarteten
> Serverfehlern eine JSON-Antwort im Standardformat (statt eines nackten HTTP-500
> ohne Body). Die `message` enthaelt die technische Ursache und wird zusaetzlich
> ins PHP-Fehlerprotokoll (cPanel) geschrieben.

---

## 2. Authentifizierung

### POST /api/v1/auth/login

Meldet den Benutzer an und liefert ein Bearer-Token sowie die zugaenglichen Betriebe.

Request:

```json
{ "username": "max", "password": "geheim", "device_name": "iPhone von Max" }
```

Response (`data`): `token`, `expires_at`, `user`, `farms[]`.

### POST /api/v1/auth/logout

Widerruft das aktuell verwendete Token. Zusaetzlich werden die mit diesem Token
registrierten Push-Geraete (`device_tokens.api_token_id`) entfernt.

Header: `Authorization: Bearer <token>`

Response (`data`): `{ "message": "Abgemeldet. Token wurde widerrufen." }`

---

## 3. Benutzer

### GET /api/v1/user

Liefert das eigene Profil inkl. aller Betriebe mit effektiven Berechtigungen.
Ideal fuer den App-Start (kein separater Farm-Call noetig).

Response (`data`):

```json
{
  "id": 3,
  "username": "max",
  "email": "max@example.com",
  "is_admin": false,
  "last_login": "2026-10-10 06:12:00",
  "farms": [
    {
      "id": 2,
      "name": "Testhof",
      "is_demo": false,
      "role": "owner",
      "permissions": ["cattle.read", "cattle.write", "treatments.read", "treatments.write", "..."]
    }
  ]
}
```

### PUT /api/v1/user/password

Aendert das eigene Passwort. Nach Erfolg werden **alle anderen** API-Tokens des
Benutzers widerrufen (das aktuell verwendete Token bleibt gueltig).

Request:

```json
{ "current_password": "altesPW", "new_password": "neuesPW123" }
```

Regeln: `new_password` mind. 8 Zeichen (sonst `422 VALIDATION`), `current_password`
muss korrekt sein (sonst `422 VALIDATION`).

Response (`data`): `{ "message": "...", "revoked_tokens": 2 }`

---

## 4. Betrieb

### GET /api/v1/farm

Liefert die zugaenglichen Betriebe inkl. Rolle und effektiven Berechtigungen
(gleiche Struktur wie `user.farms[]`). System-Admins erhalten alle aktiven
Betriebe mit Owner-Rechten.

---

## 5. Dashboard

### GET /api/v1/dashboard

Liefert die faelligen Aufgaben eines Betriebs in einem Rutsch (To-do-Liste der
App). Fachlogik identisch zu `public/todo_list.php` / `cron/push_notifications.php`.

Berechtigung: `cattle.read`. Betriebskontext erforderlich.

Response (`data`):

```json
{
  "weiterbehandlungen": [ { "id": 10, "cattle_id": 110, "ear_tag": "...", "treatment_date": "2026-10-10", "type": "Injektion", "medication": "Penicillin G", "dosage": "...", "notes": "..." } ],
  "hemmstoff_faellig":  [ { "cattle_id": 110, "ear_tag": "...", "treatment_date": "...", "medication": "...", "wartezeit_ende": "2026-10-11" } ],
  "hemmstoff_versendet":[ { "id": 5, "cattle_id": 110, "ear_tag": "...", "nummer": "P-001", "probe_date": "2026-10-09" } ],
  "tu_faellig":         [ { "cattle_id": 112, "ear_tag": "...", "last_insemination": "2026-08-01", "tage": 71 } ],
  "wartezeit_aktiv":    [ { "cattle_id": 110, "ear_tag": "...", "treatment_date": "...", "medication": "...", "wz_ende": "2026-10-20" } ],
  "counts": { "weiterbehandlungen": 1, "hemmstoff_faellig": 1, "hemmstoff_versendet": 1, "tu_faellig": 1, "wartezeit_aktiv": 1, "gesamt": 5 }
}
```

---

## 6. Tiere

### GET /api/v1/cattle (Liste)

Paginierte Tierliste des Betriebs. Berechtigung: `cattle.read`.

Query-Parameter (alle optional):

| Parameter    | Beispiel      | Beschreibung                                       |
|--------------|---------------|----------------------------------------------------|
| `page`       | `2`           | Seite (Standard 1)                                 |
| `per_page`   | `20`          | Eintraege pro Seite                                |
| `status`     | `Aktiv`       | `Aktiv` oder `Inaktiv`                             |
| `q`          | `2760`        | Teilsuche in der Ohrmarke                           |
| `geschlecht` | `W`           | `M` oder `W`                                        |
| `stall`      | `Stall 1`     | Tiere mit offenem Stallaufenthalt in diesem Stall  |
| `wartezeit`  | `1`           | nur Tiere mit aktuell laufender Wartezeit          |
| `traechtig`  | `1`           | nur Tiere mit letzter TU-Kontrolle = `+`           |
| `rasse`      | `Fleckvieh`   | exakter Match auf die Rasse (`c.rasse = :rasse`)   |
| `min_age`    | `24`          | Mindestalter in **Monaten** (ueber `birth_date`)   |
| `max_age`    | `60`          | Hoechstalter in **Monaten** (ueber `birth_date`)   |

Jeder Listeneintrag enthaelt zusaetzlich `current_stall`, `wz_ende`, `wz_aktiv`.

> **Filter-Gleichheit zur Web-Tieruebersicht:** `rasse`, `min_age` und `max_age`
> entsprechen 1:1 den Filtern von `public/cattle_list.php`. Das Alter wird als
> `TIMESTAMPDIFF(MONTH, c.birth_date, CURDATE())` berechnet; Tiere ohne
> `birth_date` werden bei gesetztem `min_age`/`max_age` ausgeschlossen. `min_age`
> und `max_age` akzeptieren nur nicht-negative Ganzzahlen; sonst wird der
> jeweilige Filter ignoriert. Alle Filter sind kombinierbar (z. B.
> `?status=Aktiv&rasse=Fleckvieh&min_age=24&max_age=60`).

### GET /api/v1/cattle?id=123 (Detail)

Einzeltier mit Zusatzinformationen. Berechtigung: `cattle.read`.

Response (`data`) zusaetzlich zu den Stammdaten:

```json
{
  "id": 123, "ear_tag": "...", "...": "...",
  "current_stall": "Stall 1",
  "wartezeit": { "aktiv": true, "ende": "2026-10-20", "milch_ende": "2026-10-18", "fleisch_ende": "2026-10-20" },
  "pregnancy": { "status": "+", "last_insemination": "2026-08-01", "last_check": "2026-09-05", "expected_calving": "2027-05-08" },
  "hemmstoff_probes": [ { "id": 5, "probe_date": "2026-10-09", "nummer": "P-001", "status": "versendet", "created_at": "..." } ]
}
```

### POST /api/v1/cattle

Neues Tier anlegen. Berechtigung: `cattle.write` (kein Demo).

Request: `{ "ear_tag": "276010001099", "birth_date": "2025-01-01", "rasse": "...", "geschlecht": "W", "mutter": "...", "status": "Aktiv" }`

Fehler: `409 DUPLICATE`, wenn die Ohrmarke im Betrieb bereits existiert.

### POST /api/v1/cattle/abgang

Meldet einen Abgang (Verkauf/Umstallung) oder eine Verendung. Setzt `status = Inaktiv`,
speichert den optionalen Grund in `cattle.abgang_grund` und beendet den laufenden
Stallaufenthalt. Berechtigung: `cattle.write` (kein Demo).

Request:

```json
{ "cattle_id": 123, "art": "abgang", "datum": "2026-10-10", "grund": "Verkauf an Nachbarn" }
```

`art`: `abgang` oder `verendung` (sonst `422 VALIDATION`). `datum` im Format `YYYY-MM-DD`.

Response (`data`): das aktualisierte Tier.

### GET /api/v1/cattle/breeds

Liefert die im Betrieb vorhandenen Rassen als Auswahlliste (DISTINCT,
alphabetisch sortiert). Dient der App als Quelle fuer den `rasse`-Filter der
Tierliste. Berechtigung: `cattle.read`. Betriebskontext erforderlich.

Response (`data`): Array von Rassen-Strings.

```json
{ "success": true, "data": ["Braunvieh", "Fleckvieh", "Holstein"] }
```

Leere/`NULL`-Rassen werden ausgeschlossen. Streng auf `farm_id` beschraenkt.

---

## 7. Behandlungen

### GET /api/v1/treatments

Behandlungen des Betriebs (paginiert). Filter: `?cattle_id=`. Berechtigung: `treatments.read`.

Jeder Eintrag enthaelt die Wartezeit-Felder aus dem globalen Medikamentenkatalog:
`wz_milk`, `wz_fleisch` (Tage, `null` wenn unbekannt), `wz_ende` (Datum des
spaetesten Wartezeit-Endes) und `wz_aktiv` (bool, `wz_ende >= heute`).

### POST /api/v1/treatments

Neue Behandlung anlegen. Berechtigung: `treatments.write` (kein Demo).
Das Medikament muss im globalen Katalog (`medications.name`) existieren (sonst `422`).

Request: `{ "cattle_id": 123, "medication": "Penicillin G", "dosage": "20 ml", "type": "Injektion", "treatment_date": "2026-10-10", "notes": "..." }`

---

## 8. Hemmstoffproben (Inhibitor-Tests)

Verwaltung der Tabelle `hemmstoff_probes`, streng betriebsbezogen.

### GET /api/v1/inhibitor-tests

Liste (paginiert). Berechtigung: `cattle.read`. Filter: `?cattle_id=`, `?status=`
(`frei` | `nicht frei` | `versendet`).

Felder: `id`, `cattle_id`, `ear_tag`, `probe_date`, `nummer`, `status`, `created_at`.

### POST /api/v1/inhibitor-tests

Neue Probe als `versendet` anlegen. Berechtigung: `treatments.write` (kein Demo).

Request: `{ "cattle_id": 123, "nummer": "P-002", "probe_date": "2026-10-10" }`
(`probe_date` optional, Standard = heute). Antwort: `201` mit dem Datensatz.

### PUT /api/v1/inhibitor-tests?id=5

Status einer Probe setzen. Berechtigung: `treatments.write` (kein Demo).

Request: `{ "status": "frei" }` (`frei` oder `nicht frei`, sonst `422`).

---

## 9. Push-Geraete (APNs)

Es gibt zwei **funktional gleichwertige** Endpunkte fuer die Geraeteverwaltung.
`/api/v1/device-tokens` ist ein **Alias** von `/api/v1/devices` und verwendet
dieselbe Implementierung (`includes/device_tokens.php`) — es existiert keine
doppelte Logik. Der einzige Unterschied: beim `DELETE` akzeptiert
`/api/v1/device-tokens` das Token zusaetzlich als Query-Parameter `?token=`.

### POST/PUT /api/v1/devices  (bzw. /api/v1/device-tokens)

Registriert oder aktualisiert ein Geraet (Upsert ueber das eindeutige Token).

Request:

```json
{
  "token": "<APNs-Device-Token-Hex>",
  "platform": "ios",
  "environment": "production",
  "device_name": "iPhone 15",
  "app_version": "1.0.0",
  "prefs": { "weiterbehandlung": true, "wartezeit": true, "traechtigkeit": true, "hemmstoff": true }
}
```

Optionaler Betriebskontext ueber `X-Farm-Id`/`?farm_id` (sonst betriebsneutral).
Antwort: `201` mit dem normalisierten Geraete-Datensatz.

### DELETE /api/v1/devices  (bzw. /api/v1/device-tokens)

Meldet ein Geraet ab (`is_active = 0`). Token im Body `{ "token": "..." }` oder
(nur beim Alias) als `?token=`.

> **Hinweis:** Beim `logout` werden die mit dem jeweiligen API-Token verknuepften
> Geraete automatisch entfernt (ueber `device_tokens.api_token_id`).

---

## 10. Weitere bestehende Endpunkte

Unveraendert weiterhin verfuegbar: `GET /api/v1/medications`,
`GET/POST /api/v1/inseminations`, `GET/POST /api/v1/pregnancy`,
`GET /api/v1/stalls`. Discovery: `GET /api/v1/`.

---

## 11. Push-Versand (serverseitig)

- `cron/push_notifications.php` (CLI-Cronjob, taeglich) ermittelt die faelligen
  Aufgaben je Betrieb und sendet die iOS-Pushes an die registrierten Geraete.
  Dringende Aufgaben (Wartezeit, faellige Hemmstoffprobe) werden stets einzeln
  gesendet; nicht-dringende Aufgaben werden ab dem Schwellwert
  `$SUMMARY_THRESHOLD` (Standard **3**) je Benutzer/Betrieb/Tag zu **einer
  Sammel-Mitteilung** (`type: "summary"`) gebuendelt. Details zum Payload siehe
  `docs/API_IOS_PUSH.md`, Abschnitt 4.1.
- `includes/push.php` enthaelt die vollstaendige Low-Level-APNs-Logik
  (JWT/ES256, HTTP/2 via curl, Payload-Aufbau) — ohne Composer.
- `includes/push_sender.php` ist eine schlanke **Fassade** mit High-Level-
  Funktionen, die **ausschliesslich an `push.php` delegieren** (keine zweite
  Implementierung):
  - `sendApnsPush($deviceToken, $payload, $environment = null)`
  - `sendPushToUser(PDO $db, $userId, array $payload, $farmId = null)`
  - `sendPushToFarm(PDO $db, $farmId, array $payload)`

APNs-Konfiguration erfolgt ueber `.env` (siehe `.env.example`):
`APNS_ENABLED`, `APNS_KEY_PATH` (.p8, ausserhalb von `public/`), `APNS_KEY_ID`,
`APNS_TEAM_ID`, `APNS_BUNDLE_ID`, `APNS_DEFAULT_ENV` und optional `APNS_URL`
(Host-Override). Die Fassade akzeptiert zusaetzlich die alternativen
Variablennamen `KEY_PATH`/`KEY_ID`/`TEAM_ID`/`BUNDLE_ID`.

---

## 12. Datenbank-Migration

Migration `010_api_ios_extensions.sql` ergaenzt additiv und idempotent:

- `cattle.abgang_grund` (VARCHAR 255, optional) — Grund beim Abgang/Verendung.
- `device_tokens.api_token_id` (INT, FK auf `api_tokens`, `ON DELETE CASCADE`),
  `device_tokens.device_name`, `device_tokens.app_version`, `device_tokens.last_seen_at`.

Rollback: `migrations/rollback/010_rollback.sql`. Die Aenderungen sind ebenfalls
in `migrations/install_all.sql` eingepflegt (Neuinstallationen).



---

## 13. Neue Endpunkte: Planung, Totgeburten, Gruppenaktionen, Kennzahlen

> Additiv eingeführt mit Migration **015** (`planned_treatments`). Bestehende
> Endpunkte bleiben unverändert. Alle Endpunkte erfordern Bearer-Token und
> Betriebskontext (`X-Farm-Id`); Schreibzugriffe im Demo-Betrieb → `403
> DEMO_READONLY`. Antwortformat wie gehabt: `{success, data, meta}` bzw.
> `{success:false, error:{code,message}}`. IDs als Query-Parameter.

### 13.1 Geplante Behandlungen

Berechtigung: lesen `treatments.read`, schreiben `treatments.write`.

| Methode | Pfad | Zweck |
|--------|------|-------|
| GET | `/api/v1/planned-treatments` | Liste (Filter `cattle_id`, `status`, `from`, `to`; paginiert) |
| GET | `/api/v1/planned-treatments?id=123` | Einzelne geplante Behandlung |
| POST | `/api/v1/planned-treatments` | Anlegen |
| PUT | `/api/v1/planned-treatments?id=123` | Bearbeiten (nur solange `status=geplant`) |
| DELETE | `/api/v1/planned-treatments?id=123` | Löschen |
| POST | `/api/v1/planned-treatments/complete?id=123` | Erledigen → erzeugt Behandlung |

`status`-Werte: `geplant`, `erledigt`, `verworfen`.

**POST-Body (anlegen):**
```json
{
  "cattle_id": 12,
  "planned_date": "2026-11-05",
  "medication": "Cobactan",
  "type": "Antibiotikum",
  "dosage": "20 ml",
  "notes": "Nachbehandlung Tag 3"
}
```
**Antwort (201):** vollständiger Datensatz inkl. `id`, `status: "geplant"`.

**Erledigen – POST `/complete?id=123`**, Body optional:
```json
{ "treatment_date": "2026-11-05" }
```
Antwort (201):
```json
{ "success": true, "data": {
    "planned_treatment": { "id": 123, "status": "erledigt", "treatment_id": 456 },
    "treatment": { "id": 456, "cattle_id": 12, "treatment_date": "2026-11-05", "medication": "Cobactan" }
} }
```
Fehler: `409 CONFLICT`, wenn die geplante Behandlung nicht mehr offen ist.

### 13.2 Totgeburten

Berechtigung: lesen `cattle.read`, schreiben `cattle.write`. Totgeburten sind
einer Mutter-Kuh zugeordnet (`mother_cattle_id`) und zählen wie eine Kalbung.

| Methode | Pfad | Zweck |
|--------|------|-------|
| GET | `/api/v1/stillbirths?cattle_id=12` | Totgeburten einer Kuh (paginiert) |
| GET | `/api/v1/stillbirths?id=5` | Einzelne Totgeburt |
| POST | `/api/v1/stillbirths` | Anlegen |
| DELETE | `/api/v1/stillbirths?id=5` | Löschen |

**POST-Body:**
```json
{ "cattle_id": 12, "birth_date": "2026-10-01", "sex": "weiblich", "comment": "..." }
```
`sex`: `maennlich` | `weiblich` | `unbekannt` (Standard `unbekannt`).

Zusätzlich liefert `GET /api/v1/cattle?id=12` im Detail jetzt ein Feld
`stillbirths[]` (additiv zu den Kalbungen).

### 13.3 Gruppenaktionen pro Box

Berechtigung: lesen `cattle.read`; `move`/`inactivate` → `cattle.write`,
`treat`/`plan` → `treatments.write`.

| Methode | Pfad | Zweck |
|--------|------|-------|
| GET | `/api/v1/group-actions?stall=BoxA` | Aktive Tiere mit offenem Aufenthalt in der Box |
| POST | `/api/v1/group-actions` | Gruppenaktion für mehrere Tiere |

**POST-Body je nach `action`:**
```json
{ "action": "move",       "cattle_ids": [1,2,3], "target_stall": "BoxB", "date": "2026-11-01" }
{ "action": "inactivate", "cattle_ids": [1,2],   "date": "2026-11-01", "grund": "Verkauf" }
{ "action": "treat",      "cattle_ids": [1,2,3], "medication": "Cobactan", "type": "Antibiotikum", "dosage": "20 ml", "date": "2026-11-01" }
{ "action": "plan",       "cattle_ids": [1,2,3], "medication": "Cobactan", "type": "Antibiotikum", "planned_date": "2026-11-05" }
```
Antwort: `{ "action": "...", "processed": 3, "cattle_ids": [1,2,3] }`.
Alle Tier-IDs müssen zum Betrieb gehören (sonst `422 VALIDATION`); die Aktion läuft
transaktional (alles oder nichts).

### 13.4 Kennzahlen & Cattle-Filter

- `GET /api/v1/dashboard` enthält additiv:
  - `data.geplante_faellig[]` + `data.counts.geplante_faellig`
  - `data.kennzahlen.milchkuehe` und `data.kennzahlen.trockensteher`
  - **Robustheit (Hotfix):** Diese additiven Felder sind serverseitig abgesichert
    und **typ-stabil**. Kann ein Teil nicht berechnet werden (z. B. fehlender
    Helper nach einem Teil-Deploy), liefert der Endpunkt weiterhin HTTP 200 mit den
    Kern-Aufgaben; `geplante_faellig` ist dann ein leeres Array (`[]`) und
    `kennzahlen.milchkuehe`/`trockensteher` sind dann `0` (eine Ganzzahl) – niemals
    `null`. Die Datentypen der Antwort bleiben damit konstant, sodass auch strikte
    Client-Decoder (z. B. Swift `Codable` mit nicht-optionalen Feldern) die Antwort
    immer verarbeiten können.
- `GET /api/v1/cattle` – neue Filter:
  - `trockensteher=1` – aktive, weibliche Tiere mit Trockensteller/Versiegler und
    ohne Kalbung seither.
  - `milchkuh=1` – aktive, weibliche Tiere mit mindestens einer Kalbung/Totgeburt.

### 13.5 Fehlercodes (Auszug)
`VALIDATION` (422), `NOT_FOUND` (404), `CONFLICT` (409), `DEMO_READONLY` (403),
`METHOD_NOT_ALLOWED` (405), `SERVER_ERROR` (500).
