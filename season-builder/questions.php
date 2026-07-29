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

$shortenNavigationLabel = static function ($label, $maxLength = 42) {
    $label = trim((string)$label);
    if ($label === '') {
        return 'Next question';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
    if ($length <= $maxLength) {
        return $label;
    }

    $slice = function_exists('mb_substr')
        ? mb_substr($label, 0, $maxLength - 1)
        : substr($label, 0, $maxLength - 1);

    return rtrim($slice) . '…';
};

$getStepNavigationLabel = static function (array $step) use ($mlHeadings, $shortenNavigationLabel) {
    $type = (string)($step['type'] ?? '');

    if ($type === 'q1') {
        return 'User Submitted Rounds';
    }

    if ($type === 'q2') {
        return ((int)($step['part'] ?? 1) === 1)
            ? 'Main Character'
            : 'Doing a Thing';
    }

    if ($type === 'option_vote') {
        return $shortenNavigationLabel($step['option_vote']['question'] ?? 'Option Vote');
    }

    if ($type === 'legacy_q3') {
        return 'Option Vote';
    }

    return 'Next question';
};

$isLightTheme = mlGetThemeMode() === 'light';
$minusIcon = $isLightTheme
    ? 'square-rounded-minus-light.svg'
    : 'square-rounded-minus.svg';
$plusIcon = $isLightTheme
    ? 'square-rounded-plus-light.svg'
    : 'square-rounded-plus.svg';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Next Season Voting</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('season-builder/season-builder.css')) ?>">
    <?php require_once __DIR__ . '/../pwa_head.php'; ?>
    <script src="<?= htmlspecialchars(mlAssetUrl('season-builder/questions.js')) ?>" defer></script>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'vote'; include __DIR__ . '/../header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide game-card-narrow preseason-vote-card">
        <div class="game-page-intro game-round-page-intro preseason-vote-page-intro">
            <div class="home-shell-kicker">Next Season Voting</div>
            <div class="preseason-vote-context">
                <span><?= htmlspecialchars($seasonName) ?></span>
                <span aria-hidden="true">·</span>
                <span><?= htmlspecialchars($user['UserName']) ?></span>
            </div>
            <div class="preseason-vote-question-count">
                Question <span id="step-label">1 of <?= (int)$totalSteps ?></span>
            </div>
        </div>

        <form method="post"
              action="<?= htmlspecialchars(mlUrl('season-builder/submit.php')) ?>"
              id="ml_form"
              class="vote-form-shell vote-form-shell-questions preseason-vote-form">
            <input type="hidden" name="user_id" value="<?= (int)$userId ?>">

            <?php foreach ($votingSteps as $stepIndex => $votingStep): ?>
                <?php
                $stepNumber = (int)$votingStep['step_number'];
                $previousStep = $stepIndex > 0 ? (int)$votingSteps[$stepIndex - 1]['step_number'] : null;
                $nextStep = $stepIndex < ($totalSteps - 1) ? (int)$votingSteps[$stepIndex + 1]['step_number'] : null;
                $isFirst = ($stepIndex === 0);
                $isLast = ($stepIndex === $totalSteps - 1);
                $type = (string)$votingStep['type'];

                $stepClasses = ['step', 'preseason-vote-step'];
                $stepAttributes = [
                    'data-step="' . $stepNumber . '"',
                    'data-question-type="' . htmlspecialchars($type) . '"',
                ];
                if ($isFirst) {
                    $stepClasses[] = 'current';
                }
                if ($type === 'q2') {
                    $stepAttributes[] = 'data-q2-part="' . (int)$votingStep['part'] . '"';
                    $stepAttributes[] = 'data-required-selections="2"';
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
                        <div class="game-page-intro preseason-question-intro">
                            <h1 class="game-page-title voting-question-heading preseason-question-title">
                                <?= htmlspecialchars($mlHeadings['q1']['wizard']) ?>
                            </h1>
                            <p>Distribute exactly 10 points across the round ideas. You can give up to 4 points to any one idea.</p>
                        </div>

                        <div class="step-header vote-step-header preseason-vote-step-header">
                            <h2>Total points given</h2>
                            <div class="counter-value counter-value-q1 changed" id="q1_total" aria-live="polite">0 / 10</div>
                        </div>

                        <div class="vote-progress-meta">
                            <div class="vote-progress-bar" aria-hidden="true">
                                <div class="vote-progress-bar-fill" data-selection-progress-fill></div>
                            </div>
                            <div class="vote-progress-copy">
                                Use all 10 points before continuing. Maximum 4 points per round suggestion.
                            </div>
                        </div>

                        <div class="vote-song-list vote-song-list-questions preseason-vote-list">
                            <?php foreach ($categories as $cat): ?>
                                <?php
                                $catIndex = (int)$cat['CategoryIndex'];
                                $initialPoints = $q1Existing[$catIndex] ?? 0;
                                ?>
                                <section class="game-song-entry vote-ballot-item preseason-ballot-item">
                                    <div class="game-song-entry-main vote-ballot-main">
                                        <div class="vote-ballot-songline">
                                            <div class="vote-ballot-copy">
                                                <div class="vote-ballot-title"><?= htmlspecialchars($cat['Title']) ?></div>
                                                <?php if (!empty($cat['Description'])): ?>
                                                    <div class="vote-ballot-artist preseason-ballot-description">
                                                        <?= htmlspecialchars($cat['Description']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="points-control vote-points-control"
                                         data-cat="<?= $catIndex ?>"
                                         aria-label="Points for <?= htmlspecialchars($cat['Title']) ?>">
                                        <button type="button"
                                                class="points-btn minus vote-points-btn"
                                                aria-label="Remove one point">
                                            <?php readfile(__DIR__ . '/../assets/icons/' . $minusIcon); ?>
                                        </button>
                                        <span class="points-value vote-points-value" aria-live="polite">0</span>
                                        <button type="button"
                                                class="points-btn plus vote-points-btn"
                                                aria-label="Add one point">
                                            <?php readfile(__DIR__ . '/../assets/icons/' . $plusIcon); ?>
                                        </button>
                                    </div>

                                    <input type="hidden"
                                           id="q1-hidden-<?= $catIndex ?>"
                                           name="q1[<?= $catIndex ?>]"
                                           value="<?= $initialPoints ?>">
                                </section>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'q2'): ?>
                        <?php
                        $q2Part = (int)$votingStep['part'];
                        $q2Choices = $q2Part === 1 ? $q2Part1 : $q2Part2;
                        ?>
                        <div class="game-page-intro preseason-question-intro">
                            <h1 class="game-page-title voting-question-heading preseason-question-title">
                                <?= htmlspecialchars($mlHeadings['q2']['wizard'][$q2Part]) ?>
                            </h1>
                            <p>Choose exactly 2 options.</p>
                        </div>

                        <div class="step-header vote-step-header preseason-vote-step-header">
                            <h2>Choices selected</h2>
                            <div class="counter-value counter-value-q1" data-q2-counter aria-live="polite">0 / 2</div>
                        </div>

                        <div class="vote-progress-meta">
                            <div class="vote-progress-bar" aria-hidden="true">
                                <div class="vote-progress-bar-fill" data-selection-progress-fill></div>
                            </div>
                            <div class="vote-progress-copy">Choose exactly 2 options before continuing.</div>
                        </div>

                        <div class="vote-song-list vote-song-list-questions preseason-choice-list">
                            <?php foreach ($q2Choices as $idx => $label): ?>
                                <?php
                                if (trim((string)$label) === '') { continue; }
                                $idxInt = (int)$idx;
                                $checked = in_array($idxInt, $q2Existing[$q2Part] ?? [], true);
                                ?>
                                <label class="option-row preseason-choice-row">
                                    <input type="checkbox"
                                           name="q2[<?= $q2Part ?>][]"
                                           value="<?= $idxInt ?>"
                                           data-q2-choice
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="game-song-entry vote-ballot-item preseason-choice-card">
                                        <span class="game-song-entry-main vote-ballot-main">
                                            <span class="vote-ballot-songline">
                                                <span class="vote-ballot-copy">
                                                    <span class="vote-ballot-title"><?= htmlspecialchars($label) ?></span>
                                                </span>
                                            </span>
                                        </span>
                                        <span class="preseason-choice-indicator" aria-hidden="true">✓</span>
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
                        <div class="game-page-intro preseason-question-intro">
                            <h1 class="game-page-title voting-question-heading preseason-question-title">
                                <?= htmlspecialchars($optionVote['question']) ?>
                            </h1>
                            <p>Choose exactly <?= $requiredSelections ?> option<?= $requiredSelections === 1 ? '' : 's' ?>.</p>
                        </div>

                        <div class="step-header vote-step-header preseason-vote-step-header">
                            <h2>Choices selected</h2>
                            <div class="counter-value counter-value-q1" data-option-vote-counter aria-live="polite">
                                0 / <?= $requiredSelections ?>
                            </div>
                        </div>

                        <div class="vote-progress-meta">
                            <div class="vote-progress-bar" aria-hidden="true">
                                <div class="vote-progress-bar-fill" data-selection-progress-fill></div>
                            </div>
                            <div class="vote-progress-copy">
                                Choose exactly <?= $requiredSelections ?> option<?= $requiredSelections === 1 ? '' : 's' ?> before continuing.
                            </div>
                        </div>

                        <div class="vote-song-list vote-song-list-questions preseason-choice-list">
                            <?php foreach ($optionVote['choices'] as $idx => $label): ?>
                                <?php
                                $idxInt = (int)$idx;
                                $checked = in_array($idxInt, $existingSelections, true);
                                ?>
                                <label class="option-row preseason-choice-row">
                                    <input type="checkbox"
                                           name="option_votes[<?= $roundNumber ?>][]"
                                           value="<?= $idxInt ?>"
                                           data-option-vote-choice
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="game-song-entry vote-ballot-item preseason-choice-card">
                                        <span class="game-song-entry-main vote-ballot-main">
                                            <span class="vote-ballot-songline">
                                                <span class="vote-ballot-copy">
                                                    <span class="vote-ballot-title"><?= htmlspecialchars($label) ?></span>
                                                </span>
                                            </span>
                                        </span>
                                        <span class="preseason-choice-indicator" aria-hidden="true">✓</span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'legacy_q3'): ?>
                        <div class="game-page-intro preseason-question-intro">
                            <h1 class="game-page-title voting-question-heading preseason-question-title">
                                <?= htmlspecialchars($mlHeadings['q3']['wizard']) ?>
                            </h1>
                            <p>Choose exactly 2 options.</p>
                        </div>

                        <div class="step-header vote-step-header preseason-vote-step-header">
                            <h2>Choices selected</h2>
                            <div class="counter-value counter-value-q1" data-legacy-q3-counter aria-live="polite">0 / 2</div>
                        </div>

                        <div class="vote-progress-meta">
                            <div class="vote-progress-bar" aria-hidden="true">
                                <div class="vote-progress-bar-fill" data-selection-progress-fill></div>
                            </div>
                            <div class="vote-progress-copy">Choose exactly 2 options before continuing.</div>
                        </div>

                        <div class="vote-song-list vote-song-list-questions preseason-choice-list">
                            <?php foreach ($q3Options as $idx => $label): ?>
                                <?php
                                if (trim((string)$label) === '') { continue; }
                                $idxInt = (int)$idx;
                                $checked = in_array($idxInt, $q3Existing, true);
                                ?>
                                <label class="option-row preseason-choice-row">
                                    <input type="checkbox"
                                           name="q3[]"
                                           value="<?= $idxInt ?>"
                                           data-legacy-q3-choice
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="game-song-entry vote-ballot-item preseason-choice-card">
                                        <span class="game-song-entry-main vote-ballot-main">
                                            <span class="vote-ballot-songline">
                                                <span class="vote-ballot-copy">
                                                    <span class="vote-ballot-title"><?= htmlspecialchars($label) ?></span>
                                                </span>
                                            </span>
                                        </span>
                                        <span class="preseason-choice-indicator" aria-hidden="true">✓</span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $nextButtonLabel = $nextStep !== null
                        ? $getStepNavigationLabel($votingSteps[$stepIndex + 1])
                        : '';
                    ?>
                    <div class="game-form-actions vote-form-actions-simple preseason-vote-actions">
                        <?php if ($previousStep !== null): ?>
                            <button type="button"
                                    class="button-secondary wizard-back"
                                    data-back-step="<?= $previousStep ?>">
                                Back
                            </button>
                        <?php else: ?>
                            <span class="preseason-vote-action-spacer" aria-hidden="true"></span>
                        <?php endif; ?>

                        <?php if ($isLast): ?>
                            <button type="submit" class="button-primary" disabled>Submit Votes</button>
                        <?php else: ?>
                            <button type="button"
                                    class="button-primary wizard-next"
                                    data-next-step="<?= $nextStep ?>"
                                    disabled>
                                Next: <?= htmlspecialchars($nextButtonLabel) ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
    </div>
</div>
</body>
</html>
