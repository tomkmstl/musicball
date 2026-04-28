<?php
// choice.php
require_once __DIR__ . '/../ml_session_boot.php';
require_once __DIR__ . '/../ml_config.php';

$votingSeason = mlGetVotingSeason($pdo);
if (!$votingSeason) {
    $_SESSION['ml_notice'] = 'Voting for the next season is currently closed.';
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$seasonId = (int)$votingSeason['SeasonID'];
$seasonName = (string)$votingSeason['SeasonName'];
$votingOpen = true;
require_once __DIR__ . '/ml_questions.php';
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

// --- Fetch Q1 votes for this user ---
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

// --- Fetch Q2 answers (now only QuestionNumber 1 and 2) ---
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

$userQ2 = [
    1 => [],
    2 => [],
];
foreach ($q2Rows as $row) {
    $qn = (int)$row['QuestionNumber']; // 1 = Person, 2 = Activity
    $c1 = (int)$row['Choice1Index'];
    $c2 = (int)$row['Choice2Index'];
    $userQ2[$qn] = [$c1, $c2];
}

// --- Fetch Q3 answers (single row with two choices) ---
$q3Stmt = $pdo->prepare("
    SELECT Choice1Index, Choice2Index
    FROM ML_Q3Answers
    WHERE SeasonID = ?
      AND UserID = ?
");
$q3Stmt->execute([$seasonId, $userId]);
$q3Row = $q3Stmt->fetch(PDO::FETCH_ASSOC);

$userQ3 = [];
if ($q3Row) {
    $userQ3 = [
        (int)$q3Row['Choice1Index'],
        (int)$q3Row['Choice2Index'],
    ];
}

// Helpers to map indexes -> labels from ml_questions.php
function mapQ2LabelsForPart(array $optionsForPart, array $indexes): array {
    $labels = [];
    foreach ($indexes as $idx) {
        if (isset($optionsForPart[$idx])) {
            $labels[] = $optionsForPart[$idx];
        }
    }
    return $labels;
}

function mapQ3Labels(array $allOptions, array $indexes): array {
    $labels = [];
    foreach ($indexes as $idx) {
        if (isset($allOptions[$idx])) {
            $labels[] = $allOptions[$idx];
        }
    }
    return $labels;
}

$hasQ1 = count($q1Votes) > 0;
$hasQ2 = !empty($q2Rows);
$hasQ3 = !empty($userQ3);
$hasAnyAnswers = $hasQ1 || $hasQ2 || $hasQ3;

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
    <div class="card">
        <div class="choice-page-intro">
		<h1>Your picks, <?= htmlspecialchars($user['UserName']); ?>.</h1>
            <h3>Next season: <?= htmlspecialchars($seasonName) ?></h3>
        </div>

        <!-- Submission summary section -->
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
                            # of users submitted
                        <?php else: ?>
                            Everyone has voted - <?= htmlspecialchars($seasonName) ?> is now ready!
                        <?php endif; ?>
                    </div>

                    <?php if ($allSubmitted): ?>
                        <a href="<?= htmlspecialchars(mlUrl('final.php')) ?>"
                           class="button-primary next-season-btn">
                            Show Me Next Season!
                        </a>
                    <?php else: ?>
                        <button
                            class="button-secondary next-season-btn"
                            disabled>
                            Show Me Next Season!
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="submission-summary-voters">
				<div class="submission-summary-voter-group">
					<div class="voters-title">VOTED:</div>
					<?php if (!empty($submittedUsers)): ?>
						<div class="profile-avatar-list profile-avatar-list-progress" aria-label="Voted users">
							<?php foreach ($submittedUsers as $su): ?>
								<img src="<?= htmlspecialchars(mlAssetUrl($su['profile_image_path'])) ?>" alt="<?= htmlspecialchars($su['UserName']) ?>" title="<?= htmlspecialchars($su['UserName']) ?>" class="profile-avatar profile-avatar-progress">
							<?php endforeach; ?>
						</div>
					<?php else: ?>
						<div class="voter-name">None yet</div>
					<?php endif; ?>
				</div>

				<div class="submission-summary-voter-group">
					<div class="voters-title">NOT VOTED:</div>
					<?php if (!empty($pendingUsers)): ?>
						<div class="profile-avatar-list profile-avatar-list-progress" aria-label="Users who have not voted">
							<?php foreach ($pendingUsers as $pendingUser): ?>
								<img src="<?= htmlspecialchars(mlAssetUrl($pendingUser['profile_image_path'])) ?>" alt="<?= htmlspecialchars($pendingUser['UserName']) ?>" title="<?= htmlspecialchars($pendingUser['UserName']) ?>" class="profile-avatar profile-avatar-progress">
							<?php endforeach; ?>
						</div>
					<?php else: ?>
						<div class="voter-name">No one</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

        <?php if (!$hasAnyAnswers): ?>

            <div class="buttons">
                <a href="<?= htmlspecialchars(mlUrl('season-builder/questions.php')) ?>"
                   class="button-primary">
                    Start my picks
                </a>
            </div>

            <?php if ($submittedAt): ?>
                <p>
                    Last submitted: <?= htmlspecialchars($submittedAt); ?>
                </p>
            <?php endif; ?>

            <p>
                It looks like you haven't submitted any choices yet.
            </p>

        <?php else: ?>

            <!-- Q1 -->
            <h2>
                <?= $mlHeadings['q1']['choice']; ?>
            </h2>
            <div class="q2-group">
                <?php
                $totalPoints = 0;
                foreach ($q1Votes as $vote) {
                    $pts = (int)$vote['Points'];
                    if ($pts <= 0) {
                        continue; // only show categories they actually gave points to
                    }
                    $totalPoints += $pts;
                    ?>
                    <div class="cat-choice">
                        <div class="cat-main">
                            <div class="cat-title">
                                <?= htmlspecialchars($vote['Title']) ?>
                            </div>
                        </div>
                        <div class="points-control points-control-static">
                            <span class="points-value"><?= $pts ?></span>
                            <span class="note">pts</span>
                        </div>
                    </div>
                    <?php
                }
                if ($totalPoints === 0): ?>
                    <p>You haven't assigned any points yet.</p>
                <?php endif; ?>
            </div>

            <!-- Q2 -->
            <h2>
                <?= $mlHeadings['q2']['choice']; ?>
            </h2>

            <div class="q2-group choice-group">
                <strong>Main Character</strong>
                <?php
                $labelsPerson = mapQ2LabelsForPart($q2Options[1] ?? [], $userQ2[1] ?? []);
                if (count($labelsPerson) === 0): ?>
                    <p>No choices yet.</p>
                <?php else: ?>
                    <div class="choice-values">
                        <?php foreach ($labelsPerson as $label): ?>
                            <div class="choice-value-item"><?= htmlspecialchars($label) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="q2-group choice-group">
                <strong>Doing a Thing</strong>
                <?php
                $labelsActivity = mapQ2LabelsForPart($q2Options[2] ?? [], $userQ2[2] ?? []);
                if (count($labelsActivity) === 0): ?>
                    <p>No choices yet.</p>
                <?php else: ?>
                    <div class="choice-values">
                        <?php foreach ($labelsActivity as $label): ?>
                            <div class="choice-value-item"><?= htmlspecialchars($label) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Q3 -->
            <h2>
                <?= $mlHeadings['q3']['choice']; ?>
            </h2>
            <div class="q3-group choice-group">
                <?php
                $labelsQ3 = mapQ3Labels($q3Options, $userQ3);
                if (count($labelsQ3) === 0): ?>
                    <p>No choices yet.</p>
                <?php else: ?>
                    <div class="choice-values">
                        <?php foreach ($labelsQ3 as $label): ?>
                            <div class="choice-value-item"><?= htmlspecialchars($label) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

			<div class="buttons choice-buttons-centered">
				<a href="<?= htmlspecialchars(mlUrl('season-builder/questions.php')) ?>"
				   class="button-primary">
					Change my answers
				</a>
			</div>
			
            <?php if ($submittedAt): ?>
                <p>
                    Last submitted: <?= htmlspecialchars($submittedAt); ?>
                </p>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

</body>
</html>
