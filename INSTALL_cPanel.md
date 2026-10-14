# CattleOrg – Installation auf HostEurope (cPanel)

Diese Anleitung führt Sie **Schritt für Schritt** durch die Installation der
Rinderverwaltung auf einem HostEurope-Webhosting-Paket mit **cPanel**.
Sie brauchen dafür **kein SSH und kein Composer** auf dem Server – das fertige
Upload-Paket enthält bereits alle Abhängigkeiten (`vendor/`).

> **Geschätzte Dauer:** ca. 20–30 Minuten.
> **Voraussetzung:** PHP **8.0 oder höher** (empfohlen 8.2) und eine MySQL/MariaDB-Datenbank.

---

## Überblick – das sind die 7 Schritte

1. PHP-Version prüfen/einstellen
2. Datenbank + Datenbankbenutzer anlegen
3. Dateien hochladen (ZIP) und entpacken
4. Document-Root auf den Ordner `public/` setzen
5. Konfigurationsdatei `.env` ausfüllen
6. Datenbank mit phpMyAdmin befüllen (Import)
7. Einloggen, Passwörter ändern, Betrieb anlegen

---

## Schritt 1 – PHP-Version prüfen

1. Loggen Sie sich in **cPanel** ein (z. B. `https://ihre-domain.de/cpanel` oder über das HostEurope-Kundenmenü → "cPanel öffnen").
2. Suchen Sie im Bereich **Software** den Punkt **„MultiPHP Manager"** (oder „PHP-Version auswählen").
3. Wählen Sie für Ihre Domain **PHP 8.2** (mindestens 8.0).
4. Optional unter **„PHP Extensions"** sicherstellen, dass aktiv sind: `pdo_mysql`, `mbstring`, `xml`, `gd`, `zip`, `curl`, `json`.

---

## Schritt 2 – Datenbank anlegen

1. In cPanel im Bereich **Datenbanken** auf **„MySQL®-Datenbank-Assistent"** klicken.
2. **Datenbankname** vergeben, z. B. `cattleorg`.
   - HostEurope stellt Ihrem Namen meist ein Präfix voran, z. B. `db12345_cattleorg`. **Notieren Sie den vollständigen Namen.**
3. **Datenbankbenutzer** anlegen, z. B. `cattle_user` (auch hier kommt ein Präfix davor) und ein **starkes Passwort** vergeben. **Beides notieren.**
4. Im nächsten Schritt dem Benutzer **„ALLE RECHTE" (ALL PRIVILEGES)** auf die Datenbank geben.

> 📝 **Notieren Sie sich:**
> - DB-Name: `__________________`
> - DB-Benutzer: `__________________`
> - DB-Passwort: `__________________`
> - DB-Host: in der Regel `localhost` (bei HostEurope fast immer korrekt)

---

## Schritt 3 – Dateien hochladen

Sie haben die Datei **`CattleOrg_deploy.zip`** erhalten (enthält die komplette App inkl. `vendor/`).

1. In cPanel den **„Dateimanager"** öffnen.
2. Wechseln Sie in das Verzeichnis **oberhalb** von `public_html`, also in Ihr **Home-Verzeichnis** (linke Spalte ganz oben, der Ordner z. B. `/home/db12345`).
   - **Warum?** Aus Sicherheitsgründen sollen die Programmdateien und die `.env` **nicht** öffentlich erreichbar sein. Nur der Ordner `public/` darf ins Web.
3. Klicken Sie oben auf **„Hochladen"** und laden Sie `CattleOrg_deploy.zip` hoch.
4. Zurück im Dateimanager: Rechtsklick auf die ZIP → **„Extract" / „Entpacken"**.
   - Es entsteht ein Ordner **`CattleOrg`** mit den Unterordnern `public/`, `includes/`, `admin/`, `vendor/`, `migrations/` usw.
5. Die ZIP-Datei danach löschen.

> Ergebnis: `/home/db12345/CattleOrg/` enthält die App. Der öffentliche Teil ist `/home/db12345/CattleOrg/public/`.

---

## Schritt 4 – Document-Root auf `public/` setzen

Damit Ihre Domain direkt auf den Ordner `public/` zeigt:

### Variante A (empfohlen): Document-Root der Domain ändern
1. In cPanel **„Domains"** öffnen.
2. Bei Ihrer Domain auf **„Verwalten" / „Document Root bearbeiten"** klicken.
3. Den Pfad auf **`/home/db12345/CattleOrg/public`** setzen und speichern.

### Variante B: Falls HostEurope das Ändern nicht erlaubt
Dann legen Sie die App **in** `public_html` ab und nutzen eine Weiterleitung:
1. Verschieben Sie den Inhalt des Ordners `CattleOrg` nach `public_html`, sodass `public_html/public/`, `public_html/includes/` … existieren.
2. Legen Sie in `public_html/.htaccess` folgenden Inhalt an:
   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_URI} !^/public/
   RewriteRule ^(.*)$ /public/$1 [L]
   ```
   > Hinweis: Variante A ist deutlich sicherer und wird empfohlen. Nutzen Sie B nur, wenn A nicht verfügbar ist.

---

## Schritt 5 – Konfiguration `.env` ausfüllen

1. Im Dateimanager in den Ordner `CattleOrg` wechseln.
2. Es liegt dort eine Datei **`.env`** (bereits vorbereitet). Falls nicht: `\.env.example` kopieren und in `.env` umbenennen.
   - Tipp: Versteckte Dateien einblenden über **Einstellungen → „Show Hidden Files (dotfiles)"**.
3. Rechtsklick auf `.env` → **„Edit"** und folgende Werte eintragen:

   ```ini
   APP_NAME="Muh App"
   BASE_URL="/"
   APP_ENV="production"
   APP_HTTPS="true"

   DB_HOST="localhost"
   DB_PORT="3306"
   DB_NAME="db12345_cattleorg"     # Ihr vollständiger DB-Name aus Schritt 2
   DB_USER="db12345_cattle_user"   # Ihr DB-Benutzer aus Schritt 2
   DB_PASS="IHR_DB_PASSWORT"       # Ihr DB-Passwort aus Schritt 2
   DB_CHARSET="utf8mb4"

   APP_KEY="HIER_DEN_MITGELIEFERTEN_KEY_EINFUEGEN"
   SESSION_LIFETIME="28800"
   ```

   > 🔑 **Fertiger APP_KEY** (bereits zufällig erzeugt – Sie können diesen verwenden):
   > `911e418151064d9b440a242620271984594e94721276420feff5e15adc864174`
   > (Oder einen eigenen mit 64 Hex-Zeichen verwenden.)

4. Speichern.

---

## Schritt 6 – Datenbank befüllen (phpMyAdmin Import)

1. In cPanel **„phpMyAdmin"** öffnen.
2. Links **Ihre Datenbank** (`db12345_cattleorg`) anklicken.
3. Oben auf den Reiter **„Importieren"** klicken.
4. Unter **„Zu importierende Datei"** auf „Durchsuchen" und die Datei
   **`migrations/install_all.sql`** auswählen (aus dem Upload-Paket – Sie finden sie auch separat als `CattleOrg_install.sql`).
5. Unten auf **„OK" / „Importieren"** klicken.
6. Es sollten **13 Tabellen** angelegt werden (u. a. `farms`, `users`, `cattle`, `medications` …) und Beispieldaten eines Demo-Betriebs eingespielt werden.

---

## Schritt 7 – Einloggen & absichern

1. Rufen Sie Ihre Domain auf: `https://ihre-domain.de`
2. Melden Sie sich an:

   | Konto | Benutzer | Passwort | Rolle |
   |-------|----------|----------|-------|
   | Administrator | `admin` | `admin1234` | System-Admin |
   | Demo | `demo` | `demo2024` | Nur-Lese (Demo) |

3. **WICHTIG – sofort nach dem ersten Login:**
   - Das **Admin-Passwort ändern** (Profil/Benutzerverwaltung).
   - Optional das Demo-Konto deaktivieren, wenn nicht benötigt.
4. Als Admin können Sie nun **Betriebe** und **Betriebsbesitzer (Owner)** anlegen.
   Jeder Owner kann anschließend **eigene Subkonten** (Manager/Worker/Viewer) mit Berechtigungen verwalten und sieht **nur die Daten des eigenen Betriebs**.

---

## HTTPS / SSL aktivieren (empfohlen)

HostEurope bietet kostenlose **Let's Encrypt**-Zertifikate:
1. In cPanel **„SSL/TLS Status"** öffnen.
2. Domain auswählen → **„Run AutoSSL"**.
3. Sobald aktiv, in der `.env` `APP_HTTPS="true"` lassen (sichere Cookies).

---

## Optional – iOS-Push-Benachrichtigungen einrichten

Wenn die native iOS-App („Muh App") Push-Mitteilungen erhalten soll, sind zusätzlich
diese Schritte nötig. (Ohne diese Einrichtung funktioniert die App normal weiter –
es werden nur keine Pushes versendet.)

### A) APNs-Schlüssel von Apple holen

1. Im **Apple Developer Portal** unter **Certificates, Identifiers & Profiles → Keys**
   einen neuen **Key** anlegen, dabei **APNs** anhaken und herunterladen.
   Ergebnis ist eine Datei `AuthKey_XXXXXXXXXX.p8`.
   - Der Download ist **nur einmal** möglich – gut aufbewahren.
   - Die **Key ID** (10 Zeichen) steht am Key.
2. Die **Team ID** (10 Zeichen) steht oben rechts im Developer-Account bzw. unter „Membership".
3. Der **Bundle Identifier** der App (z. B. `de.obitron.muhapp`) ist der `apns-topic`.

### B) Schlüsseldatei sicher ablegen

1. Im **Dateimanager** einen Ordner **außerhalb** von `public/` anlegen, z. B.
   `/home/DEINACCOUNT/apns/`.
2. Die Datei `AuthKey_XXXXXXXXXX.p8` dort hochladen.
3. Dateirechte möglichst restriktiv setzen (z. B. `600`).

> ⚠️ Die `.p8`-Datei **niemals** nach `public/` legen und **nicht** ins Git einchecken.

### C) `.env` ergänzen

In der bereits vorhandenen `.env` (im Ordner `CattleOrg`, eine Ebene über `public/`)
die APNs-Werte eintragen – **mit Ihren echten Werten**, hier nur Platzhalter:

```ini
APNS_ENABLED="true"
APNS_KEY_PATH="/home/DEINACCOUNT/apns/AuthKey_XXXXXXXXXX.p8"
APNS_KEY_ID="XXXXXXXXXX"
APNS_TEAM_ID="YYYYYYYYYY"
APNS_BUNDLE_ID="de.obitron.muhapp"
APNS_DEFAULT_ENV="production"
```

> Für TestFlight-/Debug-Builds nutzt die App i. d. R. die **Sandbox** – dann das Gerät
> beim Registrieren mit `environment: "sandbox"` anmelden.

### D) Datenbank-Migrationen für Push

Falls noch nicht geschehen, die Push-Tabellen anlegen (phpMyAdmin → Reiter **SQL**
bzw. **Importieren**): Inhalt von `migrations/009_create_device_tokens.sql` ausführen
(legt `device_tokens` und `push_log` an). Bei einer Neuinstallation über
`install_all.sql` sind diese Tabellen bereits enthalten.

### E) Täglichen Cronjob einrichten

1. In cPanel unter **„Cron Jobs"** einen neuen Job anlegen.
2. Als Zeitplan **täglich** wählen (z. B. jeden Tag um 06:00 Uhr).
3. Als Befehl eintragen (PHP-Pfad ggf. anpassen – in cPanel oft `/usr/local/bin/php`
   oder `/usr/bin/php`):

   ```
   0 6 * * * /usr/local/bin/php /home/DEINACCOUNT/CattleOrg/cron/push_notifications.php >> /home/DEINACCOUNT/CattleOrg/logs/push.log 2>&1
   ```

4. **Vorher testen** (ohne wirklich zu senden): Den Befehl einmal manuell mit der
   Option `--dry-run` ausführen bzw. als einmaligen Cron-Befehl eintragen:

   ```
   /usr/local/bin/php /home/DEINACCOUNT/CattleOrg/cron/push_notifications.php --dry-run --verbose
   ```

   Die Ausgabe zeigt, **was** versendet würde, **ohne** etwas zu senden oder zu
   protokollieren. Ist alles plausibel, den `--dry-run`-Job wieder entfernen und den
   täglichen Normallauf aktiv lassen.

> **Hinweis zum Versandverhalten:** Hat ein Benutzer an einem Tag mehrere
> (standardmäßig ≥ 3) nicht-dringende fällige Aufgaben, fasst der Cron sie zu **einer
> Sammel-Mitteilung** je Betrieb/Tag zusammen; bei 1–2 Aufgaben kommen Einzel-Pushes.
> Dringende Wartezeit-Hinweise werden **immer einzeln** verschickt. Den Schwellwert
> steuert die Variable `$SUMMARY_THRESHOLD` in `cron/push_notifications.php`. Details:
> `docs/API_IOS_PUSH.md`.

---

## Fehlerbehebung (FAQ)

| Problem | Ursache / Lösung |
|---------|------------------|
| „Konfigurationsfehler: .env-Datei fehlt" | `.env` liegt nicht im Ordner `CattleOrg` (eine Ebene über `public/`). Datei anlegen/umbenennen. |
| Weiße Seite / HTTP 500 | Falsche DB-Zugangsdaten in `.env`, oder PHP-Version < 8.0. In cPanel unter „Errors" das Log prüfen. |
| „Access denied for user" | DB-Benutzer/Passwort in `.env` stimmt nicht, oder Benutzer hat keine Rechte auf die DB (Schritt 2). |
| CSS/Design fehlt | Document-Root zeigt nicht auf `public/` (Schritt 4) oder `BASE_URL` falsch. |
| PDF-Export schlägt fehl | Ordner `vendor/` wurde nicht mit hochgeladen – komplettes ZIP erneut entpacken. |
| Login klappt nicht | Wurde `install_all.sql` importiert? Tabelle `users` muss `admin` und `demo` enthalten. |

---

## Update der App (später)

Wenn eine neue Version kommt:
1. Neues ZIP hochladen und entpacken (Ordner `CattleOrg` ersetzen) – **`.env` und `logs/` vorher sichern**.
2. Falls neue DB-Migrationen dabei sind, die entsprechenden `migrations/0XX_*.sql` per phpMyAdmin importieren.
3. `.env` zurückkopieren.

---

**Bei Fragen oder Problemen:** Notieren Sie die genaue Fehlermeldung (inkl. cPanel-Error-Log)
und melden Sie sich – dann helfe ich gezielt weiter.
