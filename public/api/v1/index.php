<?php
/**
 * GET /api/v1
 * -----------------------------------------------------------------------------
 * Einstiegspunkt / Discovery der API. Listet die verfuegbaren Endpunkte.
 */

require_once __DIR__ . '/bootstrap_api.php';

jsonSuccess([
    'name'    => APP_NAME . ' API',
    'version' => 'v1',
    'auth'    => 'Bearer-Token (Header: Authorization: Bearer <token>)',
    'farm_context' => 'Header X-Farm-Id oder Query-Parameter farm_id',
    'hinweis_pfade' => 'Alle Endpunkte im Ordner-Stil mit abschliessendem Slash aufrufen, '
        . 'z. B. POST /api/v1/auth/login/ . Die Dateipfade (…/login.php) bleiben zusaetzlich gueltig.',
    'endpoints' => [
        'POST /api/v1/auth/login'      => 'Anmeldung, liefert Token + Betriebe',
        'POST /api/v1/auth/logout'     => 'Token widerrufen (meldet auch Push-Geraete des Tokens ab)',
        'GET  /api/v1/user'            => 'Eigenes Profil + Betriebe inkl. Berechtigungen',
        'PUT  /api/v1/user/password'   => 'Eigenes Passwort aendern',
        'GET  /api/v1/farm'            => 'Zugaengliche Betriebe inkl. Berechtigungen',
        'GET  /api/v1/dashboard'       => 'Kennzahlen + faellige Aufgaben des Betriebs',
        'GET  /api/v1/cattle'          => 'Tierliste (paginiert, Filter) / ?id= fuer Einzeltier',
        'POST /api/v1/cattle'          => 'Tier anlegen',
        'POST /api/v1/cattle/abgang'   => 'Abgang/Verendung eines Tieres erfassen',
        'GET  /api/v1/cattle/breeds'   => 'Vorhandene Rassen des Betriebs (Auswahlliste)',
        'GET  /api/v1/treatments'      => 'Behandlungen (?cattle_id=) inkl. Wartezeit-Feldern',
        'POST /api/v1/treatments'      => 'Behandlung anlegen',
        'GET  /api/v1/inhibitor-tests' => 'Hemmstoffproben (?cattle_id=, ?status=)',
        'POST /api/v1/inhibitor-tests' => 'Hemmstoffprobe anlegen',
        'PUT  /api/v1/inhibitor-tests' => 'Hemmstoffprobe-Status aktualisieren (?id=)',
        'GET  /api/v1/medications'     => 'Globaler Medikamentenkatalog',
        'GET  /api/v1/inseminations'   => 'Besamungen (?cattle_id=)',
        'POST /api/v1/inseminations'   => 'Besamung anlegen',
        'GET  /api/v1/pregnancy'       => 'Traechtigkeitskontrollen (?cattle_id=)',
        'POST /api/v1/pregnancy'       => 'Kontrolle anlegen',
        'GET  /api/v1/stalls'          => 'Staelle des Betriebs',
        'GET  /api/v1/planned-treatments'          => 'Geplante Behandlungen (Filter: cattle_id, status, from, to) / ?id=',
        'POST /api/v1/planned-treatments'          => 'Geplante Behandlung anlegen',
        'PUT  /api/v1/planned-treatments'          => 'Geplante Behandlung bearbeiten (?id=, nur status=geplant)',
        'DELETE /api/v1/planned-treatments'        => 'Geplante Behandlung loeschen (?id=)',
        'POST /api/v1/planned-treatments/complete' => 'Geplante Behandlung erledigen (?id=) -> erzeugt Behandlung',
        'GET  /api/v1/stillbirths'     => 'Totgeburten (?cattle_id=) / ?id=',
        'POST /api/v1/stillbirths'     => 'Totgeburt anlegen',
        'DELETE /api/v1/stillbirths'   => 'Totgeburt loeschen (?id=)',
        'GET  /api/v1/group-actions'   => 'Tiere einer Box (?stall=)',
        'POST /api/v1/group-actions'   => 'Gruppenaktion pro Box: move|inactivate|treat|plan',
        'POST /api/v1/devices'         => 'Push-Geraet registrieren/aktualisieren (iOS/APNs)',
        'PUT  /api/v1/devices'         => 'Push-Geraet registrieren/aktualisieren (Alias)',
        'DELETE /api/v1/devices'       => 'Push-Geraet abmelden',
        'POST /api/v1/device-tokens'   => 'Alias zu /api/v1/devices (Push-Geraet registrieren)',
        'PUT  /api/v1/device-tokens'   => 'Alias zu /api/v1/devices (Push-Geraet aktualisieren)',
        'DELETE /api/v1/device-tokens' => 'Alias zu /api/v1/devices (Push-Geraet abmelden)',
    ],
]);
