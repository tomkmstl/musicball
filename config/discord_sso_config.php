<?php
// config/discord_sso_config.php
// Discord SSO settings for Musicball.
//
// Required environment variables:
//   DISCORD_CLIENT_ID
//   DISCORD_CLIENT_SECRET
//
// Optional environment variables:
//   DISCORD_REDIRECT_URI  Example: https://mb-future.musicball.net/integrations/discord/callback.php
//   DISCORD_ALLOWED_GUILD_ID  Optional Discord server/guild restriction.

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
    return mlDiscordEnv('DISCORD_CLIENT_ID');
}

function mlDiscordClientSecret(): string
{
    return mlDiscordEnv('DISCORD_CLIENT_SECRET');
}

function mlDiscordAllowedGuildId(): string
{
    return mlDiscordEnv('DISCORD_ALLOWED_GUILD_ID');
}

function mlDiscordRedirectUri(): string
{
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
    return 'https://cdn.discordapp.com/avatars/' . rawurlencode($discordUserId) . '/' . rawurlencode($avatarHash) . '.' . $extension . '?size=128';
}
