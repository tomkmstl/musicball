<?php
require_once __DIR__ . '/spotify_client.php';
require_once __DIR__ . '/ml_gameplay.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$isAdminUser = mlUserIsAdmin($currentUser);

if (!$isAdminUser) {
    $_SESSION['ml_spotify_error'] = 'Only the admin account can connect Spotify.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

try {
    $authorizeUrl = mlSpotifyBuildAuthorizeUrl(true);
    header('Location: ' . $authorizeUrl);
    exit;
} catch (Throwable $e) {
    $_SESSION['ml_spotify_error'] = $e->getMessage();
    header('Location: ' . mlUrl('admin.php'));
    exit;
}
