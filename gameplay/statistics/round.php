<?php if (!empty($roundBreakdown)): ?>
    <section class="standings-section standings-breakdown-section">
        <div class="standings-section-header">
            <h2 class="standings-section-title">Per-Round Breakdown</h2>
        </div>
        <div class="standings-table-wrap">
            <table class="standings-table standings-round-breakdown-table">
                <thead>
                    <tr>
                        <th class="standings-round-number-heading">Round</th>
                        <th class="standings-round-label-heading"></th>
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
                            <td class="standings-round-number-cell"><?= (int)$roundRow['round_number'] ?></td>
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
