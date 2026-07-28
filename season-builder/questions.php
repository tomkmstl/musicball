<?php
// questions.php
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

// Determine user: prefer $currentUserId when included via index.php, otherwise session.
if (isset($currentUserId)) {
    $userId = (int)$currentUserId;
} else {
    $userId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
}

if ($userId <= 0) {
    header('Location: ' . mlUrl('?resetuser=true'));
    exit;
}

$check = $pdo->prepare('SELECT UserName FROM ML_Users WHERE UserID = ?');
$check->execute([$userId]);
$user = $check->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    unset($_SESSION['UserID'], $_SESSION['UserName'], $_SESSION['ml_user_id']);
    header('Location: ' . mlUrl('?resetuser=true'));
    exit;
}

$categories = [];
$q1Existing = [];
if ($q1Enabled) {
    $stmt = $pdo->prepare(
        'SELECT CategoryIndex, Title, Description
         FROM ML_Q1Categories
         WHERE SeasonID = ?
         ORDER BY CategoryIndex'
    );
    $stmt->execute([$seasonId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $q1Stmt = $pdo->prepare(
        'SELECT CategoryIndex, Points
         FROM ML_Q1Votes
         WHERE SeasonID = ? AND UserID = ?'
    );
    $q1Stmt->execute([$seasonId, $userId]);
    foreach ($q1Stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $q1Existing[(int)$row['CategoryIndex']] = (int)$row['Points'];
    }
}

$q2Part1 = $madlibsEnabled ? ($q2Options[1] ?? []) : [];
$q2Part2 = $madlibsEnabled ? ($q2Options[2] ?? []) : [];
$q2Existing = [1 => [], 2 => []];
if ($madlibsEnabled) {
    $q2Stmt = $pdo->prepare(
        'SELECT QuestionNumber, Choice1Index, Choice2Index
         FROM ML_Q2Answers
         WHERE SeasonID = ? AND UserID = ? AND QuestionNumber IN (1,2)'
    );
    $q2Stmt->execute([$seasonId, $userId]);
    foreach ($q2Stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qn = (int)$row['QuestionNumber'];
        $q2Existing[$qn] = [(int)$row['Choice1Index'], (int)$row['Choice2Index']];
    }
}

$optionVoteExisting = $useGenericOptionVotes
    ? mlLoadUserOptionVoteAnswers($pdo, $seasonId, $userId)
    : [];

$q3Existing = [];
if ($legacyQ3Enabled) {
    $q3Stmt = $pdo->prepare(
        'SELECT Choice1Index, Choice2Index
         FROM ML_Q3Answers
         WHERE SeasonID = ? AND UserID = ?'
    );
    $q3Stmt->execute([$seasonId, $userId]);
    if ($row = $q3Stmt->fetch(PDO::FETCH_ASSOC)) {
        $q3Existing = [(int)$row['Choice1Index'], (int)$row['Choice2Index']];
    }
}

// Build the wizard from the question families required by the saved round
// structure. Step numbers are assigned only after the real steps are known.
$votingSteps = [];
if ($q1Enabled) {
    $votingSteps[] = ['type' => 'q1'];
}
if ($madlibsEnabled) {
    $votingSteps[] = ['type' => 'q2', 'part' => 1];
    $votingSteps[] = ['type' => 'q2', 'part' => 2];
}
if ($useGenericOptionVotes) {
    foreach ($optionVoteRounds as $roundNumber => $optionVote) {
        $votingSteps[] = [
            'type' => 'option_vote',
            'round_number' => (int)$roundNumber,
            'option_vote' => $optionVote,
        ];
    }
} elseif ($legacyQ3Enabled) {
    $votingSteps[] = ['type' => 'legacy_q3'];
}

foreach ($votingSteps as $index => &$votingStep) {
    $votingStep['step_number'] = $index + 1;
}
unset($votingStep);

$totalSteps = count($votingSteps);
if ($totalSteps === 0) {
    $_SESSION['ml_notice'] = 'This season does not currently have any preseason voting questions configured.';
    header('Location: ' . mlUrl('index.php'));
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Your Choices</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('season-builder/season-builder.css')) ?>">
    <?php require_once __DIR__ . '/../pwa_head.php'; ?>
    <script src="<?= htmlspecialchars(mlAssetUrl('season-builder/questions.js')) ?>" defer></script>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'vote'; include __DIR__ . '/../header.php'; ?>
<div class="wrapper">
    <div class="card">
        <div class="questions-page-intro">
            <h1>make your selections, <?= htmlspecialchars($user['UserName']); ?></h1>
            <h3>Next season: <?= htmlspecialchars($seasonName) ?></h3>
        </div>

        <div class="step-indicator">
            Step <span id="step-label">1 of <?= (int)$totalSteps ?></span>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill"></div>
        </div>

        <form method="post" action="<?= htmlspecialchars(mlUrl('season-builder/submit.php')) ?>" id="ml_form">
            <input type="hidden" name="user_id" value="<?= (int)$userId ?>">

            <?php foreach ($votingSteps as $stepIndex => $votingStep): ?>
                <?php
                $stepNumber = (int)$votingStep['step_number'];
                $previousStep = $stepIndex > 0 ? (int)$votingSteps[$stepIndex - 1]['step_number'] : null;
                $nextStep = $stepIndex < ($totalSteps - 1) ? (int)$votingSteps[$stepIndex + 1]['step_number'] : null;
                $isFirst = ($stepIndex === 0);
                $isLast = ($stepIndex === $totalSteps - 1);
                $type = (string)$votingStep['type'];

                $stepClasses = ['step'];
                $stepAttributes = [
                    'data-step="' . $stepNumber . '"',
                    'data-question-type="' . htmlspecialchars($type) . '"',
                ];
                if ($isFirst) {
                    $stepClasses[] = 'current';
                }
                if ($type === 'q2') {
                    $stepAttributes[] = 'data-q2-part="' . (int)$votingStep['part'] . '"';
                } elseif ($type === 'option_vote') {
                    $optionVoteForAttributes = $votingStep['option_vote'];
                    $stepClasses[] = 'option-vote-step';
                    $stepAttributes[] = 'data-option-vote-round="' . (int)$votingStep['round_number'] . '"';
                    $stepAttributes[] = 'data-required-selections="' . max(1, (int)$optionVoteForAttributes['selections_per_player']) . '"';
                } elseif ($type === 'legacy_q3') {
                    $stepClasses[] = 'legacy-q3-step';
                    $stepAttributes[] = 'data-required-selections="2"';
                }
                ?>

                <div class="<?= implode(' ', $stepClasses) ?>" <?= implode(' ', $stepAttributes) ?>>
                    <?php if ($type === 'q1'): ?>
                        <div class="step-header">
                            <h2><?= $mlHeadings['q1']['wizard']; ?></h2>
                            <div class="counter-value counter-value-q1" id="q1_total">0 / 10</div>
                        </div>

                        <?php foreach ($categories as $cat): ?>
                            <?php
                            $catIndex = (int)$cat['CategoryIndex'];
                            $initialPoints = $q1Existing[$catIndex] ?? 0;
                            ?>
                            <div class="cat">
                                <div class="cat-main">
                                    <div class="cat-title"><?= htmlspecialchars($cat['Title']) ?></div>
                                    <?php if (!empty($cat['Description'])): ?>
                                        <div class="cat-desc note"><?= htmlspecialchars($cat['Description']) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="points-control" data-cat="<?= $catIndex ?>">
                                    <button type="button" class="points-btn minus">−</button>
                                    <span class="points-value">0</span>
                                    <button type="button" class="points-btn plus">+</button>
                                </div>

                                <input type="hidden"
                                       id="q1-hidden-<?= $catIndex ?>"
                                       name="q1[<?= $catIndex ?>]"
                                       value="<?= $initialPoints ?>">
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ($type === 'q2'): ?>
                        <?php
                        $q2Part = (int)$votingStep['part'];
                        $q2Choices = $q2Part === 1 ? $q2Part1 : $q2Part2;
                        ?>
                        <div class="step-header">
                            <h2><?= $mlHeadings['q2']['wizard'][$q2Part]; ?></h2>
                            <div class="counter-value counter-value-q1" data-q2-counter>0 / 2</div>
                        </div>

                        <div class="q2-group">
                            <?php foreach ($q2Choices as $idx => $label): ?>
                                <?php
                                if (trim((string)$label) === '') { continue; }
                                $idxInt = (int)$idx;
                                $checked = in_array($idxInt, $q2Existing[$q2Part] ?? [], true);
                                ?>
                                <label class="option-row">
                                    <input type="checkbox"
                                           name="q2[<?= $q2Part ?>][]"
                                           value="<?= $idxInt ?>"
                                           data-q2-choice
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="option-box">
                                        <span class="option-check"></span>
                                        <span class="option-label"><?= htmlspecialchars($label) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'option_vote'): ?>
                        <?php
                        $roundNumber = (int)$votingStep['round_number'];
                        $optionVote = $votingStep['option_vote'];
                        $requiredSelections = max(1, (int)$optionVote['selections_per_player']);
                        $existingSelections = $optionVoteExisting[$roundNumber] ?? [];
                        ?>
                        <div class="step-header">
                            <div>
                                <h2><?= htmlspecialchars($optionVote['name']) ?></h2>
                                <div class="note">Choose exactly <?= $requiredSelections ?>.</div>
                            </div>
                            <div class="counter-value counter-value-q1" data-option-vote-counter>0 / <?= $requiredSelections ?></div>
                        </div>

                        <div class="q3-group">
                            <?php foreach ($optionVote['choices'] as $idx => $label): ?>
                                <?php
                                $idxInt = (int)$idx;
                                $checked = in_array($idxInt, $existingSelections, true);
                                ?>
                                <label class="option-row">
                                    <input type="checkbox"
                                           name="option_votes[<?= $roundNumber ?>][]"
                                           value="<?= $idxInt ?>"
                                           data-option-vote-choice
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="option-box">
                                        <span class="option-check"></span>
                                        <span class="option-label"><?= htmlspecialchars($label) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'legacy_q3'): ?>
                        <div class="step-header">
                            <h2><?= $mlHeadings['q3']['wizard']; ?></h2>
                            <div class="counter-value counter-value-q1" data-legacy-q3-counter>0 / 2</div>
                        </div>

                        <div class="q3-group">
                            <?php foreach ($q3Options as $idx => $label): ?>
                                <?php
                                if (trim((string)$label) === '') { continue; }
                                $idxInt = (int)$idx;
                                $checked = in_array($idxInt, $q3Existing, true);
                                ?>
                                <label class="option-row">
                                    <input type="checkbox"
                                           name="q3[]"
                                           value="<?= $idxInt ?>"
                                           data-legacy-q3-choice
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="option-box">
                                        <span class="option-check"></span>
                                        <span class="option-label"><?= htmlspecialchars($label) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="buttons">
                        <?php if ($previousStep !== null): ?>
                            <button type="button" class="button-secondary wizard-back" data-back-step="<?= $previousStep ?>">&laquo; Back</button>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>

                        <?php if ($isLast): ?>
                            <button type="submit" class="button-primary" disabled>Submit my picks</button>
                        <?php else: ?>
                            <button type="button" class="button-primary wizard-next" data-next-step="<?= $nextStep ?>" disabled>Next &raquo;</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
    </div>
</div>
</body>
</html>
