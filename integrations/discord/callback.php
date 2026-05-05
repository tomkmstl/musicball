<?php
// integrations/discord/callback.php

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

$expectedState = (string)($_SESSION['discord_oauth_state'] ?? '');
$actualState = (string)($_GET['state'] ?? '');
unset($_SESSION['discord_oauth_state']);

if ($expectedState === '' || $actualState === '' || !hash_equals($expectedState, $actualState)) {
    mlDiscordFail('Discord login expired. Please try again.');
}

if (isset($_GET['error'])) {
    mlDiscordFail('Discord login was canceled.');
}

$code = trim((string)($_GET['code'] ?? ''));
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

    $accessToken = (string)($token['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Discord did not return an access token.');
    }

    $discordUser = mlDiscordGet('https://discord.com/api/users/@me', $accessToken);

    $discordUserId = (string)($discordUser['id'] ?? '');
    $discordUsername = (string)($discordUser['username'] ?? '');
    $discordGlobalName = (string)($discordUser['global_name'] ?? '');
    $discordEmail = trim((string)($discordUser['email'] ?? ''));
    $discordAvatarHash = isset($discordUser['avatar']) ? (string)$discordUser['avatar'] : null;

    if ($discordUserId === '') {
        throw new RuntimeException('Discord did not return a user id.');
    }

    $allowedGuildId = mlDiscordAllowedGuildId();
    if ($allowedGuildId !== '') {
        $guilds = mlDiscordGet('https://discord.com/api/users/@me/guilds', $accessToken);
        $isMember = false;

        foreach ($guilds as $guild) {
            if (is_array($guild) && (string)($guild['id'] ?? '') === $allowedGuildId) {
                $isMember = true;
                break;
            }
        }

        if (!$isMember) {
            mlDiscordFail('That Discord account is not allowed for this Musicball league.');
        }
    }

    // 1. Best match: already linked Discord user id.
    $stmt = $pdo->prepare("
        SELECT UserID, UserName, Email
        FROM ML_Users
        WHERE DiscordUserID = ?
        LIMIT 1
    ");
    $stmt->execute([$discordUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Convenient first-time match: verified Discord email matches Musicball email.
    if (!$user && $discordEmail !== '') {
        $stmt = $pdo->prepare("
            SELECT UserID, UserName, Email
            FROM ML_Users
            WHERE Email = ?
            LIMIT 1
        ");
        $stmt->execute([$discordEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 3. No match: do NOT create a duplicate user. Show simple linking info.
    if (!$user) {
        $_SESSION['pending_discord_link'] = [
            'discord_user_id' => $discordUserId,
            'discord_username' => $discordUsername,
            'discord_global_name' => $discordGlobalName,
            'discord_email' => $discordEmail,
            'discord_avatar_hash' => $discordAvatarHash,
        ];

        header('Location: ' . mlUrl('integrations/discord/not-linked.php'));
        exit;
    }

    // Link/update Discord details on the existing Musicball user.
    $update = $pdo->prepare("
        UPDATE ML_Users
        SET DiscordUserID = ?,
            DiscordUsername = ?,
            DiscordGlobalName = ?,
            DiscordAvatarHash = ?,
            DiscordLinkedAt = COALESCE(DiscordLinkedAt, NOW()),
            LastLoginAt = NOW()
        WHERE UserID = ?
    ");
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
        $_SESSION['pending_discord_link'],
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