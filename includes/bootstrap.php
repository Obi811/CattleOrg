<?php
/**
 * bootstrap.php
 * -----------------------------------------------------------------------------
 * Zentrales Include fuer ALLE Web-Seiten. Bindet Konfiguration, Helper, Auth
 * und Mandantenlogik in der richtigen Reihenfolge ein. Eliminiert das
 * Boilerplate (session_start, ini_set, require...) aus jeder einzelnen Datei.
 *
 * Verwendung am Anfang jeder Seite:
 *     require_once __DIR__ . '/../includes/bootstrap.php';   // public/*
 *     require_once dirname(__DIR__) . '/includes/bootstrap.php';
 */

declare(strict_types=1);

// Composer-Autoloader (falls vorhanden – fuer dompdf / PhpSpreadsheet)
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/config.php';     // .env laden, PDO ($db) aufbauen, Fehlerhandling
require_once __DIR__ . '/functions.php';  // Allgemeine Helper
require_once __DIR__ . '/auth.php';       // Session-Haertung, Login, CSRF
require_once __DIR__ . '/tenant.php';     // Mandanten- & Berechtigungslogik

// $db ist ab hier global verfuegbar.
