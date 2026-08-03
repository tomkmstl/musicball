<?php
// discord.php
// Discord webhook helpers for Musicball.

require_once dirname(__DIR__, 2) . '/config.php';

function mlDiscordLog(string $message, array $context = []): void
{
    $contextParts = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $contextParts[] = $key . '=' . (string)$value;
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $contextParts[] = $key . '=' . ($encoded !== false ? $encoded : '[unserializable]');
        }
    }

    $suffix = $contextParts ? ' | ' . implode(', ', $contextParts) : '';
    error_log('[Musicball Discord] ' . $message . $suffix);
}

function mlDiscordGetProfileDefinitions(): array
{
    return [
        'essential' => [
            'label' => 'Essential notifications',
            'description' => 'New round opens, voting opens, all votes submitted, and round closes.',
            'display_name_setting' => 'discord_username',
            'webhook_url_setting' => 'discord_webhook_url',
        ],
        'every' => [
            'label' => 'Every notification',
            'description' => 'All essential notifications plus song submitted, song changed, and votes submitted.',
            'display_name_setting' => 'discord_every_username',
            'webhook_url_setting' => 'discord_every_webhook_url',
        ],
        'qa' => [
            'label' => 'QA notifications',
            'description' => 'Every notification event from QA data, delivered only to the QA webhook.',
            'display_name_setting' => 'discord_qa_username',
            'webhook_url_setting' => 'discord_qa_webhook_url',
        ],
    ];
}

function mlDiscordGetProfileDefinition(string $profileKey): array
{
    $definitions = mlDiscordGetProfileDefinitions();
    return $definitions[$profileKey] ?? [];
}

function mlDiscordGetSettingsPdo(PDO $pdo): PDO
{
    return $pdo;
}

function mlDiscordGetMasterSettingsPdo(PDO $pdo): PDO
{
    if (function_exists('mlGetLivePdo')) {
        return mlGetLivePdo();
    }

    return $pdo;
}

function mlDiscordGetDataMode(PDO $pdo): string
{
    if (!function_exists('mlGetPdoDataMode')) {
        return 'unknown';
    }

    return mlGetPdoDataMode($pdo);
}

function mlDiscordGetAllowedProfileKeys(PDO $pdo): array
{
    $dataMode = mlDiscordGetDataMode($pdo);
    if ($dataMode === 'qa') {
        return ['qa'];
    }

    if ($dataMode === 'live') {
        return ['essential', 'every'];
    }

    return [];
}

function mlDiscordGetWebhookUrl(PDO $pdo, string $profileKey = 'essential'): string
{
    $definition = mlDiscordGetProfileDefinition($profileKey);
    if (empty($definition) || !in_array($profileKey, mlDiscordGetAllowedProfileKeys($pdo), true)) {
        return '';
    }

    $settingsPdo = mlDiscordGetSettingsPdo($pdo);
    $url = mlGetSettingValue($settingsPdo, $definition['webhook_url_setting'], null);
    return trim((string)($url ?? ''));
}

function mlDiscordGetRawDisplayName(PDO $pdo, string $profileKey = 'essential'): ?string
{
    $definition = mlDiscordGetProfileDefinition($profileKey);
    if (empty($definition) || !in_array($profileKey, mlDiscordGetAllowedProfileKeys($pdo), true)) {
        return null;
    }

    $settingsPdo = mlDiscordGetSettingsPdo($pdo);
    $name = mlGetSettingValue($settingsPdo, $definition['display_name_setting'], null);
    if ($name === null) {
        return null;
    }

    $name = trim((string)$name);
    return $name !== '' ? $name : null;
}

function mlDiscordIsEnabled(PDO $pdo): bool
{
    $settingsPdo = mlDiscordGetMasterSettingsPdo($pdo);
    $enabled = trim((string)mlGetSettingValue($settingsPdo, 'discord_enabled', '1'));
    return $enabled !== '0';
}

function mlDiscordGetDisplayName(PDO $pdo, string $profileKey = 'essential'): string
{
    $name = mlDiscordGetRawDisplayName($pdo, $profileKey);
    return $name !== null ? $name : 'Musicball';
}

function mlDiscordGetDeliveryProfileLabel(string $profileKey): string
{
    $definition = mlDiscordGetProfileDefinition($profileKey);
    return (string)($definition['label'] ?? 'Unknown Discord destination');
}

function mlDiscordGetEventDeliveryProfiles(PDO $pdo, string $eventKey): array
{
    $baseEventKey = mlDiscordGetBaseEventKey($eventKey);
    if ($baseEventKey === '') {
        return [];
    }

    $essentialEvents = ['submission_open', 'voting_open', 'all_votes_in', 'round_closed', 'builder_voting_complete', 'season_started'];
    $everyOnlyEvents = ['song_submitted', 'song_changed', 'votes_submitted'];
    if (!in_array($baseEventKey, array_merge($essentialEvents, $everyOnlyEvents), true)) {
        return [];
    }

    $dataMode = mlDiscordGetDataMode($pdo);
    if ($dataMode === 'qa') {
        return ['qa'];
    }

    if ($dataMode !== 'live') {
        return [];
    }

    if (in_array($baseEventKey, $essentialEvents, true)) {
        return ['essential', 'every'];
    }

    if (in_array($baseEventKey, $everyOnlyEvents, true)) {
        return ['every'];
    }

    return [];
}

function mlDiscordBuildDeliveryScopedEventKey(string $eventKey, string $profileKey): string
{
    $eventKey = mlDiscordNormalizeEventKey($eventKey);
    if ($eventKey === '') {
        return '';
    }

    if ($profileKey === 'every') {
        return mlDiscordNormalizeEventKey($eventKey . '_every');
    }

    if ($profileKey === 'qa') {
        return mlDiscordNormalizeEventKey($eventKey . '_qa');
    }

    return $eventKey;
}

function mlDiscordExtractDeliveryProfileFromEventKey(string $eventKey): string
{
    $normalized = mlDiscordNormalizeEventKey($eventKey);
    if ($normalized === '') {
        return 'essential';
    }

    if (preg_match('/(?:^|_)qa$/', $normalized)) {
        return 'qa';
    }

    if (preg_match('/(?:^|_)every$/', $normalized)) {
        return 'every';
    }

    return 'essential';
}

function mlDiscordIsWebhookUrlAllowed(string $url): bool
{
    if ($url === '') {
        return false;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');

    if ($scheme !== 'https') {
        return false;
    }

    $allowedHosts = [
        'discord.com',
        'www.discord.com',
        'discordapp.com',
        'www.discordapp.com'
    ];

    if (!in_array($host, $allowedHosts, true)) {
        return false;
    }

    if (strpos($path, '/api/webhooks/') !== 0) {
        return false;
    }

    return true;
}

function mlDiscordMaskWebhookUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $length = strlen($url);
    if ($length <= 20) {
        return str_repeat('*', $length);
    }

    return substr($url, 0, 12) . str_repeat('*', max(0, $length - 20)) . substr($url, -8);
}

function mlDiscordGetConfigStatus(PDO $pdo): array
{
    $profiles = [];
    foreach (mlDiscordGetProfileDefinitions() as $profileKey => $definition) {
        $webhookUrl = mlDiscordGetWebhookUrl($pdo, $profileKey);
        $rawDisplayName = mlDiscordGetRawDisplayName($pdo, $profileKey);
        $profiles[$profileKey] = [
            'profile_key' => $profileKey,
            'label' => (string)$definition['label'],
            'description' => (string)$definition['description'],
            'display_name_setting' => (string)$definition['display_name_setting'],
            'webhook_url_setting' => (string)$definition['webhook_url_setting'],
            'display_name' => $rawDisplayName ?? '',
            'resolved_display_name' => mlDiscordGetDisplayName($pdo, $profileKey),
            'display_name_present' => ($rawDisplayName !== null),
            'webhook_url' => $webhookUrl,
            'webhook_masked' => mlDiscordMaskWebhookUrl($webhookUrl),
            'webhook_present' => ($webhookUrl !== ''),
            'webhook_valid' => ($webhookUrl !== '' && mlDiscordIsWebhookUrlAllowed($webhookUrl)),
        ];
    }

    $essentialProfile = $profiles['essential'];

    return [
        'enabled' => mlDiscordIsEnabled($pdo),
        'enabled_setting' => trim((string)mlGetSettingValue(mlDiscordGetMasterSettingsPdo($pdo), 'discord_enabled', '1')) === '1',
        'profiles' => $profiles,
        'webhook_url' => $essentialProfile['webhook_url'],
        'webhook_masked' => $essentialProfile['webhook_masked'],
        'webhook_present' => $essentialProfile['webhook_present'],
        'webhook_valid' => $essentialProfile['webhook_valid'],
        'display_name' => $essentialProfile['display_name'],
        'event_log_ready' => mlDiscordEventLogTableExists($pdo)
    ];
}

function mlDiscordSendTestMessage(PDO $pdo, string $eventKey = 'submission_open'): array
{
    if (!mlDiscordIsEnabled($pdo)) {
        return [
            'sent' => false,
            'reason' => 'discord_disabled',
            'status_code' => 0,
            'error' => 'All Discord notifications are off.',
            'event_key' => mlDiscordNormalizeEventKey($eventKey),
            'profile_results' => []
        ];
    }

    $eventKey = mlDiscordNormalizeEventKey($eventKey);
    $messageText = mlDiscordBuildTestMessageText($eventKey);
    if ($messageText === '') {
        return [
            'sent' => false,
            'reason' => 'invalid_event_key',
            'status_code' => 0,
            'error' => 'Choose a valid Discord notification type to test.'
        ];
    }

    $deliveryProfiles = mlDiscordGetEventDeliveryProfiles($pdo, $eventKey);
    $profileKey = $deliveryProfiles[0] ?? '';
    $profileResults = [];

    if ($profileKey === '') {
        return [
            'sent' => false,
            'reason' => 'blocked_data_mode',
            'status_code' => 0,
            'error' => 'Discord test blocked because the live/QA data mode could not be verified.',
            'event_key' => $eventKey,
            'profile_results' => []
        ];
    }

    $webhookUrl = mlDiscordGetWebhookUrl($pdo, $profileKey);

    if ($webhookUrl === '') {
        $profileResults[$profileKey] = [
            'sent' => false,
            'reason' => 'not_configured',
            'status_code' => 0,
            'error' => ''
        ];

        return [
            'sent' => false,
            'reason' => 'no_webhook_url',
            'status_code' => 0,
            'error' => 'Save the ' . mlDiscordGetDeliveryProfileLabel($profileKey) . ' webhook URL first.',
            'event_key' => $eventKey,
            'profile_results' => $profileResults
        ];
    }

    if (!mlDiscordIsWebhookUrlAllowed($webhookUrl)) {
        $errorMessage = mlDiscordGetDeliveryProfileLabel($profileKey) . ' webhook URL is invalid.';
        $profileResults[$profileKey] = [
            'sent' => false,
            'reason' => 'invalid_webhook_url',
            'status_code' => 0,
            'error' => $errorMessage
        ];

        return [
            'sent' => false,
            'reason' => 'invalid_webhook_url',
            'status_code' => 0,
            'error' => $errorMessage,
            'event_key' => $eventKey,
            'profile_results' => $profileResults
        ];
    }

    $payload = mlDiscordBuildPayload($pdo, $messageText, ['profile_key' => $profileKey]);
    $response = mlDiscordSendWebhookRequest($webhookUrl, $payload, 3);
    $statusCode = (int)($response['status_code'] ?? 0);
    $errorMessage = trim((string)($response['error'] ?? ''));

    $profileResults[$profileKey] = [
        'sent' => !empty($response['ok']),
        'reason' => !empty($response['ok']) ? 'sent' : 'send_failed',
        'status_code' => $statusCode,
        'error' => $errorMessage
    ];

    if (empty($response['ok'])) {
        mlDiscordLog('Test webhook send failed.', [
            'status_code' => $statusCode,
            'error' => $errorMessage,
            'event_key' => $eventKey,
            'profile_key' => $profileKey
        ]);
    }

    return [
        'sent' => !empty($response['ok']),
        'reason' => !empty($response['ok']) ? 'sent' : 'send_failed',
        'status_code' => $statusCode,
        'error' => $errorMessage,
        'event_key' => $eventKey,
        'profile_results' => $profileResults
    ];
}


function mlDiscordGetTestEventOptions(): array
{
    return [
        'submission_open' => 'New round opens',
        'voting_open' => 'Voting opens',
        'all_votes_in' => 'All votes submitted',
        'round_closed' => 'Round closes',
        'builder_voting_complete' => 'Season Builder voting complete',
        'season_started' => 'Season started',
        'song_submitted' => 'Song submitted',
        'song_changed' => 'Song changed',
        'votes_submitted' => 'Votes submitted',
    ];
}

function mlDiscordBuildTestMessageText(string $eventKey): string
{
    $eventKey = mlDiscordNormalizeEventKey($eventKey);
    $timestamp = gmdate('Y-m-d H:i:s') . ' UTC';

    $messages = [
        'submission_open' => "🧪 Test: 🎵 Round 3 is open — song submissions are live.",
        'voting_open' => "🧪 Test: 🗳️ Voting is open for Round 3.",
        'all_votes_in' => "🧪 Test: ✅ All votes are in for Round 3. Results and standings are final.",
        'round_closed' => "🧪 Test: 🏁 Round 3 is officially closed.",
        'builder_voting_complete' => "🧪 Test: 🧱 Season Builder voting is complete for Season 3. Next season is ready for review.",
        'season_started' => "🧪 Test: 🚀 Season 3 is now live on Musicball !",
        'song_submitted' => "🧪 Test: 🎶 musicballer submitted a song for Round 3.",
        'song_changed' => "🧪 Test: 🙇‍♂️ Somebody is on skates in Round 3!",
        'votes_submitted' => "🧪 Test: 🗳️ musicballer submitted votes for Round 3.",
    ];

    return $messages[$eventKey] ?? '';
}

function mlDiscordEventLogTableExists(PDO $pdo): bool
{
    static $existsByConnection = [];

    $dataMode = mlDiscordGetDataMode($pdo);
    if ($dataMode !== 'live' && $dataMode !== 'qa') {
        return false;
    }

    $cacheKey = spl_object_id($pdo) . ':' . $dataMode;

    if (array_key_exists($cacheKey, $existsByConnection)) {
        return $existsByConnection[$cacheKey];
    }

    try {
        $tableName = $dataMode === 'qa' ? 'QA_ML_DiscordEventLog' : 'ML_DiscordEventLog';
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ');
        $stmt->execute([$tableName]);
        $existsByConnection[$cacheKey] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $existsByConnection[$cacheKey] = false;
    }

    return $existsByConnection[$cacheKey];
}

function mlDiscordNormalizeEventKey(string $eventKey): string
{
    $eventKey = strtolower(trim($eventKey));
    $eventKey = preg_replace('/[^a-z0-9_\-]+/', '_', $eventKey);
    $eventKey = trim((string)$eventKey, '_');

    return $eventKey;
}

function mlDiscordHasEventBeenSent(PDO $pdo, int $seasonRoundId, string $eventKey): bool
{
    if ($seasonRoundId <= 0 || !mlDiscordEventLogTableExists($pdo)) {
        return false;
    }

    $eventKey = mlDiscordNormalizeEventKey($eventKey);
    if ($eventKey === '') {
        return false;
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM ML_DiscordEventLog
        WHERE SeasonRoundID = ?
          AND EventKey = ?
        LIMIT 1
    ');
    $stmt->execute([$seasonRoundId, $eventKey]);

    return (bool)$stmt->fetchColumn();
}

function mlDiscordMarkEventSent(PDO $pdo, int $seasonRoundId, string $eventKey, string $messageText = ''): bool
{
    if ($seasonRoundId <= 0 || !mlDiscordEventLogTableExists($pdo)) {
        return false;
    }

    $eventKey = mlDiscordNormalizeEventKey($eventKey);
    if ($eventKey === '') {
        return false;
    }

    $stmt = $pdo->prepare('
        INSERT INTO ML_DiscordEventLog (SeasonRoundID, EventKey, MessageText)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            MessageText = VALUES(MessageText),
            SentAt = SentAt
    ');

    return $stmt->execute([$seasonRoundId, $eventKey, $messageText]);
}

function mlDiscordBuildPayload(PDO $pdo, string $messageText, array $options = []): array
{
    $profileKey = isset($options['profile_key']) ? (string)$options['profile_key'] : 'essential';
    if (mlDiscordGetDataMode($pdo) === 'qa' && strpos($messageText, '[QA] ') !== 0) {
        $messageText = '[QA] ' . $messageText;
    }

    $payload = [
        'content' => $messageText,
        'username' => (string)($options['username'] ?? mlDiscordGetDisplayName($pdo, $profileKey))
    ];

    if (isset($options['avatar_url']) && trim((string)$options['avatar_url']) !== '') {
        $payload['avatar_url'] = trim((string)$options['avatar_url']);
    }

    return $payload;
}

function mlDiscordSendWebhookRequest(string $webhookUrl, array $payload, int $timeoutSeconds = 3): array
{
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($jsonPayload === false) {
        return [
            'ok' => false,
            'status_code' => 0,
            'error' => 'Failed to encode Discord payload.',
            'response_body' => ''
        ];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            return [
                'ok' => false,
                'status_code' => $statusCode,
                'error' => $curlError !== '' ? $curlError : 'Discord webhook request failed.',
                'response_body' => ''
            ];
        }

        return [
            'ok' => ($statusCode >= 200 && $statusCode < 300),
            'status_code' => $statusCode,
            'error' => ($statusCode >= 200 && $statusCode < 300) ? '' : 'Discord webhook returned HTTP ' . $statusCode . '.',
            'response_body' => (string)$responseBody
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n" .
                        'Content-Length: ' . strlen($jsonPayload) . "\r\n",
            'content' => $jsonPayload,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ]
    ]);

    $responseBody = @file_get_contents($webhookUrl, false, $context);
    $statusCode = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string)$headerLine, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
    }

    if ($responseBody === false) {
        return [
            'ok' => false,
            'status_code' => $statusCode,
            'error' => 'Discord webhook request failed.',
            'response_body' => ''
        ];
    }

    return [
        'ok' => ($statusCode >= 200 && $statusCode < 300),
        'status_code' => $statusCode,
        'error' => ($statusCode >= 200 && $statusCode < 300) ? '' : 'Discord webhook returned HTTP ' . $statusCode . '.',
        'response_body' => (string)$responseBody
    ];
}

function mlDiscordBuildRoundLabel(array $round): string
{
    $roundNumber = (int)($round['RoundNumber'] ?? 0);
    if ($roundNumber > 0) {
        return 'Round ' . $roundNumber;
    }

    $title = trim((string)($round['Title'] ?? ''));
    return $title !== '' ? $title : 'This round';
}

function mlDiscordGetExpectedPlayerCount(PDO $pdo): int
{
    global $totalPlayers;

    if (isset($totalPlayers) && (int)$totalPlayers > 0) {
        return (int)$totalPlayers;
    }

    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM ML_Users')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function mlDiscordLoadRound(PDO $pdo, int $seasonRoundId): ?array
{
    if ($seasonRoundId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT sr.SeasonRoundID, sr.SeasonID, sr.RoundNumber, sr.Title, sr.SongsDue, sr.VotesDue, s.SeasonName\n            FROM ML_SeasonRounds sr\n            LEFT JOIN ML_Seasons s ON sr.SeasonID = s.SeasonID\n            WHERE sr.SeasonRoundID = ?\n            LIMIT 1\n        ");
        $stmt->execute([$seasonRoundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function mlDiscordRoundHasPlaylist(PDO $pdo, int $seasonRoundId): bool
{
    if ($seasonRoundId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT 1\n            FROM ML_RoundPlaylists\n            WHERE SeasonRoundID = ?\n            LIMIT 1\n        ");
        $stmt->execute([$seasonRoundId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mlDiscordIsFinalRoundForSeason(PDO $pdo, array $round): bool
{
    $seasonId = (int)($round['SeasonID'] ?? 0);
    $roundNumber = (int)($round['RoundNumber'] ?? 0);
    if ($seasonId <= 0 || $roundNumber <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT MAX(RoundNumber) FROM ML_SeasonRounds WHERE SeasonID = ?');
        $stmt->execute([$seasonId]);
        return $roundNumber === (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


function mlDiscordLoadUserLabel(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return 'Someone';
    }

    try {
        $stmt = $pdo->prepare("
            SELECT UserName
            FROM ML_Users
            WHERE UserID = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $userName = trim((string)$stmt->fetchColumn());
        return $userName !== '' ? $userName : 'Someone';
    } catch (Throwable $e) {
        return 'Someone';
    }
}

function mlDiscordGetVoteSubmissionCount(PDO $pdo, int $seasonRoundId): int
{
    if ($seasonRoundId <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM ML_RoundVoteSubmissions\n            WHERE SeasonRoundID = ?\n        ");
        $stmt->execute([$seasonRoundId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function mlDiscordBuildSeasonScopedEventLogId(int $seasonId): int
{
    if ($seasonId <= 0) {
        return 0;
    }

    return 0 - $seasonId;
}

function mlDiscordGetSeasonBuilderSubmissionCount(PDO $pdo, int $seasonId): int
{
    if ($seasonId <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(DISTINCT UserID)\n            FROM ML_Submissions\n            WHERE SeasonID = ?\n        " );
        $stmt->execute([$seasonId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function mlDiscordMaybeSendSeasonBuilderVotingComplete(PDO $pdo, int $seasonId): array
{
    if ($seasonId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_season'];
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT SeasonID, SeasonName\n            FROM ML_Seasons\n            WHERE SeasonID = ?\n            LIMIT 1\n        " );
        $stmt->execute([$seasonId]);
        $season = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['sent' => false, 'reason' => 'season_lookup_failed'];
    }

    if (!$season) {
        return ['sent' => false, 'reason' => 'season_not_found'];
    }

    $expectedPlayers = mlDiscordGetExpectedPlayerCount($pdo);
    if ($expectedPlayers <= 0) {
        return ['sent' => false, 'reason' => 'expected_players_missing'];
    }

    $submissionCount = mlDiscordGetSeasonBuilderSubmissionCount($pdo, $seasonId);
    if ($submissionCount < $expectedPlayers) {
        return ['sent' => false, 'reason' => 'builder_votes_not_complete'];
    }

    $seasonLabel = trim((string)($season['SeasonName'] ?? ''));
    if ($seasonLabel === '') {
        $seasonLabel = 'Season ' . $seasonId;
    }

    $messageText = '💎 Rounds are ready for ' . $seasonLabel . '!';
    return mlDiscordSendMessageOnce($pdo, mlDiscordBuildSeasonScopedEventLogId($seasonId), 'builder_voting_complete', $messageText);
}

function mlDiscordMaybeSendSeasonStarted(PDO $pdo, int $seasonId): array
{
    if ($seasonId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_season'];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT SeasonID, SeasonName
            FROM ML_Seasons
            WHERE SeasonID = ?
            LIMIT 1
        " );
        $stmt->execute([$seasonId]);
        $season = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['sent' => false, 'reason' => 'season_lookup_failed'];
    }

    if (!$season) {
        return ['sent' => false, 'reason' => 'season_not_found'];
    }

    $seasonLabel = trim((string)($season['SeasonName'] ?? ''));
    if ($seasonLabel === '') {
        $seasonLabel = 'Season ' . $seasonId;
    }

    $messageText = '🚀 ' . $seasonLabel . ' is now live on Musicball !';
    return mlDiscordSendMessageOnce($pdo, mlDiscordBuildSeasonScopedEventLogId($seasonId), 'season_started', $messageText);
}

function mlDiscordSendMessageOnce(PDO $pdo, int $seasonRoundId, string $eventKey, string $messageText, array $options = []): array
{
    $result = [
        'sent' => false,
        'reason' => '',
        'status_code' => 0,
        'error' => '',
        'profile_results' => []
    ];

    if (!mlDiscordIsEnabled($pdo)) {
        $result['reason'] = 'discord_disabled';
        return $result;
    }

    $dataMode = mlDiscordGetDataMode($pdo);
    if ($dataMode !== 'live' && $dataMode !== 'qa') {
        $result['reason'] = 'blocked_data_mode';
        $result['error'] = 'Discord send blocked because the live/QA data mode could not be verified.';
        mlDiscordLog('Blocked send because data mode could not be verified.', [
            'season_round_id' => $seasonRoundId,
            'event_key' => $eventKey
        ]);
        return $result;
    }

    $deliveryProfiles = mlDiscordGetEventDeliveryProfiles($pdo, $eventKey);
    if (empty($deliveryProfiles)) {
        $result['reason'] = 'invalid_event_key';
        return $result;
    }

    $configuredProfileCount = 0;
    $successfulProfiles = [];
    $failedProfiles = [];
    $alreadySentProfiles = [];

    foreach ($deliveryProfiles as $profileKey) {
        $webhookUrl = mlDiscordGetWebhookUrl($pdo, $profileKey);
        if ($webhookUrl === '') {
            $result['profile_results'][$profileKey] = [
                'sent' => false,
                'reason' => 'not_configured',
                'status_code' => 0,
                'error' => ''
            ];
            continue;
        }

        $configuredProfileCount++;
        $deliveryEventKey = mlDiscordBuildDeliveryScopedEventKey($eventKey, $profileKey);

        if (!mlDiscordIsWebhookUrlAllowed($webhookUrl)) {
            $result['profile_results'][$profileKey] = [
                'sent' => false,
                'reason' => 'invalid_webhook_url',
                'status_code' => 0,
                'error' => 'Invalid webhook URL.'
            ];
            $failedProfiles[] = $profileKey;
            if ($result['error'] === '') {
                $result['error'] = mlDiscordGetDeliveryProfileLabel($profileKey) . ' webhook URL is invalid.';
            }
            mlDiscordLog('Blocked send because webhook URL is invalid.', [
                'season_round_id' => $seasonRoundId,
                'event_key' => $eventKey,
                'profile_key' => $profileKey
            ]);
            continue;
        }

        if (mlDiscordHasEventBeenSent($pdo, $seasonRoundId, $deliveryEventKey)) {
            $result['profile_results'][$profileKey] = [
                'sent' => false,
                'reason' => 'already_sent',
                'status_code' => 0,
                'error' => ''
            ];
            $alreadySentProfiles[] = $profileKey;
            continue;
        }

        $payloadOptions = $options;
        $payloadOptions['profile_key'] = $profileKey;
        $payload = mlDiscordBuildPayload($pdo, $messageText, $payloadOptions);
        $response = mlDiscordSendWebhookRequest($webhookUrl, $payload, 3);
        $statusCode = (int)($response['status_code'] ?? 0);
        $errorMessage = trim((string)($response['error'] ?? ''));

        $result['profile_results'][$profileKey] = [
            'sent' => !empty($response['ok']),
            'reason' => !empty($response['ok']) ? 'sent' : 'send_failed',
            'status_code' => $statusCode,
            'error' => $errorMessage
        ];

        if (empty($response['ok'])) {
            if ($result['status_code'] === 0 && $statusCode > 0) {
                $result['status_code'] = $statusCode;
            }
            if ($result['error'] === '') {
                $result['error'] = mlDiscordGetDeliveryProfileLabel($profileKey) . ($errorMessage !== '' ? ': ' . $errorMessage : ' webhook send failed.');
            }
            $failedProfiles[] = $profileKey;
            mlDiscordLog('Webhook send failed.', [
                'season_round_id' => $seasonRoundId,
                'event_key' => $eventKey,
                'profile_key' => $profileKey,
                'status_code' => $statusCode,
                'error' => $errorMessage
            ]);
            continue;
        }

        if (!mlDiscordMarkEventSent($pdo, $seasonRoundId, $deliveryEventKey, $messageText)) {
            $result['profile_results'][$profileKey] = [
                'sent' => false,
                'reason' => 'event_log_failed',
                'status_code' => $statusCode,
                'error' => 'Event log insert failed.'
            ];
            if ($result['error'] === '') {
                $result['error'] = mlDiscordGetDeliveryProfileLabel($profileKey) . ' webhook sent, but event log insert failed.';
            }
            $failedProfiles[] = $profileKey;
            mlDiscordLog('Webhook sent but event log insert failed.', [
                'season_round_id' => $seasonRoundId,
                'event_key' => $eventKey,
                'profile_key' => $profileKey
            ]);
            continue;
        }

        $successfulProfiles[] = $profileKey;
    }

    if ($configuredProfileCount === 0) {
        $result['reason'] = 'no_webhook_url';
        return $result;
    }

    if (!empty($successfulProfiles)) {
        $result['sent'] = true;
        $result['reason'] = !empty($failedProfiles) ? 'partial_sent' : 'sent';
        return $result;
    }

    if (!empty($failedProfiles)) {
        $result['reason'] = 'send_failed';
        return $result;
    }

    if (!empty($alreadySentProfiles)) {
        $result['reason'] = 'already_sent';
        return $result;
    }

    $result['reason'] = 'not_configured';
    return $result;
}


function mlDiscordBuildUserScopedEventKey(string $baseEventKey, int $userId, string $extraScope = ''): string
{
    $baseEventKey = mlDiscordNormalizeEventKey($baseEventKey);
    $scope = $baseEventKey;

    if ($userId > 0) {
        $scope .= '_u' . $userId;
    }

    $extraScope = mlDiscordNormalizeEventKey($extraScope);
    if ($extraScope !== '') {
        $scope .= '_' . $extraScope;
    }

    return $scope;
}

function mlDiscordGetTrackedEventLabels(): array
{
    return [
        'submission_open' => 'Submission Open',
        'voting_open' => 'Voting Open',
        'all_votes_in' => 'All Votes In',
        'round_closed' => 'Round Closed',
        'builder_voting_complete' => 'Season Builder Voting Complete',
        'season_started' => 'Season Started',
    ];
}

function mlDiscordGetAllEventLabels(): array
{
    return [
        'submission_open' => 'Submission Open',
        'voting_open' => 'Voting Open',
        'all_votes_in' => 'All Votes In',
        'round_closed' => 'Round Closed',
        'builder_voting_complete' => 'Season Builder Voting Complete',
        'season_started' => 'Season Started',
        'song_submitted' => 'Song Submitted',
        'song_changed' => 'Song Changed',
        'votes_submitted' => 'Votes Submitted',
    ];
}

function mlDiscordGetBaseEventKey(string $eventKey): string
{
    $normalized = mlDiscordNormalizeEventKey($eventKey);
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^(submission_open|voting_open|all_votes_in|round_closed|builder_voting_complete|season_started|song_submitted|song_changed|votes_submitted)(?:_|$)/', $normalized, $matches)) {
        return $matches[1];
    }

    return $normalized;
}

function mlDiscordGetEventLabel(string $eventKey): string
{
    $labels = mlDiscordGetAllEventLabels();
    $baseEventKey = mlDiscordGetBaseEventKey($eventKey);
    return $labels[$baseEventKey] ?? $baseEventKey;
}

function mlDiscordGetRecentEventLog(PDO $pdo, int $limit = 25): array
{
    if (!mlDiscordEventLogTableExists($pdo)) {
        return [];
    }

    $limit = max(1, min(100, $limit));

    try {
        $stmt = $pdo->query("
            SELECT del.DiscordEventID,
                   del.SeasonRoundID,
                   del.EventKey,
                   del.MessageText,
                   del.SentAt,
                   sr.SeasonID,
                   sr.RoundNumber,
                   sr.Title,
                   s.SeasonName
            FROM ML_DiscordEventLog del
            LEFT JOIN ML_SeasonRounds sr ON del.SeasonRoundID = sr.SeasonRoundID
            LEFT JOIN ML_Seasons s ON sr.SeasonID = s.SeasonID
            ORDER BY del.SentAt DESC, del.DiscordEventID DESC
            LIMIT " . (int)$limit);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        mlDiscordLog('Failed to load recent Discord event log.', ['error' => $e->getMessage()]);
        return [];
    }

    foreach ($rows as &$row) {
        $eventKey = (string)($row['EventKey'] ?? '');
        $profileKey = mlDiscordExtractDeliveryProfileFromEventKey($eventKey);
        $row['DeliveryProfileKey'] = $profileKey;
        $row['DeliveryProfileLabel'] = mlDiscordGetDeliveryProfileLabel($profileKey);
        $row['EventLabel'] = mlDiscordGetEventLabel($eventKey) . ' (' . $row['DeliveryProfileLabel'] . ')';
        $row['RoundLabel'] = mlDiscordBuildRoundLabel($row);
    }
    unset($row);

    return $rows;
}

function mlDiscordGetSeasonRoundEventMatrix(PDO $pdo, int $seasonId): array
{
    if ($seasonId <= 0) {
        return [];
    }

    $eventLabels = mlDiscordGetTrackedEventLabels();

    try {
        $roundStmt = $pdo->prepare("
            SELECT SeasonRoundID, SeasonID, RoundNumber, Title, SongsDue, VotesDue
            FROM ML_SeasonRounds
            WHERE SeasonID = ?
            ORDER BY RoundNumber ASC, SeasonRoundID ASC
        ");
        $roundStmt->execute([$seasonId]);
        $rounds = $roundStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        mlDiscordLog('Failed to load season rounds for Discord matrix.', ['season_id' => $seasonId, 'error' => $e->getMessage()]);
        return [];
    }

    if (!$rounds) {
        return [];
    }

    $logged = [];
    if (mlDiscordEventLogTableExists($pdo)) {
        try {
            $eventStmt = $pdo->prepare("
                SELECT SeasonRoundID, EventKey, SentAt
                FROM ML_DiscordEventLog
                WHERE SeasonRoundID IN (
                    SELECT SeasonRoundID FROM ML_SeasonRounds WHERE SeasonID = ?
                )
            ");
            $eventStmt->execute([$seasonId]);
            foreach ($eventStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $roundId = (int)($row['SeasonRoundID'] ?? 0);
                $eventKey = (string)($row['EventKey'] ?? '');
                $sentAt = (string)($row['SentAt'] ?? '');
                $baseEventKey = mlDiscordGetBaseEventKey($eventKey);
                if ($roundId > 0 && $baseEventKey !== '') {
                    if (!isset($logged[$roundId][$baseEventKey]) || $sentAt > $logged[$roundId][$baseEventKey]) {
                        $logged[$roundId][$baseEventKey] = $sentAt;
                    }
                }
            }
        } catch (Throwable $e) {
            mlDiscordLog('Failed to load Discord event matrix rows.', ['season_id' => $seasonId, 'error' => $e->getMessage()]);
        }
    }

    foreach ($rounds as &$round) {
        $roundId = (int)($round['SeasonRoundID'] ?? 0);
        $round['RoundLabel'] = mlDiscordBuildRoundLabel($round);
        $round['DiscordEvents'] = [];
        foreach ($eventLabels as $eventKey => $label) {
            $sentAt = isset($logged[$roundId][$eventKey]) ? (string)$logged[$roundId][$eventKey] : '';
            $round['DiscordEvents'][$eventKey] = [
                'label' => $label,
                'sent' => ($sentAt !== ''),
                'sent_at' => $sentAt,
            ];
        }
    }
    unset($round);

    return $rounds;
}

function mlDiscordMaybeSendVotingOpenForRound(PDO $pdo, array $round, array $playlistRecord = []): array
{
    $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
    if ($seasonRoundId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_round'];
    }

    $hasPlaylist = !empty($playlistRecord);
    if (!$hasPlaylist) {
        $hasPlaylist = mlDiscordRoundHasPlaylist($pdo, $seasonRoundId);
    }

    if (!$hasPlaylist) {
        return ['sent' => false, 'reason' => 'playlist_missing'];
    }

    $messageText = '🗳️ Voting is open for ' . mlDiscordBuildRoundLabel($round);
    return mlDiscordSendMessageOnce($pdo, $seasonRoundId, 'voting_open', $messageText);
}

function mlDiscordMaybeSendAllVotesInForRound(PDO $pdo, int $seasonRoundId): array
{
    if ($seasonRoundId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_round'];
    }

    $round = mlDiscordLoadRound($pdo, $seasonRoundId);
    if (!$round) {
        return ['sent' => false, 'reason' => 'round_not_found'];
    }

    if (!mlDiscordRoundHasPlaylist($pdo, $seasonRoundId)) {
        return ['sent' => false, 'reason' => 'playlist_missing'];
    }

    $votesDue = null;
    $votesDueRaw = isset($round['VotesDue']) ? (string)$round['VotesDue'] : '';
    if ($votesDueRaw !== '') {
        try {
            $votesDue = new DateTimeImmutable($votesDueRaw, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            $votesDue = null;
        }
    }

    if (!$votesDue instanceof DateTimeImmutable) {
        return ['sent' => false, 'reason' => 'votes_due_missing'];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($now > $votesDue) {
        return ['sent' => false, 'reason' => 'votes_due_passed'];
    }

    $expectedPlayers = mlDiscordGetExpectedPlayerCount($pdo);
    if ($expectedPlayers <= 0) {
        return ['sent' => false, 'reason' => 'expected_players_missing'];
    }

    $voteSubmissionCount = mlDiscordGetVoteSubmissionCount($pdo, $seasonRoundId);
    if ($voteSubmissionCount < $expectedPlayers) {
        return ['sent' => false, 'reason' => 'votes_not_complete'];
    }

    if (mlDiscordIsFinalRoundForSeason($pdo, $round)) {
        $messageText = '✅ The season is complete. Final standings are ready.';
    } else {
        $messageText = '✅ All votes are in for ' . mlDiscordBuildRoundLabel($round) . '. Results and standings are final.';
    }
    return mlDiscordSendMessageOnce($pdo, $seasonRoundId, 'all_votes_in', $messageText);
}

function mlDiscordMaybeSendSubmissionOpenForRound(PDO $pdo, array $round): array
{
    $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
    if ($seasonRoundId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_round'];
    }

    if ((string)($round['round_state'] ?? $round['status_key'] ?? '') !== 'submission') {
        return ['sent' => false, 'reason' => 'round_not_in_submission'];
    }

    $messageText = '🎵 ' . mlDiscordBuildRoundLabel($round) . ' is open — song submissions are live.';
    return mlDiscordSendMessageOnce($pdo, $seasonRoundId, 'submission_open', $messageText);
}

function mlDiscordMaybeSendRoundClosedForRound(PDO $pdo, array $round): array
{
    $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
    if ($seasonRoundId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_round'];
    }

    if ((string)($round['round_state'] ?? $round['status_key'] ?? '') !== 'closed') {
        return ['sent' => false, 'reason' => 'round_not_closed'];
    }

    $messageText = '🏁 ' . mlDiscordBuildRoundLabel($round) . ' is officially closed.';
    return mlDiscordSendMessageOnce($pdo, $seasonRoundId, 'round_closed', $messageText);
}

function mlDiscordMaybeSendSongSubmittedForRound(PDO $pdo, array $round, string $userLabel, int $userId): array
{
    $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
    if ($seasonRoundId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_round'];
    }

    $userLabel = trim($userLabel);
    if ($userLabel === '') {
        $userLabel = 'Someone';
    }

    $eventKey = mlDiscordBuildUserScopedEventKey('song_submitted', $userId);
    $messageText = '🎶 ' . $userLabel . ' submitted a song for ' . mlDiscordBuildRoundLabel($round) . '.';
    return mlDiscordSendMessageOnce($pdo, $seasonRoundId, $eventKey, $messageText);
}

function mlDiscordMaybeSendSongChangedForRound(PDO $pdo, array $round, string $userLabel, int $userId, string $trackScope = ''): array
{
    $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
    if ($seasonRoundId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_round'];
    }

    $userLabel = trim($userLabel);
    if ($userLabel === '') {
        $userLabel = 'Someone';
    }

    if ($trackScope === '') {
        $trackScope = 'changed';
    }

    $eventKey = mlDiscordBuildUserScopedEventKey('song_changed', $userId, $trackScope);
    $messageText = '🙇‍♂️ Somebody is on skates in ' . mlDiscordBuildRoundLabel($round) . '!';
    return mlDiscordSendMessageOnce($pdo, $seasonRoundId, $eventKey, $messageText);
}

function mlDiscordMaybeSendVotesSubmittedForRound(PDO $pdo, int $seasonRoundId, int $userId): array
{
    if ($seasonRoundId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_round'];
    }

    if ($userId <= 0) {
        return ['sent' => false, 'reason' => 'invalid_user'];
    }

    $round = mlDiscordLoadRound($pdo, $seasonRoundId);
    if (!$round) {
        return ['sent' => false, 'reason' => 'round_not_found'];
    }

    $userLabel = mlDiscordLoadUserLabel($pdo, $userId);
    $eventKey = mlDiscordBuildUserScopedEventKey('votes_submitted', $userId);
    $messageText = '🗳️ ' . $userLabel . ' submitted votes for ' . mlDiscordBuildRoundLabel($round) . '.';
    return mlDiscordSendMessageOnce($pdo, $seasonRoundId, $eventKey, $messageText);
}

function mlDiscordProcessSeasonPresentation(PDO $pdo, array $presentedRounds): void
{
    if (!mlDiscordIsEnabled($pdo) || empty($presentedRounds)) {
        return;
    }

    $currentSubmissionRound = null;
    $latestClosedRound = null;

    foreach ($presentedRounds as $round) {
        $roundState = (string)($round['round_state'] ?? $round['status_key'] ?? '');

        if ($currentSubmissionRound === null && $roundState === 'submission') {
            $currentSubmissionRound = $round;
        }

        if ($roundState === 'closed') {
            $latestClosedRound = $round;
        }
    }

    if ($currentSubmissionRound !== null) {
        try {
            mlDiscordMaybeSendSubmissionOpenForRound($pdo, $currentSubmissionRound);
        } catch (Throwable $e) {
            // Never interrupt gameplay for Discord failures.
        }
    }

    if ($latestClosedRound !== null) {
        try {
            mlDiscordMaybeSendRoundClosedForRound($pdo, $latestClosedRound);
        } catch (Throwable $e) {
            // Never interrupt gameplay for Discord failures.
        }
    }
}
