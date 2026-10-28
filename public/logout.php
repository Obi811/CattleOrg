<?php
/**
 * public/logout.php
 * -----------------------------------------------------------------------------
 * Meldet den Benutzer ab und leitet zur Anmeldung zurück.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

logoutUser();
redirect(url('login.php'));
