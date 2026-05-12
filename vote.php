<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'season';
$currentUserId = (int)$currentUser['UserID'];
$seasonRoundId = isset($_GET['season_round_id']) ? (int)$_GET['season_round_id'] : (isset($_POST['season_round_id']) ? (int)$_POST['season_round_id'] : 0);
$round = $seasonRoundId > 0 ? mlFindRoundById($pdo, $seasonRoundId) : null;

if (!$round) {
    header('Location: season.php');
    exit;
}

$presentation = mlComputeRoundPresentation($pdo, [$round], $currentUserId);
$roundView = $presentation[0];
$ballot = mlBuildVotingBallot($pdo, (int)$round['SeasonID'], $seasonRoundId, $currentUserId);
$voteDraft = mlGetRoundVoteDraft($currentUserId, (int)$round['SeasonID'], $seasonRoundId);
$ownSong = mlGetRoundSongDraft($pdo, $currentUserId, (int)$round['SeasonID'], $seasonRoundId);
$message = '';
$error = '';
$canEditVotes = !empty($roundView['can_vote']) && empty($roundView['vote_submitted']);
$ownSongEntryId = !empty($ownSong['round_song_id']) ? 'entry_' . (int)$ownSong['round_song_id'] : '';

$totalVoteCapacity = max(1, mlGetIntSetting($pdo, 'votes_per_round', 12));
$maxVotesPerSongRaw = mlGetIntSetting($pdo, 'vote_max_per_song', 0);
$maxPerSong = ($maxVotesPerSongRaw <= 0) ? $totalVoteCapacity : min($maxVotesPerSongRaw, $totalVoteCapacity);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$roundView['can_vote']) {
        $error = 'Voting for this round is not open in the current round stage.';
    } elseif (!empty($roundView['vote_submitted'])) {
        $error = 'You already submitted votes for this round.';
    } else {
        $scores = isset($_POST['scores']) && is_array($_POST['scores']) ? $_POST['scores'] : [];
        $comments = isset($_POST['comments']) && is_array($_POST['comments']) ? $_POST['comments'] : [];
        $entries = [];
        $allocatedVotes = 0;

        foreach ($ballot as $entry) {
            $entryId = (string)$entry['entry_id'];

            if (empty($entry['can_score'])) {
                continue;
            }

            $score = isset($scores[$entryId]) ? (int)$scores[$entryId] : 0;
            $score = max(0, min($maxPerSong, $score));
            $allocatedVotes += $score;

            $entries[$entryId] = [
                'score' => $score,
                'comment' => isset($comments[$entryId]) ? trim((string)$comments[$entryId]) : '',
            ];
        }

        $songComment = trim((string)($_POST['song_comment'] ?? ''));
        $markSubmitted = isset($_POST['vote_action']) && $_POST['vote_action'] === 'submit_votes';

        if ($allocatedVotes > $totalVoteCapacity) {
            $error = 'You assigned more than the allowed total votes for this round.';
        } elseif ($markSubmitted && $allocatedVotes !== $totalVoteCapacity) {
            $error = 'You must use all available votes before submitting.';
        } else {
            mlSaveRoundVoteDraft($currentUserId, (int)$round['SeasonID'], $seasonRoundId, ['entries' => $entries], $markSubmitted);

            if (!empty($ownSong)) {
                mlSaveRoundSongComment($currentUserId, (int)$round['SeasonID'], $seasonRoundId, $songComment);
            }

            if ($markSubmitted) {
                header('Location: season.php');
                exit;
            }

            $message = 'Your progress has been saved.';
        }
    }

    if ($error === '') {
        $presentation = mlComputeRoundPresentation($pdo, [$round], $currentUserId);
        $roundView = $presentation[0];
        $canEditVotes = !empty($roundView['can_vote']) && empty($roundView['vote_submitted']);
        $voteDraft = mlGetRoundVoteDraft($currentUserId, (int)$round['SeasonID'], $seasonRoundId);
        $ballot = mlBuildVotingBallot($pdo, (int)$round['SeasonID'], $seasonRoundId, $currentUserId);
        $ownSong = mlGetRoundSongDraft($pdo, $currentUserId, (int)$round['SeasonID'], $seasonRoundId);
    } else {
        $voteDraft = ['entries' => $entries];
        if (!empty($ownSong)) {
            $ownSong['comment'] = trim((string)($_POST['song_comment'] ?? ($ownSong['comment'] ?? '')));
        }
    }
}

$allocatedVotes = 0;
foreach ($ballot as $entry) {
    if (empty($entry['can_score'])) {
        continue;
    }
    $entryId = (string)$entry['entry_id'];
    $savedEntry = isset($voteDraft['entries'][$entryId]) && is_array($voteDraft['entries'][$entryId]) ? $voteDraft['entries'][$entryId] : ['score' => 0, 'comment' => ''];
    $allocatedVotes += max(0, min($maxPerSong, (int)($savedEntry['score'] ?? 0)));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Vote</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide game-card-narrow">
        <div class="game-page-intro game-round-page-intro">
            <div class="home-shell-kicker">Round Voting</div>
            <?php if (trim((string)$round['Title']) !== ''): ?>
                <h1 class="game-page-title"><?= htmlspecialchars($round['Title']) ?></h1>
                <?php if (trim((string)$round['Tagline']) !== ''): ?>
                    <p><?= htmlspecialchars($round['Tagline']) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <h1 class="game-page-title">Round Voting</h1>
            <?php endif; ?>
        </div>

        <?php if (!empty($roundView['vote_submitted'])): ?>
            <div class="status-banner">Your votes for this round have already been submitted and can no longer be changed.</div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="vote.php?season_round_id=<?= (int)$seasonRoundId ?>" class="vote-form-shell vote-form-shell-questions" id="round-vote-form">
            <input type="hidden" name="season_round_id" value="<?= (int)$seasonRoundId ?>">

            <div class="step-header vote-step-header">
                <h2>total votes given</h2>
                <div class="counter-value counter-value-q1 changed" id="round-vote-total"><?= (int)$allocatedVotes ?> / <?= (int)$totalVoteCapacity ?></div>
            </div>
            <div class="vote-progress-meta">
				<div class="vote-progress-bar">
					<div
						class="vote-progress-bar-fill"
						id="vote-progress-bar-fill"
						style="width: <?= min(100, round(($allocatedVotes / max(1, $totalVoteCapacity)) * 100)) ?>%;"
					></div>
				</div>

				<div class="vote-progress-copy">
					You can give up to <?= (int)$totalVoteCapacity ?> votes.
					Max <?= ($maxVotesPerSongRaw <= 0) ? '∞' : (int)$maxPerSong ?> per song.
				</div>
			</div>

            <div class="vote-song-list vote-song-list-questions">
                <?php foreach ($ballot as $entry): ?>
                    <?php
                    $entryId = (string)$entry['entry_id'];
                    $isOwnSong = !empty($entry['is_current_user_song']);
                    $savedEntry = isset($voteDraft['entries'][$entryId]) && is_array($voteDraft['entries'][$entryId]) ? $voteDraft['entries'][$entryId] : ['score' => 0, 'comment' => ''];
                    $savedScore = max(0, min($maxPerSong, (int)($savedEntry['score'] ?? 0)));
                    $ownSongCommentValue = $isOwnSong ? trim((string)($ownSong['comment'] ?? $entry['comment'] ?? '')) : '';
                    $ownVoteCommentValue = $isOwnSong ? trim((string)($savedEntry['comment'] ?? '')) : '';
                    ?>
                    <section class="game-song-entry vote-ballot-item<?= $isOwnSong ? ' vote-ballot-item-own' : '' ?>" data-entry-id="<?= htmlspecialchars($entryId) ?>">
                        <div class="game-song-entry-main vote-ballot-main">
                            <div class="vote-ballot-songline">
                                <img src="<?= htmlspecialchars($entry['artwork']) ?>" alt="Album art" class="vote-ballot-art">
                                <div class="vote-ballot-copy">
									<div class="vote-ballot-title">
										<?= htmlspecialchars($entry['title']) ?>
										<?php if ($isOwnSong): ?>
											<span class="your-song-badge">your song</span>
										<?php endif; ?>
									</div>

									<div class="vote-ballot-artist">
										<?= htmlspecialchars($entry['artist']) ?>
									</div>

									<?php if (trim((string)$entry['album']) !== ''): ?>
										<div class="vote-ballot-album">
											<?= htmlspecialchars($entry['album']) ?>
										</div>
									<?php endif; ?>
								</div>
                            </div>

                            <?php if ($isOwnSong): ?>
                                <div class="vote-ballot-comment-wrap vote-ballot-comment-wrap-own">
                                    <label class="admin-label" for="song_comment">Song comment</label>
                                    <textarea name="song_comment" id="song_comment" class="vote-comment-input song-comment-input" rows="4" readonly ><?= htmlspecialchars($ownSongCommentValue) ?></textarea>
                                </div>
                                <div class="vote-ballot-comment-wrap vote-ballot-comment-wrap-own vote-ballot-comment-wrap-own-vote">
                                    <label class="admin-label" for="own_vote_comment">Your voting comment</label>
                                    <textarea name="own_vote_comment" id="own_vote_comment" class="vote-comment-input" rows="3" <?= !$canEditVotes ? 'disabled' : '' ?>><?= htmlspecialchars($ownVoteCommentValue) ?></textarea>
                                    <div class="note your-song-note">You cannot vote for your own song.</div>
                                </div>
                            <?php else: ?>
                                <div class="vote-ballot-comment-wrap vote-ballot-comment-wrap-modern">
									<div class="vote-comment-shell">
										<textarea
											name="comments[<?= htmlspecialchars($entryId) ?>]"
											id="comment_<?= htmlspecialchars($entryId) ?>"
											class="vote-comment-input vote-comment-input-modern"
											rows="4"
											maxlength="800"
											placeholder="Add a comment (optional)..."
											<?= !$canEditVotes ? 'disabled' : '' ?>
										><?= htmlspecialchars((string)($savedEntry['comment'] ?? '')) ?></textarea>

										<div class="vote-comment-counter">
											<span class="vote-comment-counter-value">0</span>/800
										</div>
									</div>
								</div>
                            <?php endif; ?>
                        </div>

                        <?php if (!$isOwnSong): ?>
                            <div class="points-control vote-points-control" data-entry-id="<?= htmlspecialchars($entryId) ?>">
                                <?php
									$isLightTheme = (($themePreference ?? 'dark') === 'light');

									$minusIcon = $isLightTheme
										? 'square-rounded-minus-light.svg'
										: 'square-rounded-minus.svg';

									$plusIcon = $isLightTheme
										? 'square-rounded-plus-light.svg'
										: 'square-rounded-plus.svg';
								?>

								<button type="button" class="points-btn minus vote-points-btn" aria-label="Remove one point" <?= !$canEditVotes ? 'disabled' : '' ?>>
									<?php readfile(__DIR__ . '/assets/icons/' . $minusIcon); ?>
								</button>

								<span class="points-value vote-points-value"><?= (int)$savedScore ?></span>

								<button type="button" class="points-btn plus vote-points-btn" aria-label="Add one point" <?= !$canEditVotes ? 'disabled' : '' ?>>
									<?php readfile(__DIR__ . '/assets/icons/' . $plusIcon); ?>
								</button>
                            </div>
                            <input type="hidden" name="scores[<?= htmlspecialchars($entryId) ?>]" id="score_<?= htmlspecialchars($entryId) ?>" value="<?= (int)$savedScore ?>">
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="game-form-actions vote-form-actions-simple">
                <button type="submit" name="vote_action" value="save_draft" class="button-secondary" <?= !$canEditVotes ? 'disabled' : '' ?>>Save Progress</button>
                <button type="submit" name="vote_action" value="submit_votes" class="button-primary" id="submit-votes-button" <?= (!$canEditVotes || $allocatedVotes !== $totalVoteCapacity) ? 'disabled' : '' ?>>Submit Votes</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const form = document.getElementById('round-vote-form');
    if (!form) {
        return;
    }

    const totalDisplay = document.getElementById('round-vote-total');
    const submitButton = document.getElementById('submit-votes-button');
    const controls = Array.from(form.querySelectorAll('.vote-points-control'));
    const maxPerSong = <?= (int)$maxPerSong ?>;
    const totalCapacity = <?= (int)$totalVoteCapacity ?>;
	const progressFill = document.getElementById('vote-progress-bar-fill');

    function getTotalAllocated() {
        let total = 0;
        controls.forEach(function (control) {
            const entryId = control.getAttribute('data-entry-id');
            const input = document.getElementById('score_' + entryId);
            const value = input ? parseInt(input.value || '0', 10) : 0;
            total += isNaN(value) ? 0 : value;
        });
        return total;
    }

    function updateTotal() {
		const total = getTotalAllocated();

		if (totalDisplay) {
			totalDisplay.textContent = total + ' / ' + totalCapacity;
			totalDisplay.classList.remove('changed');

			window.requestAnimationFrame(function () {
				totalDisplay.classList.add('changed');
			});
		}

		if (progressFill) {
			progressFill.style.width =
				Math.min(100, Math.round((total / totalCapacity) * 100)) + '%';
		}

		if (submitButton) {
			submitButton.disabled = (total !== totalCapacity);
		}
	}

    function syncAllButtons() {
        const totalAllocated = getTotalAllocated();

        controls.forEach(function (control) {
            const entryId = control.getAttribute('data-entry-id');
            const input = document.getElementById('score_' + entryId);
            const valueNode = control.querySelector('.vote-points-value');
            const minus = control.querySelector('.minus');
            const plus = control.querySelector('.plus');
            const current = input ? parseInt(input.value || '0', 10) : 0;
            const safeValue = isNaN(current) ? 0 : current;

            if (valueNode) {
                valueNode.textContent = String(safeValue);
            }

            if (minus) {
                minus.disabled = minus.hasAttribute('data-locked') ? true : safeValue <= 0;
            }

            if (plus) {
                plus.disabled = plus.hasAttribute('data-locked') ? true : (safeValue >= maxPerSong || totalAllocated >= totalCapacity);
            }
        });
    }

    controls.forEach(function (control) {
        const entryId = control.getAttribute('data-entry-id');
        const input = document.getElementById('score_' + entryId);
        const minus = control.querySelector('.minus');
        const plus = control.querySelector('.plus');

        if (minus && minus.hasAttribute('disabled')) {
            minus.setAttribute('data-locked', '1');
        }

        if (plus && plus.hasAttribute('disabled')) {
            plus.setAttribute('data-locked', '1');
        }

        if (minus && input && !minus.hasAttribute('data-locked')) {
            minus.addEventListener('click', function () {
                let current = parseInt(input.value || '0', 10);
                if (isNaN(current)) {
                    current = 0;
                }

                input.value = String(Math.max(0, current - 1));
                syncAllButtons();
                updateTotal();
            });
        }

        if (plus && input && !plus.hasAttribute('data-locked')) {
            plus.addEventListener('click', function () {
                let current = parseInt(input.value || '0', 10);
                if (isNaN(current)) {
                    current = 0;
                }

                const totalAllocated = getTotalAllocated();
                if (current >= maxPerSong || totalAllocated >= totalCapacity) {
                    return;
                }

                input.value = String(current + 1);
                syncAllButtons();
                updateTotal();
            });
        }
    });

    syncAllButtons();
    updateTotal();
})();
</script>
</body>
</html>
