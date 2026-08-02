<?php
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/discord/discord.php';

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
            header('Location: ' . mlUrl('season.php?season_id=' . $redirectSeasonId));
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['ml_season_error'] = $e->getMessage();
        $redirectSeasonId = $requestedSeasonId > 0 ? $requestedSeasonId : $seasonId;
        header('Location: ' . mlUrl('season.php?season_id=' . $redirectSeasonId));
        exit;
    }
}
$seasonList = mlLoadSeasonSummaries($pdo);
$seasonRow = mlLoadSeasonById($pdo, $selectedSeasonId);
$rounds = $seasonRow ? mlLoadSeasonRoundsForGameplay($pdo, $selectedSeasonId) : [];
$presentedRounds = $seasonRow ? mlComputeRoundPresentation($pdo, $rounds, (int)$currentUser['UserID']) : [];
$privatePlaylistStorageReady = mlPlaylistPinsTableAvailable($pdo);
$privatePlaylist = $privatePlaylistStorageReady
    ? mlLoadUserPrivatePlaylist($pdo, (int)$currentUser['UserID'])
    : null;
$privatePlaylistUrl = trim((string)($privatePlaylist['PlaylistURL'] ?? ''));

if ($seasonRow && mlMaybeAutoGeneratePlaylists($pdo, $presentedRounds, (int)$currentUser['UserID'])) {
    $_SESSION['ml_season_message'] = 'Playlist generated automatically.';
    header('Location: ' . mlUrl('season.php?season_id=' . $selectedSeasonId));
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
        <div class="game-page-topline game-page-topline-compact season-page-topline">
            <div class="game-page-intro">
				<div class="home-shell-kicker">Season</div>

				<h1 class="game-page-title">
					<?= htmlspecialchars(mlGetLeagueName($pdo)) ?>
				</h1>
			</div>
            <div class="season-page-actions">
                <?php if ($privatePlaylistUrl !== ''): ?>
                    <a
                        href="<?= htmlspecialchars($privatePlaylistUrl) ?>"
                        class="season-private-playlist-control"
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        aria-label="Open my private playlist"
                    >
                        <span class="season-private-playlist-icon" aria-hidden="true"></span>
                    </a>
                <?php else: ?>
                    <button
                        type="button"
                        class="season-private-playlist-control is-disabled"
                        aria-label="Private playlist not set"
                        aria-controls="season-private-playlist-message"
                        data-private-playlist-missing
                    >
                        <span class="season-private-playlist-icon" aria-hidden="true"></span>
                    </button>
                <?php endif; ?>

            <?php if (count($seasonList) > 1): ?>
				<details class="game-season-switcher-menu standings-season-switcher-menu season-page-season-switcher">
					<summary class="game-season-switcher-toggle standings-season-switcher-toggle" aria-label="Change season">
						<span class="standings-season-switcher-label">
							<span class="standings-season-switcher-season">
								<?= $seasonRow ? htmlspecialchars($seasonRow['SeasonName']) : 'Select Season' ?>
							</span>
						</span>
						<span class="standings-season-switcher-icon" aria-hidden="true">▾</span>
					</summary>

					<div class="game-season-switcher-panel standings-season-options-panel">
						<?php foreach ($seasonList as $seasonOption): ?>
							<a href="<?= htmlspecialchars(mlUrl('season.php?season_id=' . (int)$seasonOption['SeasonID'])) ?>" class="standings-season-option" data-mb-nav>
								<span class="standings-season-switcher-season">
									<?= htmlspecialchars($seasonOption['SeasonName']) ?><?= ((int)$seasonOption['RoundCount'] > 0) ? '' : ' - no rounds yet' ?>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endif; ?>
            </div>
        </div>

        <?php if ($privatePlaylistUrl === ''): ?>
            <div id="season-private-playlist-message" class="season-private-playlist-bubble" role="status" aria-live="polite" hidden>
                No playlist link found. Add playlist link in Settings
            </div>
        <?php endif; ?>

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
    const missingPlaylistButton = document.querySelector('[data-private-playlist-missing]');
    const missingPlaylistMessage = document.getElementById('season-private-playlist-message');
    let missingPlaylistTimer = null;

    if (missingPlaylistButton && missingPlaylistMessage) {
        missingPlaylistButton.addEventListener('click', function () {
            if (missingPlaylistTimer !== null) {
                window.clearTimeout(missingPlaylistTimer);
            }

            missingPlaylistMessage.hidden = false;
            window.requestAnimationFrame(function () {
                missingPlaylistMessage.classList.add('is-visible');
            });

            missingPlaylistTimer = window.setTimeout(function () {
                missingPlaylistMessage.classList.remove('is-visible');
                missingPlaylistTimer = window.setTimeout(function () {
                    missingPlaylistMessage.hidden = true;
                    missingPlaylistTimer = null;
                }, 200);
            }, 3400);
        });
    }

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
document.querySelectorAll('.standings-season-option').forEach(function (link) {
    link.addEventListener('click', function () {
        link.classList.add('is-pressed');

        document.body.classList.add('mb-page-leaving');

        document.querySelectorAll('.standings-season-option').forEach(function (otherLink) {
            otherLink.classList.add('is-loading');
        });
    });
});
</script>
</body>
</html>
