<?php
require_once 'session_boot.php';
require_once 'config.php';
require_once __DIR__ . '/config/discord_sso_config.php';

$loginError = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
unset($_SESSION['login_error']);

$reset = isset($_GET['resetuser']) &&
         strtolower((string)$_GET['resetuser']) !== 'false' &&
         $_GET['resetuser'] !== '0';

if ($reset) {
    unset(
        $_SESSION['UserID'],
        $_SESSION['UserName'],
        $_SESSION['DiscordUserID'],
        $_SESSION['ml_user_id'],
        $_SESSION['SpotifyAccessToken'],
        $_SESSION['SpotifyRefreshToken'],
        $_SESSION['PendingSpotifyID'],
        $_SESSION['PendingSpotifyDisplayName'],
        $_SESSION['PendingSpotifyEmail'],
        $_SESSION['spotify_oauth_state'],
        $_SESSION['discord_oauth_state']
    );
}

if (isset($_SESSION['UserID']) || isset($_SESSION['ml_user_id'])) {
    header('Location: ' . mlUrl('season.php'));
    exit;
}

$discordReady = mlDiscordConfigIsReady();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Log In | Musicball</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<div class="wrapper">
    <div class="card login-card login-card-sso">
        <div class="login-logo-wrap">
            <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo.png')) ?>" alt="<?= htmlspecialchars(mlGetLeagueName($pdo)) ?>" class="login-logo">
        </div>

        <div class="login-intro-copy">
            <h1>Welcome back</h1>
            <p>Sign in to continue to Musicball.</p>
        </div>

        <?php if (!empty($loginError)): ?>
            <div class="note login-error-note">
                <?= htmlspecialchars($loginError) ?>
            </div>
        <?php endif; ?>

        <?php if ($discordReady): ?>
            <div class="buttons login-buttons login-buttons-sso">
                <a href="<?= htmlspecialchars(mlUrl('integrations/discord/login.php')) ?>" class="discord-sso-button">
                    <span class="discord-sso-icon" aria-hidden="true">
                        <img src="<?= htmlspecialchars(mlAssetUrl('images/Discord-Symbol-White.svg')) ?>" alt="">
                    </span>
                    <span>Continue with Discord</span>
                </a>
            </div>
        <?php else: ?>
            <div class="note login-error-note">
                Discord login is temporarily unavailable.
            </div>
        <?php endif; ?>

        <p class="login-home-link">
            <a href="<?= htmlspecialchars(mlUrl('home.php')) ?>">Back to Musicball home</a>
        </p>
    </div>
</div>
</body>
</html>