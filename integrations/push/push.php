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
