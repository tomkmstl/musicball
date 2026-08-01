<?php
$leaderMetrics = [
    'points' => 'Total Points',
    'round_wins' => 'Round Wins',
    'total_voters' => 'Total Voters',
    'podiums' => 'Podiums',
    'best_round_score' => 'Best Round',
    'holdouts' => 'Hold Outs',
];

$leaderGroups = [];
foreach ($leaderMetrics as $metricKey => $metricLabel) {
    $metricStandings = $standings;
    usort($metricStandings, static function (array $a, array $b) use ($metricKey): int {
        $valueA = (int)($a[$metricKey] ?? 0);
        $valueB = (int)($b[$metricKey] ?? 0);
        if ($valueA !== $valueB) {
            return $valueB <=> $valueA;
        }

        $tieBreakers = ['points', 'round_wins', 'total_voters', 'podiums', 'best_round_score', 'holdouts'];
        foreach ($tieBreakers as $tieKey) {
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

    $metricLeaders = array_slice($metricStandings, 0, 5);
    $previousValue = null;
    $displayRank = 0;
    foreach ($metricLeaders as $index => &$leaderRow) {
        $metricValue = (int)($leaderRow[$metricKey] ?? 0);
        if ($index === 0 || $metricValue !== $previousValue) {
            $displayRank = $index + 1;
            $previousValue = $metricValue;
        }
        $leaderRow['leader_rank'] = $displayRank;
        $leaderRow['leader_value'] = $metricValue;
    }
    unset($leaderRow);

    $leaderGroups[] = [
        'key' => $metricKey,
        'label' => $metricLabel,
        'rows' => $metricLeaders,
    ];
}
?>

<?php if (!$hasClosedRounds || empty($standings)): ?>
    <div class="status-banner">No leaders are available yet.</div>
<?php else: ?>
    <section class="standings-section statistics-leaders-section">
        <div class="standings-section-header">
            <h2 class="standings-section-title"><?= $showAllTimeStandings ? 'All-Time Leaders' : 'Season Leaders' ?></h2>
        </div>

        <div class="statistics-leaders-grid">
            <?php foreach ($leaderGroups as $leaderGroup): ?>
                <?php $leaderHeadingId = 'statistics-leader-' . $leaderGroup['key']; ?>
                <section class="statistics-leader-card" aria-labelledby="<?= htmlspecialchars($leaderHeadingId) ?>">
                    <header class="statistics-leader-card-header">
                        <h3 id="<?= htmlspecialchars($leaderHeadingId) ?>" class="statistics-leader-card-title"><?= htmlspecialchars($leaderGroup['label']) ?></h3>
                    </header>

                    <div class="statistics-leader-list" role="list">
                        <?php foreach ($leaderGroup['rows'] as $leaderRow): ?>
                            <?php $profileImagePath = !empty($leaderRow['profile_image_path']) ? $leaderRow['profile_image_path'] : mlGetUserProfilePath((int)$leaderRow['user_id']); ?>
                            <div class="statistics-leader-row<?= !empty($leaderRow['is_current_user']) ? ' is-current-user' : '' ?>" role="listitem">
                                <span class="statistics-leader-rank" aria-label="Rank <?= (int)$leaderRow['leader_rank'] ?>"><?= (int)$leaderRow['leader_rank'] ?></span>
                                <img
                                    src="<?= htmlspecialchars($profileImagePath) ?>"
                                    alt="<?= htmlspecialchars($leaderRow['user_name']) ?>"
                                    title="<?= htmlspecialchars($leaderRow['user_name']) ?>"
                                    class="profile-avatar statistics-leader-avatar"
                                >
                                <span class="statistics-leader-name"><?= htmlspecialchars($leaderRow['user_name']) ?><?= !empty($leaderRow['is_current_user']) ? ' (You)' : '' ?></span>
                                <span class="statistics-leader-value"><?= (int)$leaderRow['leader_value'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
