<?php
require_once __DIR__ . '/ml_gameplay.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'settings';
$message = '';
$error = '';
$hasProfileImageColumn = mlUsersHasProfileImageColumn($pdo);
$isAdminUser = mlUserIsAdmin($currentUser);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['settings_action']) ? (string)$_POST['settings_action'] : '';

    if ($action === 'appearance') {
        $themeMode = isset($_POST['theme_mode']) ? (string)$_POST['theme_mode'] : 'dark';
        mlSetThemeMode($themeMode);
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
                        $profileDir = __DIR__ . '/images/profiles';
                        if (!is_dir($profileDir)) {
                            @mkdir($profileDir, 0775, true);
                        }

                        if (!is_dir($profileDir) || !is_writable($profileDir)) {
                            $error = 'The profile photo folder is not writable.';
                        } else {
                            $uploadedFilename = 'user_' . (int)$currentUser['UserID'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
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
    <div class="card game-card game-card-narrow">
        <div class="settings-page-intro">
            <h1>Settings</h1>
        </div>

        <?php if ($message !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($isAdminUser): ?>
            <a href="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="settings-admin-link">Open Admin Settings</a>
        <?php endif; ?>

        <div class="settings-stack">
            <form method="post" action="settings.php" class="settings-form" enctype="multipart/form-data">
                <input type="hidden" name="settings_action" value="profile">
                <details class="settings-section settings-collapse">
                    <summary class="settings-collapse-summary">
                        <span class="settings-collapse-title">
                            <span class="home-shell-kicker">Profile</span>
                            <span class="settings-heading">Your info</span>
                        </span>
                        <span class="settings-collapse-icon" aria-hidden="true"></span>
                    </summary>

                    <div class="settings-collapse-content">
                        <p>Update your display name, email, and profile photo.</p>

                        <div class="settings-profile-grid">
                            <div class="settings-profile-photo-block">
                                <img src="<?= htmlspecialchars($currentProfileImage) ?>" alt="<?= htmlspecialchars($currentUser['UserName']) ?>" class="profile-avatar profile-avatar-settings">
                                <label class="admin-label" for="profile_photo">Change photo</label>
                                <input type="file" name="profile_photo" id="profile_photo" class="admin-input settings-file-input" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                                <p>JPG, PNG, WEBP, or GIF. Max 5 MB.</p>
                            </div>

                            <div class="settings-profile-fields">
                                <label class="admin-label" for="display_name">Display Name</label>
                                <input type="text" name="display_name" id="display_name" class="admin-input" value="<?= htmlspecialchars((string)$currentUser['UserName']) ?>" maxlength="100" required>

                                <label class="admin-label" for="email">Email</label>
                                <input type="email" name="email" id="email" class="admin-input" value="<?= htmlspecialchars((string)$currentUser['Email']) ?>" maxlength="255" required>

                                <?php if (!$hasProfileImageColumn): ?>
                                    <p>Photo uploads will start working after the <code>ProfileImageFilename</code> column is added to <code>ML_Users</code>.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="game-form-actions">
                            <button type="submit" class="button-primary">Save profile</button>
                        </div>
                    </div>
                </details>
            </form>

            <form method="post" action="settings.php" class="settings-form">
                <input type="hidden" name="settings_action" value="appearance">
                <div class="settings-section">
                    <div class="home-shell-kicker">Appearance</div>
                    <h2>Color mode</h2>
                    <div class="theme-toggle-row">
                        <div class="theme-toggle-copy">
                            <span class="theme-toggle-label">Light <span aria-hidden="true">↔</span> Dark</span>
                            <span class="theme-toggle-note">Use the switch to choose your mode.</span>
                        </div>
                        <label class="theme-switch" for="theme_mode_toggle" aria-label="Toggle light and dark mode">
                            <input type="hidden" name="theme_mode" value="<?= $currentThemeMode === 'dark' ? 'dark' : 'light' ?>">
                            <input type="checkbox" id="theme_mode_toggle" name="theme_mode_toggle" value="dark" <?= $currentThemeMode === 'dark' ? 'checked' : '' ?> onchange="this.form.theme_mode.value = this.checked ? 'dark' : 'light'; this.form.submit();">
                            <span class="theme-switch-track"></span>
                        </label>
                    </div>
                </div>
            </form>




            <a href="logout.php" class="button-secondary settings-logout-link">Logout</a>
        </div>
    </div>
</div>
</body>
</html>
