<?php
require_once __DIR__ . '/ml_gameplay.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'standings';

$requestedSeasonKey = isset($_GET['season_id']) ? trim((string)$_GET['season_id']) : '';
$showAllTimeStandings = ($requestedSeasonKey === 'all');
$requestedSeasonId = $showAllTimeStandings ? 0 : (int)$requestedSeasonKey;
$selectedSeasonId = $showAllTimeStandings ? 0 : mlResolveGameplaySeasonId($pdo, $requestedSeasonId, $seasonId);
$seasonList = mlLoadSeasonSummaries($pdo);
$seasonRow = $showAllTimeStandings ? ['SeasonID' => 0, 'SeasonName' => 'All Time', 'IsActive' => 0] : mlLoadSeasonById($pdo, $selectedSeasonId);
$standingsData = $showAllTimeStandings
    ? mlBuildAllTimeStandingsData($pdo, (int)$currentUser['UserID'])
    : ($seasonRow ? mlBuildStandingsData($pdo, $selectedSeasonId, (int)$currentUser['UserID']) : ['standings' => [], 'round_breakdown' => []]);

if (!$showAllTimeStandings && $requestedSeasonId <= 0 && $seasonRow && empty($standingsData['closed_round_count'])) {
    foreach ($seasonList as $seasonOption) {
        $fallbackSeasonId = (int)($seasonOption['SeasonID'] ?? 0);
        if ($fallbackSeasonId <= 0 || $fallbackSeasonId === (int)$selectedSeasonId) {
            continue;
        }

        $fallbackData = mlBuildStandingsData($pdo, $fallbackSeasonId, (int)$currentUser['UserID']);
        if (!empty($fallbackData['closed_round_count'])) {
            $selectedSeasonId = $fallbackSeasonId;
            $seasonRow = mlLoadSeasonById($pdo, $selectedSeasonId);
            $standingsData = $fallbackData;
            break;
        }
    }
}

$standings = $standingsData['standings'] ?? [];
$roundBreakdown = $showAllTimeStandings ? [] : ($standingsData['round_breakdown'] ?? []);
$hasClosedRounds = !empty($standingsData['closed_round_count']);

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

        $tieBreakers = ['points', 'round_wins', 'total_voters', 'podiums', 'best_round_score', 'holdouts'];
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

function mlStandingsSortUrl(string $targetSort, string $currentSort, string $currentDir, bool $showAllTimeStandings, $seasonRow): string
{
    $nextDir = ($currentSort === $targetSort && $currentDir === 'desc') ? 'asc' : 'desc';
    $params = [
        'sort' => $targetSort,
        'dir' => $nextDir,
    ];

    if ($showAllTimeStandings) {
        $params['season_id'] = 'all';
    } elseif ($seasonRow && isset($seasonRow['SeasonID'])) {
        $params['season_id'] = (int)$seasonRow['SeasonID'];
    }

    return 'standings.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Standings</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide">
        <div class="game-page-topline game-page-topline-compact">
            <div class="game-page-intro">
                <div class="home-shell-kicker">Standings</div>
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
                        <form method="get" action="standings.php" class="game-season-switcher-form">
                            <label class="game-season-switcher-label" for="season_id">View season</label>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortKey) ?>">
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir) ?>">
                            <select name="season_id" id="season_id" class="admin-input game-season-select" onchange="this.form.submit()">
                                <option value="all" <?= $showAllTimeStandings ? 'selected' : '' ?>>All Time</option>
                                <?php foreach ($seasonList as $seasonOption): ?>
                                    <option value="<?= (int)$seasonOption['SeasonID'] ?>" <?= !$showAllTimeStandings && $seasonRow && (int)$seasonRow['SeasonID'] === (int)$seasonOption['SeasonID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($seasonOption['SeasonName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>

        <?php if (!$hasClosedRounds || empty($standings)): ?>
            <div class="status-banner">No standings are available yet.</div>
        <?php else: ?>
            <section class="standings-section">
                <div class="standings-section-header">
                    <h2 class="standings-section-title"><?= $showAllTimeStandings ? 'All-Time Standings' : 'Season Standings' ?></h2>
                </div>
                <div class="standings-table-wrap">
                    <table class="standings-table standings-table-expanded">
                        <thead>
                            <tr>
                                <th class="standings-rank-heading"><span></span></th>
                                <th class="standings-avatar-heading"><span class="game-visually-hidden">Profile</span></th>
                                <th class="standings-player-heading"><span>Player</span></th>
                                <?php
                                $sortableColumns = [
                                    'points' => 'Total Points',
                                    'round_wins' => 'Round Wins',
                                    'total_voters' => 'Total Voters',
                                    'podiums' => 'Podiums',
                                    'best_round_score' => 'Best Round',
                                    'holdouts' => 'Hold Outs',
                                ];
                                ?>
                                <?php foreach ($sortableColumns as $columnKey => $columnLabel): ?>
                                    <?php
					$isActiveSort = ($sortKey === $columnKey);
					?>
					<th class="standings-sortable-heading<?= $isActiveSort ? ' is-active-sort' : '' ?>">
					    <a href="<?= htmlspecialchars(mlStandingsSortUrl($columnKey, $sortKey, $sortDir, $showAllTimeStandings, $seasonRow)) ?>" class="standings-sort-link<?= $isActiveSort ? ' is-active-sort' : '' ?>">
					        <span><?= htmlspecialchars($columnLabel) ?></span>
					    </a>
					</th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($standings as $row): ?>
                                <?php $profileImagePath = !empty($row['profile_image_path']) ? $row['profile_image_path'] : mlGetUserProfilePath((int)$row['user_id']); ?>
                                <tr class="<?= !empty($row['is_current_user']) ? 'is-current-user' : '' ?>">
                                    <td class="standings-rank-cell"><?= (int)($row['display_rank'] ?? $row['rank']) ?></td>
                                    <td class="standings-avatar-cell">
                                        <img src="<?= htmlspecialchars($profileImagePath) ?>" alt="<?= htmlspecialchars($row['user_name']) ?>" title="<?= htmlspecialchars($row['user_name']) ?>" class="profile-avatar profile-avatar-standings">
                                    </td>
                                    <td class="standings-player-name-cell">
                                        <span class="standings-player-name"><?= htmlspecialchars($row['user_name']) ?><?= !empty($row['is_current_user']) ? ' (You)' : '' ?></span>
                                    </td>
                                    <td><?= (int)$row['points'] ?></td>
                                    <td><?= (int)$row['round_wins'] ?></td>
                                    <td><?= (int)($row['total_voters'] ?? $row['positive_voter_total'] ?? 0) ?></td>
                                    <td><?= (int)$row['podiums'] ?></td>
                                    <td><?= (int)$row['best_round_score'] ?></td>
                                    <td><?= (int)$row['holdouts'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if (!empty($roundBreakdown)): ?>
                <section class="standings-section standings-breakdown-section">
                    <div class="standings-section-header">
                        <h2 class="standings-section-title">Per-Round Breakdown</h2>
                    </div>
                    <div class="standings-table-wrap">
                        <table class="standings-table standings-round-breakdown-table">
                            <thead>
                                <tr>
                                    <th>Round</th>
                                    <?php foreach ($standings as $player): ?>
                                        <?php $playerProfileImagePath = !empty($player['profile_image_path']) ? $player['profile_image_path'] : mlGetUserProfilePath((int)$player['user_id']); ?>
                                        <th class="<?= !empty($player['is_current_user']) ? 'is-current-user-heading' : '' ?>">
                                            <div class="standings-breakdown-heading">
                                                <img src="<?= htmlspecialchars($playerProfileImagePath) ?>" alt="<?= htmlspecialchars($player['user_name']) ?>" title="<?= htmlspecialchars($player['user_name']) ?>" class="profile-avatar profile-avatar-standings profile-avatar-standings-mini">
                                                <span><?= htmlspecialchars($player['user_name']) ?><?= !empty($player['is_current_user']) ? ' (You)' : '' ?></span>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roundBreakdown as $roundRow): ?>
                                    <tr>
                                        <td class="standings-round-label-cell">
                                            <div class="standings-round-label">Round <?= (int)$roundRow['round_number'] ?></div>
                                            <div class="standings-round-title"><?= htmlspecialchars($roundRow['title']) ?></div>
                                        </td>
                                        <?php foreach ($standings as $player): ?>
                                            <?php $playerCell = $roundRow['players'][(int)$player['user_id']] ?? null; ?>
                                            <td class="standings-round-score-cell <?= !empty($player['is_current_user']) ? 'is-current-user-cell' : '' ?> <?= !empty($playerCell['is_winner']) ? 'is-round-winner' : '' ?>">
                                                <?php if ($playerCell && $playerCell['points'] !== null): ?>
                                                    <div class="standings-round-score-value"><?= (int)$playerCell['points'] ?></div>
                                                    <div class="standings-round-score-meta"><?= (int)$playerCell['voter_count'] ?> voters</div>
                                                <?php else: ?>
                                                    <div class="standings-round-score-empty">-</div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
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

    document.querySelectorAll('.standings-sort-link').forEach(function (link) {
        link.addEventListener('pointerdown', function () {
            applyStandingsLeavingState();
        }, { passive: true });

        link.addEventListener('click', function () {
            applyStandingsLeavingState();
        });
    });

    var seasonSelect = document.getElementById('season_id');
    if (seasonSelect) {
        seasonSelect.addEventListener('change', function () {
            applyStandingsLeavingState();

            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    if (seasonSelect.form) {
                        if (typeof seasonSelect.form.requestSubmit === 'function') {
                            seasonSelect.form.requestSubmit();
                        } else {
                            seasonSelect.form.submit();
                        }
                    }
                });
            });
        });
    }

    var seasonForm = document.querySelector('.game-season-switcher-form');
    if (seasonForm) {
        seasonForm.addEventListener('submit', function () {
            applyStandingsLeavingState();
        });
    }
});
</script>
</body>
</html>
