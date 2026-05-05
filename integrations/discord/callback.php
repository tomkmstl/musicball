<?php
// integrations/discord/callback.php
// Completes Discord OAuth and creates the Musicball session.

require_once __DIR__ . '/../../session_boot.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/discord_sso_config.php';

function mlDiscordFail(string $message): void
{
    $_SESSION['login_error'] = $message;
    header('Location: ' . mlUrl('index.php'));
    exit;
}

function mlDiscordPost(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Discord token request failed. ' . $error);
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Discord token response was not valid JSON.');
    }

    return $decoded;
}

function mlDiscordGet(string $url, string $accessToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Discord API request failed. ' . $error);
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Discord API response was not valid JSON.');
    }

    return $decoded;
}

if (!mlDiscordConfigIsReady()) {
    mlDiscordFail('Discord login is not configured yet.');
}

$expectedState = isset($_SESSION['discord_oauth_state']) ? (string)$_SESSION['discord_oauth_state'] : '';
$actualState = isset($_GET['state']) ? (string)$_GET['state'] : '';
unset($_SESSION['discord_oauth_state']);

if ($expectedState === '' || $actualState === '' || !hash_equals($expectedState, $actualState)) {
    mlDiscordFail('Discord login expired. Please try again.');
}

if (isset($_GET['error'])) {
    mlDiscordFail('Discord login was canceled.');
}

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
if ($code === '') {
    mlDiscordFail('Discord did not return a login code.');
}

try {
    $token = mlDiscordPost('https://discord.com/api/oauth2/token', [
        'client_id' => mlDiscordClientId(),
        'client_secret' => mlDiscordClientSecret(),
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => mlDiscordRedirectUri(),
    ]);

    $accessToken = isset($token['access_token']) ? (string)$token['access_token'] : '';
    if ($accessToken === '') {
        throw new RuntimeException('Discord did not return an access token.');
    }

    $discordUser = mlDiscordGet('https://discord.com/api/users/@me', $accessToken);

    $discordUserId = isset($discordUser['id']) ? (string)$discordUser['id'] : '';
    $discordUsername = isset($discordUser['username']) ? (string)$discordUser['username'] : '';
    $discordGlobalName = isset($discordUser['global_name']) ? (string)$discordUser['global_name'] : '';
    $discordEmail = isset($discordUser['email']) ? trim((string)$discordUser['email']) : '';
    $discordAvatarHash = isset($discordUser['avatar']) ? (string)$discordUser['avatar'] : null;

    if ($discordUserId === '') {
        throw new RuntimeException('Discord did not return a user id.');
    }

    $allowedGuildId = mlDiscordAllowedGuildId();
    if ($allowedGuildId !== '') {
        $guilds = mlDiscordGet('https://discord.com/api/users/@me/guilds', $accessToken);
        $isMember = false;

        foreach ($guilds as $guild) {
            if (is_array($guild) && isset($guild['id']) && (string)$guild['id'] === $allowedGuildId) {
                $isMember = true;
                break;
            }
        }

        if (!$isMember) {
            mlDiscordFail('That Discord account is not a member of the Musicball Discord server.');
        }
    }

    $stmt = $pdo->prepare("\n        SELECT UserID, UserName, Email\n        FROM ML_Users\n        WHERE DiscordUserID = ?\n        LIMIT 1\n    ");
    $stmt->execute([$discordUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user && $discordEmail !== '') {
        $stmt = $pdo->prepare("\n            SELECT UserID, UserName, Email\n            FROM ML_Users\n            WHERE Email = ?\n            LIMIT 1\n        ");
        $stmt->execute([$discordEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        mlDiscordFail('This Discord account is not linked to a Musicball player yet. Ask the commissioner to add your email address to Musicball first.');
    }

    $update = $pdo->prepare("\n        UPDATE ML_Users\n        SET DiscordUserID = ?,\n            DiscordUsername = ?,\n            DiscordGlobalName = ?,\n            DiscordAvatarHash = ?,\n            DiscordLinkedAt = COALESCE(DiscordLinkedAt, NOW()),\n            LastLoginAt = NOW()\n        WHERE UserID = ?\n    ");
    $update->execute([
        $discordUserId,
        $discordUsername,
        $discordGlobalName !== '' ? $discordGlobalName : null,
        $discordAvatarHash,
        (int)$user['UserID'],
    ]);

    session_regenerate_id(true);

    $_SESSION['UserID'] = (int)$user['UserID'];
    $_SESSION['UserName'] = (string)$user['UserName'];
    $_SESSION['DiscordUserID'] = $discordUserId;

    unset(
        $_SESSION['ml_user_id'],
        $_SESSION['SpotifyAccessToken'],
        $_SESSION['SpotifyRefreshToken'],
        $_SESSION['PendingSpotifyID'],
        $_SESSION['PendingSpotifyDisplayName'],
        $_SESSION['PendingSpotifyEmail'],
        $_SESSION['spotify_oauth_state']
    );

    header('Location: ' . mlUrl('season.php'));
    exit;
} catch (Throwable $e) {
    error_log('Discord SSO callback failed: ' . $e->getMessage());
    mlDiscordFail('Discord login failed. Please try again.');
}
