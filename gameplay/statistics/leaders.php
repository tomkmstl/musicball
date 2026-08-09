<?php
$leaderGroups = [];
foreach ($leaderMetrics as $metricKey => $metricLabel) {
    $metricStandings = $standings;
    usort($metricStandings, static function (array $a, array $b) use ($metricKey): int {
        $valueA = (int)($a[$metricKey] ?? 0);
        $valueB = (int)($b[$metricKey] ?? 0);
        if ($valueA !== $valueB) {
            return $valueB <=> $valueA;
        }

        foreach (mlGetOverallStandingsTieBreakerKeys() as $tieKey) {
            if ($tieKey === $metricKey) {
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

    $previousValue = null;
    $displayRank = 0;
    foreach ($metricStandings as $index => &$leaderRow) {
        $metricValue = (int)($leaderRow[$metricKey] ?? 0);
        if ($index === 0 || $metricValue !== $previousValue) {
            $displayRank = $index + 1;
            $previousValue = $metricValue;
        }
        $leaderRow['leader_rank'] = $displayRank;
        $leaderRow['leader_value'] = $metricValue;
    }
    unset($leaderRow);

    $leaderGroups[$metricKey] = [
        'key' => $metricKey,
        'label' => $metricLabel,
        'rows' => array_slice($metricStandings, 0, 5),
        'full_rows' => $metricStandings,
    ];
}

$selectedLeaderGroup = $leaderMetricKey !== '' && isset($leaderGroups[$leaderMetricKey])
    ? $leaderGroups[$leaderMetricKey]
    : null;
$leaderBoardUrl = mlLeagueLeaderUrl('', $showAllTimeStandings, $seasonRow);

function mlRenderLeaderRanking(array $leaderGroup): void
{
    ?>
    <section class="statistics-leader-detail-card" aria-label="<?= htmlspecialchars($leaderGroup['label']) ?> full rankings">
        <header class="statistics-leader-detail-header">
            <div class="home-shell-kicker">Full Rankings</div>
            <h2 class="statistics-leader-detail-title"><?= htmlspecialchars($leaderGroup['label']) ?></h2>
        </header>

        <div class="statistics-leader-list statistics-leader-detail-list" role="list">
            <?php foreach ($leaderGroup['full_rows'] as $leaderRow): ?>
                <?php $profileImagePath = !empty($leaderRow['profile_image_path']) ? $leaderRow['profile_image_path'] : mlGetUserProfilePath((int)$leaderRow['user_id']); ?>
                <div class="statistics-leader-row<?= !empty($leaderRow['is_current_user']) ? ' is-current-user' : '' ?>" role="listitem">
                    <span class="statistics-leader-rank" aria-label="Rank <?= (int)$leaderRow['leader_rank'] ?>"><?= (int)$leaderRow['leader_rank'] ?></span>
                    <img
                        src="<?= htmlspecialchars($profileImagePath) ?>"
                        alt=""
                        title="<?= htmlspecialchars($leaderRow['user_name']) ?>"
                        class="profile-avatar statistics-leader-avatar"
                    >
                    <span class="statistics-leader-name"><?= htmlspecialchars($leaderRow['user_name']) ?><?= !empty($leaderRow['is_current_user']) ? ' (You)' : '' ?></span>
                    <span class="statistics-leader-value" aria-label="<?= htmlspecialchars($leaderGroup['label']) ?>: <?= (int)$leaderRow['leader_value'] ?>"><?= (int)$leaderRow['leader_value'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}
?>

<?php if (!$hasFinalRounds || empty($standings)): ?>
    <div class="status-banner">No leaders are available yet.</div>
<?php else: ?>
    <section
        class="standings-section statistics-leaders-section"
        data-leaders-section
    >
        <div
            class="statistics-leaders-stage<?= $selectedLeaderGroup ? ' is-detail-open' : '' ?>"
            data-leaders-stage
            data-leaders-board-url="<?= htmlspecialchars($leaderBoardUrl) ?>"
            data-leaders-initial-metric="<?= htmlspecialchars($selectedLeaderGroup['key'] ?? '') ?>"
        >
            <div
                class="statistics-leaders-board-view"
                data-leaders-board
                <?= $selectedLeaderGroup ? 'aria-hidden="true" inert' : '' ?>
            >
                <div class="standings-section-header">
                    <h2 class="standings-section-title"><?= $showAllTimeStandings ? 'All-Time Leaders' : 'Season Leaders' ?></h2>
                </div>

                <div class="statistics-leaders-grid">
                    <?php foreach ($leaderGroups as $leaderGroup): ?>
                        <?php $leaderHeadingId = 'statistics-leader-' . $leaderGroup['key']; ?>
                        <section class="statistics-leader-card" aria-labelledby="<?= htmlspecialchars($leaderHeadingId) ?>">
                            <header class="statistics-leader-card-header">
                                <a
                                    href="<?= htmlspecialchars(mlLeagueLeaderUrl($leaderGroup['key'], $showAllTimeStandings, $seasonRow)) ?>"
                                    class="statistics-leader-card-link"
                                    data-leader-metric-link="<?= htmlspecialchars($leaderGroup['key']) ?>"
                                    aria-label="View the full <?= htmlspecialchars($leaderGroup['label']) ?> rankings"
                                >
                                    <h3 id="<?= htmlspecialchars($leaderHeadingId) ?>" class="statistics-leader-card-title"><?= htmlspecialchars($leaderGroup['label']) ?></h3>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="song-database-result-icon statistics-leader-card-icon" aria-hidden="true" focusable="false">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M12 9v-3.586a1 1 0 0 1 1.707 -.707l6.586 6.586a1 1 0 0 1 0 1.414l-6.586 6.586a1 1 0 0 1 -1.707 -.707v-3.586h-6v-6h6"></path>
                                        <path d="M3 9v6"></path>
                                    </svg>
                                </a>
                            </header>

                            <div class="statistics-leader-list" role="list">
                                <?php foreach ($leaderGroup['rows'] as $leaderRow): ?>
                                    <?php $profileImagePath = !empty($leaderRow['profile_image_path']) ? $leaderRow['profile_image_path'] : mlGetUserProfilePath((int)$leaderRow['user_id']); ?>
                                    <div class="statistics-leader-row<?= !empty($leaderRow['is_current_user']) ? ' is-current-user' : '' ?>" role="listitem">
                                        <span class="statistics-leader-rank" aria-label="Rank <?= (int)$leaderRow['leader_rank'] ?>"><?= (int)$leaderRow['leader_rank'] ?></span>
                                        <img
                                            src="<?= htmlspecialchars($profileImagePath) ?>"
                                            alt=""
                                            title="<?= htmlspecialchars($leaderRow['user_name']) ?>"
                                            class="profile-avatar statistics-leader-avatar"
                                        >
                                        <span class="statistics-leader-name"><?= htmlspecialchars($leaderRow['user_name']) ?><?= !empty($leaderRow['is_current_user']) ? ' (You)' : '' ?></span>
                                        <span class="statistics-leader-value" aria-label="<?= htmlspecialchars($leaderGroup['label']) ?>: <?= (int)$leaderRow['leader_value'] ?>"><?= (int)$leaderRow['leader_value'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>

            <div
                class="statistics-leaders-detail-view"
                data-leaders-detail
                <?= $selectedLeaderGroup ? '' : 'aria-hidden="true" inert' ?>
            >
                <div class="statistics-leaders-detail-inner">
                    <a
                        href="<?= htmlspecialchars($leaderBoardUrl) ?>"
                        class="song-database-back statistics-leaders-back"
                        data-leaders-back
                        aria-label="Back to season leaders"
                    >
                        <span class="song-database-back-visual">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="song-database-result-icon song-database-back-icon" aria-hidden="true" focusable="false">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                <path d="M12 9v-3.586a1 1 0 0 1 1.707 -.707l6.586 6.586a1 1 0 0 1 0 1.414l-6.586 6.586a1 1 0 0 1 -1.707 -.707v-3.586h-6v-6h6"></path>
                                <path d="M3 9v6"></path>
                            </svg>
                            <span>BACK</span>
                        </span>
                    </a>

                    <div data-leaders-detail-content>
                        <?php if ($selectedLeaderGroup): ?>
                            <?php mlRenderLeaderRanking($selectedLeaderGroup); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($leaderGroups as $leaderGroup): ?>
            <template data-leader-detail-template="<?= htmlspecialchars($leaderGroup['key']) ?>">
                <?php mlRenderLeaderRanking($leaderGroup); ?>
            </template>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
