<?php
// ml_session_boot.php
// Centralized session setup for Musicball.
// Makes sessions last longer so users don’t get kicked out constantly.

$lifetime = 60 * 60 * 24 * 14; // 14 days in seconds

// Let PHP know sessions can live this long on the server side
ini_set('session.gc_maxlifetime', $lifetime);

// Make the session cookie itself last this long in the browser
// (path '/' so it works across the whole site)
session_set_cookie_params($lifetime, '/');

// Start the session if it isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
