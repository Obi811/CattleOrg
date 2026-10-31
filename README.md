# CattleOrg – Mandantenfähige Rinderverwaltung

CattleOrg ist die neu entwickelte, **mandantenfähige (multi-tenant)** Version der
Rinderverwaltung. Mehrere Betriebe (Mandanten) teilen sich eine Anwendung und eine
Datenbank, ihre Daten sind jedoch über eine `farm_id`-Trennung strikt voneinander
isoliert. Die Anwendung ist in **PHP 8** (ohne Framework, schlanke eigene Struktur)
mit **MySQL/MariaDB** umgesetzt und stellt zusätzlich eine **REST-API** bereit, die
als Grundlage für eine spätere mobile App dient.

---

## Inhaltsverzeichnis

1. [Funktionsüberblick](#funktionsüberblick)
2. [Technischer Aufbau](#technischer-aufbau)
3. [Installation](#installation)
4. [Konfiguration](#konfiguration)
5. [Mandantenfähigkeit (Multi-Tenancy)](#mandantenfähigkeit-multi-tenancy)
6. [Rollen & Berechtigungen](#rollen--berechtigungen)
7. [Demo-Betrieb & Standardzugänge](#demo-betrieb--standardzugänge)
8. [REST-API](#rest-api)
9. [Push-Benachrichtigungen (iOS/APNs)](#push-benachrichtigungen-iosapns)
10. [Datenbank-Migrationen](#datenbank-migrationen)
11. [Sicherheit](#sicherheit)
12. [Projektstruktur](#projektstruktur)

> **Weiterführende Dokumentation:** [`docs/API_IOS.md`](docs/API_IOS.md)
> (REST-API für die native iOS-App) und [`docs/API_IOS_PUSH.md`](docs/API_IOS_PUSH.md)
> (API + APNs-Push-System, Einrichtung auf cPanel).

---

## Funktionsüberblick

- **Tierverwaltung**: Anlegen, Bearbeiten, Detail- und Verlaufsansicht von Tieren
  (Ohrmarke, Geburtsdatum, Rasse, Geschlecht, Mutter, Status, Zu-/Abgang) mit
  Filtern in der Tierliste (Status, Geschlecht, Stall, Wartezeit, Trächtigkeit).
- **Abgang/Verendung melden** (`cattle_abgang.php`): Tier auf inaktiv setzen,
  optionalen Grund speichern und den laufenden Stallaufenthalt beenden.
- **Behandlungen** mit globalem Medikamentenkatalog (inkl. Wartezeiten Milch/Fleisch)
  und automatischer **Wartezeit-Warnung** je Tier.
- **Hemmstoffproben** verwalten (versendet / frei / nicht frei).
- **Besamungen** und **Trächtigkeitskontrollen** inklusive Übersicht offener Kontrollen.
- **Stallverwaltung** pro Betrieb (ersetzt die früher hartcodierte Stallliste) inkl.
  Stallhistorie je Tier.
- **Kalender** mit Monatsansicht und **Aufgaben-/Erinnerungsliste** (`todo_list.php`):
  fällige Weiterbehandlungen, Hemmstoffproben und Trächtigkeitskontrollen auf einen Blick.
- **CSV-Import** des Tierbestands (authentifiziert und betriebsbezogen).
- **PDF-Export** von Tierliste und Kalender (per `dompdf`, mit Druck-Fallback).
- **Profil** (`profile.php`): eigenes Passwort und eigene E-Mail-Adresse ändern.
- **Benutzerverwaltung** je Betrieb (Subaccounts anlegen/bearbeiten/löschen,
  Rollen & Berechtigungen) sowie System-Administration (Betriebe, globale Benutzer,
  globaler Medikamentenkatalog).
- **REST-API** (`/api/v1`) mit Bearer-Token-Authentifizierung als Grundlage für die
  native iOS-App („Muh App").
- **Push-Benachrichtigungen** für iOS (Apple APNs, direkt aus PHP) über einen
  täglichen Cronjob – mit Einzel- und **Sammel-Mitteilungen**.

---

## Technischer Aufbau

| Komponente      | Technologie                                  |
|-----------------|----------------------------------------------|
| Sprache         | PHP 8.0+ (ohne Framework)                     |
| Datenbank       | MySQL 5.7+/8 oder MariaDB 10.4+               |
| Datenzugriff    | PDO mit Prepared Statements                   |
| Frontend        | Server-seitiges PHP + Bootstrap 5 (CDN)       |
| PDF-Export      | dompdf (via Composer)                          |
| Multi-Tenancy   | Shared Database, Shared Schema (`farm_id`)     |
| API-Auth        | Bearer-Token (SHA-256-Hash in `api_tokens`)    |
| Push (iOS)      | Apple APNs direkt aus PHP (JWT ES256, HTTP/2 via curl) |
| Cron            | täglicher CLI-Job `cron/push_notifications.php` |

> **Hinweis zur Produktionsumgebung:** Gehostet wird auf HostEurope/cPanel mit
> **PHP 8.3** und **MariaDB** (ohne Root-Zugriff). Der Push-Versand nutzt keine
> zusätzliche Composer-Abhängigkeit – APNs wird mit PHP-Bordmitteln (`openssl`,
> `curl` mit HTTP/2) angesprochen.

---

## Installation

### Fertiges Download-Paket (empfohlen für HostEurope/cPanel)

Bei jedem [Release](https://github.com/Obi811/CattleOrg/releases) wird automatisch ein
fertiges Deployment-Paket erzeugt und als Download bereitgestellt:

- **`CattleOrg_deploy_<version>.zip`** – komplette App **inkl. `vendor/`-Abhängigkeiten**
  und vorbereiteter `.env` (mit frisch erzeugtem `APP_KEY`). Es wird **kein SSH/Composer**
  auf dem Server benötigt.
- **`CattleOrg_install.sql`** – alle Datenbank-Migrationen in einer Datei für den Import
  über phpMyAdmin.
- **`...zip.sha256`** – Prüfsumme zur Integritätskontrolle.

> Schritt-für-Schritt-Anleitung für HostEurope: siehe [`INSTALL_cPanel.md`](INSTALL_cPanel.md).
>
> Das Paket wird über den GitHub-Actions-Workflow `.github/workflows/release.yml` gebaut.
> Es lässt sich auch manuell über **Actions → „Release-Paket erstellen" → Run workflow**
> anstoßen (Ergebnis dann unter den Workflow-Artefakten).

### Voraussetzungen

- PHP **8.0 oder höher** mit den Erweiterungen `pdo_mysql` und `json`
- MySQL/MariaDB
- [Composer](https://getcomposer.org/) (für PDF-Export und Autoloading)
- Ein Webserver (Apache/Nginx) **oder** der eingebaute PHP-Server zum Testen

### Schritt für Schritt

```bash
# 1. Repository klonen
git clone git@github.com:Obi811/CattleOrg.git
cd CattleOrg

# 2. Abhängigkeiten installieren (dompdf etc.)
composer install

# 3. Konfiguration anlegen
cp .env.example .env
#    .env anschließend mit einem Editor öffnen und Werte anpassen
#    (DB-Zugang, BASE_URL, APP_ENV, APP_KEY, …)

# 4. Datenbank anlegen
mysql -u root -p -e "CREATE DATABASE cattleorg CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# 5. Migrationen + Seed-Daten einspielen
php migrations/run_migrations.php

# 6. Anwendung lokal starten (Document-Root = public/)
php -S localhost:8000 -t public
```

Danach ist die Anwendung unter `http://localhost:8000/login.php` erreichbar.

> **Migrationsstatus / Rollback**
> ```bash
> php migrations/run_migrations.php --status     # Status aller Migrationen
> php migrations/run_migrations.php --rollback   # letzte Migrationen zurückrollen
> ```

### Produktivbetrieb

- Setze den **Document-Root des Webservers auf das Verzeichnis `public/`**, damit
  `includes/`, `migrations/` und `templates/` nicht direkt erreichbar sind.
  (Ergänzend liegen in diesen Ordnern `.htaccess`-Dateien, die einen Direktzugriff
  unter Apache verweigern.)
- Setze in der `.env`: `APP_ENV="production"` und `APP_HTTPS="true"`.
- Stelle sicher, dass der Ordner `logs/` durch den Webserver beschreibbar ist.

---

## Konfiguration

Alle Einstellungen erfolgen über die `.env`-Datei (niemals einchecken!). Die wichtigsten Werte:

| Variable            | Bedeutung                                                        |
|---------------------|------------------------------------------------------------------|
| `APP_NAME`          | Anzeigename der Anwendung                                         |
| `BASE_URL`          | Basis-Pfad ab Domain-Root (z. B. `/` oder `/cattleorg/`)         |
| `APP_ENV`           | `production` (Fehler werden geloggt) oder `development`           |
| `APP_HTTPS`         | `true` aktiviert Secure-Cookies                                  |
| `DB_HOST`/`DB_PORT` | Datenbank-Host und -Port                                         |
| `DB_NAME`           | Datenbankname                                                    |
| `DB_USER`/`DB_PASS` | Datenbank-Zugangsdaten                                           |
| `APP_KEY`           | Zufälliger Schlüssel (`php -r "echo bin2hex(random_bytes(32));"`) |
| `SESSION_LIFETIME`  | Session-Lebensdauer in Sekunden (Standard 28800 = 8 h)          |
| `API_TOKEN_TTL_DAYS`| Gültigkeit eines API-Tokens in Tagen                            |
| `API_RATE_LIMIT`    | Max. API-Anfragen pro Zeitfenster                               |
| `API_RATE_WINDOW`   | Rate-Limit-Fenster in Sekunden                                  |

### Push-Konfiguration (Apple APNs)

Die iOS-Push-Benachrichtigungen werden direkt über Apple APNs versendet. Die
zugehörigen Variablen stehen ebenfalls in der `.env` (Vorlage: `.env.example`):

| Variable           | Bedeutung                                                               |
|--------------------|-------------------------------------------------------------------------|
| `APNS_ENABLED`     | `true` aktiviert den APNs-Versand, `false` schaltet ihn ab              |
| `APNS_KEY_PATH`    | Absoluter Pfad zur `.p8`-Schlüsseldatei – **außerhalb von `public/`!**  |
| `APNS_KEY_ID`      | Key ID des `.p8`-Schlüssels (Apple Developer Portal → Keys)             |
| `APNS_TEAM_ID`     | Team ID (Apple Developer Portal → Membership)                           |
| `APNS_BUNDLE_ID`   | Bundle Identifier der iOS-App (z. B. `de.obitron.muhapp`) = `apns-topic`|
| `APNS_DEFAULT_ENV` | Standard-Umgebung, falls ein Gerät keine angibt: `production`/`sandbox` |
| `APNS_URL`         | Optionaler Host-Override (sonst automatisch je nach Umgebung)           |

> **FCM nicht in Verwendung:** Die Variablen `FCM_CREDENTIALS_PATH` und
> `FCM_PROJECT_ID` sind in der `.env.example` lediglich als Platzhalter für eine
> eventuelle spätere Android-/Firebase-Unterstützung vorhanden. Sie werden vom
> aktuellen Code **nicht** ausgewertet und können leer bleiben.

---

## Mandantenfähigkeit (Multi-Tenancy)

CattleOrg verwendet das Muster **„Shared Database, Shared Schema"**:

- Jede fachliche Tabelle (z. B. `cattle`, `treatments`, `inseminations`,
  `pregnancy_checks`, `stall_history`, `hemmstoff_probes`, `stalls`) besitzt eine
  Spalte **`farm_id`**, die den Datensatz genau einem Betrieb zuordnet.
- **Jede** Datenbankabfrage wird konsequent mit `WHERE farm_id = :farm_id` gefiltert.
  Einzelsatz-Zugriffe prüfen zusätzlich `AND id = :id`, um fremde Datensätze auch bei
  manipulierten IDs auszuschließen (Schutz vor *Insecure Direct Object Reference*).
- Der aktive Betrieb wird nach dem Login in der Session gehalten
  (`current_farm_id`). Bei mehreren Betrieben erfolgt eine **Betriebsauswahl**
  (`farm_select.php`), die jederzeit gewechselt werden kann.
- Die Zuordnung Benutzer ↔ Betrieb mit Rolle erfolgt in der Tabelle `farm_users`
  (n:m-Beziehung). So kann ein Benutzer in mehreren Betrieben mit
  unterschiedlichen Rollen tätig sein.

### Globale Daten: Medikamentenkatalog

Der **Medikamentenkatalog (`medications`) ist bewusst global** und besitzt *keine*
`farm_id`. Behandlungen referenzieren den Medikamentennamen; so steht allen Betrieben
ein einheitlicher, gepflegter Katalog (inkl. Wartezeiten) zur Verfügung. Die Pflege
erfolgt durch berechtigte Betriebsnutzer (`medications.manage`) bzw. zentral durch
den System-Administrator.

---

## Rollen & Berechtigungen

Pro Betrieb erhält ein Benutzer eine Rolle. Daraus ergeben sich Standard-Berechtigungen,
die je Benutzer optional feiner übersteuert werden können (`permissions`-Tabelle).

| Rolle      | Beschreibung                                                        |
|------------|---------------------------------------------------------------------|
| `owner`    | Vollzugriff inkl. **Benutzerverwaltung** des Betriebs (`users.manage`) |
| `manager`  | Alle Datenoperationen inkl. Medikamentenpflege, **keine** Benutzerverwaltung |
| `worker`   | Tiere, Behandlungen, Besamungen, Trächtigkeitskontrollen erfassen   |
| `viewer`   | Nur Lesezugriff (z. B. Demo-Betrieb)                                |

Wichtige Berechtigungs-Schlüssel: `cattle.read`, `cattle.write`, `treatments.read`,
`treatments.write`, `medications.manage`, `inseminations.write`, `pregnancy.write`,
`stall.manage`, `users.manage`, `export.access`, `import.access`.

Der **System-Administrator** (`users.is_admin = 1`) steht über den Betrieben: Er
verwaltet Betriebe, alle Benutzer und den globalen Medikamentenkatalog und kann jeden
aktiven Betrieb betreten.

**Verwaltungsseiten** (Berechtigung `users.manage` bzw. Admin):

- `public/admin/user_list.php` – Benutzerübersicht
- `public/admin/user_edit.php` – Benutzer anlegen/bearbeiten/löschen, Betriebszuordnung
- `public/admin/farm_users.php` – Mitglieder & Rollen eines Betriebs pflegen
- `public/admin/farm_manage.php` – Betriebe verwalten (Admin)
- `public/admin/medications.php` – globalen Medikamentenkatalog pflegen

---

## Demo-Betrieb & Standardzugänge

Nach dem Einspielen der Seed-Daten existieren folgende Zugänge:

| Zugang  | Benutzer | Passwort   | Rolle                         |
|---------|----------|------------|-------------------------------|
| Admin   | `admin`  | `admin1234`| System-Administrator          |
| Demo    | `demo`   | `demo2024` | Viewer im Demo-Betrieb (lesend)|

> ⚠️ **Wichtig:** Das Admin-Passwort `admin1234` ist nur für die Erstinbetriebnahme.
> **Bitte unmittelbar nach dem ersten Login ändern!**

Der **Demo-Betrieb** ist als `is_demo = 1` markiert und **schreibgeschützt**:
Schreibzugriffe werden serverseitig blockiert (`requireWriteAccess()` bzw.
`apiRequireWrite()`), sodass die Demo gefahrlos ausprobiert werden kann. Über den
Button „Demo-Betrieb ansehen" auf der Login-Seite gelangt man ohne Zugangsdaten hinein.

---

## REST-API

Die API ist unter `/api/v1` erreichbar und für die spätere mobile App vorbereitet.

### Authentifizierung

1. Token über `POST /api/v1/auth/login` anfordern.
2. Token bei allen weiteren Aufrufen im Header mitsenden:
   `Authorization: Bearer <token>`
3. Den Betriebskontext über den Header `X-Farm-Id: <id>` (oder `?farm_id=`) angeben.

Antwortformat (einheitlich):

```jsonc
// Erfolg
{ "success": true, "data": { /* ... */ }, "meta": { /* optional */ } }
// Fehler
{ "success": false, "error": { "code": "VALIDATION", "message": "…" } }
```

> **Pfad-Konvention:** Alle Endpunkte lassen sich im **Ordner-Stil mit
> abschließendem Slash** aufrufen (z. B. `POST /api/v1/auth/login/`). Für
> `auth/login` und `auth/logout` bleiben zusätzlich die Dateipfade
> (`…/login.php`, `…/logout.php`) als Alias gültig (Abwärtskompatibilität).

### Endpunkte (vollständig)

| Methode & Pfad                  | Beschreibung                                              | Berechtigung     |
|---------------------------------|----------------------------------------------------------|------------------|
| `GET    /api/v1`                | Discovery (Liste aller Endpunkte)                        | –                |
| `POST   /api/v1/auth/login`     | Anmeldung, liefert Token + zugängliche Betriebe          | –                |
| `POST   /api/v1/auth/logout`    | Aktuelles Token widerrufen (meldet auch Push-Geräte ab)  | Token            |
| `GET    /api/v1/user`           | Eigenes Profil + Betriebe inkl. Berechtigungen           | Token            |
| `PUT    /api/v1/user/password`  | Eigenes Passwort ändern                                   | Token            |
| `GET    /api/v1/farm`           | Zugängliche Betriebe des Benutzers                       | Token            |
| `GET    /api/v1/dashboard`      | Kennzahlen + fällige Aufgaben des Betriebs               | `cattle.read`    |
| `GET    /api/v1/cattle`         | Tierliste (paginiert, Filter); `?id=` für ein Einzeltier | `cattle.read`    |
| `POST   /api/v1/cattle`         | Tier anlegen                                             | `cattle.write`   |
| `POST   /api/v1/cattle/abgang`  | Abgang/Verendung eines Tieres erfassen                   | `cattle.write`   |
| `GET    /api/v1/cattle/breeds`  | Vorhandene Rassen des Betriebs (Auswahlliste)            | `cattle.read`    |
| `GET    /api/v1/treatments`     | Behandlungen (`?cattle_id=`) inkl. Wartezeit-Feldern     | `treatments.read`|
| `POST   /api/v1/treatments`     | Behandlung anlegen                                       | `treatments.write`|
| `GET    /api/v1/inhibitor-tests`| Hemmstoffproben (`?cattle_id=`, `?status=`)              | `cattle.read`    |
| `POST   /api/v1/inhibitor-tests`| Hemmstoffprobe anlegen                                   | `treatments.write`|
| `PUT    /api/v1/inhibitor-tests`| Hemmstoffprobe-Status aktualisieren (`?id=`)            | `treatments.write`|
| `GET    /api/v1/medications`    | Globaler Medikamentenkatalog                            | Token            |
| `GET    /api/v1/inseminations`  | Besamungen (`?cattle_id=`)                              | `cattle.read`    |
| `POST   /api/v1/inseminations`  | Besamung anlegen                                        | `inseminations.write`|
| `GET    /api/v1/pregnancy`      | Trächtigkeitskontrollen (`?cattle_id=`)                 | `cattle.read`    |
| `POST   /api/v1/pregnancy`      | Kontrolle anlegen                                       | `pregnancy.write`|
| `GET    /api/v1/stalls`         | Ställe des Betriebs                                     | `cattle.read`    |
| `POST/PUT/DELETE /api/v1/devices` | Push-Gerät registrieren/aktualisieren/abmelden (APNs) | Token            |
| `POST/PUT/DELETE /api/v1/device-tokens` | Alias zu `/api/v1/devices`                      | Token            |

Eine maschinenlesbare Übersicht liefert `GET /api/v1` selbst (Discovery-Endpunkt).

> **Noch nicht als API vorhanden** (bewusst dokumentiert): `PUT`/`DELETE` für
> Tiere (Bearbeiten/Löschen) sowie ein globales „Logout aller Geräte". Details
> siehe [`docs/API_IOS.md`](docs/API_IOS.md) und [`docs/API_IOS_PUSH.md`](docs/API_IOS_PUSH.md).

### Beispiele

```bash
# 1) Anmelden und Token erhalten
curl -X POST http://localhost:8000/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -d '{"username":"demo","password":"demo2024","device_name":"Test"}'

# 2) Tierliste des Betriebs 1 abrufen
curl http://localhost:8000/api/v1/cattle \
     -H "Authorization: Bearer <TOKEN>" \
     -H "X-Farm-Id: 1"
```

### Rate-Limiting

Pro Token gilt ein konfigurierbares Limit (`API_RATE_LIMIT` Anfragen je
`API_RATE_WINDOW` Sekunden). Bei Überschreitung antwortet die API mit
`429 RATE_LIMITED` und einem `Retry-After`-Header.

---

## Push-Benachrichtigungen (iOS/APNs)

CattleOrg verschickt Push-Mitteilungen für die native iOS-App **direkt über Apple
APNs** – ohne externen Dienst, ohne zusätzliche Composer-Abhängigkeit und ohne FCM.
Der Versand erfolgt mit PHP-Bordmitteln: ein ES256-JWT (`openssl`) authentifiziert
gegen APNs, die Zustellung läuft über **HTTP/2 via curl**.

### Bausteine

| Datei | Aufgabe |
|-------|---------|
| `includes/push.php`        | Low-Level-APNs-Logik (JWT ES256, HTTP/2, Payload-Aufbau inkl. `buildSummaryPayload()`) |
| `includes/push_sender.php` | schlanke Fassade mit High-Level-Funktionen (delegiert nur an `push.php`) |
| `cron/push_notifications.php` | täglicher CLI-Cronjob: ermittelt fällige Aufgaben und verschickt die Pushes |

Geräte melden sich nach dem Login über `POST /api/v1/devices` (bzw. den Alias
`/api/v1/device-tokens`) mit ihrem APNs-Device-Token an; abgemeldet wird per `DELETE`.

### Täglicher Cronjob

Der Versand wird **einmal täglich** über einen cPanel-Cronjob angestoßen. Beispiel
(PHP-Pfad ggf. anpassen):

```
0 6 * * * /usr/local/bin/php /home/DEINACCOUNT/CattleOrg/cron/push_notifications.php >> /home/DEINACCOUNT/CattleOrg/logs/push.log 2>&1
```

**Aufrufoptionen:**

```bash
php cron/push_notifications.php             # Normallauf (sendet)
php cron/push_notifications.php --dry-run   # zeigt nur, was passieren würde (sendet NICHT)
php cron/push_notifications.php --verbose   # ausführlichere Logausgabe
```

> Empfehlung: Vor dem Scharfschalten zuerst mit `--dry-run` testen – dabei wird
> nichts versendet und nichts in `push_log` protokolliert.

### Einzel- vs. Sammel-Mitteilungen

Um die Flut vieler Einzel-Pushes zu vermeiden, bündelt der Cron nicht-dringende
Aufgaben zu **einer Sammel-Mitteilung je Benutzer, Betrieb und Tag**:

- **1–2** fällige (nicht-dringende) Aufgaben → weiterhin **Einzel-Pushes**.
- **Ab dem Schwellwert** (`$SUMMARY_THRESHOLD`, oben im Skript, Standard **3**) →
  **eine Sammel-Mitteilung** pro Betrieb/Tag, z. B.
  *„5 Trächtigkeitskontrollen, 2 Hemmstoffproben (Ergebnis), 1 Weiterbehandlung"*.
- **Dringende Wartezeit-Mitteilungen gehen immer einzeln** – unabhängig vom Schwellwert.

Die Sammel-Mitteilung trägt `type: "summary"`, `target: "tasks"` (die App springt
in den Aufgaben-Tab), die `farm_id`, optional einen `filter` (nur wenn genau **eine**
Kategorie enthalten ist: `traechtigkeit`/`hemmstoff`/`weiterbehandlung`), einen
`badge` sowie `thread-id: "farm-<id>"`. Zusätzlich sorgt der Header
`apns-collapse-id: sum-<farm_id>-<JJJJMMTT>` dafür, dass eine neuere Übersicht vom
selben Tag die ältere ersetzt statt sich zu stapeln. Ein `push_log`-Eintrag je
enthaltener Aufgabe verhindert Doppelversand am selben Tag.

> Den Schwellwert kannst du über die Variable `$SUMMARY_THRESHOLD` in
> `cron/push_notifications.php` anpassen (z. B. `2`, damit schon ab 2 Aufgaben
> gebündelt wird). Ausführliche Payload-Beschreibung: [`docs/API_IOS_PUSH.md`](docs/API_IOS_PUSH.md).

---

## Datenbank-Migrationen

Die SQL-Migrationen liegen in `migrations/` und werden über den Runner
`php migrations/run_migrations.php` eingespielt (siehe [Installation](#installation)).
Für Neuinstallationen bündelt `migrations/install_all.sql` alle Schritte in einer
Datei (ideal für den phpMyAdmin-Import auf cPanel).

| Datei | Inhalt |
|-------|--------|
| `001_create_farms.sql`             | Betriebe (Mandanten) |
| `002_create_users_and_farm_users.sql` | Benutzer + n:m-Zuordnung Benutzer↔Betrieb mit Rolle |
| `003_create_core_tables.sql`       | Kern: `cattle`, `treatments`, `inseminations`, `pregnancy_checks`, `stall_history`, `hemmstoff_probes` |
| `004_create_medications_global.sql`| globaler Medikamentenkatalog (ohne `farm_id`) |
| `005_create_stalls.sql`            | Ställe je Betrieb |
| `006_create_permissions.sql`       | granulare Berechtigungs-Overrides |
| `007_create_api_tokens.sql`        | API-Tokens (SHA-256-Hash) |
| `008_seed_demo_and_admin.sql`      | Seed: Admin-Konto, Demo-Betrieb, Demo-Benutzer |
| `009_create_device_tokens.sql`     | Push-Geräte (`device_tokens`) und Protokoll (`push_log`) |
| `010_api_ios_extensions.sql`       | iOS-Erweiterungen: `cattle.abgang_grund`, Zusatzspalten in `device_tokens` |

Zu jeder Migration existiert ein passendes Skript unter `migrations/rollback/`.

> **Hinweise zu 009 & 010**
> - **009** legt die für die Push-Funktion nötigen Tabellen `device_tokens`
>   (registrierte iOS-Geräte) und `push_log` (Doppelversand-Schutz) an. Die
>   Nummer `008` war bereits durch die Seed-Migration belegt.
> - **010** ist **additiv und idempotent** formuliert: Die inkrementellen
>   `ALTER TABLE … ADD COLUMN/KEY/FOREIGN KEY IF NOT EXISTS` nutzen die
>   `IF NOT EXISTS`-Syntax, die **MariaDB 10.4+** voraussetzt (das ist die
>   Produktionsumgebung auf HostEurope/cPanel). Für **Neuinstallationen** sind
>   dieselben Spalten bereits direkt in den `CREATE TABLE`-Anweisungen von
>   `install_all.sql` enthalten – dieser Weg ist Standard-SQL und läuft auch auf
>   reinem MySQL 8. Auf reinem MySQL 8 müsste nur die inkrementelle 010 ohne
>   `IF NOT EXISTS` ausgeführt werden.

---

## Sicherheit

Die Neuentwicklung behebt gezielt die Schwachstellen der Altanwendung:

- **Keine hartcodierten Zugangsdaten** – alle Geheimnisse liegen in `.env`
  (nicht im Git, siehe `.gitignore`).
- **Durchgängige Authentifizierung** auf allen Seiten und API-Endpunkten
  (inkl. der früher offenen Endpunkte wie der Muttertier-Suche und dem CSV-Import).
- **CSRF-Schutz** für alle ändernden Formular-Requests (`requireCsrf()`).
- **PDO Prepared Statements** durchgehend → Schutz vor SQL-Injection.
- **Ausgabe-Escaping** mit `e()` (htmlspecialchars) → Schutz vor XSS.
- **Session-Härtung**: `HttpOnly`, `SameSite=Lax`, `Secure` (bei HTTPS),
  Session-Regeneration beim Login, Idle-Timeout.
- **Mandantentrennung** auf jeder Abfrage (`farm_id`) inkl. IDOR-Schutz.
- **Fehlerausgabe** im Produktivbetrieb deaktiviert; Fehler werden geloggt.
- **Passwörter** als bcrypt-Hash (`password_hash`).

---

## Mitwirken / Workflow

Änderungen laufen immer über einen Pull Request – es wird **nicht** direkt in `main` committet:

1. **Branch** von `main` anlegen (z. B. `feature/...`, `fix/...`, `chore/...`).
2. Änderung umsetzen, lokal `php -l` prüfen (oder `composer lint`).
3. **Pull Request** gegen `main` öffnen (PR-Vorlage ausfüllen).
4. **CI abwarten:** Der Check **„PHP Syntax-Check"** muss grün sein.
5. **Merge** erst nach grüner CI – der Merge erfolgt durch den Betreiber.

Abhängigkeiten werden wöchentlich von **Dependabot** aktualisiert (GitHub Actions + Composer).

---

## Projektstruktur

```
CattleOrg/
├── includes/            # Kern: Konfiguration, Auth, Mandantenlogik, API-Helfer
│   ├── config.php       #   .env-Laden, PDO-Verbindung, Fehlerbehandlung
│   ├── functions.php    #   allgemeine Hilfsfunktionen
│   ├── auth.php         #   Session-Härtung, Login, CSRF
│   ├── tenant.php       #   Multi-Tenancy + Rollen/Berechtigungen
│   ├── api_helpers.php  #   JSON-API, Bearer-Token, Rate-Limit
│   ├── device_tokens.php#   gemeinsame Logik für /devices & /device-tokens
│   ├── push.php         #   APNs-Low-Level (JWT ES256, HTTP/2, Payloads)
│   ├── push_sender.php  #   Fassade mit High-Level-Push-Funktionen
│   └── bootstrap.php    #   zentraler Einstieg (Web)
├── public/              # öffentlicher Document-Root
│   ├── login.php, logout.php, farm_select.php, index.php, profile.php
│   ├── cattle_*.php     #   Tierverwaltung (Liste, Detail, Abgang, Timeline …)
│   ├── add_treatment.php, insemination_add.php, add_pregnancy.php, todo_list.php …
│   ├── admin/           #   System-/Betriebsadministration (Betriebe, Benutzer, Medikamente)
│   ├── exports/         #   PDF-Exporte (dompdf)
│   └── api/v1/          #   REST-API (auth, user, dashboard, cattle, treatments, devices …)
├── cron/                # CLI-Cronjobs
│   └── push_notifications.php   #   täglicher Push-Versand (Einzel-/Sammel-Mitteilungen)
├── templates/           # gemeinsame Layout-Bausteine (header/footer)
├── migrations/          # SQL-Migrationen (001–010), Seed-Daten, install_all.sql, Runner
│   └── rollback/        #   zugehörige Rollback-Skripte
├── docs/                # Zusatzdoku (API_IOS.md, API_IOS_PUSH.md)
├── icons/               # App-Icons/Assets
├── logs/                # Laufzeit-Logs (nicht im Git, nur .gitkeep)
├── .github/             # GitHub Actions (php-lint.yml CI, release.yml), Dependabot, Templates
├── .env.example         # Konfigurationsvorlage
├── composer.json        # Abhängigkeiten & Autoloading
├── INSTALL_cPanel.md    # Schritt-für-Schritt-Installation auf HostEurope/cPanel
└── README.md
```

---

© CattleOrg – Mandantenfähige Rinderverwaltung. Lizenz: MIT.



---

## Neu in Version V1.0.5 (Planung, Gruppenaktionen, Trockensteher)

- **Geplante Behandlungen** – Behandlungen auf ein festes Datum einplanen; sie
  erscheinen an dem Tag in den Aufgaben und im Kalender und werden per „Erledigt"
  zu einer echten Behandlung. Benötigt **Migration 015**
  (`migrations/015_create_planned_treatments.sql`).
- **Gruppenaktionen pro Box** – mehrere Tiere einer Box gemeinsam umstallen,
  inaktivieren, behandeln oder eine Behandlung einplanen (`public/group_actions.php`,
  Navigationspunkt „Gruppenaktionen", nur mit `cattle.write`).
- **Startseiten-Kachel „Trockensteher"** (statt „Offene Trächtigkeit") mit Verlinkung
  auf die gefilterte Tierliste (`trockensteher=1`).
- **API** – neue additive Endpunkte für geplante Behandlungen, Totgeburten,
  Gruppenaktionen sowie Kennzahlen Milchkühe/Trockensteher und Cattle-Filter
  `trockensteher=1` / `milchkuh=1`. Details: `docs/API_IOS.md`, Abschnitt 13.
- **Stabilere API** – globaler Fehler-Handler: unerwartete Serverfehler liefern jetzt
  eine JSON-Antwort mit Ursache (statt eines nackten HTTP-500) und schreiben die
  Details ins PHP-Fehlerprotokoll. Das Dashboard (`GET /api/v1/dashboard`) ist gegen
  fehlende Helfer/Teilausfälle gehärtet (try/catch, typ-stabile Antwort: Zahlen
  bleiben Zahlen, Listen bleiben Listen) und antwortet auch bei Teilausfällen mit
  den Kern-Aufgaben.

Vollständige Liste der Änderungen: siehe `CHANGELOG.md`.

### Migrationsliste (Auszug, neueste zuerst)
- `015_create_planned_treatments.sql` – Tabelle `planned_treatments` (geplante Behandlungen).
- `014_create_stillbirths.sql` – Tabelle `stillbirths` (Totgeburten je Kuh).
- `010_api_ios_extensions.sql` – API-/iOS-Erweiterungen (u. a. `abgang_grund`).
- `009_create_device_tokens.sql` – Push-Geräte-Tokens.
