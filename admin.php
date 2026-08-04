<?php
require_once 'session_boot.php';
require_once 'config.php';
require_once __DIR__ . '/season-builder/sb_season_builder.php';
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';
require_once __DIR__ . '/integrations/discord/discord.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

if (empty($_SESSION['ml_admin_csrf']) || !is_string($_SESSION['ml_admin_csrf'])) {
    $_SESSION['ml_admin_csrf'] = bin2hex(random_bytes(24));
}
$adminCsrfToken = (string)$_SESSION['ml_admin_csrf'];

$discordDataMode = mlDiscordGetDataMode($pdo);

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

            if (mlGetSeasonSubmissionCount($pdo, (int)$targetVotingSeason['SeasonID']) <= 0) {
                throw new RuntimeException('Voting cannot be closed early until at least one player has submitted.');
            }

            $pdo->beginTransaction();
            mlLockSeasonBuilder($pdo, (int)$targetVotingSeason['SeasonID']);
            mlSetSeasonConfig($pdo, (int)$targetVotingSeason['SeasonID'], 'voting_open', '0');
            $pdo->commit();
            $_SESSION['ml_admin_message'] = 'Voting for ' . $targetVotingSeason['SeasonName'] . ' is now closed early. You can still review the partial results and start the season when ready.';
            header('Location: ' . mlUrl('admin.php'));
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

        if ($action === 'save_discord_settings') {
            $discordEnabled = isset($_POST['discord_enabled']) && $_POST['discord_enabled'] === '1';
            if ($discordDataMode === 'qa') {
                $discordQaWebhookUrl = trim((string)($_POST['discord_qa_webhook_url'] ?? ''));
                $discordQaUsername = trim((string)($_POST['discord_qa_username'] ?? ''));

                if ($discordQaWebhookUrl !== '' && !mlDiscordIsWebhookUrlAllowed($discordQaWebhookUrl)) {
                    throw new RuntimeException('Enter a valid QA webhook URL that starts with https://discord.com/api/webhooks/.');
                }

                if ($discordQaWebhookUrl !== '') {
                    mlSetSettingValue($pdo, 'discord_qa_webhook_url', $discordQaWebhookUrl);
                }
                mlSetSettingValue($pdo, 'discord_qa_username', $discordQaUsername !== '' ? $discordQaUsername : null);
            } elseif ($discordDataMode === 'live') {
                $discordWebhookUrl = trim((string)($_POST['discord_webhook_url'] ?? ''));
                $discordUsername = trim((string)($_POST['discord_username'] ?? ''));
                $discordEveryWebhookUrl = trim((string)($_POST['discord_every_webhook_url'] ?? ''));
                $discordEveryUsername = trim((string)($_POST['discord_every_username'] ?? ''));

                if ($discordWebhookUrl !== '' && !mlDiscordIsWebhookUrlAllowed($discordWebhookUrl)) {
                    throw new RuntimeException('Enter a valid Essential webhook URL that starts with https://discord.com/api/webhooks/.');
                }

                if ($discordEveryWebhookUrl !== '' && !mlDiscordIsWebhookUrlAllowed($discordEveryWebhookUrl)) {
                    throw new RuntimeException('Enter a valid Every webhook URL that starts with https://discord.com/api/webhooks/.');
                }

                if ($discordWebhookUrl !== '') {
                    mlSetSettingValue($pdo, 'discord_webhook_url', $discordWebhookUrl);
                }
                mlSetSettingValue($pdo, 'discord_username', $discordUsername !== '' ? $discordUsername : null);
                if ($discordEveryWebhookUrl !== '') {
                    mlSetSettingValue($pdo, 'discord_every_webhook_url', $discordEveryWebhookUrl);
                }
                mlSetSettingValue($pdo, 'discord_every_username', $discordEveryUsername !== '' ? $discordEveryUsername : null);
            } else {
                throw new RuntimeException('Discord settings were not saved because the live/QA data mode could not be verified.');
            }

            mlSetSettingValue(mlDiscordGetMasterSettingsPdo($pdo), 'discord_enabled', $discordEnabled ? '1' : '0');

            $_SESSION['ml_admin_message'] = 'Discord settings saved.';
            header('Location: ' . mlUrl('admin.php'));
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

            header('Location: ' . mlUrl('admin.php'));
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
            header('Location: ' . mlUrl('admin.php'));
            exit;
        }

        if ($action === 'save_playlist_settings') {
            $playlistBuildMode = isset($_POST['playlist_build_mode']) ? strtolower(trim((string)$_POST['playlist_build_mode'])) : 'due';
            if (!in_array($playlistBuildMode, ['due', 'wait'], true)) {
                throw new RuntimeException('Choose a valid playlist timing option.');
            }

            mlSetSettingValue($pdo, 'playlist_build_mode', $playlistBuildMode);
            $_SESSION['ml_admin_message'] = 'Playlist timing saved.';
            header('Location: ' . mlUrl('admin.php'));
            exit;
        }

        if ($action === 'generate_current_playlist') {
            $submittedCsrfToken = isset($_POST['admin_csrf']) ? (string)$_POST['admin_csrf'] : '';
            if ($submittedCsrfToken === '' || !hash_equals($adminCsrfToken, $submittedCsrfToken)) {
                throw new RuntimeException('The playlist request expired. Refresh Admin and try again.');
            }

            $adminRounds = mlComputeRoundPresentation($pdo, mlLoadSeasonRoundsForGameplay($pdo, $seasonId), $currentUserId);
            $playlistResult = mlHandleManualPlaylistTrigger($pdo, $adminRounds);

            if (!empty($playlistResult['already_generated'])) {
                $_SESSION['ml_admin_message'] = 'Playlist already exists for ' . $playlistResult['title'] . '.';
            } else {
                $_SESSION['ml_admin_message'] = 'Playlist generated for ' . $playlistResult['title'] . '.';
            }

            header('Location: ' . mlUrl('admin.php'));
            exit;
        }

        if ($action === 'create_season') {
            $newSeasonName = isset($_POST['new_season_name']) ? trim((string)$_POST['new_season_name']) : '';

            if (mlGetNextSeason($pdo)) {
                throw new RuntimeException('A Next season already exists. Start it before creating another season.');
            }

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
            mlSetSeasonConfig($pdo, $nextSeasonId, 'builder_locked', '0');

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
        header('Location: ' . mlUrl('admin.php'));
        exit;
    }
}

$seasonRoundsReady = mlSeasonRoundsAvailable($pdo);

$votesPerRoundSetting = max(1, mlGetIntSetting($pdo, 'votes_per_round', 12));
$voteMaxPerSongSettingRaw = mlGetIntSetting($pdo, 'vote_max_per_song', 0);
$voteMaxPerSongUnlimited = ($voteMaxPerSongSettingRaw <= 0);
$voteMaxPerSongSetting = $voteMaxPerSongUnlimited ? $votesPerRoundSetting : min($voteMaxPerSongSettingRaw, $votesPerRoundSetting);
$discordStatus = mlDiscordGetConfigStatus($pdo);
$discordHealthClass = '';
$discordHealthMessage = '';

if ($discordStatus['enabled_setting']) {
    $discordHealthIssues = [];
    if (!$discordStatus['event_log_ready']) {
        $discordHealthIssues[] = 'Musicball cannot record which notifications have been sent';
    }

    if ($discordDataMode === 'qa') {
        $qaProfile = $discordStatus['profiles']['qa'];
        if (!$qaProfile['webhook_present']) {
            $discordHealthIssues[] = 'the QA webhook URL is missing';
        } elseif (!$qaProfile['webhook_valid']) {
            $discordHealthIssues[] = 'the QA webhook URL is invalid';
        }

        if (!$discordHealthIssues) {
            $discordHealthClass = 'success';
            $discordHealthMessage = 'Discord notifications are on. The QA webhook is ready.';
        }
    } else {
        $connectedProfiles = [];
        $missingProfiles = [];
        foreach (['essential', 'every'] as $profileKey) {
            $profile = $discordStatus['profiles'][$profileKey];
            $profileLabel = $profileKey === 'essential' ? 'Essential' : 'Every';
            if ($profile['webhook_present'] && !$profile['webhook_valid']) {
                $discordHealthIssues[] = $profileLabel . ' has an invalid webhook URL';
            } elseif ($profile['webhook_valid']) {
                $connectedProfiles[] = $profileLabel;
            } else {
                $missingProfiles[] = $profileLabel;
            }
        }

        if (!$connectedProfiles && !$discordHealthIssues) {
            $discordHealthIssues[] = 'no live webhook URL is configured';
        }

        if (!$discordHealthIssues) {
            $discordHealthClass = 'success';
            if (!$missingProfiles) {
                $discordHealthMessage = 'Discord notifications are on. Essential and Every are ready.';
            } else {
                $discordHealthMessage = 'Discord notifications are on. ' . implode(' and ', $connectedProfiles) . ' is connected; ' . implode(' and ', $missingProfiles) . ' is not configured.';
            }
        }
    }

    if ($discordHealthIssues) {
        $discordHealthClass = 'error';
        $discordHealthMessage = 'Discord notifications need attention: ' . implode('; ', $discordHealthIssues) . '.';
    }
}

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
           COALESCE(cfg.ConfigValue, '0') AS VotingOpenValue
    FROM ML_Seasons s
    LEFT JOIN ML_Config cfg
      ON cfg.SeasonID = s.SeasonID
     AND cfg.ConfigKey = 'voting_open'
    ORDER BY s.SeasonID DESC
");
$seasonList = $seasonListStmt->fetchAll(PDO::FETCH_ASSOC);

$currentSeasonRow = mlGetCurrentSeason($pdo);
$currentSeasonId = $currentSeasonRow ? (int)$currentSeasonRow['SeasonID'] : 0;
$nextSeasonRow = mlGetNextSeason($pdo);
$nextSeasonRowId = $nextSeasonRow ? (int)$nextSeasonRow['SeasonID'] : 0;

$seasonList = array_values(array_filter(
    $seasonList,
    static function (array $row) use ($currentSeasonId, $nextSeasonRowId): bool {
        $rowSeasonId = (int)$row['SeasonID'];
        return $rowSeasonId === $currentSeasonId
            || ($nextSeasonRowId > 0 && $rowSeasonId === $nextSeasonRowId);
    }
));
foreach ($seasonList as &$seasonRow) {
    $seasonRow['HasCommittedRounds'] = $seasonRoundsReady && mlSeasonHasCommittedRounds($pdo, (int)$seasonRow['SeasonID']) ? '1' : '0';
}
unset($seasonRow);

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
                    <div class="admin-roku-mobile-groups">
                        <button type="button" class="admin-roku-mobile-group is-active" data-admin-nav="gameplay">Gameplay</button>
                        <button type="button" class="admin-roku-mobile-group" data-admin-nav="season-setup">Season Setup</button>
                        <button type="button" class="admin-roku-mobile-group" data-admin-nav="integrations">Integrations</button>
                        <button type="button" class="admin-roku-mobile-group" data-admin-nav="notification-status">Notification Status</button>
                    </div>
                </div>

                <nav class="admin-roku-nav">
                    <button type="button" class="admin-roku-link is-active" data-admin-nav="gameplay">Gameplay</button>
                    <button type="button" class="admin-roku-link" data-admin-nav="season-setup">Season Setup</button>
                    <button type="button" class="admin-roku-link" data-admin-nav="integrations">Integrations</button>
                    <button type="button" class="admin-roku-link" data-admin-nav="notification-status">Notification Status</button>
                </nav>
            </aside>

            <div class="admin-roku-content">
                <section class="admin-panel admin-admin-view is-active" data-admin-view="gameplay">
                    <div class="home-shell-kicker">Gameplay</div>
                    <h2>Gameplay</h2>
                    <h3>Round voting settings</h3>
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

                    <div class="admin-section-divider">
                        <h3>Playlist timing</h3>
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
                                    <input type="hidden" name="admin_csrf" value="<?= htmlspecialchars($adminCsrfToken) ?>">
                                    <button
                                        type="submit"
                                        class="button-secondary"
                                        onclick="return confirm('Generate this Spotify playlist now? Its saved URL will become the fixed playlist for this round.');"
                                    >Generate Current Playlist</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="admin-panel admin-admin-view" data-admin-view="season-setup">
                    <div class="home-shell-kicker">Season setup</div>
                    <h2>Season setup</h2>
                    <h3>Manage seasons</h3>
                    <p>
                        Manage the current season and any next season being prepared.
                    </p>

                    <div class="admin-season-list">
                        <?php foreach ($seasonList as $seasonRow): ?>
                            <?php
                            $rowSeasonId = (int)$seasonRow['SeasonID'];
                            $rowType = $rowSeasonId === $currentSeasonId ? 'Current' : 'Next';
                            $rowVotingOpen = ((string)$seasonRow['VotingOpenValue'] === '1');
                            $rowVotingComplete = mlIsSeasonVotingComplete($pdo, $rowSeasonId);
                            $rowBuilderLocked = mlIsSeasonBuilderLocked($pdo, $rowSeasonId);
                            ?>
                            <article class="admin-season-card">
                                <div class="admin-season-card-header">
                                    <h4 class="admin-season-card-name"><?= htmlspecialchars($seasonRow['SeasonName']) ?></h4>
                                    <div class="admin-season-card-status">
                                        <?php if ($rowType === 'Current'): ?>
                                            <span class="pill pill-open">Current</span>
                                        <?php else: ?>
                                            <span class="pill pill-neutral">Next</span>
                                            <span class="note">
                                                <?= $rowVotingOpen ? ($rowVotingComplete ? 'Voting complete' : 'Voting open') : ($rowVotingComplete ? 'Voting complete' : ($rowSeasonId === $nextSeasonRowId && mlWasSeasonVotingClosedEarly($pdo, $rowSeasonId) ? 'Voting closed early' : ($rowBuilderLocked ? 'Voting closed' : 'Setup in progress'))) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="admin-season-card-actions">
                                    <?php if ($rowType === 'Current'): ?>
                                        <?php if (!$rowBuilderLocked): ?>
                                            <a href="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                Edit Setup
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= htmlspecialchars(mlUrl('view_rounds.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                            View Rounds
                                        </a>
                                        <?php if ((string)$seasonRow['HasCommittedRounds'] === '1'): ?>
                                            <a href="<?= htmlspecialchars(mlUrl('season_rounds.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                Edit Rounds
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (!$rowBuilderLocked): ?>
                                            <a href="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                Edit Setup
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= htmlspecialchars(mlUrl('view_rounds.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                            View Rounds
                                        </a>
                                        <?php if ($rowVotingOpen && !$rowVotingComplete): ?>
                                            <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-season-action-form">
                                                <input type="hidden" name="admin_action" value="close_voting">
                                                <button type="submit" class="button-secondary">
                                                    Close Voting Early
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($rowBuilderLocked && !mlCanStartNextSeason($pdo, $rowSeasonId)): ?>
                                            <a href="<?= htmlspecialchars(mlUrl('season_rounds.php?season_id=' . $rowSeasonId)) ?>" class="button-secondary admin-table-link">
                                                Edit Schedule
                                            </a>
                                        <?php endif; ?>
                                        <?php if (mlCanStartNextSeason($pdo, $rowSeasonId)): ?>
                                            <a href="<?= htmlspecialchars(mlUrl('season_rounds.php?season_id=' . $rowSeasonId)) ?>" class="button-primary admin-table-link">
                                                Review Next Season
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$nextSeasonRow): ?>
                        <div class="admin-section-divider">
                            <h3>Create the next season</h3>
                            <p>
                                Name the next season now. You can finish its setup before opening voting.
                            </p>

                            <form
                                method="post"
                                action="<?= htmlspecialchars(mlUrl('admin.php')) ?>"
                                class="admin-form-stack"
                                onsubmit="return confirm('Create this new season? You can edit its setup before opening voting.');"
                            >
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

                                <button type="submit" class="button-primary">Create Next Season</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="admin-admin-view admin-admin-view-stack" data-admin-view="integrations">
                <section class="admin-panel">
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
                            <a
                                href="<?= htmlspecialchars(mlUrl('integrations/spotify/connect.php')) ?>"
                                class="button-primary"
                                <?= $spotifyConnection['is_connected'] ? 'onclick="return confirm(\'Reconnect Spotify? The saved account will only change after you complete Spotify authorization.\');"' : '' ?>
                            ><?= $spotifyConnection['is_connected'] ? 'Reconnect Spotify' : 'Connect Spotify' ?></a>
                            <?php if ($spotifyConnection['is_connected']): ?>
                                <form
                                    method="post"
                                    action="<?= htmlspecialchars(mlUrl('integrations/spotify/disconnect.php')) ?>"
                                    class="admin-inline-form"
                                    onsubmit="return confirm('Disconnect Spotify from Musicball? Song search and playlist generation will stop until an account is connected again. Existing Spotify playlists will not be deleted.');"
                                >
                                    <input type="hidden" name="admin_csrf" value="<?= htmlspecialchars($adminCsrfToken) ?>">
                                    <button type="submit" class="button-primary button-danger">Disconnect Spotify</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <p>This account stays separate from player settings and is controlled only here in Admin.</p>
                    <?php endif; ?>
                </section>

                <section class="admin-panel">
                    <div class="home-shell-kicker">Discord</div>
                    <h2>Discord Notifications</h2>

                    <form method="post" action="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-form-stack" id="discord-settings-form">
                        <input type="hidden" name="admin_action" value="save_discord_settings">

                        <div class="theme-toggle-row admin-theme-toggle-row discord-toggle-row">
                            <div class="theme-toggle-copy">
                                <span class="theme-toggle-label">Notifications</span>
                                <span class="theme-toggle-note" id="discord-toggle-label"><?= $discordStatus['enabled_setting'] ? 'On' : 'Off' ?></span>
                            </div>
                            <label class="theme-switch" for="discord_enabled_toggle" aria-label="Turn Discord notifications on or off">
                                <input type="checkbox" id="discord_enabled_toggle" name="discord_enabled_toggle" value="1" <?= $discordStatus['enabled_setting'] ? 'checked' : '' ?>>
                                <input type="hidden" name="discord_enabled" id="discord_enabled_hidden" value="<?= $discordStatus['enabled_setting'] ? '1' : '0' ?>">
                                <span class="theme-switch-track"></span>
                            </label>
                        </div>

                        <?php if ($discordHealthMessage !== ''): ?>
                            <div class="status-banner<?= $discordHealthClass !== '' ? ' ' . htmlspecialchars($discordHealthClass) : '' ?>">
                                <?= htmlspecialchars($discordHealthMessage) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($discordDataMode === 'live'): ?>
                        <div class="admin-section-divider">
                            <h3>Essential notifications</h3>
                            <p class="note admin-note-top-xs">Round openings, voting, results, and season events.</p>

                            <div>
                                <label class="admin-label" for="discord_username">Display name (optional)</label>
                                <input
                                    type="text"
                                    name="discord_username"
                                    id="discord_username"
                                    class="admin-input"
                                    maxlength="80"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['essential']['display_name']) ?>"
                                    placeholder="Musicball"
                                >
                            </div>

                            <?php $essentialWebhookConfigured = $discordStatus['profiles']['essential']['webhook_valid']; ?>
                            <div data-discord-webhook-control>
                                <div class="admin-section-actions discord-webhook-credential-status">
                                    <span class="admin-discord-mini-badge <?= $essentialWebhookConfigured ? 'sent' : 'pending' ?>">
                                        <?= $essentialWebhookConfigured ? 'Configured' : ($discordStatus['profiles']['essential']['webhook_present'] ? 'Needs attention' : 'Not configured') ?>
                                    </span>
                                    <?php if ($essentialWebhookConfigured): ?>
                                        <button type="button" class="button-secondary" data-discord-webhook-replace aria-expanded="false">Replace webhook</button>
                                    <?php endif; ?>
                                </div>
                                <div data-discord-webhook-editor <?= $essentialWebhookConfigured ? 'hidden' : '' ?>>
                                    <label class="admin-label" for="discord_webhook_url">Webhook URL</label>
                                    <input
                                        type="url"
                                        name="discord_webhook_url"
                                        id="discord_webhook_url"
                                        class="admin-input"
                                        value=""
                                        placeholder="https://discord.com/api/webhooks/..."
                                        inputmode="url"
                                        autocomplete="new-password"
                                    >
                                    <?php if ($essentialWebhookConfigured): ?>
                                        <div class="admin-section-actions admin-form-top-sm">
                                            <button type="button" class="button-secondary" data-discord-webhook-cancel>Cancel</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="admin-section-divider">
                            <h3>Every notification</h3>
                            <p class="note admin-note-top-xs">All Essential alerts plus song and vote activity.</p>

                            <div>
                                <label class="admin-label" for="discord_every_username">Display name (optional)</label>
                                <input
                                    type="text"
                                    name="discord_every_username"
                                    id="discord_every_username"
                                    class="admin-input"
                                    maxlength="80"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['every']['display_name']) ?>"
                                    placeholder="Musicball"
                                >
                            </div>

                            <?php $everyWebhookConfigured = $discordStatus['profiles']['every']['webhook_valid']; ?>
                            <div data-discord-webhook-control>
                                <div class="admin-section-actions discord-webhook-credential-status">
                                    <span class="admin-discord-mini-badge <?= $everyWebhookConfigured ? 'sent' : 'pending' ?>">
                                        <?= $everyWebhookConfigured ? 'Configured' : ($discordStatus['profiles']['every']['webhook_present'] ? 'Needs attention' : 'Not configured') ?>
                                    </span>
                                    <?php if ($everyWebhookConfigured): ?>
                                        <button type="button" class="button-secondary" data-discord-webhook-replace aria-expanded="false">Replace webhook</button>
                                    <?php endif; ?>
                                </div>
                                <div data-discord-webhook-editor <?= $everyWebhookConfigured ? 'hidden' : '' ?>>
                                    <label class="admin-label" for="discord_every_webhook_url">Webhook URL</label>
                                    <input
                                        type="url"
                                        name="discord_every_webhook_url"
                                        id="discord_every_webhook_url"
                                        class="admin-input"
                                        value=""
                                        placeholder="https://discord.com/api/webhooks/..."
                                        inputmode="url"
                                        autocomplete="new-password"
                                    >
                                    <?php if ($everyWebhookConfigured): ?>
                                        <div class="admin-section-actions admin-form-top-sm">
                                            <button type="button" class="button-secondary" data-discord-webhook-cancel>Cancel</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($discordDataMode === 'qa'): ?>
                        <div class="admin-section-divider">
                            <h3>QA notifications</h3>
                            <p class="note admin-note-top-xs">Every notification event from QA mode.</p>

                            <div>
                                <label class="admin-label" for="discord_qa_username">Display name (optional)</label>
                                <input
                                    type="text"
                                    name="discord_qa_username"
                                    id="discord_qa_username"
                                    class="admin-input"
                                    maxlength="80"
                                    value="<?= htmlspecialchars($discordStatus['profiles']['qa']['display_name']) ?>"
                                    placeholder="Musicball QA"
                                >
                            </div>

                            <?php $qaWebhookConfigured = $discordStatus['profiles']['qa']['webhook_valid']; ?>
                            <div data-discord-webhook-control>
                                <div class="admin-section-actions discord-webhook-credential-status">
                                    <span class="admin-discord-mini-badge <?= $qaWebhookConfigured ? 'sent' : 'pending' ?>">
                                        <?= $qaWebhookConfigured ? 'Configured' : ($discordStatus['profiles']['qa']['webhook_present'] ? 'Needs attention' : 'Not configured') ?>
                                    </span>
                                    <?php if ($qaWebhookConfigured): ?>
                                        <button type="button" class="button-secondary" data-discord-webhook-replace aria-expanded="false">Replace webhook</button>
                                    <?php endif; ?>
                                </div>
                                <div data-discord-webhook-editor <?= $qaWebhookConfigured ? 'hidden' : '' ?>>
                                    <label class="admin-label" for="discord_qa_webhook_url">Webhook URL</label>
                                    <input
                                        type="url"
                                        name="discord_qa_webhook_url"
                                        id="discord_qa_webhook_url"
                                        class="admin-input"
                                        value=""
                                        placeholder="https://discord.com/api/webhooks/..."
                                        inputmode="url"
                                        autocomplete="new-password"
                                    >
                                    <?php if ($qaWebhookConfigured): ?>
                                        <div class="admin-section-actions admin-form-top-sm">
                                            <button type="button" class="button-secondary" data-discord-webhook-cancel>Cancel</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="button-primary">Save Discord Settings</button>
                    </form>

                    <div class="admin-section-divider">
                        <h3>Test notification</h3>
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
                            <button type="submit" class="button-secondary" <?= !$discordStatus['enabled_setting'] ? 'disabled' : '' ?>>Send <?= $discordDataMode === 'qa' ? 'QA' : 'Live' ?> Test Message</button>
                        </form>
                    </div>
                </section>
                </div>

                <section class="admin-panel admin-admin-view" data-admin-view="notification-status">
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

            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var toggle = document.getElementById('discord_enabled_toggle');
    var hidden = document.getElementById('discord_enabled_hidden');
    var label = document.getElementById('discord-toggle-label');
    if (!toggle || !hidden || !label) {
        return;
    }

    function syncDiscordToggleUi() {
        hidden.value = toggle.checked ? '1' : '0';
        label.textContent = toggle.checked ? 'On' : 'Off';
    }

    syncDiscordToggleUi();
    toggle.addEventListener('change', syncDiscordToggleUi);
})();
</script>

<script>
(function () {
    document.querySelectorAll('[data-discord-webhook-control]').forEach(function (control) {
        var replaceButton = control.querySelector('[data-discord-webhook-replace]');
        var cancelButton = control.querySelector('[data-discord-webhook-cancel]');
        var editor = control.querySelector('[data-discord-webhook-editor]');
        var input = editor ? editor.querySelector('input[type="url"]') : null;

        if (!replaceButton || !editor || !input) {
            return;
        }

        replaceButton.addEventListener('click', function () {
            editor.hidden = false;
            replaceButton.hidden = true;
            replaceButton.setAttribute('aria-expanded', 'true');
            input.focus();
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                input.value = '';
                editor.hidden = true;
                replaceButton.hidden = false;
                replaceButton.setAttribute('aria-expanded', 'false');
            });
        }
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

    var defaultView = 'gameplay';
    var storageKey = 'musicballAdminView';
    var navButtons = document.querySelectorAll('[data-admin-nav]');
    var viewPanels = document.querySelectorAll('[data-admin-view]');
    var mobileNavStrip = document.querySelector('.admin-roku-mobile-groups');
    var legacyViewMap = {
        'round-voting-settings': 'gameplay',
        'playlist-timing': 'gameplay',
        'create-next-season': 'season-setup',
        'manage-existing-seasons': 'season-setup',
        'playlist-account': 'integrations',
        'discord-webhook-notifications': 'integrations',
        'discord-notification-status': 'notification-status'
    };

    function revealActiveMobileNav(viewName) {
        if (!mobileNavStrip || mobileNavStrip.clientWidth <= 0) {
            return;
        }

        var activeButton = Array.prototype.find.call(
            mobileNavStrip.querySelectorAll('[data-admin-nav]'),
            function (button) {
                return button.getAttribute('data-admin-nav') === viewName;
            }
        );
        if (!activeButton) {
            return;
        }

        var stripRect = mobileNavStrip.getBoundingClientRect();
        var buttonRect = activeButton.getBoundingClientRect();
        if (buttonRect.left < stripRect.left) {
            mobileNavStrip.scrollLeft -= stripRect.left - buttonRect.left + 8;
        } else if (buttonRect.right > stripRect.right) {
            mobileNavStrip.scrollLeft += buttonRect.right - stripRect.right + 8;
        }
    }

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
            var isActive = button.getAttribute('data-admin-nav') === viewName;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        revealActiveMobileNav(viewName);

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

    var initialView = defaultView;
    try {
        var requestedView = new URLSearchParams(window.location.search).get('view');
        if (requestedView) {
            initialView = legacyViewMap[requestedView] || requestedView;
        } else {
            var storedView = window.localStorage.getItem(storageKey);
            if (storedView) {
                initialView = legacyViewMap[storedView] || storedView;
            }
        }
    } catch (error) {
    }

    activateAdminView(initialView);
});
</script>
</body>
</html>
