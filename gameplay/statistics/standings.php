<?php
function mlTruncateAdvancedStandingsName(string $name, int $limit = 12): string
{
    if ($limit < 2) {
        return $name;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($name) > $limit
            ? rtrim(mb_substr($name, 0, $limit - 1)) . '…'
            : $name;
    }

    return strlen($name) > $limit
        ? rtrim(substr($name, 0, $limit - 1)) . '…'
        : $name;
}
?>

<?php if (!$hasFinalRounds || empty($standings)): ?>
    <div class="status-banner">No standings are available yet.</div>
<?php else: ?>
    <section class="standings-section">
        <div class="<?= $standingsMode === 'standard' ? 'standings-standard-shell' : '' ?>">
            <div class="standings-section-header standings-section-header-with-modes">
                <h2 class="standings-section-title"><?= $showAllTimeStandings ? 'All-Time Standings' : 'Season Standings' ?></h2>

                <nav class="standings-view-toggle" aria-label="Standings view">
                    <a
                        href="<?= htmlspecialchars(mlLeagueStandingsModeUrl('standard', $showAllTimeStandings, $seasonRow, $sortKey, $sortDir)) ?>"
                        class="standings-view-toggle-link<?= $standingsMode === 'standard' ? ' is-active' : '' ?>"
                        aria-label="Standard standings"
                        <?= $standingsMode === 'standard' ? 'aria-current="page"' : '' ?>
                    >STD</a>
                    <a
                        href="<?= htmlspecialchars(mlLeagueStandingsModeUrl('advanced', $showAllTimeStandings, $seasonRow, $sortKey, $sortDir)) ?>"
                        class="standings-view-toggle-link<?= $standingsMode === 'advanced' ? ' is-active' : '' ?>"
                        aria-label="Advanced standings"
                        <?= $standingsMode === 'advanced' ? 'aria-current="page"' : '' ?>
                    >ADV</a>
                </nav>
            </div>

            <?php if ($standingsMode === 'standard'): ?>
                <div class="statistics-leader-list standings-standard-list" role="list" aria-label="League standings by total points">
                    <?php foreach ($standings as $row): ?>
                        <?php $profileImagePath = !empty($row['profile_image_path']) ? $row['profile_image_path'] : mlGetUserProfilePath((int)$row['user_id']); ?>
                        <div class="statistics-leader-row standings-standard-row<?= !empty($row['is_current_user']) ? ' is-current-user' : '' ?>" role="listitem">
                            <span class="statistics-leader-rank" aria-label="Rank <?= (int)($row['display_rank'] ?? $row['rank']) ?>"><?= (int)($row['display_rank'] ?? $row['rank']) ?></span>
                            <img
                                src="<?= htmlspecialchars($profileImagePath) ?>"
                                alt=""
                                title="<?= htmlspecialchars($row['user_name']) ?>"
                                class="profile-avatar statistics-leader-avatar"
                            >
                            <span class="statistics-leader-name"><?= htmlspecialchars($row['user_name']) ?></span>
                            <span class="statistics-leader-value" aria-label="Total Points: <?= (int)$row['points'] ?>"><?= (int)$row['points'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="standings-table-wrap">
                    <table class="standings-table standings-table-expanded">
                        <thead>
                            <tr>
                                <th class="standings-rank-heading"><span class="game-visually-hidden">Rank</span></th>
                                <th class="standings-identity-heading"><span>Player</span></th>
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
                                    <?php $isActiveSort = ($sortKey === $columnKey); ?>
                                    <th class="standings-sortable-heading<?= $isActiveSort ? ' is-active-sort' : '' ?>">
                                        <a href="<?= htmlspecialchars(mlLeagueStandingsSortUrl($columnKey, $sortKey, $sortDir, $showAllTimeStandings, $seasonRow)) ?>" class="standings-sort-link<?= $isActiveSort ? ' is-active-sort' : '' ?>">
                                            <span><?= htmlspecialchars($columnLabel) ?></span>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($standings as $row): ?>
                                <?php
                                $profileImagePath = !empty($row['profile_image_path']) ? $row['profile_image_path'] : mlGetUserProfilePath((int)$row['user_id']);
                                $shortDisplayName = trim((string)($row['short_display_name'] ?? ''));
                                $fullPlayerLabel = $shortDisplayName !== '' ? $shortDisplayName : (string)$row['user_name'];
                                $displayPlayerLabel = mlTruncateAdvancedStandingsName($fullPlayerLabel);
                                ?>
                                <tr class="<?= !empty($row['is_current_user']) ? 'is-current-user' : '' ?>">
                                    <td class="standings-rank-cell" aria-label="Rank <?= (int)($row['display_rank'] ?? $row['rank']) ?>"><?= (int)($row['display_rank'] ?? $row['rank']) ?></td>
                                    <td class="standings-identity-cell">
                                        <div class="standings-identity">
                                            <img src="<?= htmlspecialchars($profileImagePath) ?>" alt="" title="<?= htmlspecialchars($row['user_name']) ?>" class="profile-avatar profile-avatar-standings">
                                            <span class="standings-player-name" title="<?= htmlspecialchars($fullPlayerLabel) ?>" aria-label="<?= htmlspecialchars($fullPlayerLabel) ?>"><?= htmlspecialchars($displayPlayerLabel) ?></span>
                                        </div>
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
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
