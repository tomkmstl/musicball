<?php
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/push/push.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'settings';
$message = '';
$error = '';
$hasProfileImageColumn = mlUsersHasProfileImageColumn($pdo);
$privatePlaylistStorageReady = mlPlaylistPinsTableAvailable($pdo);
$privatePlaylist = $privatePlaylistStorageReady
    ? mlLoadUserPrivatePlaylist($pdo, (int)$currentUser['UserID'])
    : null;
$privatePlaylistUrl = trim((string)($privatePlaylist['PlaylistURL'] ?? ''));
$pushStorageReady = mlPushStorageReady($pdo);
$pushReady = mlPushServerReady($pdo);

if (empty($_SESSION['ml_push_csrf']) || !is_string($_SESSION['ml_push_csrf'])) {
    $_SESSION['ml_push_csrf'] = bin2hex(random_bytes(24));
}
$pushCsrfToken = (string)$_SESSION['ml_push_csrf'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['settings_action']) ? (string)$_POST['settings_action'] : '';

    if ($action === 'appearance') {
        $themeMode = isset($_POST['theme_mode']) ? (string)$_POST['theme_mode'] : 'dark';
        mlSetThemeMode($themeMode);
    } elseif ($action === 'private_playlist') {
        $submittedPlaylistUrl = trim((string)($_POST['private_playlist_url'] ?? ''));
        $privatePlaylistUrl = $submittedPlaylistUrl;

        if (!$privatePlaylistStorageReady) {
            $error = 'Private playlist storage is not available yet.';
        } elseif ($submittedPlaylistUrl === '') {
            try {
                mlDeleteUserPrivatePlaylist($pdo, (int)$currentUser['UserID']);
                $privatePlaylistUrl = '';
                $message = 'Private playlist removed.';
            } catch (Throwable $e) {
                $error = 'The private playlist could not be removed. Please try again.';
            }
        } else {
            $playlist = mlValidatePlaylistPinUrl($submittedPlaylistUrl);

            if (empty($playlist['valid'])) {
                $error = (string)$playlist['error'];
            } else {
                try {
                    mlSaveUserPrivatePlaylist($pdo, (int)$currentUser['UserID'], $playlist);
                    $privatePlaylistUrl = (string)$playlist['url'];
                    $message = 'Private playlist saved.';
                } catch (Throwable $e) {
                    $error = 'The private playlist could not be saved. Please try again.';
                }
            }
        }
    } elseif ($action === 'profile') {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $uploadedFilename = null;

        if ($displayName === '') {
            $error = 'Display name is required.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $duplicateStmt = $pdo->prepare('SELECT UserID FROM ML_Users WHERE (UserName = ? OR Email = ?) AND UserID <> ? LIMIT 1');
            $duplicateStmt->execute([$displayName, $email, (int)$currentUser['UserID']]);
            if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
                $error = 'That display name or email is already being used by another player.';
            }
        }

        if ($error === '' && isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo']) && (int)$_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if (!$hasProfileImageColumn) {
                $error = 'Profile photo uploads require the ProfileImageFilename database column to exist first.';
            } else {
                $upload = $_FILES['profile_photo'];
                $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

                if ($uploadError !== UPLOAD_ERR_OK) {
                    $error = 'The photo could not be uploaded. Please try again.';
                } else {
                    $maxBytes = 5 * 1024 * 1024;
                    $tmpName = (string)$upload['tmp_name'];
                    $originalName = (string)$upload['name'];
                    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    $imageInfo = @getimagesize($tmpName);

                    if ((int)($upload['size'] ?? 0) > $maxBytes) {
                        $error = 'Profile photos must be 5 MB or smaller.';
                    } elseif (!in_array($extension, $allowedExtensions, true)) {
                        $error = 'Profile photos must be JPG, PNG, WEBP, or GIF.';
                    } elseif ($imageInfo === false) {
                        $error = 'The uploaded file is not a valid image.';
                    } else {
                        $profileDir = __DIR__ . '/uploads/profiles';
                        if (!is_dir($profileDir)) {
                            @mkdir($profileDir, 0775, true);
                        }

                        if (!is_dir($profileDir) || !is_writable($profileDir)) {
                            $error = 'The profile photo folder is not writable.';
                        } else {
                            $uploadedFilename = 'upload_user_' . (int)$currentUser['UserID'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                            $destination = $profileDir . '/' . $uploadedFilename;
                            $resizeError = '';

                            if (!mlResizeImageToFit($tmpName, $destination, 600, 600, $imageInfo, $resizeError)) {
                                $error = $resizeError !== '' ? $resizeError : 'The photo could not be saved.';
                            }
                        }
                    }
                }
            }
        }

        if ($error === '') {
            if ($hasProfileImageColumn) {
                if ($uploadedFilename !== null) {
                    $stmt = $pdo->prepare('UPDATE ML_Users SET UserName = ?, Email = ?, ProfileImageFilename = ? WHERE UserID = ?');
                    $stmt->execute([$displayName, $email, $uploadedFilename, (int)$currentUser['UserID']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE ML_Users SET UserName = ?, Email = ? WHERE UserID = ?');
                    $stmt->execute([$displayName, $email, (int)$currentUser['UserID']]);
                }
            } else {
                $stmt = $pdo->prepare('UPDATE ML_Users SET UserName = ?, Email = ? WHERE UserID = ?');
                $stmt->execute([$displayName, $email, (int)$currentUser['UserID']]);
            }

            $_SESSION['UserName'] = $displayName;
            $currentUser = mlRequireAuthenticatedUser($pdo);
            $message = $uploadedFilename !== null ? 'Profile updated and new photo saved.' : 'Profile updated.';
        }
    }
}

$currentThemeMode = mlGetThemeMode();
$currentProfileImage = $currentUser['profile_image_path'] ?? mlGetUserProfilePath((int)$currentUser['UserID'], $currentUser['ProfileImageFilename'] ?? null);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Settings</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="settings-page-shell">
        <div class="settings-page-intro">
            <h1>Settings</h1>
        </div>

        <?php if ($message !== ''): ?>
            <div class="status-banner success settings-feedback" role="status" data-settings-feedback><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="status-banner error settings-feedback" role="alert" data-settings-feedback><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="settings-sheet">
            <form method="post" action="settings.php" class="settings-sheet-section" enctype="multipart/form-data">
                <input type="hidden" name="settings_action" value="profile">
                <h2 class="settings-heading">Profile</h2>

                <div class="settings-profile-grid">
                    <div class="settings-profile-photo-block">
                        <img src="<?= htmlspecialchars($currentProfileImage) ?>" alt="<?= htmlspecialchars($currentUser['UserName']) ?>" class="profile-avatar profile-avatar-settings">
                        <input type="file" name="profile_photo" id="profile_photo" class="settings-photo-input" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" data-settings-photo-input>
                        <label class="button-secondary settings-photo-picker" for="profile_photo">Change Photo</label>
                        <span class="settings-photo-name" data-settings-photo-name>No photo selected</span>
                    </div>

                    <div class="settings-profile-fields">
                        <div class="settings-field">
                            <label class="admin-label" for="display_name">Display Name</label>
                            <input type="text" name="display_name" id="display_name" class="admin-input" value="<?= htmlspecialchars((string)$currentUser['UserName']) ?>" maxlength="100" required>
                        </div>

                        <div class="settings-field">
                            <label class="admin-label" for="email">Email</label>
                            <input type="email" name="email" id="email" class="admin-input" value="<?= htmlspecialchars((string)$currentUser['Email']) ?>" maxlength="255" required>
                        </div>
                    </div>
                </div>

                <?php if (!$hasProfileImageColumn): ?>
                    <p class="settings-inline-message">Photo uploads are not available yet.</p>
                <?php endif; ?>

                <div class="game-form-actions settings-sheet-actions">
                    <button type="submit" class="button-secondary">Save Profile</button>
                </div>
            </form>

            <form method="post" action="settings.php" class="settings-sheet-section">
                <input type="hidden" name="settings_action" value="private_playlist">
                <h2 class="settings-heading">Playlist Link</h2>

                <div class="settings-inline-form">
                    <div class="settings-field">
                        <label class="admin-label" for="private_playlist_url">Playlist URL</label>
                        <input
                            type="url"
                            name="private_playlist_url"
                            id="private_playlist_url"
                            class="admin-input"
                            value="<?= htmlspecialchars($privatePlaylistUrl) ?>"
                            placeholder="https://..."
                            maxlength="2048"
                            inputmode="url"
                            autocomplete="url"
                            spellcheck="false"
                            <?= $privatePlaylistStorageReady ? '' : 'disabled' ?>
                        >
                    </div>
                    <button type="submit" class="button-secondary"<?= $privatePlaylistStorageReady ? '' : ' disabled' ?>>Save Link</button>
                </div>

                <?php if (!$privatePlaylistStorageReady): ?>
                    <p class="settings-inline-message">Playlist links are not available yet.</p>
                <?php endif; ?>
            </form>

            <section class="settings-sheet-section settings-control-row" data-push-settings>
                <div class="settings-control-copy">
                    <h2 class="settings-heading">Push Notifications</h2>
                    <div class="settings-push-status" data-push-status>Checking this device...</div>

                    <?php if (!$pushStorageReady): ?>
                        <p class="settings-inline-message">Push notifications are not available yet.</p>
                    <?php endif; ?>
                </div>
                <div class="game-form-actions settings-push-actions">
                    <button type="button" class="button-primary" data-push-toggle<?= $pushReady ? '' : ' disabled' ?>>Turn on reminders</button>
                </div>
            </section>

            <form method="post" action="settings.php" class="settings-sheet-section settings-control-row">
                <input type="hidden" name="settings_action" value="appearance">
                <div class="settings-control-copy">
                    <h2 class="settings-heading">Appearance</h2>
                    <span class="theme-toggle-label">Light <span aria-hidden="true">↔</span> Dark</span>
                </div>
                <label class="theme-switch" for="theme_mode_toggle" aria-label="Toggle light and dark mode">
                    <input type="hidden" name="theme_mode" value="<?= $currentThemeMode === 'dark' ? 'dark' : 'light' ?>">
                    <input type="checkbox" id="theme_mode_toggle" name="theme_mode_toggle" value="dark" <?= $currentThemeMode === 'dark' ? 'checked' : '' ?> onchange="this.form.theme_mode.value = this.checked ? 'dark' : 'light'; this.form.submit();">
                    <span class="theme-switch-track"></span>
                </label>
            </form>
        </div>
    </div>
</div>
<script>
window.ML_PUSH_SETTINGS = <?= json_encode([
    'ready' => $pushReady,
    'publicKey' => $pushReady ? mlPushVapidPublicKey() : '',
    'endpoint' => mlUrl('integrations/push/subscription.php'),
    'csrfToken' => $pushCsrfToken,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(mlAssetUrl('assets/js/settings-page.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(mlAssetUrl('assets/js/push-settings.js')) ?>" defer></script>
</body>
</html>
