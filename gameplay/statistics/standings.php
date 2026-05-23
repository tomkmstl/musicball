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
<?php endif; ?>
