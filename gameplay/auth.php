<?php
// gameplay/auth.php
// Authentication, session, and PDO access helpers.

function mlRequireAuthenticatedUser(PDO $pdo): array {
    $userId = 0;

    if (isset($_SESSION['UserID'])) {
        $userId = (int)$_SESSION['UserID'];
    } elseif (isset($_SESSION['ml_user_id'])) {
        $userId = (int)$_SESSION['ml_user_id'];
    }

    if ($userId <= 0) {
        header('Location: ./?resetuser=true');
        exit;
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
        unset($_SESSION['UserID'], $_SESSION['UserName'], $_SESSION['ml_user_id']);
        header('Location: ./?resetuser=true');
        exit;
    }

    $user['profile_image_path'] = mlGetUserProfilePath((int)$user['UserID'], $user['ProfileImageFilename'] ?? null);

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
