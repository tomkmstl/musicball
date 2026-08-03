<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'standings';

$statisticsView = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'standings';
$allowedStatisticsViews = ['standings', 'round', 'leaders', 'trends'];
if (!in_array($statisticsView, $allowedStatisticsViews, true)) {
    $statisticsView = 'standings';
}

$requestedSeasonKey = isset($_GET['season_id']) ? trim((string)$_GET['season_id']) : '';
$showAllTimeStandings = ($requestedSeasonKey === 'all');
$requestedSeasonId = $showAllTimeStandings ? 0 : (int)$requestedSeasonKey;
$selectedSeasonId = $showAllTimeStandings ? 0 : mlResolveGameplaySeasonId($pdo, $requestedSeasonId, $seasonId);
$seasonList = mlLoadSeasonSummaries($pdo);
$seasonRow = $showAllTimeStandings ? ['SeasonID' => 0, 'SeasonName' => 'All Time', 'IsActive' => 0] : mlLoadSeasonById($pdo, $selectedSeasonId);
$standingsData = $showAllTimeStandings
    ? mlBuildAllTimeStandingsData($pdo, (int)$currentUser['UserID'])
    : ($seasonRow ? mlBuildStandingsData($pdo, $selectedSeasonId, (int)$currentUser['UserID']) : ['standings' => [], 'round_breakdown' => []]);

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
$roundBreakdown = $showAllTimeStandings ? [] : ($standingsData['round_breakdown'] ?? []);
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

if (!empty($standings)) {
    usort($standings, static function (array $a, array $b) use ($sortKey, $sortDir): int {
        $valueA = (int)($a[$sortKey] ?? 0);
        $valueB = (int)($b[$sortKey] ?? 0);

        if ($valueA !== $valueB) {
            if ($sortDir === 'asc') {
                return $valueA <=> $valueB;
            }
            return $valueB <=> $valueA;
        }

        $tieBreakers = mlGetOverallStandingsTieBreakerKeys();
        foreach ($tieBreakers as $tieKey) {
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

            $standingRow['display_rank'] = ($sortDir === 'asc')
                ? ($rankCount - $groupStartIndex)
                : ($groupStartIndex + 1);
        }
        unset($standingRow);
    }
}

function mlStatisticsUrl(string $targetView, bool $showAllTimeStandings, $seasonRow, string $sortKey, string $sortDir): string
{
    $params = [
        'view' => $targetView,
    ];

    if ($showAllTimeStandings) {
        $params['season_id'] = 'all';
    } elseif ($seasonRow && isset($seasonRow['SeasonID'])) {
        $params['season_id'] = (int)$seasonRow['SeasonID'];
    }

    if ($targetView === 'standings') {
        $params['sort'] = $sortKey;
        $params['dir'] = $sortDir;
    }

    return 'statistics.php?' . http_build_query($params);
}

function mlStandingsSortUrl(string $targetSort, string $currentSort, string $currentDir, bool $showAllTimeStandings, $seasonRow): string
{
    $nextDir = ($currentSort === $targetSort && $currentDir === 'desc') ? 'asc' : 'desc';
    $params = [
        'view' => 'standings',
        'sort' => $targetSort,
        'dir' => $nextDir,
    ];

    if ($showAllTimeStandings) {
        $params['season_id'] = 'all';
    } elseif ($seasonRow && isset($seasonRow['SeasonID'])) {
        $params['season_id'] = (int)$seasonRow['SeasonID'];
    }

    return 'statistics.php?' . http_build_query($params);
}

$statisticsViewPath = __DIR__ . '/gameplay/statistics/' . $statisticsView . '.php';
if (!is_file($statisticsViewPath)) {
    $statisticsViewPath = __DIR__ . '/gameplay/statistics/standings.php';
    $statisticsView = 'standings';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Statistics</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="standings-section-nav">
    <a href="<?= htmlspecialchars(mlStatisticsUrl('standings', $showAllTimeStandings, $seasonRow, $sortKey, $sortDir)) ?>" class="standings-section-nav-link<?= $statisticsView === 'standings' ? ' standings-section-nav-link-active' : '' ?>">
        Standings
    </a>

    <a href="<?= htmlspecialchars(mlStatisticsUrl('round', $showAllTimeStandings, $seasonRow, $sortKey, $sortDir)) ?>" class="standings-section-nav-link<?= $statisticsView === 'round' ? ' standings-section-nav-link-active' : '' ?>">
        Round
    </a>

    <a href="<?= htmlspecialchars(mlStatisticsUrl('leaders', $showAllTimeStandings, $seasonRow, $sortKey, $sortDir)) ?>" class="standings-section-nav-link<?= $statisticsView === 'leaders' ? ' standings-section-nav-link-active' : '' ?>">
        Leaders
    </a>

    <a href="<?= htmlspecialchars(mlStatisticsUrl('trends', $showAllTimeStandings, $seasonRow, $sortKey, $sortDir)) ?>" class="standings-section-nav-link<?= $statisticsView === 'trends' ? ' standings-section-nav-link-active' : '' ?>">
        Trends
    </a>
</div>
<div class="wrapper">
    <div class="card game-card game-card-wide">
        <div class="game-page-topline game-page-topline-compact standings-page-topline">
            <details class="game-season-switcher-menu standings-season-switcher-menu">
                <summary class="game-season-switcher-toggle standings-season-switcher-toggle" aria-label="Change season">
                    <span class="standings-season-switcher-label">
                        <span class="standings-season-switcher-league"><?= htmlspecialchars(mlGetLeagueName($pdo)) ?></span>

                        <?php if ($seasonRow): ?>
                            <span class="standings-season-switcher-separator">•</span>

                            <span class="standings-season-switcher-season">
                                <?= htmlspecialchars($seasonRow['SeasonName']) ?>
                            </span>
                        <?php endif; ?>
                    </span>

                    <span class="standings-season-switcher-icon" aria-hidden="true">▾</span>
                </summary>

                <div class="game-season-switcher-panel standings-season-options-panel">
                    <a href="statistics.php?view=<?= htmlspecialchars($statisticsView) ?>&season_id=all&sort=<?= htmlspecialchars($sortKey) ?>&dir=<?= htmlspecialchars($sortDir) ?>" class="standings-season-option">
                        <span class="standings-season-switcher-league"><?= htmlspecialchars(mlGetLeagueName($pdo)) ?></span>
                        <span class="standings-season-switcher-separator">•</span>
                        <span class="standings-season-switcher-season">All Time</span>
                    </a>

                    <?php foreach ($seasonList as $seasonOption): ?>
                        <a href="statistics.php?view=<?= htmlspecialchars($statisticsView) ?>&season_id=<?= (int)$seasonOption['SeasonID'] ?>&sort=<?= htmlspecialchars($sortKey) ?>&dir=<?= htmlspecialchars($sortDir) ?>" class="standings-season-option">
                            <span class="standings-season-switcher-league"><?= htmlspecialchars(mlGetLeagueName($pdo)) ?></span>
                            <span class="standings-season-switcher-separator">•</span>
                            <span class="standings-season-switcher-season">
                                <?= htmlspecialchars($seasonOption['SeasonName']) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>

        <?php require $statisticsViewPath; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    if (!body) {
        return;
    }

    function applyStandingsLeavingState() {
        if (!body.classList.contains('mb-page-leaving')) {
            body.classList.add('mb-page-leaving');
        }
    }

    document.querySelectorAll('.standings-sort-link, .standings-section-nav-link, .standings-season-option').forEach(function (link) {
        link.addEventListener('pointerdown', function () {
            applyStandingsLeavingState();
        }, { passive: true });

        link.addEventListener('click', function () {
            applyStandingsLeavingState();
        });
    });
});
</script>
</body>
</html>
