<?php
// integrations/discord/login.php
// Starts Discord OAuth for Musicball login.

require_once __DIR__ . '/../../session_boot.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/discord_sso_config.php';

if (!mlDiscordConfigIsReady()) {
    $_SESSION['login_error'] = 'Discord login is not configured yet.';
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$state = bin2hex(random_bytes(32));
$_SESSION['discord_oauth_state'] = $state;

$scope = 'identify email';
if (mlDiscordAllowedGuildId() !== '') {
    $scope .= ' guilds';
}

$params = [
    'client_id' => mlDiscordClientId(),
    'redirect_uri' => mlDiscordRedirectUri(),
    'response_type' => 'code',
    'scope' => $scope,
    'state' => $state,
    'prompt' => 'none',
];

header('Location: https://discord.com/oauth2/authorize?' . http_build_query($params));
exit;
