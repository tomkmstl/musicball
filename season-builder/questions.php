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

// Validate UserID
$check = $pdo->prepare("SELECT UserName FROM ML_Users WHERE UserID = ?");
$check->execute([$userId]);
$user = $check->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Clear bad session & send them back to pick again
    unset($_SESSION['UserID'], $_SESSION['UserName'], $_SESSION['ml_user_id']);
    header('Location: ' . mlUrl('?resetuser=true'));
    exit;
}

// Q1 categories
$stmt = $pdo->prepare("
    SELECT CategoryIndex, Title, Description
    FROM ML_Q1Categories
    WHERE SeasonID = ?
    ORDER BY CategoryIndex
");
$stmt->execute([$seasonId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Break Q2 options out (NOW ONLY 1 & 2)
$q2Part1 = $q2Options[1] ?? [];
$q2Part2 = $q2Options[2] ?? [];

// -------------------------
// Load existing answers
// -------------------------

// Q1: existing votes
$q1Existing = [];
$q1Stmt = $pdo->prepare("
    SELECT CategoryIndex, Points
    FROM ML_Q1Votes
    WHERE SeasonID = ?
      AND UserID = ?
");
$q1Stmt->execute([$seasonId, $userId]);
foreach ($q1Stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $q1Existing[(int)$row['CategoryIndex']] = (int)$row['Points'];
}

// Q2: only rows for QuestionNumber 1 & 2 now
$q2Existing = [
    1 => [],
    2 => [],
];
$q2Stmt = $pdo->prepare("
    SELECT QuestionNumber, Choice1Index, Choice2Index
    FROM ML_Q2Answers
    WHERE SeasonID = ?
      AND UserID = ?
      AND QuestionNumber IN (1,2)
");
$q2Stmt->execute([$seasonId, $userId]);
foreach ($q2Stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $qn = (int)$row['QuestionNumber'];
    $q2Existing[$qn] = [(int)$row['Choice1Index'], (int)$row['Choice2Index']];
}

// Q3: single row with two choices
$q3Existing = [];
$q3Stmt = $pdo->prepare("
    SELECT Choice1Index, Choice2Index
    FROM ML_Q3Answers
    WHERE SeasonID = ?
      AND UserID = ?
");
$q3Stmt->execute([$seasonId, $userId]);
if ($row = $q3Stmt->fetch(PDO::FETCH_ASSOC)) {
    $q3Existing = [
        (int)$row['Choice1Index'],
        (int)$row['Choice2Index'],
    ];
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
            Step <span id="step-label">1 of 4</span>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill"></div>
        </div>

        <form method="post" action="<?= htmlspecialchars(mlUrl('season-builder/submit.php')) ?>" id="ml_form">
            <input type="hidden" name="user_id" value="<?= (int)$userId ?>">

            <!-- STEP 1: Q1 -->
            <div class="step current" data-step="1">
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
                            <div class="cat-title">
                                <?= htmlspecialchars($cat['Title']) ?>
                            </div>
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

                <div class="buttons">
                    <span></span>
                    <button type="button" class="button-primary" id="next-step-1" disabled>
                        Next &raquo;
                    </button>
                </div>
            </div>

            <!-- STEP 2: Q2 Part 1 -->
            <div class="step" data-step="2">
                <div class="step-header">
                    <h2><?= $mlHeadings['q2']['wizard'][1]; ?></h2>
                    <div class="counter-value counter-value-q1" id="q2-counter-1">0 / 2</div>
                </div>

                <div class="q2-group">
                    <?php foreach ($q2Part1 as $idx => $label): ?>
                        <?php
                        $idxInt = (int)$idx;
                        $checked = in_array($idxInt, $q2Existing[1] ?? [], true);
                        ?>
                        <label class="option-row">
                            <input type="checkbox"
                                   name="q2[1][]"
                                   value="<?= $idxInt ?>"
                                   <?= $checked ? 'checked' : '' ?>>
                            <span class="option-box">
                                <span class="option-check"></span>
                                <span class="option-label"><?= htmlspecialchars($label) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="buttons">
                    <button type="button" class="button-secondary" id="back-step-2">&laquo; Back</button>
                    <button type="button" class="button-primary" id="next-step-2" disabled>Next &raquo;</button>
                </div>
            </div>

            <!-- STEP 3: Q2 Part 2 -->
            <div class="step" data-step="3">
                <div class="step-header">
                    <h2><?= $mlHeadings['q2']['wizard'][2]; ?></h2>
                    <div class="counter-value counter-value-q1" id="q2-counter-2">0 / 2</div>
                </div>

                <div class="q2-group">
                    <?php foreach ($q2Part2 as $idx => $label): ?>
                        <?php
                        $idxInt = (int)$idx;
                        $checked = in_array($idxInt, $q2Existing[2] ?? [], true);
                        ?>
                        <label class="option-row">
                            <input type="checkbox"
                                   name="q2[2][]"
                                   value="<?= $idxInt ?>"
                                   <?= $checked ? 'checked' : '' ?>>
                            <span class="option-box">
                                <span class="option-check"></span>
                                <span class="option-label"><?= htmlspecialchars($label) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="buttons">
                    <button type="button" class="button-secondary" id="back-step-3">&laquo; Back</button>
                    <button type="button" class="button-primary" id="next-step-3" disabled>Next &raquo;</button>
                </div>
            </div>

            <!-- STEP 4: Q3 -->
            <div class="step" data-step="4">
                <div class="step-header">
                    <h2><?= $mlHeadings['q3']['wizard']; ?></h2>
                    <div class="counter-value counter-value-q1" id="q3-counter">0 / 2</div>
                </div>

                <div class="q3-group">
                    <?php foreach ($q3Options as $idx => $label): ?>
                        <?php
                        $idxInt = (int)$idx;
                        $checked = in_array($idxInt, $q3Existing, true);
                        ?>
                        <label class="option-row">
                            <input type="checkbox"
                                   name="q3[]"
                                   value="<?= $idxInt ?>"
                                   <?= $checked ? 'checked' : '' ?>>
                            <span class="option-box">
                                <span class="option-check"></span>
                                <span class="option-label"><?= htmlspecialchars($label) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="buttons">
                    <button type="button" class="button-secondary" id="back-step-4">&laquo; Back</button>
                    <button type="submit" class="button-primary" id="submit-button" disabled>
                        Submit my picks
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>
</body>
</html>
