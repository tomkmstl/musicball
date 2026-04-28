<?php
require_once __DIR__ . '/ml_gameplay.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'season';
$currentUserId = (int)$currentUser['UserID'];
$seasonRoundId = isset($_GET['season_round_id']) ? (int)$_GET['season_round_id'] : 0;
$round = $seasonRoundId > 0 ? mlFindRoundById($pdo, $seasonRoundId) : null;

if (!$round) {
    header('Location: season.php');
    exit;
}

$presentation = mlComputeRoundPresentation($pdo, [$round], $currentUserId);
$roundView = $presentation[0];
$results = mlBuildRoundResultsPreview($pdo, (int)$round['SeasonID'], $seasonRoundId, $currentUserId);
$roundPodium = mlBuildRoundPodium($pdo, (int)$round['SeasonID'], $seasonRoundId, $currentUserId);
$isLiveVoting = (($roundView['round_state'] ?? '') === 'voting') && !mlRoundIsFinishedForDisplay($roundView);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Results</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide game-card-narrow">
        <div class="game-page-intro game-round-page-intro">
            <?php if ($isLiveVoting): ?>
                <div class="home-shell-kicker">Live voting snapshot</div>
            <?php else: ?>
                <div class="home-shell-kicker">Round results</div>
            <?php endif; ?>

            <?php if (trim((string)$round['Title']) !== ''): ?>
                <h1 class="game-page-title"><?= htmlspecialchars($round['Title']) ?></h1>
                <?php if (trim((string)$round['Tagline']) !== ''): ?>
                    <p><?= htmlspecialchars($round['Tagline']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (!$isLiveVoting && !empty($roundPodium)): ?>
            <div class="game-round-reveal-podium" aria-label="Top finishers">
                <?php
                    $podiumByPlace = [];
                    foreach ($roundPodium as $podiumFinisher) {
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

        <?php if (!$isLiveVoting): ?>
        <div class="results-toggle-row">
            <a href="#" id="toggle-comments" class="results-toggle-link" aria-pressed="false">
                Hide Voting
            </a>
        </div>
        <?php endif; ?>

        <?php if (empty($results)): ?>
            <p>No results are available for this round yet.</p>
        <?php else: ?>
            <div class="vote-song-list vote-song-list-questions results-song-list">
                <?php foreach ($results as $index => $result): ?>
                    <section class="game-song-entry vote-ballot-item result-ballot-item">
                        <div class="game-song-entry-main vote-ballot-main">
                            <div class="vote-ballot-songline">
                                <img src="<?= htmlspecialchars($result['entry']['artwork']) ?>" alt="Album art" class="vote-ballot-art">
                                <div class="vote-ballot-copy">
                                    <div class="game-song-entry-title">#<?= $index + 1 ?> · <?= htmlspecialchars($result['entry']['title']) ?></div>
                                    <div class="game-song-entry-meta"><?= htmlspecialchars($result['entry']['artist']) ?><?php if (trim((string)$result['entry']['album']) !== ''): ?> · <?= htmlspecialchars($result['entry']['album']) ?><?php endif; ?></div>
                                </div>
                            </div>

                            <?php if (!$isLiveVoting): ?>
                                <?php
                                    $visibleVotes = [];
                                    foreach (($result['vote_breakdown'] ?? []) as $vote) {
                                        $voteScore = (int)($vote['score'] ?? 0);
                                        $voteComment = trim((string)($vote['comment'] ?? ''));

                                        if ($voteScore === 0 && $voteComment === '') {
                                            continue;
                                        }

                                        $vote['score'] = $voteScore;
                                        $vote['comment'] = $voteComment;
                                        $vote['resolved_profile_image_path'] = (string)($vote['profile_image_path'] ?? mlGetUserProfilePath((int)($vote['voter_user_id'] ?? 0)));
                                        $visibleVotes[] = $vote;
                                    }

                                    usort($visibleVotes, function ($a, $b) {
                                        $scoreA = (int)($a['score'] ?? 0);
                                        $scoreB = (int)($b['score'] ?? 0);
                                        if ($scoreA !== $scoreB) {
                                            return ($scoreA > $scoreB) ? -1 : 1;
                                        }

                                        $hasCommentA = trim((string)($a['comment'] ?? '')) !== '';
                                        $hasCommentB = trim((string)($b['comment'] ?? '')) !== '';
                                        if ($hasCommentA !== $hasCommentB) {
                                            return $hasCommentA ? -1 : 1;
                                        }

                                        return strcasecmp((string)($a['voter_name'] ?? ''), (string)($b['voter_name'] ?? ''));
                                    });
                                ?>

                                <div class="result-breakdown-wrap">
                                    <div class="result-breakdown-head">
                                        <div class="result-submitted-by-row">
                                            <img src="<?= htmlspecialchars($result['entry']['profile_image_path'] ?? mlGetUserProfilePath((int)$result['entry']['user_id'])) ?>" alt="<?= htmlspecialchars($result['entry']['user_name']) ?>" title="<?= htmlspecialchars($result['entry']['user_name']) ?>" class="profile-avatar profile-avatar-result profile-avatar-result-submitter">
                                            <div class="game-song-entry-meta result-submitted-by-copy">Submitted by <?= htmlspecialchars($result['entry']['user_name']) ?></div>
                                        </div>
                                        <div class="result-score-stack result-score-stack-total">
                                            <div class="result-score-total"><?= (int)$result['total_score'] ?></div>
                                            <div class="result-score-meta"><?= (int)($result['positive_voter_count'] ?? 0) ?> voter<?= ((int)($result['positive_voter_count'] ?? 0) === 1) ? '' : 's' ?></div>
                                        </div>
                                    </div>

                                    <?php if (trim((string)($result['entry']['comment'] ?? '')) !== ''): ?>
                                        <div class="result-song-comment result-voting-block"><?= nl2br(htmlspecialchars((string)$result['entry']['comment'])) ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($visibleVotes)): ?>
                                        <div class="result-vote-list result-voting-block">
                                            <?php foreach ($visibleVotes as $vote): ?>
                                                <?php $showVoteScore = ((int)$vote['score'] !== 0); ?>
                                                <div class="result-vote-line">
                                                    <div class="result-vote-main">
                                                        <div class="result-vote-person">
                                                            <img src="<?= htmlspecialchars($vote['resolved_profile_image_path']) ?>" alt="<?= htmlspecialchars($vote['voter_name']) ?>" title="<?= htmlspecialchars($vote['voter_name']) ?>" class="profile-avatar profile-avatar-result-commenter">
                                                            <span class="result-vote-name"><?= htmlspecialchars($vote['voter_name']) ?></span>
                                                        </div>
                                                        <?php if ($vote['comment'] !== ''): ?>
                                                            <div class="result-vote-comment result-comment-block"><?= htmlspecialchars($vote['comment']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="result-vote-score-wrap">
                                                        <?php if ($showVoteScore): ?>
                                                            <span class="result-vote-score"><?= (int)$vote['score'] ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p>No submitted votes are available for this song yet.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($isLiveVoting): ?>
                            <div class="points-control points-control-static result-points-control">
                                <div class="result-score-stack">
                                    <div class="result-score-total"><?= (int)$result['total_score'] ?></div>
                                    <div class="result-score-meta"><?= (int)($result['positive_voter_count'] ?? 0) ?> voter<?= ((int)($result['positive_voter_count'] ?? 0) === 1) ? '' : 's' ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php if (!$isLiveVoting): ?>
<script>
(function () {
    const toggleButton = document.getElementById('toggle-comments');
    if (!toggleButton) {
        return;
    }

    let votingHidden = false;

    toggleButton.addEventListener('click', function (e) {
		e.preventDefault();
        votingHidden = !votingHidden;
        document.body.classList.toggle('results-voting-hidden', votingHidden);
        toggleButton.textContent = votingHidden ? 'Show Voting' : 'Hide Voting';
        toggleButton.setAttribute('aria-pressed', votingHidden ? 'true' : 'false');
    });
})();
</script>
<?php endif; ?>
</body>
</html>
