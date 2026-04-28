<?php
require_once __DIR__ . '/ml_gameplay.php';
require_once __DIR__ . '/ml_discord.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'season';
$isAdminUser = mlUserIsAdmin($currentUser);

$seasonMessage = isset($_SESSION['ml_season_message']) ? (string)$_SESSION['ml_season_message'] : '';
unset($_SESSION['ml_season_message']);
$seasonError = isset($_SESSION['ml_season_error']) ? (string)$_SESSION['ml_season_error'] : '';
unset($_SESSION['ml_season_error']);
if ($seasonError === '' && isset($_SESSION['ml_playlist_auto_error']) && trim((string)$_SESSION['ml_playlist_auto_error']) !== '') {
    $seasonError = trim((string)$_SESSION['ml_playlist_auto_error']);
}
unset($_SESSION['ml_playlist_auto_error']);

$requestedSeasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;
$selectedSeasonId = mlResolveGameplaySeasonId($pdo, $requestedSeasonId, $seasonId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdminUser) {
    $action = isset($_POST['season_action']) ? (string)$_POST['season_action'] : '';

    try {
        if ($action === 'generate_current_playlist') {
            $adminRounds = mlComputeRoundPresentation($pdo, mlLoadSeasonRoundsForGameplay($pdo, $selectedSeasonId), (int)$currentUser['UserID']);
            $playlistResult = mlHandleManualPlaylistTrigger($pdo, $adminRounds);

            if (!empty($playlistResult['already_generated'])) {
                $_SESSION['ml_season_message'] = 'Playlist already exists for ' . $playlistResult['title'] . '.';
            } else {
                $_SESSION['ml_season_message'] = 'Playlist generated for ' . $playlistResult['title'] . '.';
            }

            $redirectSeasonId = $requestedSeasonId > 0 ? $requestedSeasonId : $seasonId;
            header('Location: season.php?season_id=' . $redirectSeasonId);
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['ml_season_error'] = $e->getMessage();
        $redirectSeasonId = $requestedSeasonId > 0 ? $requestedSeasonId : $seasonId;
        header('Location: season.php?season_id=' . $redirectSeasonId);
        exit;
    }
}
$seasonList = mlLoadSeasonSummaries($pdo);
$seasonRow = mlLoadSeasonById($pdo, $selectedSeasonId);
$rounds = $seasonRow ? mlLoadSeasonRoundsForGameplay($pdo, $selectedSeasonId) : [];
$presentedRounds = $seasonRow ? mlComputeRoundPresentation($pdo, $rounds, (int)$currentUser['UserID']) : [];

if ($seasonRow && mlMaybeAutoGeneratePlaylists($pdo, $presentedRounds, (int)$currentUser['UserID'])) {
    $_SESSION['ml_season_message'] = 'Playlist generated automatically.';
    header('Location: season.php?season_id=' . $selectedSeasonId);
    exit;
}

if ($seasonRow) {
    $rounds = mlLoadSeasonRoundsForGameplay($pdo, $selectedSeasonId);
    $presentedRounds = mlComputeRoundPresentation($pdo, $rounds, (int)$currentUser['UserID']);

    try {
        mlDiscordProcessSeasonPresentation($pdo, $presentedRounds);
    } catch (Throwable $e) {
        // Never interrupt gameplay for Discord failures.
    }
}

mlCloseSessionReadOnly();

$closedRounds = 0;
$submissionOpenCount = 0;
$votingOpenCount = 0;
$lastClosedRoundNumber = 0;

foreach ($presentedRounds as &$round) {
    if (mlRoundIsFinishedForDisplay($round)) {
        $round['podium_finishers'] = mlBuildRoundPodium($pdo, (int)$round['SeasonID'], (int)$round['SeasonRoundID'], (int)$currentUser['UserID']);
    } else {
        $round['podium_finishers'] = [];
    }

    if (($round['status_key'] ?? '') === 'closed') {
        $closedRounds++;
        $lastClosedRoundNumber = max($lastClosedRoundNumber, (int)($round['RoundNumber'] ?? 0));
    }
    if (($round['status_key'] ?? '') === 'submission') {
        $submissionOpenCount++;
    }
    if (($round['status_key'] ?? '') === 'voting') {
        $votingOpenCount++;
    }
}
unset($round);

$activeRound = null;
$nextRounds = [];
$completedRounds = [];

foreach ($presentedRounds as $round) {
    $roundNumber = (int)($round['RoundNumber'] ?? 0);
    $isClosed = (($round['status_key'] ?? '') === 'closed');

    if ($isClosed) {
        $completedRounds[] = $round;
        continue;
    }

    if ($activeRound === null && $roundNumber > $lastClosedRoundNumber) {
        $activeRound = $round;
        continue;
    }

    $nextRounds[] = $round;
}

$seasonRevealState = $activeRound
    && mlRoundIsFinishedForDisplay($activeRound)
    && (($activeRound['status_key'] ?? '') !== 'closed');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Season</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide">
        <div class="game-page-topline game-page-topline-compact">
            <div class="game-page-intro">
                <div class="home-shell-kicker">Season</div>
                <h1 class="game-page-title">
				<?= htmlspecialchars(mlGetLeagueName($pdo)) ?>
				<?php if ($seasonRow): ?>
					<span class="game-season-subtitle">
						<?= htmlspecialchars($seasonRow['SeasonName']) ?>
					</span>
				<?php endif; ?>
			</h1>
            </div>
            <?php if (count($seasonList) > 1): ?>
                <details class="game-season-switcher-menu">
                    <summary class="game-season-switcher-toggle" aria-label="Change season">
                        <span aria-hidden="true">...</span>
                    </summary>
                    <div class="game-season-switcher-panel">
                        <form method="get" action="season.php" class="game-season-switcher-form">
                            <label class="game-season-switcher-label" for="season_id">View season</label>
                            <select name="season_id" id="season_id" class="admin-input game-season-select" onchange="this.form.submit()">
                                <?php foreach ($seasonList as $seasonOption): ?>
                                    <option value="<?= (int)$seasonOption['SeasonID'] ?>" <?= $seasonRow && (int)$seasonRow['SeasonID'] === (int)$seasonOption['SeasonID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($seasonOption['SeasonName']) ?><?= ((int)$seasonOption['RoundCount'] > 0) ? '' : ' - no rounds yet' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>

        <?php if ($seasonMessage !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($seasonMessage) ?></div>
        <?php endif; ?>

        <?php if ($seasonError !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($seasonError) ?></div>
        <?php endif; ?>

        <?php if (!$seasonRow): ?>
            <div class="status-banner error">No season could be loaded.</div>
        <?php elseif (empty($presentedRounds)): ?>
            <div class="status-banner">
                This season does not have committed rounds yet. Once you start the season from admin, the round hub will appear here.
            </div>
        <?php else: ?>
            <?php if ($activeRound): ?>
                <div class="game-round-section game-round-section-active<?= $seasonRevealState ? ' game-round-section-reveal' : '' ?>">
                    <div class="game-round-section-heading-wrap">
                        <div>
                            <h2><?= $seasonRevealState ? 'Round Complete' : 'Active Round' ?></h2>
                            <?php if ($seasonRevealState): ?>
                                <p class="game-round-section-note">Everybody has voted. Results are locked in while this round stays visible until the vote deadline passes.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="game-round-list game-round-list-active<?= $seasonRevealState ? ' game-round-list-reveal' : '' ?>">
                        <?php $round = $activeRound; $showProgress = !$seasonRevealState; $showRevealPodium = $seasonRevealState; include __DIR__ . '/season_round_card.partial.php'; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($nextRounds)): ?>
                <div class="game-round-section game-round-section-next">
                    <div class="game-round-section-heading-wrap">
                        <h2>Next Rounds</h2>
                    </div>
                    <div class="game-round-list">
                        <?php foreach ($nextRounds as $round): ?>
                            <?php $showProgress = false; $showRevealPodium = false; include __DIR__ . '/season_round_card.partial.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <!-- ? PLAYLIST LINK ADDED HERE -->
			<div class="game-round-section">
				<div class="buttons">
					<a href="playlists.php" class="button-secondary">
						View <?= htmlspecialchars(mlGetLeagueName($pdo)) ?> Playlists
					</a>
				</div>
			</div>
            <?php endif; ?>

            <?php if (!empty($completedRounds)): ?>
                <div class="game-round-section game-round-section-completed">
                    <div class="game-round-section-heading-wrap game-round-section-heading-wrap-subtle">
                        <h2>Completed Rounds</h2>
                    </div>
                    <div class="game-round-list game-round-list-completed">
                        <?php foreach ($completedRounds as $round): ?>
                            <?php $showProgress = false; $showRevealPodium = false; include __DIR__ . '/season_round_card.partial.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function detectBrowserTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (error) {
            return 'UTC';
        }
    }

    function formatUtcSchedule(utcValue, timezone) {
        if (!utcValue) {
            return 'TBD';
        }

        const isoLike = utcValue.replace(' ', 'T') + 'Z';
        const date = new Date(isoLike);
        if (Number.isNaN(date.getTime())) {
            return 'TBD';
        }

        return new Intl.DateTimeFormat(undefined, {
            month: 'numeric',
            day: 'numeric',
            year: '2-digit',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZone: timezone
        }).format(date);
    }

    const timezone = detectBrowserTimezone();

    document.querySelectorAll('[data-utc-schedule-value]').forEach(function (node) {
        const value = node.getAttribute('data-utc-schedule-value') || '';
        const formatted = formatUtcSchedule(value, timezone);

        if (node.getAttribute('data-schedule-kind') === 'submit') {
            node.textContent = 'submit ' + formatted;
        } else if (node.getAttribute('data-schedule-kind') === 'vote') {
            node.textContent = 'vote by ' + formatted;
        } else {
            node.textContent = formatted;
        }
    });
});
</script>
</body>
</html>
