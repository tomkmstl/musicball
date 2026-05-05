<?php
require_once __DIR__ . '/../session_boot.php';
require_once __DIR__ . '/../config.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;

if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $discordUserId = trim((string)($_POST['discord_user_id'] ?? ''));

    if ($userId <= 0) {
        $error = 'Choose a Musicball user.';
    } elseif ($discordUserId === '') {
        $error = 'Enter a Discord ID.';
    } elseif (!preg_match('/^\d{10,32}$/', $discordUserId)) {
        $error = 'Discord ID should be numeric.';
    } else {
        try {
            $existing = $pdo->prepare("
                SELECT UserID, UserName
                FROM ML_Users
                WHERE DiscordUserID = ?
                  AND UserID <> ?
                LIMIT 1
            ");
            $existing->execute([$discordUserId, $userId]);
            $existingUser = $existing->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                $error = 'That Discord ID is already linked to ' . $existingUser['UserName'] . '.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE ML_Users
                    SET DiscordUserID = ?,
                        DiscordLinkedAt = COALESCE(DiscordLinkedAt, NOW())
                    WHERE UserID = ?
                ");
                $stmt->execute([$discordUserId, $userId]);

                $message = 'Discord account linked.';
            }
        } catch (Throwable $e) {
            error_log('Discord manual link failed: ' . $e->getMessage());
            $error = 'Could not save the Discord link.';
        }
    }
}

$users = $pdo->query("
    SELECT UserID, UserName, Email, DiscordUserID
    FROM ML_Users
    ORDER BY UserName
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Link Discord User | Musicball</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(mlUrl('styles.css')); ?>">
    <style>
        body {
            background: #070812;
            color: #fff;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding: 32px;
        }
        .admin-card {
            max-width: 760px;
            margin: 0 auto;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 22px;
            padding: 28px;
        }
        label {
            display: block;
            margin-top: 16px;
            margin-bottom: 6px;
            font-weight: 700;
        }
        select, input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(0,0,0,.28);
            color: #fff;
        }
        button {
            margin-top: 18px;
            padding: 12px 18px;
            border: 0;
            border-radius: 999px;
            font-weight: 800;
            cursor: pointer;
        }
        .message {
            margin: 14px 0;
            padding: 12px;
            border-radius: 12px;
            background: rgba(54, 211, 153, .16);
            color: #7ff0c7;
        }
        .error {
            margin: 14px 0;
            padding: 12px;
            border-radius: 12px;
            background: rgba(255, 85, 85, .16);
            color: #ff9b9b;
        }
        .hint {
            color: rgba(255,255,255,.7);
            line-height: 1.5;
        }
        .linked-list {
            margin-top: 28px;
            font-size: 14px;
            color: rgba(255,255,255,.72);
        }
        .linked-list div {
            padding: 8px 0;
            border-top: 1px solid rgba(255,255,255,.1);
        }
    </style>
</head>
<body>
    <main class="admin-card">
        <h1>Link Discord User</h1>

        <p class="hint">
            Use this when a player logs in with Discord and sees the “not linked yet” screen.
            Copy their Discord ID from that screen, choose their existing Musicball user, and save.
        </p>

        <?php if ($message !== ''): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="user_id">Musicball user</label>
            <select name="user_id" id="user_id" required>
                <option value="">Choose user...</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo (int)$user['UserID']; ?>">
                        <?php echo htmlspecialchars($user['UserName']); ?>
                        <?php if (!empty($user['Email'])): ?>
                            — <?php echo htmlspecialchars($user['Email']); ?>
                        <?php endif; ?>
                        <?php if (!empty($user['DiscordUserID'])): ?>
                            — already linked
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="discord_user_id">Discord ID</label>
            <input
                type="text"
                name="discord_user_id"
                id="discord_user_id"
                placeholder="Example: 123456789012345678"
                required
            >

            <button type="submit">Link Discord Account</button>
        </form>

        <section class="linked-list">
            <h2>Current Discord links</h2>
            <?php foreach ($users as $user): ?>
                <?php if (!empty($user['DiscordUserID'])): ?>
                    <div>
                        <?php echo htmlspecialchars($user['UserName']); ?>:
                        <?php echo htmlspecialchars($user['DiscordUserID']); ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>