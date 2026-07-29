<?php
// choice.php
require_once __DIR__ . '/../session_boot.php';
require_once __DIR__ . '/../config.php';

$votingSeason = mlGetVotingSeason($pdo);
if (!$votingSeason) {
    $_SESSION['ml_notice'] = 'Voting for the next season is currently closed.';
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$seasonId = (int)$votingSeason['SeasonID'];
$seasonName = (string)$votingSeason['SeasonName'];
$votingOpen = true;
require_once __DIR__ . '/sb_questions.php';
if (!$votingOpen) {
    $_SESSION['ml_notice'] = 'Voting for the next season is currently closed.';
    header('Location: ' . mlUrl('index.php'));
    exit;
}


// Determine user: prefer $currentUserId when included via index.php, otherwise session
if (isset($currentUserId)) {
    $userId = (int)$currentUserId;
} else {
    $userId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
}

if ($userId <= 0) {
    header('Location: ' . mlUrl('?resetuser=true'));
    exit;
}

// Validate user
$userStmt = $pdo->prepare("SELECT UserName FROM ML_Users WHERE UserID = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    unset($_SESSION['UserID'], $_SESSION['UserName'], $_SESSION['ml_user_id']);
    header('Location: ' . mlUrl('?resetuser=true'));
    exit;
}

// --- Fetch submitted-at info (optional, just for display) ---
$submittedAt = null;
$subStmt = $pdo->prepare("
    SELECT SubmittedAt
    FROM ML_Submissions
    WHERE SeasonID = ?
      AND UserID = ?
");
$subStmt->execute([$seasonId, $userId]);
if ($row = $subStmt->fetch(PDO::FETCH_ASSOC)) {
    $submittedAt = $row['SubmittedAt'];
}

// --- Fetch only the answer families used by this season structure. ---
$q1Votes = [];
if ($q1Enabled) {
    $q1Stmt = $pdo->prepare("
        SELECT v.CategoryIndex, v.Points, c.Title, c.Description
        FROM ML_Q1Votes v
        JOIN ML_Q1Categories c
          ON v.SeasonID = c.SeasonID
         AND v.CategoryIndex = c.CategoryIndex
        WHERE v.SeasonID = ?
          AND v.UserID = ?
        ORDER BY v.CategoryIndex
    ");
    $q1Stmt->execute([$seasonId, $userId]);
    $q1Votes = $q1Stmt->fetchAll(PDO::FETCH_ASSOC);
}

$q2Rows = [];
$userQ2 = [1 => [], 2 => []];
if ($madlibsEnabled) {
    $q2Stmt = $pdo->prepare("
        SELECT QuestionNumber, Choice1Index, Choice2Index
        FROM ML_Q2Answers
        WHERE SeasonID = ?
          AND UserID = ?
          AND QuestionNumber IN (1,2)
        ORDER BY QuestionNumber
    ");
    $q2Stmt->execute([$seasonId, $userId]);
    $q2Rows = $q2Stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($q2Rows as $row) {
        $qn = (int)$row['QuestionNumber'];
        $c1 = (int)$row['Choice1Index'];
        $c2 = (int)$row['Choice2Index'];
        $userQ2[$qn] = [$c1, $c2];
    }
}

// --- Fetch round-specific Option Vote answers ---
$userOptionVotes = $useGenericOptionVotes
    ? mlLoadUserOptionVoteAnswers($pdo, $seasonId, $userId)
    : [];

// Legacy Q3 remains readable for seasons created before Option Votes became
// round-specific.
$userQ3 = [];
if ($legacyQ3Enabled) {
    $q3Stmt = $pdo->prepare("
        SELECT Choice1Index, Choice2Index
        FROM ML_Q3Answers
        WHERE SeasonID = ?
          AND UserID = ?
    ");
    $q3Stmt->execute([$seasonId, $userId]);
    if ($q3Row = $q3Stmt->fetch(PDO::FETCH_ASSOC)) {
        $userQ3 = [
            (int)$q3Row['Choice1Index'],
            (int)$q3Row['Choice2Index'],
        ];
    }
}

// Helpers to map indexes -> labels from sb_questions.php
function mapQ2LabelsForPart(array $optionsForPart, array $indexes): array {
    $labels = [];
    foreach ($indexes as $idx) {
        if (isset($optionsForPart[$idx])) {
            $labels[] = $optionsForPart[$idx];
        }
    }
    return $labels;
}

function mapOptionLabels(array $allOptions, array $indexes): array {
    $labels = [];
    foreach ($indexes as $idx) {
        if (isset($allOptions[$idx])) {
            $labels[] = $allOptions[$idx];
        }
    }
    return $labels;
}

$hasQ1 = $q1Enabled && count($q1Votes) > 0;
$hasQ2 = $madlibsEnabled && !empty($q2Rows);
$hasOptionVotes = false;
foreach ($userOptionVotes as $roundSelections) {
    if (!empty($roundSelections)) {
        $hasOptionVotes = true;
        break;
    }
}
$hasQ3 = !empty($userQ3);
$hasAnyAnswers = $hasQ1 || $hasQ2 || $hasOptionVotes || $hasQ3;

// --- League submission summary (for pie chart / counter) ---
$submittedCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT UserID)
    FROM ML_Submissions
    WHERE SeasonID = ?
");
$submittedCountStmt->execute([$seasonId]);
$submittedCount = (int)$submittedCountStmt->fetchColumn();

$userSelect = 'SELECT UserID, UserName';
if (mlUsersHasProfileImageColumn($pdo)) {
    $userSelect .= ', ProfileImageFilename';
}
$userSelect .= ' FROM ML_Users ORDER BY UserID ASC';

$allUsersStmt = $pdo->query($userSelect);
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($allUsers as &$leagueUser) {
    $leagueUser['profile_image_path'] = mlGetUserProfilePath((int)$leagueUser['UserID'], $leagueUser['ProfileImageFilename'] ?? null);
}
unset($leagueUser);

$totalUsers = count($allUsers);
$submittedCount = max(0, min($submittedCount, $totalUsers)); // clamp for safety
$allSubmitted = ($submittedCount >= $totalUsers);

$submittedUsersStmt = $pdo->prepare("
    SELECT DISTINCT u.UserID, u.UserName" . (mlUsersHasProfileImageColumn($pdo) ? ', u.ProfileImageFilename' : '') . "
    FROM ML_Users u
    INNER JOIN ML_Submissions s
        ON u.UserID = s.UserID
    WHERE s.SeasonID = ?
    ORDER BY u.UserID
");
$submittedUsersStmt->execute([$seasonId]);
$submittedUsers = $submittedUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$submittedUserIds = [];
foreach ($submittedUsers as &$submittedUser) {
    $submittedUser['profile_image_path'] = mlGetUserProfilePath((int)$submittedUser['UserID'], $submittedUser['ProfileImageFilename'] ?? null);
    $submittedUserIds[(int)$submittedUser['UserID']] = true;
}
unset($submittedUser);

$pendingUsers = [];
foreach ($allUsers as $leagueUser) {
    if (!isset($submittedUserIds[(int)$leagueUser['UserID']])) {
        $pendingUsers[] = $leagueUser;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Your Picks</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('season-builder/season-builder.css')) ?>">
    <?php require_once __DIR__ . '/../pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'vote'; include __DIR__ . '/../header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide game-card-narrow preseason-picks-card">
        <div class="game-page-intro game-round-page-intro">
            <div class="home-shell-kicker">Next Season Voting</div>
            <h1 class="game-page-title">Your Picks</h1>
            <div class="preseason-picks-meta">
                <?= htmlspecialchars($seasonName) ?> · <?= htmlspecialchars($user['UserName']) ?>
                <?php if ($submittedAt): ?>
                    · Submitted <?= htmlspecialchars($submittedAt) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $submissionProgressPercent = 0;
        if ($totalUsers > 0) {
            $submissionProgressPercent = (int) round(($submittedCount / $totalUsers) * 100);
            $submissionProgressPercent = max(0, min(100, $submissionProgressPercent));
        }
        ?>
        <div class="submission-summary">
            <div class="submission-summary-main">
                <div class="submission-summary-chart">
                    <div class="mb-progress-inline">
                        <span class="mb-progress-chart" style="--mb-progress: <?= $submissionProgressPercent ?>%;"></span>
                    </div>
                </div>

                <div class="submission-summary-text">
                    <div class="submission-summary-count">
                        <?= (int)$submittedCount ?> / <?= (int)$totalUsers ?>
                    </div>

                    <div class="submission-summary-label">
                        <?php if (!$allSubmitted): ?>
                            league members submitted
                        <?php else: ?>
                            Everyone has voted — <?= htmlspecialchars($seasonName) ?> is ready.
                        <?php endif; ?>
                    </div>

                    <?php if ($allSubmitted): ?>
                        <a href="<?= htmlspecialchars(mlUrl('final.php')) ?>"
                           class="button-primary next-season-btn">
                            Show Me Next Season
                        </a>
                    <?php else: ?>
                        <button class="button-secondary next-season-btn" disabled>
                            Show Me Next Season
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="submission-summary-voters">
                <div class="submission-summary-voter-group">
                    <div class="voters-title">Voted</div>
                    <?php if (!empty($submittedUsers)): ?>
                        <div class="profile-avatar-list profile-avatar-list-progress" aria-label="Voted users">
                            <?php foreach ($submittedUsers as $su): ?>
                                <img src="<?= htmlspecialchars(mlAssetUrl($su['profile_image_path'])) ?>"
                                     alt="<?= htmlspecialchars($su['UserName']) ?>"
                                     title="<?= htmlspecialchars($su['UserName']) ?>"
                                     class="profile-avatar profile-avatar-progress">
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="voter-name">None yet</div>
                    <?php endif; ?>
                </div>

                <div class="submission-summary-voter-group">
                    <div class="voters-title">Not voted</div>
                    <?php if (!empty($pendingUsers)): ?>
                        <div class="profile-avatar-list profile-avatar-list-progress" aria-label="Users who have not voted">
                            <?php foreach ($pendingUsers as $pendingUser): ?>
                                <img src="<?= htmlspecialchars(mlAssetUrl($pendingUser['profile_image_path'])) ?>"
                                     alt="<?= htmlspecialchars($pendingUser['UserName']) ?>"
                                     title="<?= htmlspecialchars($pendingUser['UserName']) ?>"
                                     class="profile-avatar profile-avatar-progress">
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="voter-name">No one</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$hasAnyAnswers): ?>
            <div class="status-banner">
                It looks like you have not submitted any choices yet.
            </div>
            <div class="game-form-actions vote-form-actions-simple preseason-picks-actions">
                <a href="<?= htmlspecialchars(mlUrl('season-builder/questions.php')) ?>"
                   class="button-primary">
                    Start Voting
                </a>
            </div>
        <?php else: ?>

            <?php if ($q1Enabled): ?>
                <section class="game-round-section preseason-picks-section">
                    <div class="preseason-picks-heading">
                        <div class="home-shell-kicker">Points Ballot</div>
                        <h2><?= $mlHeadings['q1']['choice']; ?></h2>
                    </div>
                    <div class="vote-song-list vote-song-list-questions preseason-picks-list">
                        <?php
                        $totalPoints = 0;
                        foreach ($q1Votes as $vote):
                            $pts = (int)$vote['Points'];
                            if ($pts <= 0) {
                                continue;
                            }
                            $totalPoints += $pts;
                            ?>
                            <section class="game-song-entry vote-ballot-item preseason-pick-item">
                                <div class="game-song-entry-main vote-ballot-main">
                                    <div class="vote-ballot-songline">
                                        <div class="vote-ballot-copy">
                                            <div class="vote-ballot-title"><?= htmlspecialchars($vote['Title']) ?></div>
                                            <?php if (trim((string)$vote['Description']) !== ''): ?>
                                                <div class="vote-ballot-artist"><?= htmlspecialchars($vote['Description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="preseason-pick-static-score">
                                    <strong><?= $pts ?></strong>
                                    <span>pts</span>
                                </div>
                            </section>
                        <?php endforeach; ?>

                        <?php if ($totalPoints === 0): ?>
                            <div class="status-banner">You have not assigned any points yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($madlibsEnabled): ?>
                <section class="game-round-section preseason-picks-section">
                    <div class="preseason-picks-heading">
                        <div class="home-shell-kicker">Madlibs</div>
                        <h2>Your selected ingredients</h2>
                    </div>

                    <?php
                    $labelsPerson = mapQ2LabelsForPart($q2Options[1] ?? [], $userQ2[1] ?? []);
                    $labelsActivity = mapQ2LabelsForPart($q2Options[2] ?? [], $userQ2[2] ?? []);
                    ?>
                    <div class="vote-song-list vote-song-list-questions preseason-picks-list">
                        <section class="game-song-entry vote-ballot-item preseason-pick-item">
                            <div class="game-song-entry-main vote-ballot-main">
                                <div class="vote-ballot-songline">
                                    <div class="vote-ballot-copy">
                                        <div class="vote-ballot-title">Main Character</div>
                                        <div class="preseason-pick-values">
                                            <?php if (empty($labelsPerson)): ?>
                                                <span class="note">No choices yet.</span>
                                            <?php else: ?>
                                                <?php foreach ($labelsPerson as $label): ?>
                                                    <span class="preseason-pick-value"><?= htmlspecialchars($label) ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="game-song-entry vote-ballot-item preseason-pick-item">
                            <div class="game-song-entry-main vote-ballot-main">
                                <div class="vote-ballot-songline">
                                    <div class="vote-ballot-copy">
                                        <div class="vote-ballot-title">Doing a Thing</div>
                                        <div class="preseason-pick-values">
                                            <?php if (empty($labelsActivity)): ?>
                                                <span class="note">No choices yet.</span>
                                            <?php else: ?>
                                                <?php foreach ($labelsActivity as $label): ?>
                                                    <span class="preseason-pick-value"><?= htmlspecialchars($label) ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($useGenericOptionVotes): ?>
                <?php foreach ($optionVoteRounds as $roundNumber => $optionVote): ?>
                    <section class="game-round-section preseason-picks-section">
                        <div class="preseason-picks-heading">
                            <div class="home-shell-kicker">Option Vote · Round <?= (int)$roundNumber ?></div>
                            <h2><?= htmlspecialchars($optionVote['question']) ?></h2>
                        </div>
                        <div class="vote-song-list vote-song-list-questions preseason-picks-list">
                            <section class="game-song-entry vote-ballot-item preseason-pick-item">
                                <div class="game-song-entry-main vote-ballot-main">
                                    <div class="vote-ballot-songline">
                                        <div class="vote-ballot-copy">
                                            <div class="vote-ballot-title">Your selection<?= count($userOptionVotes[$roundNumber] ?? []) === 1 ? '' : 's' ?></div>
                                            <div class="preseason-pick-values">
                                                <?php
                                                $labels = mapOptionLabels(
                                                    $optionVote['choices'],
                                                    $userOptionVotes[$roundNumber] ?? []
                                                );
                                                ?>
                                                <?php if (empty($labels)): ?>
                                                    <span class="note">No choices yet.</span>
                                                <?php else: ?>
                                                    <?php foreach ($labels as $label): ?>
                                                        <span class="preseason-pick-value"><?= htmlspecialchars($label) ?></span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php elseif ($legacyQ3Enabled): ?>
                <section class="game-round-section preseason-picks-section">
                    <div class="preseason-picks-heading">
                        <div class="home-shell-kicker">Option Vote</div>
                        <h2><?= $mlHeadings['q3']['choice']; ?></h2>
                    </div>
                    <div class="vote-song-list vote-song-list-questions preseason-picks-list">
                        <section class="game-song-entry vote-ballot-item preseason-pick-item">
                            <div class="game-song-entry-main vote-ballot-main">
                                <div class="vote-ballot-songline">
                                    <div class="vote-ballot-copy">
                                        <div class="vote-ballot-title">Your selections</div>
                                        <div class="preseason-pick-values">
                                            <?php $labelsQ3 = mapOptionLabels($q3Options, $userQ3); ?>
                                            <?php if (empty($labelsQ3)): ?>
                                                <span class="note">No choices yet.</span>
                                            <?php else: ?>
                                                <?php foreach ($labelsQ3 as $label): ?>
                                                    <span class="preseason-pick-value"><?= htmlspecialchars($label) ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </section>
            <?php endif; ?>

            <div class="game-form-actions vote-form-actions-simple preseason-picks-actions">
                <a href="<?= htmlspecialchars(mlUrl('season-builder/questions.php')) ?>"
                   class="button-primary">
                    Change My Answers
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
