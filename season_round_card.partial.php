<?php
$showRevealPodium = isset($showRevealPodium) ? (bool)$showRevealPodium : false;
$roundSongDraft = isset($round['song_draft']) && is_array($round['song_draft']) ? $round['song_draft'] : [];
$hasChosenSong = !empty($round['song_saved'])
    && in_array((string)($round['round_state'] ?? ''), ['submission', 'upcoming'], true)
    && trim((string)($roundSongDraft['title'] ?? '')) !== ''
    && trim((string)($roundSongDraft['artist'] ?? '')) !== '';
?>
<section class="game-round-card<?= !empty($activeRound) && (int)$round['SeasonRoundID'] === (int)$activeRound['SeasonRoundID'] ? ' game-round-card-active' : '' ?><?= ($round['status_key'] ?? '') === 'closed' ? ' game-round-card-completed' : '' ?>">
    <div class="game-round-card-top">
        <div>
            <div class="round-number">Round <?= (int)$round['RoundNumber'] ?></div>
            <div class="round-title"><?= htmlspecialchars($round['Title']) ?></div>
            <?php if (trim((string)$round['Tagline']) !== ''): ?>
                <div class="round-tag"><?= htmlspecialchars($round['Tagline']) ?></div>
            <?php endif; ?>
            <div class="round-schedule-inline">
                <span data-utc-schedule-value="<?= htmlspecialchars($round['songs_due_utc'] ?? '') ?>" data-schedule-kind="submit">submit <?= htmlspecialchars($round['songs_due_label'] ?? '') ?></span>
                <span class="round-schedule-separator"> · </span>
                <span data-utc-schedule-value="<?= htmlspecialchars($round['votes_due_utc'] ?? '') ?>" data-schedule-kind="vote">vote by <?= htmlspecialchars($round['votes_due_label'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <div class="game-round-detail-stack">
        <?php if ($showRevealPodium && !empty($round['podium_finishers'])): ?>
            <div class="game-round-reveal-podium" aria-label="Top finishers">
                <?php
                    $podiumByPlace = [];
                    foreach ($round['podium_finishers'] as $podiumFinisher) {
                        $podiumByPlace[$podiumFinisher['place_label']] = $podiumFinisher;
                    }
                    $podiumOrder = ['1st', '2nd', '3rd'];
                ?>
                <?php foreach ($podiumOrder as $placeLabel): ?>
                    <?php if (empty($podiumByPlace[$placeLabel])) { continue; } ?>
                    <?php $podiumFinisher = $podiumByPlace[$placeLabel]; ?>
                    <div class="game-round-reveal-place game-round-reveal-place-<?= $placeLabel === '1st' ? 'first' : ($placeLabel === '2nd' ? 'second' : 'third') ?>">
                        <div class="game-round-reveal-badge"><?= htmlspecialchars($podiumFinisher['place_label']) ?></div>
                        <img src="<?= htmlspecialchars($podiumFinisher['profile_image_path']) ?>" alt="<?= htmlspecialchars($podiumFinisher['user_name']) ?>" title="<?= htmlspecialchars($podiumFinisher['user_name']) ?>" class="profile-avatar profile-avatar-reveal">
                        <div class="game-round-reveal-name"><?= htmlspecialchars($podiumFinisher['user_name']) ?></div>
                        <div class="game-round-reveal-score"><?= (int)$podiumFinisher['total_score'] ?> pts</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($showProgress && (($round['round_state'] ?? '') === 'submission' || ($round['round_state'] ?? '') === 'voting')): ?>
            <?php
                $isSubmissionProgress = (($round['round_state'] ?? '') === 'submission');
                $progressCompletedAria = $isSubmissionProgress ? 'Submitted users' : 'Voted users';
                $progressPendingAria = $isSubmissionProgress ? 'Users still choosing songs' : 'Users still voting';
                $progressCompletedTitle = $isSubmissionProgress ? 'Submitted' : 'Voted';
                $progressPendingTitle = $isSubmissionProgress ? 'Still choosing' : 'Still voting';
                $progressCompletedIconSvg = file_get_contents(
					__DIR__ . '/assets/icons/' . ($isSubmissionProgress ? 'chosen-song.svg' : 'vote-complete.svg')
				);

				$progressPendingIconSvg = file_get_contents(
					__DIR__ . '/assets/icons/' . ($isSubmissionProgress ? 'searching.svg' : 'vote-pending.svg')
				);
            ?>
            <div class="game-round-progress" aria-label="Round progress">
                <div class="game-round-progress-line game-round-progress-line-avatar">
                    <span class="game-round-progress-status">
						<span class="game-round-progress-icon game-round-progress-icon-complete" aria-label="<?= htmlspecialchars($progressCompletedTitle) ?>" title="<?= htmlspecialchars($progressCompletedTitle) ?>"><?= $progressCompletedIconSvg ?></span>
						<span class="game-round-progress-status-label">Complete</span>
					</span>
                    <?php if (!empty($round['progress_completed_users'])): ?>
                        <div class="profile-avatar-list profile-avatar-list-progress" aria-label="<?= htmlspecialchars($progressCompletedAria) ?>">
                            <?php foreach ($round['progress_completed_users'] as $progressUser): ?>
                                <img src="<?= htmlspecialchars($progressUser['profile_image_path']) ?>" alt="<?= htmlspecialchars($progressUser['user_name']) ?>" title="<?= htmlspecialchars($progressUser['user_name']) ?>" class="profile-avatar profile-avatar-progress">
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="game-round-progress-empty" aria-label="No users yet">—</span>
                    <?php endif; ?>
                </div>
                <div class="game-round-progress-line game-round-progress-line-avatar">
                    <span class="game-round-progress-status">
						<span class="game-round-progress-icon game-round-progress-icon-pending" aria-label="<?= htmlspecialchars($progressPendingTitle) ?>" title="<?= htmlspecialchars($progressPendingTitle) ?>"><?= $progressPendingIconSvg ?></span>
						<span class="game-round-progress-status-label">Waiting</span>
					</span>
                    <?php if (!empty($round['progress_pending_users'])): ?>
                        <div class="profile-avatar-list profile-avatar-list-progress" aria-label="<?= htmlspecialchars($progressPendingAria) ?>">
                            <?php foreach ($round['progress_pending_users'] as $progressUser): ?>
                                <img src="<?= htmlspecialchars($progressUser['profile_image_path']) ?>" alt="<?= htmlspecialchars($progressUser['user_name']) ?>" title="<?= htmlspecialchars($progressUser['user_name']) ?>" class="profile-avatar profile-avatar-progress">
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="game-round-progress-empty" aria-label="No users yet">—</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($isAdminUser) && ($round['round_state'] ?? '') === 'submission' && !empty($round['can_manual_generate_playlist'])): ?>
        <div class="game-round-admin-build">
            <div class="note note-bottom-sm">
                Admin: this playlist can be generated now. This works either because everyone has already submitted, or because the Songs Due deadline has passed.
            </div>
            <form method="post" action="season.php?season_id=<?= (int)($round['SeasonID'] ?? 0) ?>">
                <input type="hidden" name="season_action" value="generate_current_playlist">
                <button type="submit" class="button-secondary">Generate Current Playlist</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="game-round-actions">
        <?php if (!empty($round['can_choose_song'])): ?>
            <a href="song.php?season_round_id=<?= (int)$round['SeasonRoundID'] ?>" class="game-round-action-link" aria-label="<?= $hasChosenSong ? 'Chosen Song' : 'Choose Song' ?>">
                <span class="game-round-action-icon" aria-hidden="true">
					<?php readfile(__DIR__ . '/assets/icons/' . ($hasChosenSong ? 'chosen-song.svg' : 'submit-song.svg')); ?>
				</span>
                <span class="game-round-action-label"><?= $hasChosenSong ? 'Chosen Song' : 'Choose Song' ?></span>
            </a>
        <?php elseif (($round['round_state'] ?? '') === 'submission'): ?>
            <span class="game-round-action-link game-round-action-link-disabled" aria-disabled="true" title="Song changes are closed for this round.">
                <span class="game-round-action-icon" aria-hidden="true">
					<?php readfile(__DIR__ . '/assets/icons/' . ($hasChosenSong ? 'chosen-song.svg' : 'submit-song.svg')); ?>
				</span>
                <span class="game-round-action-label"><?= $hasChosenSong ? 'Chosen Song' : 'Choose Song' ?></span>
            </span>
        <?php else: ?>
            <?php if (!empty($round['playlist_url'])): ?>
                <a href="<?= htmlspecialchars($round['playlist_url']) ?>" class="game-round-action-link" aria-label="Go To Playlist" target="_blank" rel="noopener noreferrer">
				<span class="game-round-action-icon" aria-hidden="true">
					<?php readfile(__DIR__ . '/assets/icons/build-playlist.svg'); ?>
				</span>
                    <span class="game-round-action-label">Playlist</span>
                </a>
            <?php else: ?>
                <span class="game-round-action-link game-round-action-link-disabled" aria-disabled="true" title="Playlist has not been generated yet">
					<span class="game-round-action-icon" aria-hidden="true">
						<?php readfile(__DIR__ . '/assets/icons/build-playlist.svg'); ?>
					</span>
                    <span class="game-round-action-label">Playlist</span>
                </span>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (($round['round_state'] ?? '') === 'voting' && !empty($round['vote_submitted'])): ?>
            <a href="results.php?season_round_id=<?= (int)$round['SeasonRoundID'] ?>" class="game-round-action-link" aria-label="Results">
                <span class="game-round-action-icon" aria-hidden="true">
					<?php readfile(__DIR__ . '/assets/icons/results.svg'); ?>
				</span>
                <span class="game-round-action-label">Results</span>
            </a>
        <?php elseif (($round['round_state'] ?? '') === 'voting'): ?>
            <a href="vote.php?season_round_id=<?= (int)$round['SeasonRoundID'] ?>" class="game-round-action-link" aria-label="Vote">
                <span class="game-round-action-icon" aria-hidden="true">
					<?php readfile(__DIR__ . '/assets/icons/vote.svg'); ?>
				</span>
                <span class="game-round-action-label">Vote</span>
            </a>
        <?php elseif (($round['round_state'] ?? '') === 'closed'): ?>
            <a href="results.php?season_round_id=<?= (int)$round['SeasonRoundID'] ?>" class="game-round-action-link" aria-label="Results">
                <span class="game-round-action-icon" aria-hidden="true">
					<?php readfile(__DIR__ . '/assets/icons/results.svg'); ?>
				</span>
                <span class="game-round-action-label">Results</span>
            </a>
        <?php else: ?>
            <span class="game-round-action-link game-round-action-link-disabled" aria-disabled="true">
                <span class="game-round-action-icon" aria-hidden="true">
					<?php readfile(__DIR__ . '/assets/icons/vote.svg'); ?>
				</span>
                <span class="game-round-action-label">Vote</span>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($hasChosenSong): ?>
        <div class="game-round-chosen-song" aria-label="Your chosen song">
            <span class="game-round-chosen-song-title"><?= htmlspecialchars((string)$roundSongDraft['title']) ?></span>
            <span class="game-round-chosen-song-separator">&nbsp;&middot;&nbsp;</span>
            <span class="game-round-chosen-song-artist"><?= htmlspecialchars((string)$roundSongDraft['artist']) ?></span>
        </div>
    <?php endif; ?>
</section>
