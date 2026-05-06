<?php
// gameplay/auth.php
// Authentication, session, and PDO access helpers.

function mlIsLocalDevHost(): bool {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);

    return $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.test');
}

function mlIsLocalDevRemoteAddress(): bool {
    $remoteAddress = strtolower(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')));

    return $remoteAddress === '127.0.0.1'
        || $remoteAddress === '::1'
        || $remoteAddress === 'localhost';
}

function mlLocalAuthBypassAllowed(): bool {
    if (!mlIsLocalDevHost() || !mlIsLocalDevRemoteAddress()) {
        return false;
    }

    if (defined('ML_ALLOW_LOCAL_AUTH_BYPASS')) {
        return ML_ALLOW_LOCAL_AUTH_BYPASS === true || ML_ALLOW_LOCAL_AUTH_BYPASS === 1 || ML_ALLOW_LOCAL_AUTH_BYPASS === '1';
    }

    return true;
}

function mlGetLocalDevUserId(): int {
    if (defined('ML_LOCAL_DEV_USER_ID')) {
        return max(0, (int)ML_LOCAL_DEV_USER_ID);
    }

    $envUserId = getenv('ML_LOCAL_DEV_USER_ID');
    if ($envUserId !== false && trim((string)$envUserId) !== '') {
        return max(0, (int)$envUserId);
    }

    return 5;
}

function mlFetchAuthenticatedUserById(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }

    $select = 'UserID, UserName, Email';
    if (mlUsersHasIsAdminColumn($pdo)) {
        $select .= ', IsAdmin';
    }
    if (mlUsersHasProfileImageColumn($pdo)) {
        $select .= ', ProfileImageFilename';
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM ML_Users WHERE UserID = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $user['profile_image_path'] = mlGetUserProfilePath((int)$user['UserID'], $user['ProfileImageFilename'] ?? null);

    return $user;
}

function mlRequireAuthenticatedUser(PDO $pdo): array {
    $userId = 0;

    if (isset($_SESSION['UserID'])) {
        $userId = (int)$_SESSION['UserID'];
    } elseif (isset($_SESSION['ml_user_id'])) {
        $userId = (int)$_SESSION['ml_user_id'];
    }

    if ($userId <= 0 && mlLocalAuthBypassAllowed()) {
        $userId = mlGetLocalDevUserId();
        $_SESSION['UserID'] = $userId;
        $_SESSION['ml_user_id'] = $userId;
    }

    if ($userId <= 0) {
        header('Location: ./?resetuser=true');
        exit;
    }

    $user = mlFetchAuthenticatedUserById($pdo, $userId);

    if (!$user && mlLocalAuthBypassAllowed()) {
        unset($_SESSION['UserID'], $_SESSION['UserName'], $_SESSION['ml_user_id']);
        $userId = mlGetLocalDevUserId();
        $_SESSION['UserID'] = $userId;
        $_SESSION['ml_user_id'] = $userId;
        $user = mlFetchAuthenticatedUserById($pdo, $userId);
    }

    if (!$user) {
        unset($_SESSION['UserID'], $_SESSION['UserName'], $_SESSION['ml_user_id']);
        header('Location: ./?resetuser=true');
        exit;
    }

    $_SESSION['UserID'] = (int)$user['UserID'];
    $_SESSION['ml_user_id'] = (int)$user['UserID'];
    if (isset($user['UserName'])) {
        $_SESSION['UserName'] = (string)$user['UserName'];
    }

    return $user;
}
function mlCloseSessionReadOnly(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
function mlEnsureSessionWritable(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}
function mlGameplayPdo(): ?PDO {
    global $pdo;
    return ($pdo instanceof PDO) ? $pdo : null;
}
