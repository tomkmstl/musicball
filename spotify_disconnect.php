<?php
require_once __DIR__ . '/spotify_client.php';
require_once __DIR__ . '/gameplay/bootstrap.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$isAdminUser = mlUserIsAdmin($currentUser);

if (!$isAdminUser) {
    $_SESSION['ml_spotify_error'] = 'Only the admin account can disconnect Spotify.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

mlSpotifyDisconnect($pdo);
$_SESSION['ml_spotify_message'] = 'Spotify connection removed from Musicball.';
header('Location: ' . mlUrl('admin.php'));
exit;
