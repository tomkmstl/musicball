<?php
// preview.php
// Admin-only, read-only preview of the player preseason voting experience.

require_once __DIR__ . '/../session_boot.php';
require_once __DIR__ . '/../config.php';

$currentUserId = isset($_SESSION['UserID'])
    ? (int)$_SESSION['UserID']
    : (isset($_SESSION['ml_user_id']) ? (int)$_SESSION['ml_user_id'] : 0);

if ($currentUserId <= 0 || !mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$seasonId = isset($_GET['season_id'])
    ? (int)$_GET['season_id']
    : (isset($_POST['season_id']) ? (int)$_POST['season_id'] : 0);

if ($seasonId <= 0) {
    $_SESSION['ml_admin_error'] = 'Choose a season before previewing voting.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$seasonStmt = $pdo->prepare(
    'SELECT SeasonID, SeasonName
     FROM ML_Seasons
     WHERE SeasonID = ?
     LIMIT 1'
);
$seasonStmt->execute([$seasonId]);
$previewSeason = $seasonStmt->fetch(PDO::FETCH_ASSOC);

if (!$previewSeason) {
    $_SESSION['ml_admin_error'] = 'That season could not be found.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$seasonId = (int)$previewSeason['SeasonID'];
$seasonName = (string)$previewSeason['SeasonName'];
$previewMode = true;
$votingOpen = true;
$previewReturnUrl = mlUrl('season-builder/season_options.php?season_id=' . $seasonId);

include __DIR__ . '/questions.php';
exit;
