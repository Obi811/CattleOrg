# CattleOrg / „Muh App" – API- & iOS-Push-Dokumentation

> Übergabedokument für die **native iOS-App** (separater Chat).
> Beschreibt die vorhandene REST-API, die neuen Geräte-/Push-Endpunkte,
> das APNs-Push-System (Server-Seite) und alle Einrichtungsschritte auf
> dem cPanel-Hosting.

Stand: Oktober 2026 · API-Version: `v1` · Backend: PHP 8.3 / MariaDB (cPanel, ohne Root)

---

## 1. Grundlagen

| Thema | Wert |
|-------|------|
| Basis-URL | `https://DEINE-DOMAIN/api/v1` |
| Authentifizierung | `Authorization: Bearer <token>` |
| Betriebs-Kontext | Header `X-Farm-Id: <id>` **oder** Query `?farm_id=<id>` |
| Datenformat | JSON (Request-Body und Response) |
| Zeichensatz | UTF-8 |

**Antwort-Format** (einheitlich):

```jsonc
// Erfolg
{ "success": true, "data": { ... }, "meta": { ... } }
// Fehler
{ "success": false, "error": { "code": "VALIDATION", "message": "..." } }
```

Wichtige Fehlercodes: `UNAUTHENTICATED` (401), `FORBIDDEN` / `FARM_REQUIRED` (403),
`VALIDATION` (422), `RATE_LIMITED` (429), `NOT_FOUND` (404), `METHOD_NOT_ALLOWED` (405).

Ein Token wird beim Login erzeugt, als SHA-256-Hash in `api_tokens` gespeichert und
läuft nach `API_TOKEN_TTL_DAYS` (Standard 90 Tage) ab.

---

## 2. Vorhandene API-Endpunkte (Bestand)

Alle Endpunkte außer Login/Logout erfordern den Bearer-Token. Fachliche Endpunkte
erfordern zusätzlich den Betriebs-Kontext (`X-Farm-Id`) und eine Berechtigung.

| Methode & Pfad | Zweck | Berechtigung | Parameter |
|----------------|-------|--------------|-----------|
| `GET /api/v1` | Discovery (Liste der Endpunkte) | – | – |
| `POST /api/v1/auth/login` | Anmeldung → Token + Betriebe | – | Body: `username`, `password`, `device_name?` |
| `POST /api/v1/auth/logout` | aktuellen Token widerrufen | Token | – |
| `GET /api/v1/farm` | zugängliche Betriebe | Token | – |
| `GET /api/v1/cattle` | Tierliste (paginiert) | `cattle.read` | `?page=`, `?per_page=`; `?id=` für Einzeltier |
| `POST /api/v1/cattle` | Tier anlegen | `cattle.write` | Body: Tierdaten |
| `GET /api/v1/treatments` | Behandlungen | `treatments.read` | `?cattle_id=` |
| `POST /api/v1/treatments` | Behandlung anlegen | `treatments.write` | Body (Medikament wird gegen `medications` geprüft) |
| `GET /api/v1/medications` | globaler Medikamentenkatalog | Token | – |
| `GET /api/v1/inseminations` | Besamungen | `cattle.read` | `?cattle_id=` |
| `POST /api/v1/inseminations` | Besamung anlegen | `inseminations.write` | Body |
| `GET /api/v1/pregnancy` | Trächtigkeitskontrollen | `cattle.read` | `?cattle_id=` |
| `POST /api/v1/pregnancy` | Kontrolle anlegen | `pregnancy.write` | Body |
| `GET /api/v1/stalls` | Ställe des Betriebs | `cattle.read` | – |
| **`POST /api/v1/devices`** | **Push-Gerät registrieren/aktualisieren** | **Token** | **(neu, siehe §3)** |
| **`PUT /api/v1/devices`** | **wie POST (Alias)** | **Token** | **(neu)** |
| **`DELETE /api/v1/devices`** | **Push-Gerät abmelden** | **Token** | **(neu)** |

### 2.1 Login-Antwort (Beispiel)

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "username": "max", "password": "geheim", "device_name": "iPhone 15" }
```

```json
{
  "success": true,
  "data": {
    "token": "AbC123...",
    "token_type": "Bearer",
    "expires_in_days": 90,
    "user": { "id": 5, "username": "max", "is_admin": false },
    "farms": [
      { "id": 2, "name": "Hof Meier", "is_demo": false, "role": "owner" }
    ]
  }
}
```

### 2.2 Noch NICHT vorhandene Endpunkte (ehrlich dokumentiert)

Diese Funktionen gibt es aktuell **nur in der Web-App**, nicht als API. Falls die
iOS-App sie braucht, müssen sie noch gebaut werden:

- **Hemmstoffproben** (`hemmstoff_probes`): keine API (nur Web). Tabelle existiert.
- **Aufgaben-/Überblick-Liste** (`todo_list.php`): keine API. Die Push-Logik bildet
  dieselben Berechnungen serverseitig ab (siehe §5), liefert sie aber nicht als
  JSON-Liste aus.
- **Profil / Passwort ändern**: nur Web (`profile.php`).
- **Cattle `PUT`/`DELETE`**: nur `GET`/`POST` vorhanden (kein Bearbeiten/Löschen über API).
- **Logout global**: widerruft nur den aktuell verwendeten Token, nicht alle Geräte.

---

## 3. Neue Endpunkte: Geräte-Registrierung für Push

Die App meldet nach dem Login ihr APNs-Device-Token beim Server an. Der
Betriebs-Kontext ist **optional**: ohne `X-Farm-Id` gilt das Gerät für **alle**
Betriebe des Benutzers; mit gültigem `X-Farm-Id` nur für diesen Betrieb.

### 3.1 Registrieren / Aktualisieren

```http
POST /api/v1/devices
Authorization: Bearer <token>
Content-Type: application/json

{
  "token": "<APNs-Device-Token-Hex>",
  "platform": "ios",
  "environment": "production",
  "prefs": {
    "weiterbehandlung": true,
    "wartezeit": true,
    "traechtigkeit": true,
    "hemmstoff": true
  }
}
```

- `token` – **Pflicht**, das APNs-Device-Token (Hex).
- `platform` – optional, `ios` (Standard) oder `android`.
- `environment` – optional, `production` (Standard) oder `sandbox`
  (TestFlight/Debug-Builds nutzen i. d. R. `sandbox`).
- `prefs` – optional, einzelne Mitteilungsarten an-/abschalten (Standard: alle an).

Der Aufruf ist ein **Upsert** über das eindeutige `token`: erneutes Senden
aktualisiert den Datensatz (z. B. geänderte `prefs` oder Betriebszuordnung) und
reaktiviert ein zuvor abgemeldetes Gerät.

**Antwort (201):**

```json
{
  "success": true,
  "data": {
    "id": 12,
    "platform": "ios",
    "environment": "production",
    "farm_id": 2,
    "is_active": true,
    "prefs": { "weiterbehandlung": true, "wartezeit": true,
               "traechtigkeit": true, "hemmstoff": true },
    "message": "Geraet registriert/aktualisiert."
  }
}
```

### 3.2 Abmelden

```http
DELETE /api/v1/devices
Authorization: Bearer <token>
Content-Type: application/json

{ "token": "<APNs-Device-Token-Hex>" }
```

Setzt `is_active = 0` – aber nur für ein Gerät, das dem **eigenen** Benutzer
gehört (Schutz gegen fremde Token-Manipulation).

```json
{ "success": true, "data": { "message": "Geraet abgemeldet.", "removed": true } }
```

### 3.3 Fehlerfälle

| Situation | HTTP | `error.code` |
|-----------|------|--------------|
| Kein/ungültiger Bearer-Token | 401 | `UNAUTHENTICATED` |
| `token` fehlt im Body | 422 | `VALIDATION` |
| Falsche Methode (z. B. GET) | 405 | `METHOD_NOT_ALLOWED` |

---

## 4. Push-Payload (APNs) & Deep-Links

Der Server sendet `apns-push-type: alert`, `apns-priority: 10`,
`apns-topic: <Bundle-ID>`. Das Payload enthält neben `aps` zusätzliche Felder
für die Navigation in der App:

```json
{
  "aps": {
    "alert": { "title": "Hemmstoffprobe faellig",
               "body": "Wartezeit fuer 27 601 0001001 abgelaufen. Bitte Hemmstoffprobe senden." },
    "sound": "default"
  },
  "type": "wartezeit",
  "farm_id": 2,
  "cattle_id": 345
}
```

| Feld | Bedeutung |
|------|-----------|
| `type` | Mitteilungsart: `weiterbehandlung`, `wartezeit`, `hemmstoff`, `traechtigkeit` **oder** `summary` (Sammel-Mitteilung) |
| `farm_id` | Betrieb, auf den sich die Mitteilung bezieht (Deep-Link / Betriebswechsel) |
| `cattle_id` | betroffenes Tier (fehlt bei `weiterbehandlung` und `summary`, da aggregiert) |
| `aps.badge` | Gesamtzahl offener Aufgaben, die dieses Gerät betreffen (App-Symbol) |
| `aps.thread-id` | Gruppierung in iOS, je Betrieb: `farm-<farm_id>` |

Empfehlung für die App: beim Tippen auf eine Einzel-Mitteilung anhand `type` +
`farm_id` (+ `cattle_id`) direkt auf die passende Ansicht navigieren (Tierdetail,
Aufgabenliste etc.).

### 4.1 Sammel-Mitteilung (`type = "summary"`)

Erhält ein Benutzer an einem Tag **mehrere** (standardmäßig ≥ 3) nicht-dringende
fällige Aufgaben, fasst der Server sie zu **einer** Mitteilung je Betrieb/Tag
zusammen, statt viele Einzel-Pushes zu senden. Dringende Mitteilungen
(`wartezeit` – Hemmstoffprobe fällig) gehen weiterhin **immer einzeln**.

```json
{
  "aps": {
    "alert": { "title": "Musterhof - heute faellig",
               "body": "5 Traechtigkeitskontrollen, 2 Hemmstoffproben (Ergebnis), 1 Weiterbehandlung" },
    "sound": "default",
    "badge": 8,
    "thread-id": "farm-2"
  },
  "type": "summary",
  "target": "tasks",
  "farm_id": 2,
  "filter": "traechtigkeit"
}
```

| Feld | Bedeutung |
|------|-----------|
| `type` | immer `"summary"` |
| `target` | immer `"tasks"` → die App soll in den **Aufgaben**-Tab springen |
| `farm_id` | Betrieb; bei mehreren Betrieben vorher dorthin wechseln |
| `filter` | **optional**; nur gesetzt, wenn die Übersicht genau **eine** Kategorie enthält. Werte: `traechtigkeit`, `hemmstoff`, `weiterbehandlung`. Die App kann die Aufgabenliste direkt danach filtern. |

Zusätzlich sendet der Server bei Sammel-Mitteilungen den Header
`apns-collapse-id: sum-<farm_id>-<JJJJMMTT>`. Dadurch **ersetzt** eine neuere
Übersicht vom selben Tag die ältere auf dem Sperrbildschirm, statt sich zu stapeln.

**Verhalten der App beim Tippen:**
- `type == "summary"` → in den Aufgaben-Tab wechseln (vorher ggf. Betrieb
  `farm_id` aktivieren), bei gesetztem `filter` direkt vorfiltern.
- sonst (Einzel-Mitteilung) → wie bisher über `cattle_id` zum Tier.

---

## 5. Serverseitige Push-Logik (Cron)

Das Skript `cron/push_notifications.php` (reine CLI, **nicht** über das Web
erreichbar) berechnet täglich je **aktivem Nicht-Demo-Betrieb** die fälligen
Aufgaben – dieselbe Logik wie die Web-Seite „Aufgaben" (`todo_list.php`) – und
verschickt daraus Push-Nachrichten.

**Die 4 Mitteilungsarten:**

1. **`weiterbehandlung`** – Behandlungen von *gestern* (außer „Trockenstellen").
   Aggregiert zu einer Nachricht pro Betrieb/Tag. `ref_key = date:<Datum>`.
2. **`wartezeit`** – Hemmstoffprobe fällig: Wartezeit (`medications.wz_milk`) der
   letzten Behandlung abgelaufen, keine „frei"-Probe danach, keine „versendet"-Probe.
   Pro Tier. `ref_key = cattle:<id>:<wartezeit_ende>`.
3. **`hemmstoff`** – Hemmstoffprobe mit Status „versendet" (Ergebnis ausstehend).
   Pro Probe. `ref_key = probe:<id>`.
4. **`traechtigkeit`** – letzte Besamung ≥ 35 Tage her, noch keine
   Trächtigkeitskontrolle danach. Pro Tier. `ref_key = cattle:<id>:<besamungsdatum>`.

**Empfänger:** alle Mitglieder (`farm_users`) des Betriebs mit Berechtigung
`cattle.read`, die mindestens ein aktives iOS-Gerät registriert haben und die
jeweilige Mitteilungsart nicht abgeschaltet haben. *Hinweis:* System-Admins ohne
`farm_users`-Mitgliedschaft erhalten bewusst keine betriebsbezogenen Pushes.

**Zusammenfassen (Sammel-Mitteilung):** Hat ein Benutzer an einem Tag mehrere
nicht-dringende fällige Aufgaben, werden sie zu **einer** Mitteilung je
Betrieb/Tag gebündelt (`type = "summary"`, siehe Abschnitt 4.1), statt viele
Einzel-Pushes zu senden. Der Schwellwert steht als `$SUMMARY_THRESHOLD` oben im
Skript (Standard: `3` → ab 3 Aufgaben Sammel-Mitteilung, bei 1–2 weiterhin
Einzel-Pushes). Dringende `wartezeit`-Mitteilungen gehen **immer einzeln**. Der
Badge-Wert je Gerät entspricht der Gesamtzahl der für dieses Gerät fälligen
Aufgaben.

**Doppelversand-Schutz:** Jede zugestellte Mitteilung wird in `push_log`
(eindeutig über `user_id` + `notification_type` + `ref_key`) protokolliert.
Ein erneuter Cron-Lauf am selben Tag sendet nichts doppelt – das gilt auch für
Mitteilungen, die Teil einer Sammel-Mitteilung waren.

**Ungültige Tokens:** Antwortet APNs mit `410` bzw.
`BadDeviceToken`/`Unregistered`/`DeviceTokenNotForTopic`, wird das Gerät
automatisch auf `is_active = 0` gesetzt.

**Aufrufoptionen:**

```bash
php cron/push_notifications.php             # Normallauf (sendet)
php cron/push_notifications.php --dry-run   # zeigt nur, was passieren würde (sendet NICHT)
php cron/push_notifications.php --verbose   # mehr Logausgabe
```

---

## 6. Einrichtung im Apple Developer Portal

1. **App ID / Bundle Identifier** anlegen (z. B. `de.obitron.muhapp`) und dort die
   Fähigkeit **Push Notifications** aktivieren.
2. Unter **Certificates, Identifiers & Profiles → Keys** einen neuen **Key**
   erstellen, **APNs** anhaken, herunterladen → Datei `AuthKey_XXXXXXXXXX.p8`.
   - Die **Key ID** (10 Zeichen) steht am Key.
   - Der Download ist **nur einmal** möglich – sicher aufbewahren.
3. Die **Team ID** (10 Zeichen) steht oben rechts im Developer-Account / unter
   „Membership".
4. In der iOS-App die Capability **Push Notifications** (und ggf. Background Modes
   → Remote notifications) hinzufügen; `UIApplication.registerForRemoteNotifications()`
   aufrufen und das erhaltene Device-Token an `POST /api/v1/devices` schicken.

---

## 7. Server-Einrichtung (cPanel)

### 7.1 Neue `.env`-Variablen

In der `.env` (nicht im Git!) eintragen – Vorlage siehe `.env.example`:

```ini
APNS_ENABLED="true"
APNS_KEY_PATH="/home/DEINACCOUNT/apns/AuthKey_XXXXXXXXXX.p8"
APNS_KEY_ID="XXXXXXXXXX"
APNS_TEAM_ID="YYYYYYYYYY"
APNS_BUNDLE_ID="de.obitron.muhapp"
APNS_DEFAULT_ENV="production"
```

> **Wichtig:** Die `.p8`-Datei **außerhalb** von `public/` ablegen (z. B. in
> `/home/DEINACCOUNT/apns/`), damit sie nicht über das Web abrufbar ist.
> Dateirechte möglichst restriktiv (z. B. `600`).

### 7.2 Datenbank-Migration (phpMyAdmin)

Die neue Migration heißt **`009_create_device_tokens.sql`** (die Nummer `008` ist
bereits durch die Seed-Migration belegt). Sie legt die Tabellen `device_tokens`
und `push_log` an.

- **Entweder** in phpMyAdmin den Inhalt von
  `migrations/009_create_device_tokens.sql` im Tab **SQL** ausführen,
- **oder** per CLI: `php migrations/run_migrations.php` (führt offene Migrationen aus).

Rückgängig machen (nur falls nötig): `migrations/rollback/009_rollback.sql`.

### 7.3 Cronjob einrichten

In cPanel unter **Cron Jobs** einen täglichen Job anlegen (Pfad zu PHP ggf.
anpassen – in cPanel oft `/usr/local/bin/php` oder `/usr/bin/php`):

```
0 6 * * * /usr/local/bin/php /home/DEINACCOUNT/cattleorg/cron/push_notifications.php >> /home/DEINACCOUNT/cattleorg/logs/push.log 2>&1
```

Empfehlung: zuerst manuell mit `--dry-run` testen.

### 7.4 Hochzuladende / neue Dateien

| Datei | Zweck |
|-------|-------|
| `migrations/009_create_device_tokens.sql` | neue Tabellen (in phpMyAdmin ausführen) |
| `migrations/rollback/009_rollback.sql` | Rollback der Migration |
| `includes/push.php` | APNs-Versandmodul (JWT ES256, HTTP/2) |
| `public/api/v1/devices/index.php` | Geräte-Endpunkt (register/update/unregister) |
| `cron/push_notifications.php` | täglicher Push-Cronjob (CLI) |
| `public/api/v1/index.php` | aktualisierte Discovery-Liste |
| `.env.example` | neue APNS_*-Variablen (Vorlage) |
| `.env` | **manuell** die echten APNS_*-Werte eintragen |
| `AuthKey_XXXXXXXXXX.p8` | **manuell**, außerhalb `public/` ablegen (nicht im Git) |

---

## 8. Hinweise zum Testen

- Ob der **reale APNs-Versand** funktioniert, lässt sich nur mit echtem
  `.p8`-Schlüssel und echtem Device-Token prüfen (nicht in der Entwicklungsumgebung
  getestet). Verifiziert wurden: JWT-Erzeugung (ES256), Payload-Aufbau,
  Dedupe-Logik und die Geräte-Endpunkte.
- Für erste Tests eignet sich der **Sandbox**-Server
  (`api.sandbox.push.apple.com`) mit Debug-/TestFlight-Builds → `environment: "sandbox"`
  beim Registrieren setzen.
