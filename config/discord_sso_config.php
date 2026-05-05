<?php
// config/discord_sso_config.php
// Discord SSO settings for Musicball.
//
// Preferred local secrets file:
//   config/discord_sso_secrets.php
//
// Supported constants in that file:
//   DISCORD_CLIENT_ID_LOCAL
//   DISCORD_CLIENT_SECRET_LOCAL
//   DISCORD_REDIRECT_URI_LOCAL
//   DISCORD_ALLOWED_GUILD_ID_LOCAL
//
// Environment variables still work as fallback.

$discordSecretsFile = __DIR__ . '/discord_sso_secrets.php';
if (file_exists($discordSecretsFile)) {
    require_once $discordSecretsFile;
}

function mlDiscordEnv(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }

    return trim((string)$value);
}

function mlDiscordClientId(): string
{
    if (defined('DISCORD_CLIENT_ID_LOCAL')) {
        return trim((string) DISCORD_CLIENT_ID_LOCAL);
    }

    return mlDiscordEnv('DISCORD_CLIENT_ID');
}

function mlDiscordClientSecret(): string
{
    if (defined('DISCORD_CLIENT_SECRET_LOCAL')) {
        return trim((string) DISCORD_CLIENT_SECRET_LOCAL);
    }

    return mlDiscordEnv('DISCORD_CLIENT_SECRET');
}

function mlDiscordAllowedGuildId(): string
{
    if (defined('DISCORD_ALLOWED_GUILD_ID_LOCAL')) {
        return trim((string) DISCORD_ALLOWED_GUILD_ID_LOCAL);
    }

    return mlDiscordEnv('DISCORD_ALLOWED_GUILD_ID');
}

function mlDiscordRedirectUri(): string
{
    if (defined('DISCORD_REDIRECT_URI_LOCAL')) {
        return trim((string) DISCORD_REDIRECT_URI_LOCAL);
    }

    $configured = mlDiscordEnv('DISCORD_REDIRECT_URI');
    if ($configured !== '') {
        return $configured;
    }

    $isSecure =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $scheme = $isSecure ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . mlUrl('integrations/discord/callback.php');
}

function mlDiscordConfigIsReady(): bool
{
    return mlDiscordClientId() !== '' && mlDiscordClientSecret() !== '';
}

function mlDiscordAvatarUrl(string $discordUserId, ?string $avatarHash): ?string
{
    $avatarHash = trim((string)$avatarHash);
    if ($discordUserId === '' || $avatarHash === '') {
        return null;
    }

    $extension = str_starts_with($avatarHash, 'a_') ? 'gif' : 'png';

    return 'https://cdn.discordapp.com/avatars/'
        . rawurlencode($discordUserId)
        . '/'
        . rawurlencode($avatarHash)
        . '.'
        . $extension
        . '?size=128';
}