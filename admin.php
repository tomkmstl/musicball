<?php
require_once 'session_boot.php';
require_once 'config.php';
require_once __DIR__ . '/season-builder/sb_season_builder.php';
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';
require_once __DIR__ . '/integrations/discord/discord.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: index.php');
    exit;
}

$adminMessage = isset($_SESSION['ml_admin_message']) ? (string)$_SESSION['ml_admin_message'] : '';
unset($_SESSION['ml_admin_message']);
$adminError = isset($_SESSION['ml_admin_error']) ? (string)$_SESSION['ml_admin_error'] : '';
unset($_SESSION['ml_admin_error']);

if (isset($_SESSION['ml_spotify_message']) && trim((string)$_SESSION['ml_spotify_message']) !== '') {
    $adminMessage = trim((string)$_SESSION['ml_spotify_message']);
    unset($_SESSION['ml_spotify_message']);
}

if (isset($_SESSION['ml_spotify_error']) && trim((string)$_SESSION['ml_spotify_error']) !== '') {
    $adminError = trim((string)$_SESSION['ml_spotify_error']);
    unset($_SESSION['ml_spotify_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['admin_action']) ? (string)$_POST['admin_action'] : '';

    try {
        if ($action === 'close_voting') {
            $targetVotingSeason = mlGetVotingSeason($pdo);
            if (!$targetVotingSeason) {
                throw new RuntimeException('There is no open next-season voting cycle to close.');
            }

            mlSetSeasonConfig($pdo, (int)$targetVotingSeason['SeasonID'], 'voting_open', '0');
            $_SESSION['ml_admin_message'] = 'Voting for ' . $targetVotingSeason['SeasonName'] . ' is now closed early. You can still review the partial results and start the season when ready.';
            header('Location: admin.php');
            exit;
        }

        if ($action === 'start_next_season') {
            $nextSeasonRow = mlGetNextSeason($pdo);

            if (!$nextSeasonRow) {
                throw new RuntimeException('A next season is required before you can review it.');
            }

            $nextSeasonId = (int)$nextSeasonRow['SeasonID'];
            if (!mlCanStartNextSeason($pdo, $nextSeasonId)) {
                throw new RuntimeException('You can only review the next season after voting is complete or after voting has been closed early with at least one submission.');
            }

            header('Location: ' . mlUrl('season_rounds.php?season_id=' . $nextSeasonId));
            exit;
        }

        if ($action === 'revert_previous_season') {
            $currentSeasonRow = mlGetCurrentSeason($pdo);
            if (!$currentSeasonRow) {
                throw new RuntimeException('No current season was found.');
            }

            $currentSeasonId = (int)$currentSeasonRow['SeasonID'];
            if (!mlCanRevertToPreviousSeason($pdo, $currentSeasonId)) {
                throw new RuntimeException('Revert is only allowed before Round 1 Songs Due for the current season.');
            }

            $previousSeasonRow = mlGetPreviousSeason($pdo, $currentSeasonId);
            if (!$previousSeasonRow) {
                throw new RuntimeException('There is no previous season available to restore.');
            }

            $pdo->beginTransaction();
            $pdo->exec('UPDATE ML_Seasons SET IsActive = 0');
            $activateStmt = $pdo->prepare('UPDATE ML_Seasons SET IsActive = 1 WHERE SeasonID = ?');
            $activateStmt->execute([(int)$previousSeasonRow['SeasonID']]);
            $pdo->commit();

            $_SESSION['ml_admin_message'] = $previousSeasonRow['SeasonName'] . ' has been restored as the current season.';
            header('Location: admin.php');
            exit;
        }

        if ($action === 'save_discord_settings') {
            $discordEnabled = isset($_POST['discord_enabled']) && $_POST['discord_enabled'] === '1';
            $discordWebhookUrl = trim((string)($_POST['discord_webhook_url'] ?? ''));
            $discordUsername = trim((string)($_POST['discord_username'] ?? ''));
            $discordEveryWebhookUrl = trim((string)($_POST['discord_every_webhook_url'] ?? ''));
            $discordEveryUsername = trim((string)($_POST['discord_every_username'] ?? ''));
            $discordQaWebhookUrl = trim((string)($_POST['discord_qa_webhook_url'] ?? ''));
            $discordQaUsername = trim((string)($_POST['discord_qa_username'] ?? ''));
            $discordSettingsPdo = mlGetLivePdo();

            if ($discordWebhookUrl !== '' && !mlDiscordIsWebhookUrlAllowed($discordWebhookUrl)) {
                throw new RuntimeException('Enter a valid Essential webhook URL that starts with https://discord.com/api/webhooks/.');
            }

            if ($discordEveryWebhookUrl !== '' && !mlDiscordIsWebhookUrlAllowed($discordEveryWebhookUrl)) {
                throw new RuntimeException('Enter a valid Every webhook URL that starts with https://discord.com/api/webhooks/.');
            }

            if ($discordQaWebhookUrl !== '' && !mlDiscordIsWebhookUrlAllowed($discordQaWebhookUrl)) {
                throw new RuntimeException('Enter a valid QA webhook URL that starts with https://discord.com/api/webhooks/.');
            }

            mlSetSettingValue($discordSettingsPdo, 'discord_enabled', $discordEnabled ? '1' : '0');
            mlSetSettingValue($discordSettingsPdo, 'discord_webhook_url', $discordWebhookUrl !== '' ? $discordWebhookUrl : null);
            mlSetSettingValue($discordSettingsPdo, 'discord_username', $discordUsername !== '' ? $discordUsername : null);
            mlSetSettingValue($discordSettingsPdo, 'discord_every_webhook_url', $discordEveryWebhookUrl !== '' ? $discordEveryWebhookUrl : null);
            mlSetSettingValue($discordSettingsPdo, 'discord_every_username', $discordEveryUsername !== '' ? $discordEveryUsername : null);
            mlSetSettingValue($discordSettingsPdo, 'discord_qa_webhook_url', $discordQaWebhookUrl !== '' ? $discordQaWebhookUrl : null);
            mlSetSettingValue($discordSettingsPdo, 'discord_qa_username', $discordQaUsername !== '' ? $discordQaUsername : null);

            $_SESSION['ml_admin_message'] = 'Discord settings saved.';
            header('Location: admin.php');
            exit;
        }

        if ($action === 'test_discord_webhook') {
            $testEventKey = trim((string)($_POST['discord_test_event'] ?? 'submission_open'));
            $testEventOptions = mlDiscordGetTestEventOptions();
            if (!isset($testEventOptions[$testEventKey])) {
                $testEventKey = 'submission_open';
            }

            $testResult = mlDiscordSendTestMessage($pdo, $testEventKey);

            if (!empty($testResult['sent'])) {
                if (($testResult['reason'] ?? '') === 'partial_sent') {
                    $errorPart = trim((string)($testResult['error'] ?? ''));
                    $_SESSION['ml_admin_message'] = $testEventOptions[$testEventKey] . ' test message sent to at least one configured webhook.';
                    if ($errorPart !== '') {
                        $_SESSION['ml_admin_error'] = 'One or more webhook deliveries failed: ' . $errorPart;
                    }
                } else {
                    $_SESSION['ml_admin_message'] = $testEventOptions[$testEventKey] . ' test message sent successfully.';
                }
            } else {
                $statusPart = !empty($testResult['status_code']) ? ' (HTTP ' . (int)$testResult['status_code'] . ')' : '';
                $errorPart = trim((string)($testResult['error'] ?? ''));
                $_SESSION['ml_admin_error'] = 'Discord test failed' . $statusPart . ($errorPart !== '' ? ': ' . $errorPart : '.');
            }

            header('Location: admin.php');
            exit;
        }

        if ($action === 'set_dev_mode') {
            $devModeEnabled = isset($_POST['dev_mode']) && $_POST['dev_mode'] === '1';
            mlSetSettingValue($pdo, 'dev_mode', $devModeEnabled ? '1' : '0');
            $_SESSION['ml_admin_message'] = $devModeEnabled
                ? 'Dev mode is on. App caching is now minimized for development.'
                : 'Dev mode is off. Standard app caching is active again.';
            header('Location: admin.php');
            exit;
        }

        if ($action === 'save_vote_settings') {
            $votesPerRound = isset($_POST['votes_per_round']) ? (int)$_POST['votes_per_round'] : 0;
            $noSongMax = isset($_POST['vote_max_per_song_unlimited']) && $_POST['vote_max_per_song_unlimited'] === '1';
            $maxVotesPerSong = isset($_POST['vote_max_per_song']) ? (int)$_POST['vote_max_per_song'] : 0;

            if ($votesPerRound < 1) {
                throw new RuntimeException('Votes per round must be at least 1.');
            }

            if ($noSongMax) {
                $maxVotesPerSong = 0;
            } elseif ($maxVotesPerSong < 1) {
                throw new RuntimeException('Max points per song must be at least 1, or choose no maximum.');
            }

            mlSetSettingValue($pdo, 'votes_per_round', (string)$votesPerRound);
            mlSetSettingValue($pdo, 'vote_max_per_song', (string)$maxVotesPerSong);

            $_SESSION['ml_admin_message'] = 'Voting settings saved.';
            header('Location: admin.php');
            exit;
        }

        if ($action === 'save_playlist_settings') {
            $playlistBuildMode = isset($_POST['playlist_build_mode']) ? strtolower(trim((string)$_POST['playlist_build_mode'])) : 'due';
            if (!in_array($playlistBuildMode, ['due', 'wait'], true)) {
                throw new RuntimeException('Choose a valid playlist timing option.');
            }

            mlSetSettingValue($pdo, 'playlist_build_mode', $playlistBuildMode);
            $_SESSION['ml_admin_message'] = 'Playlist timing saved.';
            header('Location: admin.php');
            exit;
        }

        if ($action === 'generate_current_playlist') {
            $adminRounds = mlComputeRoundPresentation($pdo, mlLoadSeasonRoundsForGameplay($pdo, $seasonId), $currentUserId);
            $playlistResult = mlHandleManualPlaylistTrigger($pdo, $adminRounds);

            if (!empty($playlistResult['already_generated'])) {
                $_SESSION['ml_admin_message'] = 'Playlist already exists for ' . $playlistResult['title'] . '.';
            } else {
                $_SESSION['ml_admin_message'] = 'Playlist generated for ' . $playlistResult['title'] . '.';
            }

            header('Location: admin.php');
            exit;
        }

        if ($action === 'create_season') {
            $newSeasonName = isset($_POST['new_season_name']) ? trim((string)$_POST['new_season_name']) : '';

            $nextSeasonIdStmt = $pdo->query('SELECT COALESCE(MAX(SeasonID), 0) + 1 FROM ML_Seasons');
            $nextSeasonId = (int)$nextSeasonIdStmt->fetchColumn();

            if ($newSeasonName === '') {
                throw new RuntimeException('Enter a season name before creating the next season.');
            }

            $pdo->beginTransaction();

            $existsStmt = $pdo->prepare('SELECT SeasonID FROM ML_Seasons WHERE SeasonID = ? LIMIT 1');
            $existsStmt->execute([$nextSeasonId]);
            if ($existsStmt->fetchColumn()) {
                throw new RuntimeException('That next season ID is already taken. Please refresh and try again.');
            }

            $insertSeasonStmt = $pdo->prepare('INSERT INTO ML_Seasons (SeasonID, SeasonName, IsActive) VALUES (?, ?, 0)');
            $insertSeasonStmt->execute([$nextSeasonId, $newSeasonName]);
            mlSetSeasonConfig($pdo, $nextSeasonId, 'voting_open', '0');

            $pdo->commit();

            $_SESSION['ml_admin_message'] = $newSeasonName . ' has been created. Finish setup below, then start voting when you are ready.';
            header('Location: ' . mlUrl('season-builder/season_setup.php?season_id=' . $nextSeasonId));
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['ml_admin_error'] = $e->getMessage();
        header('Location: admin.php');
        exit;
    }
}

$seasonRoundsReady = mlSeasonRoundsAvailable($pdo);

$votesPerRoundSetting = max(1, mlGetIntSetting($pdo, 'votes_per_round', 12));
$voteMaxPerSongSettingRaw = mlGetIntSetting($pdo, 'vote_max_per_song', 0);
$voteMaxPerSongUnlimited = ($voteMaxPerSongSettingRaw <= 0);
$voteMaxPerSongSetting = $voteMaxPerSongUnlimited ? $votesPerRoundSetting : min($voteMaxPerSongSettingRaw, $votesPerRoundSetting);
$devModeEnabled = mlIsDevMode($pdo);
$discordStatus = mlDiscordGetConfigStatus(mlGetLivePdo());
$discordTestEventOptions = mlDiscordGetTestEventOptions();
$discordTrackedEvents = mlDiscordGetTrackedEventLabels();
$discordRecentEvents = mlDiscordGetRecentEventLog($pdo, 20);
$discordCurrentSeasonMatrix = $seasonId > 0 ? mlDiscordGetSeasonRoundEventMatrix($pdo, $seasonId) : [];
$spotifyConfigured = mlSpotifyAppConfigured();
$spotifyConnection = mlSpotifyConnectionSummary($pdo);
$playlistBuildMode = mlGetPlaylistBuildMode($pdo);
$playlistBuildModeLabel = mlGetPlaylistBuildModeLabel($pdo);
$adminRounds = mlComputeRoundPresentation($pdo, mlLoadSeasonRoundsForGameplay($pdo, $seasonId), $currentUserId);
$manualPlaylistRound = null;
foreach ($adminRounds as $adminRound) {
    if (($adminRound['round_state'] ?? '') === 'submission' && !empty($adminRound['can_manual_generate_playlist'])) {
        $manualPlaylistRound = $adminRound;
        break;
    }
}

$seasonListStmt = $pdo->query("
    SELECT s.SeasonID,
           s.SeasonName,
           s.IsActive,
           COALESCE(cfg.ConfigValue, '0') AS VotingOpenValue,
           (SELECT COUNT(*) FROM ML_Q1Categories c WHERE c.SeasonID = s.SeasonID) AS CategoryCount,
           (SELECT COUNT(DISTINCT sub.UserID) FROM ML_Submissions sub WHERE sub.SeasonID = s.SeasonID) AS SubmissionCount
    FROM ML_Seasons s
    LEFT JOIN ML_Config cfg
      ON cfg.SeasonID = s.SeasonID
     AND cfg.ConfigKey = 'voting_open'
    ORDER BY s.SeasonID DESC
");
$seasonList = $seasonListStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($seasonList as &$seasonRow) {
    $seasonRow['HasCommittedRounds'] = $seasonRoundsReady && mlSeasonHasCommittedRounds($pdo, (int)$seasonRow['SeasonID']) ? '1' : '0';
}
unset($seasonRow);

$totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM ML_Users');
$totalUsers = (int)$totalUsersStmt->fetchColumn();

$activeSubmissionStmt = $pdo->prepare('SELECT COUNT(DISTINCT UserID) FROM ML_Submissions WHERE SeasonID = ?');
$activeSubmissionStmt->execute([$seasonId]);
$activeSubmissionCount = (int)$activeSubmissionStmt->fetchColumn();

$currentSeasonRow = mlGetCurrentSeason($pdo);
$currentSeasonId = $currentSeasonRow ? (int)$currentSeasonRow['SeasonID'] : 0;
$nextSeasonRow = mlGetNextSeason($pdo);
$nextSeasonRowId = $nextSeasonRow ? (int)$nextSeasonRow['SeasonID'] : 0;
$nextSeasonVotingOpen = $nextSeasonRow ? mlIsSeasonVotingOpen($pdo, $nextSeasonRowId) : false;
$nextSeasonVotingComplete = $nextSeasonRow ? mlIsSeasonVotingComplete($pdo, $nextSeasonRowId) : false;
$nextSeasonSubmissionCount = $nextSeasonRow ? mlGetSeasonSubmissionCount($pdo, $nextSeasonRowId) : 0;
$canRevertCurrentSeason = $currentSeasonId > 0 ? mlCanRevertToPreviousSeason($pdo, $currentSeasonId) : false;

$nextSeasonIdStmt = $pdo->query('SELECT COALESCE(MAX(SeasonID), 0) + 1 FROM ML_Seasons');
$nextSeasonId = (int)$nextSeasonIdStmt->fetchColumn();
$nextSeasonDefaultName = 's' . $nextSeasonId;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Admin</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'admin'; include 'header.php'; ?>

<?php
$adminEnvName = defined('MUSICBALL_ENV') ? MUSICBALL_ENV : 'unknown';
$adminDbName = ($adminEnvName === 'dev') ? 'musicball_future' : (($adminEnvName === 'prod') ? 'musicball' : 'unknown');
?>
<div style="
    position: fixed;
    right: 12px;
    bottom: 12px;
    z-index: 9999;
    padding: 5px 8px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.18);
    background: rgba(0,0,0,0.55);
    color: rgba(255,255,255,0.78);
    font-size: 11px;
    line-height: 1;
    backdrop-filter: blur(6px);
">
    <?= htmlspecialchars(strtoupper($adminEnvName)) ?> · <?= htmlspecialchars($adminDbName) ?>
</div>

<div class="wrapper">
    <div class="card admin-card">
        <div class="admin-page-topline admin-page-topline-compact">
            <div class="admin-page-intro">
                <h1>Admin</h1>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                <a href="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>" class="admin-back-link admin-back-link-discreet">QA Tools</a>
                <a href="<?= htmlspecialchars(mlUrl('settings.php')) ?>" class="admin-back-link admin-back-link-discreet">&larr; Back to Settings</a>
            </div>
        </div>

        <?php if ($adminMessage !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($adminMessage) ?></div>
        <?php endif; ?>

        <?php if ($adminError !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($adminError) ?></div>
        <?php endif; ?>

        <div class="admin-roku-shell">
            <aside class="admin-roku-sidebar" aria-label="Admin sections">
                <div class="admin-roku-mobile-nav" aria-label="Admin sections">
                    <div class="admin-roku-mobile-header">
                        <div>
                            <div class="home-shell-kicker">Admin menu</div>
                            <div class="admin-roku-mobile-title" id="admin-mobile-current-group">Gameplay</div>
                        </div>
                        <div class="admin-roku-mobile-current" id="admin-mobile-current-view">Round voting settings</div>
                    </div>

                    <div class="admin-roku-mobile-groups" role="tablist" aria-label="Admin categories">
                        <button type="button" class="admin-roku-mobile-group is-active" data-admin-mobile-group="gameplay">Gameplay</button>
                        <button type="button" class="admin-roku-mobile-group" data-admin-mobile-group="season-setup">Season setup</button>
                        <button type="button" class="admin-roku-mobile-group" data-admin-mobile-group="spotify">Spotify</button>
                        <button type="button" class="admin-roku-mobile-group" data-admin-mobile-group="discord">Discord</button>
                        <button type="button" class="admin-roku-mobile-group" data-admin-mobile-group="pwa">PWA</button>
                    </div>

                    <div class="admin-roku-mobile-panels">
                        <div class="admin-roku-mobile-panel is-active" data-admin-mobile-panel="gameplay">
                            <button type="button" class="admin-roku-mobile-link is-active" data-admin-nav="round-voting-settings">Round voting settings</button>
                            <button type="button" class="admin-roku-mobile-link" data-admin-nav="playlist-timing">Playlist timing</button>
                        </div>

                        <div class="admin-roku-mobile-panel" data-admin-mobile-panel="season-setup">
                            <button type="button" class="admin-roku-mobile-link" data-admin-nav="create-next-season">Create the next season</button>
                            <button type="button" class="admin-roku-mobile-link" data-admin-nav="manage-existing-seasons">Manage existing seasons</button>
                        </div>

                        <div class="admin-roku-mobile-panel" data-admin-mobile-panel="spotify">
                            <button type="button" class="admin-roku-mobile-link" data-admin-nav="playlist-account">Playlist Account</button>
                        </div>

                        <div class="admin-roku-mobile-panel" data-admin-mobile-panel="discord">
                            <button type="button" class="admin-roku-mobile-link" data-admin-nav="discord-webhook-notifications">Discord webhook notifications</button>
                            <button type="button" class="admin-roku-mobile-link" data-admin-nav="discord-notification-status">Discord notification status</button>
                        </div>

                        <div class="admin-roku-mobile-panel" data-admin-mobile-panel="pwa">
                            <button type="button" class="admin-roku-mobile-link" data-admin-nav="pwa-dev-mode">PWA Dev Mode</button>
                        </div>
                    </div>
                </div>

                <nav class="admin-roku-nav">
                    <div class="admin-roku-group">
                        <div class="admin-roku-group-title">Gameplay</div>
                        <button type="button" class="admin-roku-link is-active" data-admin-nav="round-voting-settings">Round voting settings</button>
                        <button type="button" class="admin-roku-link" data-admin-nav="playlist-timing">Playlist timing</button>
                    </div>

                    <div class="admin-roku-group">
                        <div class="admin-roku-group-title">Season setup</div>
                        <button type="button" class="admin-roku-link" data-admin-nav="create-next-season">Create the next season</button>
                        <button type="button" class="admin-roku-link" data-admin-nav="manage-existing-seasons">Manage existing seasons</button>
                    </div>

                    <div class="admin-roku-group">
                        <div class="admin-roku-group-title">Spotify</div>
                        <button type="button" class="admin-roku-link" data-admin-nav="playlist-account">Playlist Account</button>
                    </div>

                    <div class="admin-roku-group">
                        <div class="admin-roku-group-title">Discord</div>
                        <button type="button" class="admin-roku-link" data-admin-nav="discord-webhook-notifications">Discord webhook notifications</button>
                        <button type="button" class="admin-roku-link" data-admin-nav="discord-notification-status">Discord notification status</button>
                    </div>

                    <div class="admin-roku-group">
                        <div class="admin-roku-group-title">PWA</div>
                        <button type="button" class="admin-roku-link" data-admin-nav="pwa-dev-mode">PWA Dev Mode</button>
                    </div>
                </nav>
            </aside>

            <div class="admin-roku-content">
                <section class="admin-panel admin-admin-view is-active" data-admin-view="round-voting-settings">
                    <div class="home-shell-kicker">Gameplay</div>
                    <h2>Round voting settings</h2>
                    <p>
                        Set the total points each player gets per round, and optionally cap how many points can go on a single song.
                    </p>

                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-form-stack">
                        <input type="hidden" name="admin_action" value="save_vote_settings">

                        <div>
                            <label class="admin-label" for="votes_per_round">Votes per round</label>
                            <input
                                type="number"
                                name="votes_per_round"
                                id="votes_per_round"
                                class="admin-input"
                                min="1"
                                value="<?= (int)$votesPerRoundSetting ?>"
                                required
                            >
                        </div>

                        <div>
                            <label class="admin-label" for="vote_max_per_song">Max points per song</label>
                            <input
                                type="number"
                                name="vote_max_per_song"
                                id="vote_max_per_song"
                                class="admin-input"
                                min="1"
                                value="<?= $voteMaxPerSongUnlimited ? '' : (int)$voteMaxPerSongSetting ?>"
                                <?= $voteMaxPerSongUnlimited ? 'disabled' : '' ?>
                            >
                            <label class="admin-setting-inline">
                                <input type="checkbox" name="vote_max_per_song_unlimited" id="vote_max_per_song_unlimited" value="1" <?= $voteMaxPerSongUnlimited ? 'checked' : '' ?>>
                                <span>No maximum (use the full per-round total)</span>
                            </label>
                        </div>

                        <button type="submit" class="button-primary">Save Vote Settings</button>
                    </form>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="playlist-timing">
                    <div class="home-shell-kicker">Gameplay</div>
                    <h2>Playlist timing</h2>
                    <p>
                        Choose when a round becomes ready for a one-time playlist build. The playlist itself is only created when you trigger it, and the saved URL stays fixed afterward.
                    </p>

                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-form-stack">
                        <input type="hidden" name="admin_action" value="save_playlist_settings">

                        <div>
                            <label class="admin-label" for="playlist_build_mode">Playlist timing</label>
                            <select name="playlist_build_mode" id="playlist_build_mode" class="admin-input">
                                <option value="due" <?= $playlistBuildMode === 'due' ? 'selected' : '' ?>>Build at Songs Due</option>
                                <option value="wait" <?= $playlistBuildMode === 'wait' ? 'selected' : '' ?>>Wait for everyone</option>
                            </select>
                            <p>Current mode: <strong><?= htmlspecialchars($playlistBuildModeLabel) ?></strong></p>
                        </div>

                        <button type="submit" class="button-primary">Save Playlist Timing</button>
                    </form>

                    <?php if ($manualPlaylistRound): ?>
                        <div class="admin-section-divider">
                            <div class="admin-stat-line">
                                <strong>Manual build ready:</strong> <?= htmlspecialchars($manualPlaylistRound['Title']) ?>
                            </div>
                            <p>
                                You can generate the playlist now either because everyone has already submitted, or because the Songs Due deadline has passed. If some players have not submitted yet, the playlist will still build using the songs already received. After build, the stored playlist URL stays fixed across the app.
                            </p>
                            <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-form-top-sm">
                                <input type="hidden" name="admin_action" value="generate_current_playlist">
                                <button type="submit" class="button-secondary">Generate Current Playlist</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="create-next-season">
                    <div class="home-shell-kicker">Season setup</div>
                    <h2>Create the next season</h2>
                    <p>
                        The next available season will use <strong>Season ID <?= $nextSeasonId ?></strong>. Create it first, finish setup on the next page, then start voting only when you are ready.
                    </p>

                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-form-stack">
                        <input type="hidden" name="admin_action" value="create_season">

                        <div>
                            <label class="admin-label" for="new_season_name">Season name</label>
                            <input
                                type="text"
                                name="new_season_name"
                                id="new_season_name"
                                class="admin-input"
                                value="<?= htmlspecialchars($nextSeasonDefaultName) ?>"
                                required
                            >
                        </div>

                        <button type="submit" class="button-primary">Create</button>
                    </form>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="manage-existing-seasons">
                    <div class="home-shell-kicker">Season setup</div>
                    <h2>Manage existing seasons</h2>
                    <p>
                        Open a season to edit its setup, save progress, review next-season votes, and control the season lifecycle.
                    </p>

                    <div class="admin-season-table-wrap">
                        <table class="admin-season-table">
                            <thead>
                                <tr>
                                    <th>Season</th>
                                    <th>Status</th>
                                    <th>Categories</th>
                                    <th>Submissions</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($seasonList as $seasonRow): ?>
                                    <?php
                                    $rowSeasonId = (int)$seasonRow['SeasonID'];
                                    $rowVotingOpen = ((string)$seasonRow['VotingOpenValue'] === '1');
                                    $rowVotingComplete = mlIsSeasonVotingComplete($pdo, $rowSeasonId);
                                    $rowType = 'Past';
                                    if ($currentSeasonId > 0 && $rowSeasonId === $currentSeasonId) {
                                        $rowType = 'Current';
                                    } elseif ($nextSeasonRowId > 0 && $rowSeasonId === $nextSeasonRowId) {
                                        $rowType = 'Next';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($seasonRow['SeasonName']) ?></strong><br>
                                            <span class="note">Season ID <?= $rowSeasonId ?></span>
                                        </td>
                                        <td>
                                            <?php if ($rowType === 'Current'): ?>
                                                <span class="pill pill-open">Current</span>
                                            <?php elseif ($rowType === 'Next'): ?>
                                                <span class="pill pill-neutral">Next</span>
                                                <div class="note admin-note-top-xs">
                                                    <?= $rowVotingOpen ? ($rowVotingComplete ? 'Voting complete' : 'Voting open') : ($rowVotingComplete ? 'Voting complete' : ($rowSeasonId === $nextSeasonRowId && mlWasSeasonVotingClosedEarly($pdo, $rowSeasonId) ? 'Voting closed early' : 'Setup in progress')) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="pill pill-closed">Past</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)$seasonRow['CategoryCount'] ?></td>
                                        <td><?= (int)$seasonRow['SubmissionCount'] ?> / <?= $totalUsers ?></td>
                                        <td>
                                            <div class="admin-season-table-actions">
                                            <?php if ($rowType === 'Current'): ?>
                                                <a href="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                    Edit Setup
                                                </a>
                                                <a href="<?= htmlspecialchars(mlUrl('season.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                    View
                                                </a>
                                                <?php if ($canRevertCurrentSeason): ?>
                                                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-inline-form">
                                                        <input type="hidden" name="admin_action" value="revert_previous_season">
                                                        <button type="submit" class="button-primary">
                                                            Revert to Previous Season
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php elseif ($rowType === 'Next'): ?>
                                                <a href="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                    Edit Setup
                                                </a>
                                                <a href="<?= htmlspecialchars(mlUrl('final.php?preview=1')) ?>" class="button-secondary admin-table-link">
                                                    View Votes
                                                </a>
                                                <?php if ($rowVotingOpen && !$rowVotingComplete): ?>
                                                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-inline-form">
                                                        <input type="hidden" name="admin_action" value="close_voting">
                                                        <button type="submit" class="button-secondary">
                                                            Close Voting Early
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (mlCanStartNextSeason($pdo, $rowSeasonId)): ?>
                                                    <a href="<?= htmlspecialchars(mlUrl('season_rounds.php?season_id=' . $rowSeasonId)) ?>" class="button-primary admin-table-link">
                                                        Review Next Season
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars(mlUrl('season.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                    View
                                                </a>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="playlist-account">
                    <div class="home-shell-kicker">Spotify</div>
                    <h2>Playlist Account</h2>
                    <p>
                        Connect the single Spotify account Musicball uses for song search and playlist generation. This is now an admin-only function.
                    </p>

                    <?php if (!$spotifyConfigured): ?>
                        <p>Spotify is not configured yet. Add your client ID and secret to <code>config/spotify_config.php</code>, then return here.</p>
                    <?php else: ?>
                        <?php if ($spotifyConnection['is_connected']): ?>
                            <div class="spotify-connection-card">
                                <div class="spotify-connection-line"><strong>Connected as:</strong> <?= htmlspecialchars($spotifyConnection['display_name'] !== '' ? $spotifyConnection['display_name'] : $spotifyConnection['spotify_user_id']) ?></div>
                                <?php if ($spotifyConnection['spotify_user_id'] !== ''): ?>
                                    <div class="spotify-connection-line"><strong>Spotify user ID:</strong> <?= htmlspecialchars($spotifyConnection['spotify_user_id']) ?></div>
                                <?php endif; ?>
                                <?php if ($spotifyConnection['updated_at'] !== ''): ?>
                                    <div class="spotify-connection-line"><strong>Last updated:</strong> <?= htmlspecialchars($spotifyConnection['updated_at']) ?> UTC</div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p>No Spotify account is connected yet.</p>
                        <?php endif; ?>

                        <div class="settings-spotify-actions">
                            <a href="<?= htmlspecialchars(mlUrl('integrations/spotify/connect.php')) ?>" class="button-primary"><?= $spotifyConnection['is_connected'] ? 'Reconnect Spotify' : 'Connect Spotify' ?></a>
                            <?php if ($spotifyConnection['is_connected']): ?>
                                <a href="<?= htmlspecialchars(mlUrl('integrations/spotify/disconnect.php')) ?>" class="button-secondary">Disconnect Spotify</a>
                            <?php endif; ?>
                        </div>
                        <p>This account stays separate from player settings and is controlled only here in Admin.</p>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="discord-webhook-notifications">
                    <div class="home-shell-kicker">Discord</div>
                    <h2>Discord webhook notifications</h2>
                    <p>
                        Control Musicball's Discord webhook connections, display names, and send a safe test message before relying on live notifications.
                    </p>

                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-form-stack" id="discord-settings-form">
                        <input type="hidden" name="admin_action" value="save_discord_settings">

                        <div class="theme-toggle-row admin-theme-toggle-row discord-toggle-row">
                            <div class="theme-toggle-copy">
                                <span class="theme-toggle-label" id="discord-toggle-label">Discord Notifications <?= $discordStatus['enabled_setting'] ? 'On' : 'Off' ?></span>
                                <span class="theme-toggle-note">Turn outbound Discord messages on or off without removing your saved webhooks.</span>
                                <span class="theme-toggle-note discord-toggle-warning">Warning: changing this setting affects live Discord alerts for the whole app and requires confirmation.</span>
                            </div>
                            <label class="theme-switch" for="discord_enabled_toggle" aria-label="Toggle Discord notifications">
                                <input type="checkbox" id="discord_enabled_toggle" name="discord_enabled_toggle" value="1" <?= $discordStatus['enabled_setting'] ? 'checked' : '' ?>>
                                <input type="hidden" name="discord_enabled" id="discord_enabled_hidden" value="<?= $discordStatus['enabled_setting'] ? '1' : '0' ?>">
                                <span class="theme-switch-track"></span>
                            </label>
                        </div>

                        <div class="admin-section-divider">
                            <h3>Essential notifications</h3>
                            <p class="note admin-note-top-xs">New round opens, voting opens, all votes submitted, and round closes.</p>

                            <div>
                                <label class="admin-label" for="discord_username">Webhook display name</label>
                                <input
                                    type="text"
                                    name="discord_username"
                                    id="discord_username"
                                    class="admin-input"
                                    maxlength="80"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['essential']['display_name']) ?>"
                                    placeholder="Musicball"
                                >
                                <p class="note admin-note-top-xs">Leave blank to store a null value and let Discord use the default display name.</p>
                            </div>

                            <div>
                                <label class="admin-label" for="discord_webhook_url">Webhook URL</label>
                                <input
                                    type="url"
                                    name="discord_webhook_url"
                                    id="discord_webhook_url"
                                    class="admin-input"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['essential']['webhook_url']) ?>"
                                    placeholder="https://discord.com/api/webhooks/..."
                                    inputmode="url"
                                    autocomplete="off"
                                >
                                <?php if ($discordStatus['profiles']['essential']['webhook_present']): ?>
                                    <p>Saved value: <code><?= htmlspecialchars($discordStatus['profiles']['essential']['webhook_masked']) ?></code></p>
                                <?php else: ?>
                                    <p>No Essential webhook URL has been saved yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="admin-section-divider">
                            <h3>Every notification</h3>
                            <p class="note admin-note-top-xs">Receives everything in Essential plus song submitted, song changed, and votes submitted.</p>

                            <div>
                                <label class="admin-label" for="discord_every_username">Webhook display name</label>
                                <input
                                    type="text"
                                    name="discord_every_username"
                                    id="discord_every_username"
                                    class="admin-input"
                                    maxlength="80"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['every']['display_name']) ?>"
                                    placeholder="Musicball"
                                >
                                <p class="note admin-note-top-xs">Leave blank to store a null value and let Discord use the default display name.</p>
                            </div>

                            <div>
                                <label class="admin-label" for="discord_every_webhook_url">Webhook URL</label>
                                <input
                                    type="url"
                                    name="discord_every_webhook_url"
                                    id="discord_every_webhook_url"
                                    class="admin-input"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['every']['webhook_url']) ?>"
                                    placeholder="https://discord.com/api/webhooks/..."
                                    inputmode="url"
                                    autocomplete="off"
                                >
                                <?php if ($discordStatus['profiles']['every']['webhook_present']): ?>
                                    <p>Saved value: <code><?= htmlspecialchars($discordStatus['profiles']['every']['webhook_masked']) ?></code></p>
                                <?php else: ?>
                                    <p>No Every webhook URL has been saved yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="admin-section-divider">
                            <h3>QA notifications</h3>
                            <p class="note admin-note-top-xs">Receives all notification events, but only while the app is running in QA mode.</p>

                            <div>
                                <label class="admin-label" for="discord_qa_username">Webhook display name</label>
                                <input
                                    type="text"
                                    name="discord_qa_username"
                                    id="discord_qa_username"
                                    class="admin-input"
                                    maxlength="80"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['qa']['display_name']) ?>"
                                    placeholder="Musicball QA"
                                >
                                <p class="note admin-note-top-xs">Leave blank to store a null value and let Discord use the default display name.</p>
                            </div>

                            <div>
                                <label class="admin-label" for="discord_qa_webhook_url">Webhook URL</label>
                                <input
                                    type="url"
                                    name="discord_qa_webhook_url"
                                    id="discord_qa_webhook_url"
                                    class="admin-input"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['qa']['webhook_url']) ?>"
                                    placeholder="https://discord.com/api/webhooks/..."
                                    inputmode="url"
                                    autocomplete="off"
                                >
                                <?php if ($discordStatus['profiles']['qa']['webhook_present']): ?>
                                    <p>Saved value: <code><?= htmlspecialchars($discordStatus['profiles']['qa']['webhook_masked']) ?></code></p>
                                <?php else: ?>
                                    <p>No QA webhook URL has been saved yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="admin-stat-list">
                            <div class="admin-stat-line"><strong>Enabled setting:</strong> <?= $discordStatus['enabled_setting'] ? 'Yes' : 'No' ?></div>
                            <div class="admin-stat-line"><strong>Essential webhook URL present:</strong> <?= $discordStatus['profiles']['essential']['webhook_present'] ? 'Yes' : 'No' ?></div>
                            <div class="admin-stat-line"><strong>Essential webhook URL valid:</strong> <?= $discordStatus['profiles']['essential']['webhook_valid'] ? 'Yes' : 'No' ?></div>
                            <div class="admin-stat-line"><strong>Every webhook URL present:</strong> <?= $discordStatus['profiles']['every']['webhook_present'] ? 'Yes' : 'No' ?></div>
                            <div class="admin-stat-line"><strong>Every webhook URL valid:</strong> <?= $discordStatus['profiles']['every']['webhook_valid'] ? 'Yes' : 'No' ?></div>
                            <div class="admin-stat-line"><strong>QA webhook URL present:</strong> <?= $discordStatus['profiles']['qa']['webhook_present'] ? 'Yes' : 'No' ?></div>
                            <div class="admin-stat-line"><strong>QA webhook URL valid:</strong> <?= $discordStatus['profiles']['qa']['webhook_valid'] ? 'Yes' : 'No' ?></div>
                            <div class="admin-stat-line"><strong>Discord event log table ready:</strong> <?= $discordStatus['event_log_ready'] ? 'Yes' : 'No' ?></div>
                        </div>

                        <button type="submit" class="button-primary">Save Discord Settings</button>
                    </form>

                    <div class="admin-section-divider">
                        <p>Use the test area to send one of your real notification types. Musicball routes the test to the same webhook level that the live event would use.</p>
                        <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-inline-form admin-inline-form-wrap admin-form-top-sm">
                            <input type="hidden" name="admin_action" value="test_discord_webhook">
                            <div class="admin-inline-field">
                                <label class="admin-label" for="discord_test_event">Notification type</label>
                                <select name="discord_test_event" id="discord_test_event" class="admin-select admin-select-compact">
                                    <?php foreach ($discordTestEventOptions as $testEventKey => $testEventLabel): ?>
                                        <option value="<?= htmlspecialchars($testEventKey) ?>"><?= htmlspecialchars($testEventLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="button-secondary">Send Test Message</button>
                        </form>
                        <p class="note admin-note-top-xs">If a live webhook send fails, Musicball writes a concise Discord error to the PHP error log without interrupting gameplay.</p>
                    </div>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="discord-notification-status">
                    <div class="home-shell-kicker">Discord</div>
                    <h2>Discord notification status</h2>

                    <?php if (!$discordStatus['event_log_ready']): ?>
                        <p>The Discord event log table is not ready yet, so Musicball cannot show send history here.</p>
                    <?php else: ?>
                        <div class="admin-collapsible-tabs" data-collapsible-group="discord-status">
                            <button type="button" class="admin-collapsible-tab is-active" data-target="discord-coverage-panel">Current season coverage</button>
                            <button type="button" class="admin-collapsible-tab" data-target="discord-history-panel">Recent event log</button>
                        </div>

                        <div id="discord-coverage-panel" class="admin-collapsible-panel is-active">
                            <?php if (empty($discordCurrentSeasonMatrix)): ?>
                                <p>No current-season rounds were found yet.</p>
                            <?php else: ?>
                                <div class="admin-discord-compact-list">
                                    <?php foreach ($discordCurrentSeasonMatrix as $discordRound): ?>
                                        <div class="admin-discord-compact-row">
                                            <div class="admin-discord-compact-title"><?= htmlspecialchars($discordRound['RoundLabel']) ?></div>
                                            <div class="admin-discord-compact-badges">
                                                <?php foreach ($discordTrackedEvents as $eventKey => $eventLabel): ?>
                                                    <?php $eventState = $discordRound['DiscordEvents'][$eventKey] ?? ['sent' => false, 'sent_at' => '', 'label' => $eventLabel]; ?>
                                                    <span class="admin-discord-mini-badge <?= !empty($eventState['sent']) ? 'sent' : 'pending' ?>" title="<?= !empty($eventState['sent_at']) ? htmlspecialchars($eventState['sent_at']) : 'Not sent yet' ?>">
                                                        <?= htmlspecialchars($eventLabel) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div id="discord-history-panel" class="admin-collapsible-panel">
                            <?php if (empty($discordRecentEvents)): ?>
                                <p>No Discord round events have been logged yet.</p>
                            <?php else: ?>
                                <div class="admin-discord-log-simple">
                                    <?php foreach ($discordRecentEvents as $discordEvent): ?>
                                        <div class="admin-discord-log-row">
                                            <span class="admin-discord-log-event"><?= htmlspecialchars($discordEvent['EventLabel']) ?></span>
                                            <span class="admin-discord-log-round"><?= htmlspecialchars($discordEvent['RoundLabel']) ?></span>
                                            <?php if (!empty($discordEvent['SentAt'])): ?>
                                                <span class="admin-discord-log-time"><?= htmlspecialchars($discordEvent['SentAt']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="pwa-dev-mode">
                    <div class="home-shell-kicker">PWA</div>
                    <h2>PWA Dev Mode</h2>
                    <p>
                        Disables app caching and service worker behavior for testing changes.
                    </p>

                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-form-stack">
                        <input type="hidden" name="admin_action" value="set_dev_mode">
                        <div class="theme-toggle-row admin-theme-toggle-row">
                            <div class="theme-toggle-copy">
                                <span class="theme-toggle-label">PWA Force Clear <?= $devModeEnabled ? 'On' : 'Off' ?></span>
                                <span class="theme-toggle-note">When on, the app avoids sticky cached files during development.</span>
                            </div>
                            <label class="theme-switch" for="dev_mode_toggle" aria-label="Toggle development mode">
                                <input type="checkbox" id="dev_mode_toggle" name="dev_mode_toggle" value="1" <?= $devModeEnabled ? 'checked' : '' ?> onchange="this.form.dev_mode.value = this.checked ? '1' : '0'; this.form.submit();">
                                <input type="hidden" name="dev_mode" value="<?= $devModeEnabled ? '1' : '0' ?>">
                                <span class="theme-switch-track"></span>
                            </label>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var form = document.getElementById('discord-settings-form');
    var toggle = document.getElementById('discord_enabled_toggle');
    var hidden = document.getElementById('discord_enabled_hidden');
    var label = document.getElementById('discord-toggle-label');
    if (!form || !toggle || !hidden || !label) {
        return;
    }

    var initialChecked = toggle.checked;

    function syncDiscordToggleUi() {
        hidden.value = toggle.checked ? '1' : '0';
        label.textContent = 'Discord Notifications ' + (toggle.checked ? 'On' : 'Off');
    }

    syncDiscordToggleUi();

    toggle.addEventListener('change', function () {
        syncDiscordToggleUi();

        var confirmMessage = toggle.checked
            ? 'Turn Discord notifications ON for the whole app? Live Discord alerts will be allowed again.'
            : 'Turn Discord notifications OFF for the whole app? Live Discord alerts will stop until you turn them back on.';

        if (!window.confirm(confirmMessage)) {
            toggle.checked = initialChecked;
            syncDiscordToggleUi();
            return;
        }

        form.submit();
    });
})();
</script>

<script>
(function () {
    const unlimited = document.getElementById('vote_max_per_song_unlimited');
    const maxInput = document.getElementById('vote_max_per_song');
    const totalInput = document.getElementById('votes_per_round');
    if (!unlimited || !maxInput || !totalInput) {
        return;
    }

    function syncVoteSettingsUi() {
        if (unlimited.checked) {
            maxInput.value = '';
            maxInput.disabled = true;
        } else {
            maxInput.disabled = false;
            if (maxInput.value === '') {
                maxInput.value = totalInput.value || '1';
            }
        }
    }

    unlimited.addEventListener('change', syncVoteSettingsUi);
    totalInput.addEventListener('input', function () {
        if (!unlimited.checked && maxInput.value !== '') {
            const total = parseInt(totalInput.value || '0', 10);
            const current = parseInt(maxInput.value || '0', 10);
            if (!isNaN(total) && !isNaN(current) && current > total && total > 0) {
                maxInput.value = String(total);
            }
        }
    });

    syncVoteSettingsUi();
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var groups = document.querySelectorAll('[data-collapsible-group="discord-status"]');
    groups.forEach(function (group) {
        var buttons = group.querySelectorAll('.admin-collapsible-tab');
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-target');
                var panel = document.getElementById(targetId);
                if (!panel) {
                    return;
                }

                buttons.forEach(function (otherButton) {
                    otherButton.classList.remove('is-active');
                });
                document.querySelectorAll('.admin-collapsible-panel').forEach(function (otherPanel) {
                    otherPanel.classList.remove('is-active');
                });

                button.classList.add('is-active');
                panel.classList.add('is-active');
            });
        });
    });

    var defaultView = 'round-voting-settings';
    var storageKey = 'musicballAdminView';
    var navButtons = document.querySelectorAll('[data-admin-nav]');
    var viewPanels = document.querySelectorAll('[data-admin-view]');
    var mobileGroupButtons = document.querySelectorAll('[data-admin-mobile-group]');
    var mobilePanels = document.querySelectorAll('[data-admin-mobile-panel]');
    var mobileCurrentGroup = document.getElementById('admin-mobile-current-group');
    var mobileCurrentView = document.getElementById('admin-mobile-current-view');
    var viewToGroupMap = {
        'round-voting-settings': { group: 'gameplay', label: 'Round voting settings', groupLabel: 'Gameplay' },
        'playlist-timing': { group: 'gameplay', label: 'Playlist timing', groupLabel: 'Gameplay' },
        'create-next-season': { group: 'season-setup', label: 'Create the next season', groupLabel: 'Season setup' },
        'manage-existing-seasons': { group: 'season-setup', label: 'Manage existing seasons', groupLabel: 'Season setup' },
        'playlist-account': { group: 'spotify', label: 'Playlist Account', groupLabel: 'Spotify' },
        'discord-webhook-notifications': { group: 'discord', label: 'Discord webhook notifications', groupLabel: 'Discord' },
        'discord-notification-status': { group: 'discord', label: 'Discord notification status', groupLabel: 'Discord' },
        'pwa-dev-mode': { group: 'pwa', label: 'PWA Dev Mode', groupLabel: 'PWA' }
    };

    function activateAdminView(viewName) {
        if (!viewName) {
            viewName = defaultView;
        }

        var matched = false;

        viewPanels.forEach(function (panel) {
            var isActive = panel.getAttribute('data-admin-view') === viewName;
            panel.classList.toggle('is-active', isActive);
            if (isActive) {
                matched = true;
            }
        });

        if (!matched) {
            viewName = defaultView;
            viewPanels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-admin-view') === viewName);
            });
        }

        navButtons.forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-admin-nav') === viewName);
        });

        var viewConfig = viewToGroupMap[viewName] || viewToGroupMap[defaultView];
        if (viewConfig) {
            mobileGroupButtons.forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-admin-mobile-group') === viewConfig.group);
            });

            mobilePanels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-admin-mobile-panel') === viewConfig.group);
            });

            document.querySelectorAll('.admin-roku-mobile-link').forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-admin-nav') === viewName);
            });

            if (mobileCurrentGroup) {
                mobileCurrentGroup.textContent = viewConfig.groupLabel;
            }

            if (mobileCurrentView) {
                mobileCurrentView.textContent = viewConfig.label;
            }
        }

        try {
            window.localStorage.setItem(storageKey, viewName);
        } catch (error) {
        }
    }

    navButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            activateAdminView(button.getAttribute('data-admin-nav'));
        });
    });

    mobileGroupButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var groupName = button.getAttribute('data-admin-mobile-group');
            var activePanel = document.querySelector('[data-admin-mobile-panel="' + groupName + '"]');
            var firstLink = activePanel ? activePanel.querySelector('[data-admin-nav]') : null;

            mobileGroupButtons.forEach(function (groupButton) {
                groupButton.classList.toggle('is-active', groupButton === button);
            });

            mobilePanels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-admin-mobile-panel') === groupName);
            });

            if (firstLink) {
                activateAdminView(firstLink.getAttribute('data-admin-nav'));
            }
        });
    });

    document.querySelectorAll('.admin-roku-mobile-link').forEach(function (button) {
        button.addEventListener('click', function () {
            activateAdminView(button.getAttribute('data-admin-nav'));
        });
    });

    var initialView = defaultView;
    try {
        var storedView = window.localStorage.getItem(storageKey);
        if (storedView) {
            initialView = storedView;
        }
    } catch (error) {
    }

    activateAdminView(initialView);
});
</script>
</body>
</html>
