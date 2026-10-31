# Changelog – CattleOrg (Server / API)

## API-Fehlerbehandlung – globaler Handler (Fix HTTP 500 ohne JSON)

### Behoben
- **Nackter HTTP-500 ohne JSON-Body** bei unerwarteten Serverfehlern. Da PDO im
  `ERRMODE_EXCEPTION` laeuft, wurde eine nicht abgefangene Ausnahme (z. B. ein
  SQL-Fehler in `/api/v1/dashboard/` oder `/api/v1/user/`) zu einem PHP-Fatal.
  Mit `display_errors=0` in Produktion erhielt der Client dadurch einen leeren
  HTTP-500 – die iOS-App konnte nur "Serverfehler 500" anzeigen, nicht die
  eigentliche Ursache.

### Neu
- `public/api/v1/bootstrap_api.php`: globaler `set_exception_handler` und
  `register_shutdown_function`. Unerwartete Ausnahmen/Fatals werden jetzt als
  JSON-Fehler im Standardformat ausgeliefert
  (`{"success":false,"error":{"code":"INTERNAL_ERROR"|"FATAL_ERROR","message":...}}`)
  und zusaetzlich mit Datei:Zeile ins PHP-Fehlerprotokoll geschrieben.
- Dadurch zeigt die App die technische Ursache direkt an, und das Fehlerprotokoll
  nennt die genaue Stelle – unabhaengig davon, welcher Endpunkt betroffen ist.

### Dokumentation
- `docs/API_IOS.md`: Fehlercodes `INTERNAL_ERROR` und `FATAL_ERROR` ergaenzt,
  Hinweis zum globalen Fehler-Handler.

### Hinweis
- Die `message` enthaelt die technische Fehlerursache. Die API ist token-geschuetzt
  (nur authentifizierte Clients). Soll die Meldung in Produktion generischer sein,
  kann der Handler spaeter hinter ein Debug-Flag gelegt werden.
