<?php
/**
 * POST /api/v1/auth/logout/  (Ordner-Stil-Alias)
 * -----------------------------------------------------------------------------
 * Duenner Alias auf ../logout.php, damit der Logout sowohl ueber den Dateipfad
 * (/api/v1/auth/logout.php) als auch ueber den Ordner-Pfad
 * (/api/v1/auth/logout/) erreichbar ist – analog zu allen anderen
 * API-Ressourcen (<ressource>/index.php). Es wird KEINE Logik dupliziert.
 */

require __DIR__ . '/../logout.php';
