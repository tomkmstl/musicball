<?php
// session_boot.php
// Centralized session setup for Musicball.

$lifetime = 60 * 60 * 24 * 14; // 14 days

if (PHP_OS_FAMILY === 'Windows') {
    $sessionPath = 'C:\\laragon\\data\\musicball_sessions';
} else {
    $sessionPath = '/var/www/musicball_private/sessions';
}

if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}

if (!is_writable($sessionPath)) {
    error_log("Session path not writable: " . $sessionPath);
} else {
    ini_set('session.save_path', $sessionPath);
}

ini_set('session.gc_maxlifetime', $lifetime);
ini_set('session.cookie_lifetime', $lifetime);
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');

$isSecure =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}