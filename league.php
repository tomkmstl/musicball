<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'league';

$leagueView = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'songs';
$allowedLeagueViews = ['songs', 'standings', 'leaders', 'trends'];
if (!in_array($leagueView, $allowedLeagueViews, true)) {
    $leagueView = 'songs';
}

$leaderMetrics = [
    'points' => 'Total Points',
    'round_wins' => 'Round Wins',
    'total_voters' => 'Total Voters',
    'podiums' => 'Podiums',
    'best_round_score' => 'Best Round',
    'holdouts' => 'Hold Outs',
];
$leaderMetricKey = '';
if ($leagueView === 'leaders') {
    $requestedLeaderMetric = isset($_GET['metric']) ? strtolower(trim((string)$_GET['metric'])) : '';
    if (isset($leaderMetrics[$requestedLeaderMetric])) {
        $leaderMetricKey = $requestedLeaderMetric;
    }
}

$standingsMode = 'standard';
if ($leagueView === 'standings') {
    $requestedStandingsMode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
    if ($requestedStandingsMode === 'advanced') {
        $standingsMode = 'advanced';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leagueView = 'songs';
    $playlistAction = trim((string)($_POST['playlist_action'] ?? ''));

    try {
        $playlistUrl = '';

        if ($playlistAction === 'go_to_scone_ghetto') {
            $syncResult = mlCreateOrSyncSconeGhettoPlaylist($pdo);
            $playlistUrl = trim((string)($syncResult['SpotifyPlaylistURL'] ?? ''));

            if ($playlistUrl === '') {
                throw new RuntimeException('Spotify did not return a playlist URL for Scone Ghetto.');
            }
        } elseif ($playlistAction === 'go_to_player_playlist') {
            $playerUserId = (int)($_POST['playlist_user_id'] ?? 0);
            $syncResult = mlCreateOrSyncPlayerSongsPlaylist($pdo, $playerUserId);
            $playlistUrl = trim((string)($syncResult['SpotifyPlaylistURL'] ?? ''));
            $playerName = trim((string)($syncResult['PlaylistName'] ?? 'that playlist'));

            if ($playlistUrl === '') {
                throw new RuntimeException('Spotify did not return a playlist URL for ' . $playerName . '.');
            }
        }

        if ($playlistUrl !== '') {
            header('Location: ' . $playlistUrl);
            exit;
        }
    } catch (Throwable $e) {
        header('Location: ' . mlUrl('league.php?view=songs&status=error&message=' . rawurlencode(trim((string)$e->getMessage()))));
        exit;
    }
}

$leagueName = mlGetLeagueName($pdo);
$statusType = trim((string)($_GET['status'] ?? ''));
$statusMessage = trim((string)($_GET['message'] ?? ''));
if (!in_array($statusType, ['success', 'error'], true) || $statusMessage === '') {
    $statusType = '';
    $statusMessage = '';
}

$showAllTimeStandings = false;
$seasonList = [];
$seasonRow = null;
$standings = [];
$hasFinalRounds = false;
$sortKey = 'points';
$sortDir = 'desc';
$hasRequiredTables = false;
$sconeSongCount = 0;
$players = [];

if ($leagueView === 'songs') {
    $hasRequiredTables = (
        mlTableExists($pdo, 'ML_RoundPlaylists') &&
        mlTableExists($pdo, 'ML_RoundPlaylistItems') &&
        mlTableExists($pdo, 'ML_SeasonRounds') &&
        mlTableExists($pdo, 'ML_Users')
    );

    if ($hasRequiredTables) {
        mlGetAggregatePlaylistRecord($pdo, 'all_time', null, true);

        $sconeStmt = $pdo->query(
            "SELECT COUNT(*)
            FROM ML_RoundPlaylistItems rpi
            INNER JOIN ML_RoundPlaylists rp ON rp.RoundPlaylistID = rpi.RoundPlaylistID"
        );
        $sconeSongCount = $sconeStmt ? (int)$sconeStmt->fetchColumn() : 0;

        $usersStmt = $pdo->query(
            "SELECT UserID, UserName, ProfileImageFilename
            FROM ML_Users
            ORDER BY UserID ASC"
        );
        $allUsers = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($allUsers as $userRow) {
            $userId = (int)$userRow['UserID'];
            mlGetAggregatePlaylistRecord($pdo, 'player', $userId, true);
            $players[$userId] = [
                'user_id' => $userId,
                'user_name' => (string)$userRow['UserName'],
                'profile_image_path' => mlGetUserProfilePath($userId, $userRow['ProfileImageFilename'] ?? null),
                'song_count' => 0,
            ];
        }

        $playerCountsStmt = $pdo->query(
            "SELECT rpi.UserID, COUNT(*) AS SongCount
            FROM ML_RoundPlaylistItems rpi
            INNER JOIN ML_RoundPlaylists rp ON rp.RoundPlaylistID = rpi.RoundPlaylistID
            INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rp.SeasonRoundID
            WHERE (
                sr.RoundState = 'closed'
                OR (sr.VotesDue IS NOT NULL AND sr.VotesDue < UTC_TIMESTAMP())
            )
            GROUP BY rpi.UserID
            ORDER BY rpi.UserID ASC"
        );
        $playerCounts = $playerCountsStmt ? $playerCountsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($playerCounts as $countRow) {
            $userId = (int)$countRow['UserID'];
            if (isset($players[$userId])) {
                $players[$userId]['song_count'] = (int)$countRow['SongCount'];
            }
        }
    }
} else {
    $requestedSeasonKey = isset($_GET['season_id']) ? trim((string)$_GET['season_id']) : '';
    $showAllTimeStandings = ($requestedSeasonKey === 'all');
    $requestedSeasonId = $showAllTimeStandings ? 0 : (int)$requestedSeasonKey;
    $selectedSeasonId = $showAllTimeStandings ? 0 : mlResolveGameplaySeasonId($pdo, $requestedSeasonId, $seasonId);
    $seasonList = mlLoadSeasonSummaries($pdo);
    $seasonRow = $showAllTimeStandings ? ['SeasonID' => 0, 'SeasonName' => 'All Time', 'IsActive' => 0] : mlLoadSeasonById($pdo, $selectedSeasonId);
    $standingsData = $showAllTimeStandings
        ? mlBuildAllTimeStandingsData($pdo, (int)$currentUser['UserID'])
        : ($seasonRow ? mlBuildStandingsData($pdo, $selectedSeasonId, (int)$currentUser['UserID']) : ['standings' => []]);

    if (!$showAllTimeStandings && $requestedSeasonId <= 0 && $seasonRow && empty($standingsData['final_round_count'])) {
        foreach ($seasonList as $seasonOption) {
            $fallbackSeasonId = (int)($seasonOption['SeasonID'] ?? 0);
            if ($fallbackSeasonId <= 0 || $fallbackSeasonId === (int)$selectedSeasonId) {
                continue;
            }

            $fallbackData = mlBuildStandingsData($pdo, $fallbackSeasonId, (int)$currentUser['UserID']);
            if (!empty($fallbackData['final_round_count'])) {
                $selectedSeasonId = $fallbackSeasonId;
                $seasonRow = mlLoadSeasonById($pdo, $selectedSeasonId);
                $standingsData = $fallbackData;
                break;
            }
        }
    }

    $standings = $standingsData['standings'] ?? [];
    $hasFinalRounds = !empty($standingsData['final_round_count']);

    $allowedSorts = [
        'points' => 'points',
        'round_wins' => 'round_wins',
        'total_voters' => 'total_voters',
        'podiums' => 'podiums',
        'best_round_score' => 'best_round_score',
        'holdouts' => 'holdouts',
    ];
    $sortKey = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'points';
    if (!isset($allowedSorts[$sortKey])) {
        $sortKey = 'points';
    }
    $sortDir = isset($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : 'desc';
    if ($sortDir !== 'asc' && $sortDir !== 'desc') {
        $sortDir = 'desc';
    }

    if ($leagueView === 'standings' && $standingsMode === 'standard') {
        $sortKey = 'points';
        $sortDir = 'desc';
    }

    if (!empty($standings)) {
        usort($standings, static function (array $a, array $b) use ($sortKey, $sortDir): int {
            $valueA = (int)($a[$sortKey] ?? 0);
            $valueB = (int)($b[$sortKey] ?? 0);

            if ($valueA !== $valueB) {
                return $sortDir === 'asc' ? ($valueA <=> $valueB) : ($valueB <=> $valueA);
            }

            foreach (mlGetOverallStandingsTieBreakerKeys() as $tieKey) {
                if ($tieKey === $sortKey) {
                    continue;
                }
                $tieA = (int)($a[$tieKey] ?? 0);
                $tieB = (int)($b[$tieKey] ?? 0);
                if ($tieA !== $tieB) {
                    return $tieB <=> $tieA;
                }
            }

            return strcasecmp((string)($a['user_name'] ?? ''), (string)($b['user_name'] ?? ''));
        });

        if ($sortKey === 'points' && $sortDir === 'desc') {
            foreach ($standings as &$standingRow) {
                $standingRow['display_rank'] = (int)($standingRow['rank'] ?? 0);
            }
            unset($standingRow);
        } else {
            $rankCount = count($standings);
            $groupStartIndex = 0;
            $lastSortValue = null;

            foreach ($standings as $index => &$standingRow) {
                $currentSortValue = (int)($standingRow[$sortKey] ?? 0);
                if ($index === 0 || $currentSortValue !== $lastSortValue) {
                    $groupStartIndex = $index;
                    $lastSortValue = $currentSortValue;
                }

                $standingRow['display_rank'] = $sortDir === 'asc'
                    ? ($rankCount - $groupStartIndex)
                    : ($groupStartIndex + 1);
            }
            unset($standingRow);
        }
    }
}

function mlLeagueUrl(string $targetView, bool $showAllTimeStandings, $seasonRow, string $sortKey, string $sortDir, string $standingsMode): string
{
    $params = ['view' => $targetView];

    if ($targetView !== 'songs') {
        if ($showAllTimeStandings) {
            $params['season_id'] = 'all';
        } elseif ($seasonRow && isset($seasonRow['SeasonID'])) {
            $params['season_id'] = (int)$seasonRow['SeasonID'];
        }
    }

    if ($targetView === 'standings') {
        $params['mode'] = $standingsMode;
        if ($standingsMode === 'advanced') {
            $params['sort'] = $sortKey;
            $params['dir'] = $sortDir;
        }
    }

    return mlUrl('league.php?' . http_build_query($params));
}

function mlLeagueStandingsSortUrl(string $targetSort, string $currentSort, string $currentDir, bool $showAllTimeStandings, $seasonRow): string
{
    $nextDir = ($currentSort === $targetSort && $currentDir === 'desc') ? 'asc' : 'desc';
    $params = [
        'view' => 'standings',
        'mode' => 'advanced',
        'sort' => $targetSort,
        'dir' => $nextDir,
    ];

    if ($showAllTimeStandings) {
        $params['season_id'] = 'all';
    } elseif ($seasonRow && isset($seasonRow['SeasonID'])) {
        $params['season_id'] = (int)$seasonRow['SeasonID'];
    }

    return mlUrl('league.php?' . http_build_query($params));
}

function mlLeagueStandingsModeUrl(string $targetMode, bool $showAllTimeStandings, $seasonRow, string $sortKey, string $sortDir): string
{
    $params = [
        'view' => 'standings',
        'mode' => $targetMode === 'advanced' ? 'advanced' : 'standard',
    ];

    if ($showAllTimeStandings) {
        $params['season_id'] = 'all';
    } elseif ($seasonRow && isset($seasonRow['SeasonID'])) {
        $params['season_id'] = (int)$seasonRow['SeasonID'];
    }

    if ($targetMode === 'advanced') {
        $params['sort'] = $sortKey;
        $params['dir'] = $sortDir;
    }

    return mlUrl('league.php?' . http_build_query($params));
}

function mlLeagueLeaderUrl(string $metricKey, bool $showAllTimeStandings, $seasonRow): string
{
    $params = ['view' => 'leaders'];

    if ($showAllTimeStandings) {
        $params['season_id'] = 'all';
    } elseif ($seasonRow && isset($seasonRow['SeasonID'])) {
        $params['season_id'] = (int)$seasonRow['SeasonID'];
    }

    if ($metricKey !== '') {
        $params['metric'] = $metricKey;
    }

    return mlUrl('league.php?' . http_build_query($params));
}

function mlLeagueSeasonUrl($seasonId, string $leagueView, string $sortKey, string $sortDir, string $leaderMetricKey, string $standingsMode): string
{
    $params = [
        'view' => $leagueView,
        'season_id' => $seasonId,
    ];

    if ($leagueView === 'standings') {
        $params['mode'] = $standingsMode;
        if ($standingsMode === 'advanced') {
            $params['sort'] = $sortKey;
            $params['dir'] = $sortDir;
        }
    } elseif ($leagueView === 'leaders' && $leaderMetricKey !== '') {
        $params['metric'] = $leaderMetricKey;
    }

    return mlUrl('league.php?' . http_build_query($params));
}

$leagueViewPaths = [
    'songs' => __DIR__ . '/gameplay/league/songs.php',
    'standings' => __DIR__ . '/gameplay/statistics/standings.php',
    'leaders' => __DIR__ . '/gameplay/statistics/leaders.php',
    'trends' => __DIR__ . '/gameplay/statistics/trends.php',
];
$leagueViewPath = $leagueViewPaths[$leagueView];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - League</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<nav class="league-section-nav" aria-label="League sections">
    <?php foreach (['songs' => 'Songs', 'standings' => 'Standings', 'leaders' => 'Leaders', 'trends' => 'Trends'] as $viewKey => $viewLabel): ?>
        <a href="<?= htmlspecialchars(mlLeagueUrl($viewKey, $showAllTimeStandings, $seasonRow, $sortKey, $sortDir, $standingsMode)) ?>" class="league-section-nav-link<?= $leagueView === $viewKey ? ' league-section-nav-link-active' : '' ?>">
            <?= htmlspecialchars($viewLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
<div class="wrapper<?= $leagueView === 'songs' ? ' playlists-page library-page' : '' ?>">
    <div class="card game-card game-card-wide">
        <?php if ($leagueView !== 'songs'): ?>
            <div class="game-page-topline game-page-topline-compact standings-page-topline">
                <details class="game-season-switcher-menu standings-season-switcher-menu">
                    <summary class="game-season-switcher-toggle standings-season-switcher-toggle" aria-label="Change season">
                        <span class="standings-season-switcher-label">
                            <span class="standings-season-switcher-league"><?= htmlspecialchars($leagueName) ?></span>

                            <?php if ($seasonRow): ?>
                                <span class="standings-season-switcher-separator">&bull;</span>
                                <span class="standings-season-switcher-season"><?= htmlspecialchars($seasonRow['SeasonName']) ?></span>
                            <?php endif; ?>
                        </span>

                        <span class="standings-season-switcher-icon" aria-hidden="true">&#9662;</span>
                    </summary>

                    <div class="game-season-switcher-panel standings-season-options-panel">
                        <a href="<?= htmlspecialchars(mlLeagueSeasonUrl('all', $leagueView, $sortKey, $sortDir, $leaderMetricKey, $standingsMode)) ?>" class="standings-season-option">
                            <span class="standings-season-switcher-league"><?= htmlspecialchars($leagueName) ?></span>
                            <span class="standings-season-switcher-separator">&bull;</span>
                            <span class="standings-season-switcher-season">All Time</span>
                        </a>

                        <?php foreach ($seasonList as $seasonOption): ?>
                            <a href="<?= htmlspecialchars(mlLeagueSeasonUrl((int)$seasonOption['SeasonID'], $leagueView, $sortKey, $sortDir, $leaderMetricKey, $standingsMode)) ?>" class="standings-season-option">
                                <span class="standings-season-switcher-league"><?= htmlspecialchars($leagueName) ?></span>
                                <span class="standings-season-switcher-separator">&bull;</span>
                                <span class="standings-season-switcher-season"><?= htmlspecialchars($seasonOption['SeasonName']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        <?php endif; ?>

        <?php require $leagueViewPath; ?>
    </div>
</div>

<?php if ($leagueView === 'songs'): ?>
    <script src="<?= htmlspecialchars(mlAssetUrl('assets/js/song_database.js')) ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var playlistForms = Array.prototype.slice.call(document.querySelectorAll('.playlist-cta-form'));
        if (!playlistForms.length) {
            return;
        }

        function clearPlaylistLoadingState() {
            document.body.classList.remove('playlist-page-loading');
            playlistForms.forEach(function (form) {
                var button = form.querySelector('.playlist-cta-button');
                if (button) {
                    button.classList.remove('is-pressed', 'is-loading');
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                }
            });
        }

        playlistForms.forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('.playlist-cta-button');
                document.body.classList.add('playlist-page-loading');

                playlistForms.forEach(function (otherForm) {
                    var otherButton = otherForm.querySelector('.playlist-cta-button');
                    if (!otherButton) {
                        return;
                    }

                    if (otherButton === button) {
                        otherButton.classList.add('is-pressed', 'is-loading');
                        otherButton.setAttribute('aria-busy', 'true');
                    } else {
                        otherButton.classList.remove('is-pressed');
                        otherButton.classList.add('is-loading');
                    }

                    otherButton.disabled = true;
                });

                window.setTimeout(clearPlaylistLoadingState, 1800);
            });
        });
    });
    </script>
<?php endif; ?>
<?php if ($leagueView === 'leaders'): ?>
    <script src="<?= htmlspecialchars(mlAssetUrl('assets/js/league_leaders.js')) ?>"></script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    if (!body) {
        return;
    }

    function applyLeagueLeavingState() {
        body.classList.add('mb-page-leaving');
    }

    document.querySelectorAll('.standings-sort-link, .standings-view-toggle-link, .league-section-nav-link, .standings-season-option').forEach(function (link) {
        link.addEventListener('pointerdown', applyLeagueLeavingState, { passive: true });
        link.addEventListener('click', applyLeagueLeavingState);
    });
});
</script>
</body>
</html>
