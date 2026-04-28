<?php
require_once dirname(__DIR__) . '/spotify_client.php';
require_once dirname(__DIR__) . '/ml_gameplay.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$isAdminUser = mlUserIsAdmin($currentUser);

if (!$isAdminUser) {
    $_SESSION['ml_spotify_error'] = 'Only the admin account can connect Spotify.';
    header('Location: ../admin.php');
    exit;
}

if (!mlSpotifyAppConfigured()) {
    $_SESSION['ml_spotify_error'] = 'Spotify is not configured yet. Add your client ID and client secret first.';
    header('Location: ../admin.php');
    exit;
}

if (isset($_GET['error']) && $_GET['error'] !== '') {
    $_SESSION['ml_spotify_error'] = 'Spotify authorization was not completed: ' . (string)$_GET['error'];
    header('Location: ../admin.php');
    exit;
}

$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
$expectedState = isset($_SESSION['ml_spotify_oauth_state']) ? (string)$_SESSION['ml_spotify_oauth_state'] : '';
unset($_SESSION['ml_spotify_oauth_state']);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    $_SESSION['ml_spotify_error'] = 'Spotify authorization failed because the security state did not match.';
    header('Location: ../admin.php');
    exit;
}

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
if ($code === '') {
    $_SESSION['ml_spotify_error'] = 'Spotify did not return an authorization code.';
    header('Location: ../admin.php');
    exit;
}

try {
    $tokenRow = mlSpotifyExchangeCodeForToken($pdo, $code);
    $displayName = trim((string)($tokenRow['SpotifyDisplayName'] ?? 'your Spotify account'));
    $_SESSION['ml_spotify_message'] = 'Spotify connected successfully as ' . $displayName . '.';
} catch (Throwable $e) {
    $_SESSION['ml_spotify_error'] = $e->getMessage();
}

header('Location: ../admin.php');
exit;
