<?php
// sb_vote.php
// Routes the logged-in user into the current voting flow.

require_once __DIR__ . '/../session_boot.php';
require_once __DIR__ . '/../config.php';

$currentUserId = 0;

if (isset($_SESSION['UserID'])) {
    $currentUserId = (int)$_SESSION['UserID'];
} elseif (isset($_SESSION['ml_user_id'])) {
    $currentUserId = (int)$_SESSION['ml_user_id'];
}

if ($currentUserId <= 0) {
    header('Location: ' . mlUrl('?resetuser=true'));
    exit;
}

$votingSeason = mlGetVotingSeason($pdo);
if (!$votingSeason) {
    $_SESSION['ml_notice'] = 'Voting for the next season is currently closed.';
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$seasonId = (int)$votingSeason['SeasonID'];
$seasonName = (string)$votingSeason['SeasonName'];
$votingOpen = true;

$checkUser = $pdo->prepare("SELECT UserID FROM ML_Users WHERE UserID = ?");
$checkUser->execute([$currentUserId]);
$existingUser = $checkUser->fetch(PDO::FETCH_ASSOC);

if (!$existingUser) {
    unset(
        $_SESSION['UserID'],
        $_SESSION['UserName'],
        $_SESSION['ml_user_id']
    );

    header('Location: ' . mlUrl('?resetuser=true'));
    exit;
}

$hasStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM ML_Submissions
    WHERE SeasonID = ?
      AND UserID = ?
");
$hasStmt->execute([$seasonId, $currentUserId]);
$hasSubmitted = ((int)$hasStmt->fetchColumn() > 0);

if ($hasSubmitted) {
    include __DIR__ . '/choice.php';
    exit;
}

include __DIR__ . '/questions.php';
exit;
