<?php
require_once __DIR__ . '/../../session_boot.php';
require_once __DIR__ . '/../../config.php';

$pending = $_SESSION['pending_discord_link'] ?? null;

if (!is_array($pending)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$discordUserId = (string)($pending['discord_user_id'] ?? '');
$discordUsername = (string)($pending['discord_username'] ?? '');
$discordGlobalName = (string)($pending['discord_global_name'] ?? '');
$discordEmail = (string)($pending['discord_email'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Discord Account Not Linked | Musicball</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(mlUrl('styles.css')); ?>">
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #070812;
            color: #fff;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding: 24px;
        }
        .link-card {
            width: min(560px, 100%);
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 22px;
            padding: 28px;
            box-shadow: 0 24px 80px rgba(0,0,0,.35);
        }
        .link-card h1 {
            margin: 0 0 12px;
            font-size: 28px;
        }
        .link-card p {
            color: rgba(255,255,255,.78);
            line-height: 1.5;
        }
        .link-details {
            margin-top: 18px;
            padding: 16px;
            border-radius: 14px;
            background: rgba(0,0,0,.25);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 14px;
            line-height: 1.7;
            word-break: break-word;
        }
        .link-actions {
            margin-top: 22px;
        }
        .link-actions a {
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <main class="link-card">
        <h1>Discord account not linked yet</h1>
        <p>
            Your Discord login worked, but this Discord account is not connected to a Musicball player yet.
            Send this info to the commissioner.
        </p>

        <div class="link-details">
            Discord ID: <?php echo htmlspecialchars($discordUserId); ?><br>
            Username: <?php echo htmlspecialchars($discordUsername); ?><br>
            Display name: <?php echo htmlspecialchars($discordGlobalName); ?><br>
            Email: <?php echo htmlspecialchars($discordEmail !== '' ? $discordEmail : 'Not shared'); ?>
        </div>

        <div class="link-actions">
            <a href="<?php echo htmlspecialchars(mlUrl('index.php')); ?>">Back to login</a>
        </div>
    </main>
</body>
</html>