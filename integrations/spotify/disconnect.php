<?php
require_once __DIR__ . '/client.php';
require_once dirname(__DIR__, 2) . '/gameplay/bootstrap.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$isAdminUser = mlUserIsAdmin($currentUser);

if (!$isAdminUser) {
    $_SESSION['ml_spotify_error'] = 'Only the admin account can disconnect Spotify.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['ml_spotify_error'] = 'Use the protected Disconnect Spotify control in Admin.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$submittedCsrfToken = isset($_POST['admin_csrf']) ? (string)$_POST['admin_csrf'] : '';
$expectedCsrfToken = isset($_SESSION['ml_admin_csrf']) ? (string)$_SESSION['ml_admin_csrf'] : '';
if (
    $submittedCsrfToken === ''
    || $expectedCsrfToken === ''
    || !hash_equals($expectedCsrfToken, $submittedCsrfToken)
) {
    $_SESSION['ml_spotify_error'] = 'The disconnect request expired. Refresh Admin and try again.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

mlSpotifyDisconnect($pdo);
unset($_SESSION['ml_admin_csrf']);
$_SESSION['ml_spotify_message'] = 'Spotify connection removed from Musicball.';
header('Location: ' . mlUrl('admin.php'));
exit;
