<?php
/**
 * POST /api/v1/auth/login/  (Ordner-Stil-Alias)
 * -----------------------------------------------------------------------------
 * Duenner Alias auf ../login.php, damit der Login sowohl ueber den Dateipfad
 * (/api/v1/auth/login.php) als auch ueber den Ordner-Pfad (/api/v1/auth/login/)
 * erreichbar ist. Alle anderen API-Ressourcen nutzen den Ordner-Stil
 * (<ressource>/index.php); die native iOS-App ruft den Login ebenso im
 * Ordner-Stil auf. Es wird KEINE Logik dupliziert – die bestehende Datei wird
 * lediglich eingebunden. Deren __DIR__ bleibt dabei das auth/-Verzeichnis,
 * sodass alle relativen require-Pfade unveraendert korrekt aufloesen.
 */

require __DIR__ . '/../login.php';
