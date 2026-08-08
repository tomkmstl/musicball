<?php
// Shared Web Push subscription, delivery, and safety helpers.

require_once __DIR__ . '/../../config/push_config.php';

$mlPushAutoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($mlPushAutoload)) {
    require_once $mlPushAutoload;
}

function mlPushGetDataMode(PDO $pdo): string
{
    return function_exists('mlGetPdoDataMode') ? mlGetPdoDataMode($pdo) : 'unknown';
}

function mlPushGetPhysicalTableName(PDO $pdo, string $logicalTable): string
{
    $allowedTables = ['ML_PushSubscriptions', 'ML_PushDeliveryLog'];
    if (!in_array($logicalTable, $allowedTables, true)) {
        return '';
    }

    return mlPushGetDataMode($pdo) === 'qa' ? 'QA_' . $logicalTable : $logicalTable;
}

function mlPushTableExists(PDO $pdo, string $logicalTable): bool
{
    static $cache = [];

    $physicalTable = mlPushGetPhysicalTableName($pdo, $logicalTable);
    if ($physicalTable === '') {
        return false;
    }

    if (array_key_exists($physicalTable, $cache)) {
        return $cache[$physicalTable];
    }

    try {
        $schemaPdo = function_exists('mlGetLivePdo') ? mlGetLivePdo() : $pdo;
        $stmt = $schemaPdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $stmt->execute([$physicalTable]);
        $cache[$physicalTable] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$physicalTable] = false;
    }

    return $cache[$physicalTable];
}

function mlPushStorageReady(PDO $pdo): bool
{
    return mlPushTableExists($pdo, 'ML_PushSubscriptions')
        && mlPushTableExists($pdo, 'ML_PushDeliveryLog');
}

function mlPushIsEnabledForMode(PDO $pdo): bool
{
    $mode = mlPushGetDataMode($pdo);

    if ($mode === 'qa') {
        return mlPushQaEnabled();
    }

    if ($mode === 'live') {
        return mlPushLiveEnabled();
    }

    return false;
}

function mlPushServerReady(PDO $pdo): bool
{
    return mlPushIsEnabledForMode($pdo)
        && mlPushStorageReady($pdo)
        && mlPushVapidPublicKey() !== ''
        && mlPushVapidPrivateKey() !== ''
        && class_exists('Minishlink\\WebPush\\WebPush')
        && class_exists('Minishlink\\WebPush\\Subscription');
}

function mlPushTestNotificationOptions(): array
{
    return [
        'connection_test' => 'Connection test',
        'playlist_mode_fallback' => 'Round timing changed - playlist fallback',
        'voting_mode_fallback' => 'Round timing changed - voting fallback',
        'song_deadline_open' => 'Song deadline - Wait for Everyone (open)',
        'song_deadline_closed' => 'Song deadline - submissions closed',
        'vote_deadline_open' => 'Voting deadline - Wait for Everyone (open)',
        'vote_deadline_closed' => 'Voting deadline - voting closed',
        'song_24h' => 'Song reminder — 24 hours',
        'song_2h' => 'Song reminder — 2 hours',
        'vote_24h' => 'Voting reminder — 24 hours',
        'vote_2h' => 'Voting reminder — 2 hours',
    ];
}

function mlPushBuildNotificationCopy(
    string $notificationType,
    int $roundNumber = 1,
    string $roundTitle = ''
): array {
    $roundNumber = max(1, $roundNumber);
    $roundTitle = trim($roundTitle);
    $roundLabel = 'Round ' . $roundNumber . ($roundTitle !== '' ? ': ' . $roundTitle : '');

    switch ($notificationType) {
        case 'connection_test':
            return [
                'title' => 'REMINDERS ARE ON',
                'body' => 'Test Notification Successful.',
            ];

        case 'song_24h':
            return [
                'title' => 'Choose Your Song',
                'body' => $roundLabel . ' is due in about 24 hours.',
            ];

        case 'song_2h':
            return [
                'title' => 'SONGS ARE DUE',
                'body' => $roundLabel . ' songs due in 2 hours!',
            ];

        case 'song_deadline_open':
            return [
                'title' => 'You missed the deadline!!',
                'body' => 'Submit now for ' . $roundLabel . ".... there's still time!",
            ];

        case 'song_deadline_closed':
            return [
                'title' => 'SONG DEADLINE PASSED',
                'body' => $roundLabel . ' deadline past. You cannot submit a song for this round.',
            ];

        case 'vote_24h':
            return [
                'title' => 'Finish Your Votes',
                'body' => $roundLabel . ' closes in about 24 hours.',
            ];

        case 'playlist_mode_fallback':
            return [
                'title' => 'ROUND TIMING CHANGED',
                'body' => $roundLabel . ' reached the playlist fallback. Musicball changed future phases to Build at Songs Due and will generate the available submissions.',
            ];

        case 'voting_mode_fallback':
            return [
                'title' => 'ROUND TIMING CHANGED',
                'body' => $roundLabel . ' reached the voting fallback before the next Songs Due deadline. Musicball finalized the available votes and changed future phases to Build at Songs Due.',
            ];

        case 'vote_2h':
            return [
                'title' => 'VOTES ARE DUE',
                'body' => $roundLabel . ' votes are due in 2 hours!',
            ];

        case 'vote_deadline_open':
            return [
                'title' => 'You missed the deadline!!',
                'body' => 'Vote now for ' . $roundLabel . ".... there's still time!",
            ];

        case 'vote_deadline_closed':
            return [
                'title' => 'VOTING DEADLINE PASSED',
                'body' => $roundLabel . ' deadline past. Your votes will not be counted for the round.',
            ];
    }

    throw new InvalidArgumentException('Unsupported notification type.');
}

function mlPushResolveDeadlineNotificationType(string $phase, string $roundTimingMode, bool $phaseClosed): string
{
    $phase = strtolower(trim($phase));
    if (!in_array($phase, ['song', 'vote'], true)) {
        throw new InvalidArgumentException('Unsupported deadline notification phase.');
    }

    $canStillAct = strtolower(trim($roundTimingMode)) === 'wait' && !$phaseClosed;
    return $phase . '_deadline_' . ($canStillAct ? 'open' : 'closed');
}

function mlPushBuildDeadlineDeliveryKey(string $phase, bool $phaseClosed, string $dueAt = ''): string
{
    $phase = strtolower(trim($phase));
    if (!in_array($phase, ['song', 'vote'], true)) {
        throw new InvalidArgumentException('Unsupported deadline notification phase.');
    }

    if ($phaseClosed) {
        return $phase . '_phase_closed_v1';
    }

    $deadlineScope = substr(hash('sha256', trim($dueAt)), 0, 12);
    return $phase . '_deadline_open_' . $deadlineScope;
}

function mlPushResolveReminderWindow(DateTimeImmutable $dueAt, DateTimeImmutable $now): ?array
{
    $remainingSeconds = $dueAt->getTimestamp() - $now->getTimestamp();
    if ($remainingSeconds <= 0) {
        return $remainingSeconds >= -1800 ? ['key' => 'deadline'] : null;
    }

    if ($remainingSeconds > 86400) {
        return null;
    }

    if ($remainingSeconds <= 7200) {
        return ['key' => '2h'];
    }

    return ['key' => '24h'];
}

function mlPushEndpointHostAllowed(string $endpoint): bool
{
    if (strlen($endpoint) > 2048 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($endpoint);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));

    if ($scheme !== 'https' || $host === '') {
        return false;
    }

    $exactHosts = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'updates.push.services.mozilla.com',
    ];

    if (in_array($host, $exactHosts, true)) {
        return true;
    }

    foreach (['.push.apple.com', '.push.services.mozilla.com', '.notify.windows.com'] as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

function mlPushNormalizeSubscription(array $subscription): array
{
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = isset($subscription['keys']) && is_array($subscription['keys']) ? $subscription['keys'] : [];
    $publicKey = trim((string)($keys['p256dh'] ?? ''));
    $authToken = trim((string)($keys['auth'] ?? ''));
    $contentEncoding = strtolower(trim((string)($subscription['contentEncoding'] ?? 'aes128gcm')));

    if (!mlPushEndpointHostAllowed($endpoint)) {
        throw new InvalidArgumentException('This browser did not provide a supported push subscription.');
    }

    if ($publicKey === '' || strlen($publicKey) > 255 || $authToken === '' || strlen($authToken) > 255) {
        throw new InvalidArgumentException('This browser provided an incomplete push subscription.');
    }

    if (!in_array($contentEncoding, ['aes128gcm', 'aesgcm'], true)) {
        $contentEncoding = 'aes128gcm';
    }

    return [
        'endpoint' => $endpoint,
        'endpoint_hash' => hash('sha256', $endpoint),
        'public_key' => $publicKey,
        'auth_token' => $authToken,
        'content_encoding' => $contentEncoding,
    ];
}

function mlPushSaveSubscription(PDO $pdo, int $userId, array $subscription, string $userAgent = ''): void
{
    if ($userId <= 0 || !mlPushTableExists($pdo, 'ML_PushSubscriptions')) {
        throw new RuntimeException('Push subscription storage is not available.');
    }

    $normalized = mlPushNormalizeSubscription($subscription);
    $safeUserAgent = mb_substr(trim($userAgent), 0, 500);

    $stmt = $pdo->prepare(
        "INSERT INTO ML_PushSubscriptions
            (UserID, Endpoint, EndpointHash, PublicKey, AuthToken, ContentEncoding, UserAgent, CreatedAt, LastSeenAt, DisabledAt)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)
         ON DUPLICATE KEY UPDATE
            UserID = VALUES(UserID),
            Endpoint = VALUES(Endpoint),
            PublicKey = VALUES(PublicKey),
            AuthToken = VALUES(AuthToken),
            ContentEncoding = VALUES(ContentEncoding),
            UserAgent = VALUES(UserAgent),
            LastSeenAt = UTC_TIMESTAMP(),
            DisabledAt = NULL"
    );
    $stmt->execute([
        $userId,
        $normalized['endpoint'],
        $normalized['endpoint_hash'],
        $normalized['public_key'],
        $normalized['auth_token'],
        $normalized['content_encoding'],
        $safeUserAgent !== '' ? $safeUserAgent : null,
    ]);
}

function mlPushDisableSubscription(PDO $pdo, int $userId, string $endpoint): void
{
    if ($userId <= 0 || $endpoint === '' || !mlPushTableExists($pdo, 'ML_PushSubscriptions')) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE ML_PushSubscriptions SET DisabledAt = UTC_TIMESTAMP() WHERE UserID = ? AND EndpointHash = ?'
    );
    $stmt->execute([$userId, hash('sha256', $endpoint)]);
}

function mlPushDisableSubscriptionById(PDO $pdo, int $subscriptionId): void
{
    if ($subscriptionId <= 0 || !mlPushTableExists($pdo, 'ML_PushSubscriptions')) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE ML_PushSubscriptions SET DisabledAt = UTC_TIMESTAMP() WHERE PushSubscriptionID = ?'
    );
    $stmt->execute([$subscriptionId]);
}

function mlPushLoadActiveSubscription(PDO $pdo, int $userId, string $endpoint): ?array
{
    if ($userId <= 0 || $endpoint === '' || !mlPushTableExists($pdo, 'ML_PushSubscriptions')) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT PushSubscriptionID, UserID, Endpoint, PublicKey, AuthToken, ContentEncoding
         FROM ML_PushSubscriptions
         WHERE UserID = ? AND EndpointHash = ? AND DisabledAt IS NULL
         LIMIT 1"
    );
    $stmt->execute([$userId, hash('sha256', $endpoint)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function mlPushLoadActiveAdminSubscriptions(PDO $pdo): array
{
    if (!mlUsersHasIsAdminColumn($pdo) || !mlPushTableExists($pdo, 'ML_PushSubscriptions')) {
        return [];
    }

    $stmt = $pdo->query(
        "SELECT ps.PushSubscriptionID,
                ps.UserID,
                ps.Endpoint,
                ps.PublicKey,
                ps.AuthToken,
                ps.ContentEncoding
         FROM ML_PushSubscriptions ps
         INNER JOIN ML_Users u ON u.UserID = ps.UserID
         WHERE ps.DisabledAt IS NULL
           AND u.IsAdmin = 1
         ORDER BY ps.UserID ASC, ps.PushSubscriptionID ASC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mlPushCreateWebPushClient(): Minishlink\WebPush\WebPush
{
    if (!class_exists('Minishlink\\WebPush\\WebPush')) {
        throw new RuntimeException('The Web Push dependency is not installed.');
    }

    $auth = [
        'VAPID' => [
            'subject' => mlPushVapidSubject(),
            'publicKey' => mlPushVapidPublicKey(),
            'privateKey' => mlPushVapidPrivateKey(),
        ],
    ];
    $options = [
        'TTL' => 10800,
        'urgency' => 'high',
        'batchSize' => 100,
        'requestConcurrency' => 20,
    ];

    $client = new Minishlink\WebPush\WebPush(
        $auth,
        $options,
        10,
        ['allow_redirects' => false]
    );
    $client->setReuseVAPIDHeaders(true);

    return $client;
}

function mlPushSendNotification(
    Minishlink\WebPush\WebPush $client,
    array $subscriptionRow,
    array $payload
): array {
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        return ['success' => false, 'expired' => false, 'error' => 'The notification payload could not be encoded.'];
    }

    try {
        $subscription = Minishlink\WebPush\Subscription::create([
            'endpoint' => (string)$subscriptionRow['Endpoint'],
            'publicKey' => (string)$subscriptionRow['PublicKey'],
            'authToken' => (string)$subscriptionRow['AuthToken'],
            'contentEncoding' => (string)($subscriptionRow['ContentEncoding'] ?? 'aes128gcm'),
        ]);
        $report = $client->sendOneNotification($subscription, $payloadJson);

        return [
            'success' => $report->isSuccess(),
            'expired' => $report->isSubscriptionExpired(),
            'error' => $report->isSuccess() ? '' : mlPushSanitizeDeliveryError($report->getReason()),
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'expired' => false,
            'error' => mlPushSanitizeDeliveryError($e->getMessage()),
        ];
    }
}

function mlPushSanitizeDeliveryError(string $message): string
{
    $message = preg_replace('/https?:\/\/\S+/i', '[push endpoint]', trim($message));
    $message = preg_replace('/\s+/', ' ', (string)$message);

    return mb_substr($message !== '' ? $message : 'Push delivery failed.', 0, 500);
}

function mlPushReminderWasSent(PDO $pdo, int $subscriptionId, int $seasonRoundId, string $reminderKey): bool
{
    if (!mlPushTableExists($pdo, 'ML_PushDeliveryLog')) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM ML_PushDeliveryLog
         WHERE PushSubscriptionID = ? AND SeasonRoundID = ? AND ReminderKey = ? AND SentAt IS NOT NULL
         LIMIT 1"
    );
    $stmt->execute([$subscriptionId, $seasonRoundId, $reminderKey]);

    return (bool)$stmt->fetchColumn();
}

function mlPushRecordDeliveryAttempt(
    PDO $pdo,
    int $subscriptionId,
    int $userId,
    int $seasonRoundId,
    string $reminderKey,
    array $result
): void {
    if (!mlPushTableExists($pdo, 'ML_PushDeliveryLog')) {
        return;
    }

    $success = !empty($result['success']);
    $error = $success ? null : mlPushSanitizeDeliveryError((string)($result['error'] ?? ''));

    $stmt = $pdo->prepare(
        "INSERT INTO ML_PushDeliveryLog
            (PushSubscriptionID, UserID, SeasonRoundID, ReminderKey, Status, FailureCount, LastError, LastAttemptAt, SentAt)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)
         ON DUPLICATE KEY UPDATE
            UserID = VALUES(UserID),
            Status = VALUES(Status),
            FailureCount = IF(VALUES(Status) = 'failed', FailureCount + 1, FailureCount),
            LastError = VALUES(LastError),
            LastAttemptAt = UTC_TIMESTAMP(),
            SentAt = IF(VALUES(Status) = 'sent', VALUES(SentAt), SentAt)"
    );
    $stmt->execute([
        $subscriptionId,
        $userId,
        $seasonRoundId,
        mb_substr($reminderKey, 0, 100),
        $success ? 'sent' : 'failed',
        $success ? 0 : 1,
        $error,
        $success ? gmdate('Y-m-d H:i:s') : null,
    ]);
}

function mlPushSendNotificationOnce(
    PDO $pdo,
    Minishlink\WebPush\WebPush $client,
    array $subscription,
    int $seasonRoundId,
    string $reminderKey,
    array $payload
): array {
    $subscriptionId = (int)($subscription['PushSubscriptionID'] ?? 0);
    if ($subscriptionId <= 0 || $seasonRoundId <= 0 || trim($reminderKey) === '') {
        return ['attempted' => false, 'success' => false, 'expired' => false, 'error' => 'Invalid push delivery identity.'];
    }

    if (mlPushReminderWasSent($pdo, $subscriptionId, $seasonRoundId, $reminderKey)) {
        return ['attempted' => false, 'success' => true, 'expired' => false, 'error' => ''];
    }

    $lockName = 'musicball_push_' . substr(hash(
        'sha256',
        mlPushGetDataMode($pdo) . ':' . $subscriptionId . ':' . $seasonRoundId . ':' . $reminderKey
    ), 0, 40);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lockStmt->execute([$lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        return ['attempted' => false, 'success' => false, 'expired' => false, 'error' => 'Push delivery is already in progress.'];
    }

    try {
        if (mlPushReminderWasSent($pdo, $subscriptionId, $seasonRoundId, $reminderKey)) {
            return ['attempted' => false, 'success' => true, 'expired' => false, 'error' => ''];
        }

        $delivery = mlPushSendNotification($client, $subscription, $payload);
        mlPushRecordDeliveryAttempt(
            $pdo,
            $subscriptionId,
            (int)($subscription['UserID'] ?? 0),
            $seasonRoundId,
            $reminderKey,
            $delivery
        );

        if (!empty($delivery['expired'])) {
            mlPushDisableSubscriptionById($pdo, $subscriptionId);
        }

        return ['attempted' => true] + $delivery;
    } finally {
        try {
            $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStmt->execute([$lockName]);
        } catch (Throwable $e) {
            // The database releases named locks automatically when the connection closes.
        }
    }
}

function mlPushLoadIncompleteRoundSubscriptions(PDO $pdo, int $seasonRoundId, string $phase): array
{
    $phase = strtolower(trim($phase));
    if ($phase === 'song') {
        $completionJoin = 'LEFT JOIN ML_RoundSongs completed ON completed.SeasonRoundID = ? AND completed.UserID = ps.UserID';
        $completionWhere = 'completed.RoundSongID IS NULL';
    } elseif ($phase === 'vote') {
        $completionJoin = 'LEFT JOIN ML_RoundVoteSubmissions completed ON completed.SeasonRoundID = ? AND completed.UserID = ps.UserID';
        $completionWhere = 'completed.RoundVoteSubmissionID IS NULL';
    } else {
        throw new InvalidArgumentException('Unsupported incomplete-action phase.');
    }

    $stmt = $pdo->prepare(
        "SELECT ps.PushSubscriptionID,
                ps.UserID,
                ps.Endpoint,
                ps.PublicKey,
                ps.AuthToken,
                ps.ContentEncoding
         FROM ML_PushSubscriptions ps
         INNER JOIN ML_Users u ON u.UserID = ps.UserID
         {$completionJoin}
         WHERE ps.DisabledAt IS NULL
           AND {$completionWhere}
         ORDER BY ps.UserID ASC, ps.PushSubscriptionID ASC"
    );
    $stmt->execute([$seasonRoundId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mlPushSendIncompletePhaseClosed(PDO $pdo, array $round, string $phase): array
{
    $result = [
        'available' => false,
        'eligible' => 0,
        'sent' => 0,
        'failed' => 0,
        'expired' => 0,
    ];

    $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
    if ($seasonRoundId <= 0 || !mlPushServerReady($pdo)) {
        return $result;
    }

    $phase = strtolower(trim($phase));
    $notificationType = mlPushResolveDeadlineNotificationType($phase, 'due', true);
    $subscriptions = mlPushLoadIncompleteRoundSubscriptions($pdo, $seasonRoundId, $phase);
    $result['available'] = true;
    $result['eligible'] = count($subscriptions);

    if (empty($subscriptions)) {
        return $result;
    }

    $copy = mlPushBuildNotificationCopy(
        $notificationType,
        (int)($round['RoundNumber'] ?? 0),
        trim((string)($round['Title'] ?? ''))
    );
    $reminderKey = mlPushBuildDeadlineDeliveryKey($phase, true);
    $client = mlPushCreateWebPushClient();

    foreach ($subscriptions as $subscription) {
        $delivery = mlPushSendNotificationOnce($pdo, $client, $subscription, $seasonRoundId, $reminderKey, [
            'title' => $copy['title'],
            'body' => $copy['body'],
            'url' => mlUrl('season.php?season_id=' . (int)($round['SeasonID'] ?? 0)),
            'tag' => 'musicball-' . $reminderKey . '-' . $seasonRoundId,
        ]);

        if (empty($delivery['attempted'])) {
            continue;
        }

        if (!empty($delivery['success'])) {
            $result['sent']++;
        } else {
            $result['failed']++;
        }

        if (!empty($delivery['expired'])) {
            $result['expired']++;
        }
    }

    return $result;
}
