<?php
require_once 'ml_session_boot.php';
require_once 'ml_config.php';

$loginError = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
unset($_SESSION['login_error']);

$reset = isset($_GET['resetuser']) &&
         strtolower((string)$_GET['resetuser']) !== 'false' &&
         $_GET['resetuser'] !== '0';

if ($reset) {
    unset(
        $_SESSION['UserID'],
        $_SESSION['UserName'],
        $_SESSION['ml_user_id'],
        $_SESSION['SpotifyAccessToken'],
        $_SESSION['SpotifyRefreshToken'],
        $_SESSION['PendingSpotifyID'],
        $_SESSION['PendingSpotifyDisplayName'],
        $_SESSION['PendingSpotifyEmail'],
        $_SESSION['spotify_oauth_state']
    );
}

if (isset($_SESSION['UserID']) || isset($_SESSION['ml_user_id'])) {
    header('Location: season.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $passcode = isset($_POST['passcode']) ? trim((string)$_POST['passcode']) : '';

    if ($email === '' || $passcode === '') {
        $loginError = 'Please enter your email address and passcode.';
    } else {
        $stmt = $pdo->prepare("\n            SELECT UserID, UserName, Email, Passcode\n            FROM ML_Users\n            WHERE Email = ?\n            LIMIT 1\n        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $loginError = 'We could not find that email address.';
        } elseif ((string)$user['Passcode'] !== $passcode) {
            $loginError = 'Incorrect passcode.';
        } else {
			session_regenerate_id(true);

			$_SESSION['UserID'] = (int)$user['UserID'];
			$_SESSION['UserName'] = $user['UserName'];

            unset(
                $_SESSION['ml_user_id'],
                $_SESSION['SpotifyAccessToken'],
                $_SESSION['SpotifyRefreshToken'],
                $_SESSION['PendingSpotifyID'],
                $_SESSION['PendingSpotifyDisplayName'],
                $_SESSION['PendingSpotifyEmail'],
                $_SESSION['spotify_oauth_state']
            );

            header('Location: season.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<div class="wrapper">
    <div class="card login-card">
        <div class="login-logo-wrap">
			<img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo.png')) ?>" alt="<?= htmlspecialchars(mlGetLeagueName($pdo)) ?>" class="login-logo">
		</div>

        <?php if (!empty($loginError)): ?>
            <div class="note login-error-note">
                <?= htmlspecialchars($loginError) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php" class="login-form">
            <div class="login-field">
                <label for="email" class="admin-label">Email Address</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    required
                    autocomplete="username"
                    class="admin-input"
                >
            </div>

            <div class="login-field login-field-last">
                <label for="passcode" class="admin-label">4-digit code</label>
                <input
                    type="password"
                    name="passcode"
                    id="passcode"
                    maxlength="4"
                    required
                    autocomplete="current-password"
                    class="admin-input"
                >
            </div>

            <div class="buttons login-buttons">
                <button type="submit" class="button-primary">Log In</button>
            </div>
        </form>

        <p>
            your passcode is the last four digits of your phone number
        </p>
    </div>
</div>
</body>
</html>
