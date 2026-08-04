<?php
require_once __DIR__ . '/../../gameplay/bootstrap.php';
require_once __DIR__ . '/push.php';

$currentUser = mlRequireAuthenticatedUser($pdo);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function mlPushApiRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mlPushApiRespond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$csrfToken = (string)($_SERVER['HTTP_X_ML_PUSH_CSRF'] ?? '');
$sessionToken = (string)($_SESSION['ml_push_csrf'] ?? '');
if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    mlPushApiRespond(403, ['ok' => false, 'error' => 'Refresh Settings and try again.']);
}

$requestBody = file_get_contents('php://input');
$request = json_decode((string)$requestBody, true);
if (!is_array($request)) {
    mlPushApiRespond(400, ['ok' => false, 'error' => 'Invalid request.']);
}

$action = strtolower(trim((string)($request['action'] ?? '')));
$userId = (int)$currentUser['UserID'];
$requestScope = strtolower(trim((string)($request['scope'] ?? '')));
$isAdminTestRequest = $requestScope === 'admin_test' && in_array($action, ['status', 'test'], true);

if ($requestScope === 'admin_test' && !$isAdminTestRequest) {
    mlPushApiRespond(400, ['ok' => false, 'error' => 'Invalid admin test request.']);
}

if ($isAdminTestRequest && !mlIsAdminUserId($pdo, $userId)) {
    mlPushApiRespond(403, ['ok' => false, 'error' => 'Administrator access is required.']);
}

// QA Tools tests the current admin device, not a rewound gameplay snapshot.
// All ordinary status, subscribe, unsubscribe, and delivery requests continue
// using the active data mode.
$pushPdo = $isAdminTestRequest && function_exists('mlGetLivePdo') ? mlGetLivePdo() : $pdo;

if (!mlPushTableExists($pushPdo, 'ML_PushSubscriptions')) {
    mlPushApiRespond(503, ['ok' => false, 'error' => 'Deadline reminder storage is not available yet.']);
}

try {
    if ($action === 'status') {
        $endpoint = trim((string)($request['endpoint'] ?? ''));
        $activeSubscription = mlPushLoadActiveSubscription($pushPdo, $userId, $endpoint);
        mlPushApiRespond(200, ['ok' => true, 'subscribed' => $activeSubscription !== null]);
    }

    if ($action === 'subscribe') {
        if (!mlPushServerReady($pdo)) {
            mlPushApiRespond(503, ['ok' => false, 'error' => 'Deadline reminders are not available yet.']);
        }

        $subscription = isset($request['subscription']) && is_array($request['subscription'])
            ? $request['subscription']
            : [];
        mlPushSaveSubscription($pdo, $userId, $subscription, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        mlPushApiRespond(200, ['ok' => true, 'subscribed' => true]);
    }

    if ($action === 'unsubscribe') {
        $endpoint = trim((string)($request['endpoint'] ?? ''));
        mlPushDisableSubscription($pdo, $userId, $endpoint);
        mlPushApiRespond(200, ['ok' => true, 'subscribed' => false]);
    }

    if ($action === 'test') {
        if (!mlIsAdminUserId($pdo, $userId)) {
            mlPushApiRespond(403, ['ok' => false, 'error' => 'Administrator access is required.']);
        }

        if (!mlPushServerReady($pushPdo)) {
            mlPushApiRespond(503, ['ok' => false, 'error' => 'Deadline reminders are not available yet.']);
        }

        $endpoint = trim((string)($request['endpoint'] ?? ''));
        $subscriptionRow = mlPushLoadActiveSubscription($pushPdo, $userId, $endpoint);
        if ($subscriptionRow === null) {
            mlPushApiRespond(404, ['ok' => false, 'error' => 'Turn on reminders for this device first.']);
        }

        $notificationType = strtolower(trim((string)($request['notification_type'] ?? 'connection_test')));
        if (!array_key_exists($notificationType, mlPushTestNotificationOptions())) {
            mlPushApiRespond(400, ['ok' => false, 'error' => 'Choose a supported test notification.']);
        }

        $notificationCopy = mlPushBuildNotificationCopy($notificationType, 12, 'Notification Test');
        mlCloseSessionReadOnly();
        $client = mlPushCreateWebPushClient();
        $result = mlPushSendNotification($client, $subscriptionRow, [
            'title' => $notificationCopy['title'],
            'body' => $notificationCopy['body'],
            'url' => mlUrl('season.php'),
            'tag' => 'musicball-reminder-test-' . $notificationType,
        ]);

        if (!empty($result['expired'])) {
            mlPushDisableSubscriptionById($pushPdo, (int)$subscriptionRow['PushSubscriptionID']);
        }

        if (empty($result['success'])) {
            mlPushApiRespond(502, ['ok' => false, 'error' => 'The test notification could not be delivered.']);
        }

        mlPushApiRespond(200, ['ok' => true]);
    }
} catch (InvalidArgumentException $e) {
    mlPushApiRespond(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Push subscription request failed: ' . $e->getMessage());
    mlPushApiRespond(500, ['ok' => false, 'error' => 'Deadline reminders could not be updated. Please try again.']);
}

mlPushApiRespond(400, ['ok' => false, 'error' => 'Unknown request.']);
