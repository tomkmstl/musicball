<?php
require_once __DIR__ . '/../session_boot.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sb_season_builder.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$targetSeasonId = 0;
if (isset($_GET['season_id'])) {
    $targetSeasonId = (int)$_GET['season_id'];
} elseif (isset($_POST['season_id'])) {
    $targetSeasonId = (int)$_POST['season_id'];
}

if ($targetSeasonId <= 0) {
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$adminMessage = isset($_SESSION['ml_admin_message']) ? (string)$_SESSION['ml_admin_message'] : '';
unset($_SESSION['ml_admin_message']);
$adminError = isset($_SESSION['ml_admin_error']) ? (string)$_SESSION['ml_admin_error'] : '';
unset($_SESSION['ml_admin_error']);

$overrideDates = isset($_GET['override_dates']) && $_GET['override_dates'] === '1';
$overrideQuerySuffix = $overrideDates ? '&override_dates=1' : '';

$slotCount = 12;
$seasonBuilderReady = mlSeasonBuilderAvailable($pdo);

function mlBuildCategorySlotsFromPost() {
    $slots = [];

    // "round_ideas" is the new user-facing name. Keep the old "categories"
    // payload as a temporary fallback so an already-open admin form does not
    // break if it is submitted after this update is deployed.
    if (isset($_POST['round_ideas']) && is_array($_POST['round_ideas'])) {
        $posted = $_POST['round_ideas'];
    } elseif (isset($_POST['categories']) && is_array($_POST['categories'])) {
        $posted = $_POST['categories'];
    } else {
        $posted = [];
    }

    foreach ($posted as $row) {
        if (!is_array($row)) {
            continue;
        }

        $slots[] = [
            'title' => trim((string)($row['title'] ?? '')),
            'description' => trim((string)($row['description'] ?? '')),
        ];
    }

    return $slots;
}

function mlLoadCategorySlots(PDO $pdo, $seasonId) {
    $slots = [];

    $stmt = $pdo->prepare('SELECT CategoryIndex, Title, Description FROM ML_Q1Categories WHERE SeasonID = ? ORDER BY CategoryIndex');
    $stmt->execute([(int)$seasonId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slots[] = [
            'title' => (string)$row['Title'],
            'description' => (string)($row['Description'] ?? ''),
        ];
    }

    return $slots;
}

function mlBuildQ2OptionsFromPost() {
    $parts = [1 => [], 2 => []];
    $posted = isset($_POST['q2_options']) && is_array($_POST['q2_options']) ? $_POST['q2_options'] : [];

    foreach ([1, 2] as $part) {
        $postedPart = isset($posted[$part]) && is_array($posted[$part]) ? $posted[$part] : [];
        foreach ($postedPart as $label) {
            $parts[$part][] = trim((string)$label);
        }
    }

    return $parts;
}

function mlLoadQ2OptionsForSetup(PDO $pdo, $seasonId, array $questionConfig, $optionsInitialized) {
    $parts = [1 => [], 2 => []];

    // Before the admin has configured Madlibs for this season, retain the
    // existing starter choices. Once saved, load only the season-specific
    // rows so an intentionally empty or incomplete draft stays empty.
    if ($optionsInitialized) {
        $stmt = $pdo->prepare(
            'SELECT PartNumber, OptionIndex, Label
             FROM ML_SeasonQ2Options
             WHERE SeasonID = ?
             ORDER BY PartNumber, OptionIndex'
        );
        $stmt->execute([(int)$seasonId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $part = (int)$row['PartNumber'];
            if (!isset($parts[$part])) {
                continue;
            }
            $parts[$part][] = (string)$row['Label'];
        }

        return $parts;
    }

    foreach ([1, 2] as $part) {
        $configured = isset($questionConfig['q2Options'][$part]) && is_array($questionConfig['q2Options'][$part])
            ? $questionConfig['q2Options'][$part]
            : [];

        foreach ($configured as $label) {
            $parts[$part][] = (string)$label;
        }
    }

    return $parts;
}

function mlBuildOptionVoteRounds(array $roundSlots) {
    $optionVoteRounds = [];

    foreach ($roundSlots as $roundNumber => $slot) {
        if (($slot['round_type'] ?? '') !== 'q3_era') {
            continue;
        }

        $optionVoteRounds[(int)$roundNumber] = [
            'round_number' => (int)$roundNumber,
            'name' => trim((string)($slot['tag_override'] ?? '')),
            'selections_per_player' => 1,
            'choices' => [],
        ];
    }

    return $optionVoteRounds;
}

function mlOptionVoteSelectionSettingReady(PDO $pdo) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_SeasonRoundSlots LIKE 'OptionVoteSelections'");
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function mlLoadOptionVoteSelectionCounts(PDO $pdo, $seasonId, array &$optionVoteRounds) {
    if (empty($optionVoteRounds) || !mlOptionVoteSelectionSettingReady($pdo)) {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT RoundNumber, OptionVoteSelections
         FROM ML_SeasonRoundSlots
         WHERE SeasonID = ? AND RoundType = 'q3_era'
         ORDER BY RoundNumber"
    );
    $stmt->execute([(int)$seasonId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roundNumber = (int)$row['RoundNumber'];
        if (!isset($optionVoteRounds[$roundNumber])) {
            continue;
        }

        $selectionCount = (int)($row['OptionVoteSelections'] ?? 0);
        $optionVoteRounds[$roundNumber]['selections_per_player'] = $selectionCount > 0 ? $selectionCount : 1;
    }
}

function mlLoadOptionVoteChoices(PDO $pdo, $seasonId, array &$optionVoteRounds) {
    if (empty($optionVoteRounds) || !mlTableExists($pdo, 'ML_SeasonRoundOptionChoices')) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT RoundNumber, OptionIndex, ChoiceText
         FROM ML_SeasonRoundOptionChoices
         WHERE SeasonID = ?
         ORDER BY RoundNumber, OptionIndex'
    );
    $stmt->execute([(int)$seasonId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roundNumber = (int)$row['RoundNumber'];
        if (!isset($optionVoteRounds[$roundNumber])) {
            continue;
        }

        $optionVoteRounds[$roundNumber]['choices'][] = (string)$row['ChoiceText'];
    }
}

function mlApplyOptionVoteChoicesFromPost(array &$optionVoteRounds) {
    $postedOptionVotes = isset($_POST['option_votes']) && is_array($_POST['option_votes'])
        ? $_POST['option_votes']
        : [];

    foreach ($optionVoteRounds as $roundNumber => &$optionVote) {
        $postedChoices = isset($postedOptionVotes[$roundNumber]) && is_array($postedOptionVotes[$roundNumber])
            ? $postedOptionVotes[$roundNumber]
            : [];

        $choices = [];
        foreach ($postedChoices as $choice) {
            $choice = trim((string)$choice);
            if ($choice === '') {
                continue;
            }

            if (strlen($choice) > 150) {
                throw new RuntimeException(
                    'Round ' . $roundNumber . ' Option Vote choices must be 150 characters or fewer.'
                );
            }

            $choices[] = $choice;
        }

        $optionVote['choices'] = $choices;
    }
    unset($optionVote);
}

function mlApplyOptionVoteSelectionCountsFromPost(array &$optionVoteRounds) {
    $postedSelections = isset($_POST['option_vote_selections']) && is_array($_POST['option_vote_selections'])
        ? $_POST['option_vote_selections']
        : [];

    foreach ($optionVoteRounds as $roundNumber => &$optionVote) {
        $rawValue = $postedSelections[$roundNumber] ?? '';
        if (is_array($rawValue) || is_object($rawValue)) {
            throw new RuntimeException('Round ' . $roundNumber . ' Option Vote has an invalid selections-per-player value.');
        }

        $rawValue = trim((string)$rawValue);
        if ($rawValue === '' || !ctype_digit($rawValue)) {
            throw new RuntimeException('Round ' . $roundNumber . ' Option Vote needs a whole-number selections-per-player value.');
        }

        $selectionCount = (int)$rawValue;
        if ($selectionCount < 1 || $selectionCount > 255) {
            throw new RuntimeException('Round ' . $roundNumber . ' Option Vote selections per player must be between 1 and 255.');
        }

        $optionVote['selections_per_player'] = $selectionCount;
    }
    unset($optionVote);
}

function mlScheduleValueIsPastUtc($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }

    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        $now = new DateTime('now', new DateTimeZone('UTC'));
        return $dt < $now;
    } catch (Throwable $e) {
        return false;
    }
}

$seasonStmt = $pdo->prepare('SELECT SeasonID, SeasonName, IsActive FROM ML_Seasons WHERE SeasonID = ? LIMIT 1');
$seasonStmt->execute([$targetSeasonId]);
$setupSeason = $seasonStmt->fetch(PDO::FETCH_ASSOC);

if (!$setupSeason) {
    $_SESSION['ml_admin_error'] = 'That season could not be found.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$questionConfig = mlLoadSeasonQuestionConfig($pdo, $targetSeasonId);
$categorySlots = mlLoadCategorySlots($pdo, $targetSeasonId);

$madlibsOptionsInitialized = ((string)mlGetSeasonConfig(
    $pdo,
    $targetSeasonId,
    'madlibs_options_initialized',
    '0'
) === '1');
$q2OptionsForSetup = mlLoadQ2OptionsForSetup(
    $pdo,
    $targetSeasonId,
    $questionConfig,
    $madlibsOptionsInitialized
);

$roundSlots = mlLoadSeasonRoundSlots($pdo, $targetSeasonId, $slotCount);
$questionRequirements = mlGetRoundQuestionRequirements($roundSlots);
$q1Enabled = (bool)$questionRequirements['q1_enabled'];
$q1MinimumCategories = (int)$questionRequirements['q1_minimum_categories'];
$madlibsEnabled = (bool)$questionRequirements['madlibs_enabled'];
$optionVoteRounds = mlBuildOptionVoteRounds($roundSlots);
$optionVoteStorageReady = mlTableExists($pdo, 'ML_SeasonRoundOptionChoices');
$optionVoteSelectionSettingReady = mlOptionVoteSelectionSettingReady($pdo);
$optionVotePlayerStorageReady = mlTableExists($pdo, 'ML_SeasonRoundOptionVotes');
mlLoadOptionVoteChoices($pdo, $targetSeasonId, $optionVoteRounds);
if ($optionVoteSelectionSettingReady) {
    mlLoadOptionVoteSelectionCounts($pdo, $targetSeasonId, $optionVoteRounds);
}
$seasonHasBegun = ((int)$setupSeason['IsActive'] === 1);

$existingSubmissionStmt = $pdo->prepare('SELECT COUNT(DISTINCT UserID) FROM ML_Submissions WHERE SeasonID = ?');
$existingSubmissionStmt->execute([$targetSeasonId]);
$existingSubmissionCount = (int)$existingSubmissionStmt->fetchColumn();

if (!$seasonHasBegun) {
    foreach ($roundSlots as $slot) {
        if (
            mlScheduleValueIsPastUtc($slot['schedule_left']) ||
            mlScheduleValueIsPastUtc($slot['schedule_right'])
        ) {
            $seasonHasBegun = true;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setupAction = isset($_POST['setup_action']) ? (string)$_POST['setup_action'] : '';

    try {
        if (!$seasonBuilderReady) {
            throw new RuntimeException('Run the database migration first: db/ml_season_builder_schema.sql');
        }

        if (!in_array($setupAction, ['save_options', 'start_voting'], true)) {
            throw new RuntimeException('Unknown season options action.');
        }

        if ($existingSubmissionCount > 0) {
            throw new RuntimeException(
                'Voting options can no longer be changed because player submissions already exist for this season.'
            );
        }

        if ($q1Enabled) {
            $categorySlots = mlBuildCategorySlotsFromPost();
        }
        if ($madlibsEnabled) {
            $q2OptionsForSetup = mlBuildQ2OptionsFromPost();
        }
        mlApplyOptionVoteChoicesFromPost($optionVoteRounds);
        mlApplyOptionVoteSelectionCountsFromPost($optionVoteRounds);

        if (!empty($optionVoteRounds) && !$optionVoteStorageReady) {
            throw new RuntimeException(
                'Create ML_SeasonRoundOptionChoices before saving Option Vote configuration.'
            );
        }

        if (!empty($optionVoteRounds) && !$optionVoteSelectionSettingReady) {
            throw new RuntimeException(
                'Add OptionVoteSelections to ML_SeasonRoundSlots before saving Option Vote configuration.'
            );
        }

        if ($q1Enabled) {
            foreach ($categorySlots as $index => $slot) {
                if ($slot['title'] === '' && $slot['description'] !== '') {
                    throw new RuntimeException('Round idea ' . ($index + 1) . ' has a description but no title.');
                }
            }
        }

        $configuredCategoryCount = 0;
        foreach ($categorySlots as $slot) {
            if ($slot['title'] !== '') {
                $configuredCategoryCount++;
            }
        }

        $q2Part1Count = 0;
        $q2Part2Count = 0;
        foreach ($q2OptionsForSetup[1] as $label) {
            if ($label !== '') { $q2Part1Count++; }
        }
        foreach ($q2OptionsForSetup[2] as $label) {
            if ($label !== '') { $q2Part2Count++; }
        }

        if ($setupAction === 'start_voting') {
            if (!empty($optionVoteRounds) && !$optionVotePlayerStorageReady) {
                throw new RuntimeException(
                    'Create ML_SeasonRoundOptionVotes before starting voting with Option Vote rounds.'
                );
            }
            foreach ($optionVoteRounds as $roundNumber => $optionVote) {
                $choiceCount = count($optionVote['choices']);
                $selectionCount = max(1, (int)$optionVote['selections_per_player']);
                if ($choiceCount <= $selectionCount) {
                    throw new RuntimeException(
                        'Round ' . $roundNumber . ' (' . $optionVote['name'] . ') needs at least ' .
                        ($selectionCount + 1) . ' choices when players select ' . $selectionCount . '.'
                    );
                }
            }
            if (!$overrideDates && $seasonHasBegun) {
                throw new RuntimeException('Season Builder voting cannot be started after this season has already begun.');
            }
            if ($q1Enabled && $configuredCategoryCount < $q1MinimumCategories) {
                throw new RuntimeException(
                    'Add at least ' . $q1MinimumCategories . ' User Submitted Round ideas before starting voting. ' .
                    'The current round structure uses results through ' . mlOrdinalLabel((int)$questionRequirements['q1_max_rank']) . ' place.'
                );
            }
            if ($madlibsEnabled && ($q2Part1Count < 2 || $q2Part2Count < 2)) {
                throw new RuntimeException('Each Madlibs column needs at least 2 options before starting voting.');
            }
            $validRoundTypes = ['fixed', 'q1_ranked_category', 'q2_madlib', 'q3_era', 'walkman'];
            $usedQ1Ranks = [];
            $configuredRoundCount = 0;

            foreach ($roundSlots as $roundNumber => $slot) {
                $roundType = $slot['round_type'];
                if ($roundType === '') {
                    throw new RuntimeException('Choose a round type for round ' . $roundNumber . ' before starting voting.');
                }

                $configuredRoundCount++;

                if (!in_array($roundType, $validRoundTypes, true)) {
                    throw new RuntimeException('Round ' . $roundNumber . ' has an invalid round type.');
                }

                if ($roundType === 'fixed' && $slot['fixed_round_id'] === '') {
                    throw new RuntimeException('Round ' . $roundNumber . ' is fixed, but no fixed round was selected.');
                }

                if ($roundType === 'q1_ranked_category') {
                    $rank = (int)$slot['q1_rank'];
                    if ($rank <= 0) {
                        throw new RuntimeException('Round ' . $roundNumber . ' needs a User Submitted Round finishing position.');
                    }
                    if ($rank > $configuredCategoryCount) {
                        throw new RuntimeException('Round ' . $roundNumber . ' uses the ' . mlOrdinalLabel($rank) . '-place User Submitted Round result, but only ' . $configuredCategoryCount . ' round ideas are configured.');
                    }
                    if (isset($usedQ1Ranks[$rank])) {
                        throw new RuntimeException('The ' . mlOrdinalLabel($rank) . '-place User Submitted Round result is used more than once in the round order.');
                    }
                    $usedQ1Ranks[$rank] = true;
                }
            }

            if ($configuredRoundCount !== $slotCount) {
                throw new RuntimeException('Configure all ' . $slotCount . ' rounds before starting voting.');
            }
        }

        $pdo->beginTransaction();

        if ($q1Enabled) {
            $pdo->prepare('DELETE FROM ML_Q1Categories WHERE SeasonID = ?')->execute([$targetSeasonId]);
            $insertCategoryStmt = $pdo->prepare('INSERT INTO ML_Q1Categories (SeasonID, CategoryIndex, Title, Description) VALUES (?, ?, ?, ?)');
            $categoryIndex = 1;
            foreach ($categorySlots as $slot) {
                if ($slot['title'] === '') {
                    continue;
                }

                $insertCategoryStmt->execute([
                    $targetSeasonId,
                    $categoryIndex,
                    $slot['title'],
                    $slot['description'] !== '' ? $slot['description'] : null,
                ]);
                $categoryIndex++;
            }
        }

        if ($madlibsEnabled) {
            $pdo->prepare('DELETE FROM ML_SeasonQ2Options WHERE SeasonID = ?')->execute([$targetSeasonId]);
            $insertQ2Stmt = $pdo->prepare('INSERT INTO ML_SeasonQ2Options (SeasonID, PartNumber, OptionIndex, Label) VALUES (?, ?, ?, ?)');
            foreach ([1, 2] as $part) {
                $optionIndex = 1;
                foreach ($q2OptionsForSetup[$part] as $label) {
                    if ($label === '') {
                        continue;
                    }
                    $insertQ2Stmt->execute([$targetSeasonId, $part, $optionIndex, $label]);
                    $optionIndex++;
                }
            }
            mlSetSeasonConfig($pdo, $targetSeasonId, 'madlibs_options_initialized', '1');
        }

        if (!empty($optionVoteRounds)) {
            $updateOptionVoteSelectionsStmt = $pdo->prepare(
                "UPDATE ML_SeasonRoundSlots
                 SET OptionVoteSelections = ?
                 WHERE SeasonID = ? AND RoundNumber = ? AND RoundType = 'q3_era'"
            );
            $deleteOptionChoicesStmt = $pdo->prepare(
                'DELETE FROM ML_SeasonRoundOptionChoices
                 WHERE SeasonID = ? AND RoundNumber = ?'
            );
            $insertOptionChoiceStmt = $pdo->prepare(
                'INSERT INTO ML_SeasonRoundOptionChoices
                    (SeasonID, RoundNumber, OptionIndex, ChoiceText)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($optionVoteRounds as $roundNumber => $optionVote) {
                $updateOptionVoteSelectionsStmt->execute([
                    (int)$optionVote['selections_per_player'],
                    $targetSeasonId,
                    $roundNumber,
                ]);

                // Only rewrite choices for rounds that are currently Option Votes.
                // Choices belonging to a round that was changed to another type are
                // intentionally retained so they can be recovered if the admin
                // changes that slot back later.
                $deleteOptionChoicesStmt->execute([$targetSeasonId, $roundNumber]);

                $optionIndex = 1;
                foreach ($optionVote['choices'] as $choice) {
                    $insertOptionChoiceStmt->execute([
                        $targetSeasonId,
                        $roundNumber,
                        $optionIndex,
                        $choice,
                    ]);
                    $optionIndex++;
                }
            }
        }

        if ($setupAction === 'start_voting') {
            mlSetSeasonConfig($pdo, $targetSeasonId, 'voting_open', '1');
            $_SESSION['ml_admin_message'] = 'Voting is now live for ' . $setupSeason['SeasonName'] . '.';
        } else {
            $_SESSION['ml_admin_message'] = 'Voting options saved for ' . $setupSeason['SeasonName'] . '.';
        }

        $pdo->commit();
        header('Location: ' . mlUrl('season-builder/season_options.php?season_id=' . $targetSeasonId . $overrideQuerySuffix));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $adminError = $e->getMessage();
    }
}

$setupVotingOpen = ((string)mlGetSeasonConfig($pdo, $targetSeasonId, 'voting_open', '0') === '1');
$setupIsActive = ((int)$setupSeason['IsActive'] === 1);

$totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM ML_Users');
$totalUsers = (int)$totalUsersStmt->fetchColumn();

$submissionStmt = $pdo->prepare('SELECT COUNT(DISTINCT UserID) FROM ML_Submissions WHERE SeasonID = ?');
$submissionStmt->execute([$targetSeasonId]);
$submissionCount = (int)$submissionStmt->fetchColumn();

$configuredCategoryCount = 0;
foreach ($categorySlots as $slot) {
    if ($slot['title'] !== '') {
        $configuredCategoryCount++;
    }
}

$q2Part1Count = 0;
$q2Part2Count = 0;
foreach ($q2OptionsForSetup[1] as $label) {
    if ($label !== '') { $q2Part1Count++; }
}
foreach ($q2OptionsForSetup[2] as $label) {
    if ($label !== '') { $q2Part2Count++; }
}

$optionVoteChoiceCount = 0;
$optionVoteIncompleteRounds = [];
foreach ($optionVoteRounds as $roundNumber => $optionVote) {
    $choiceCount = count($optionVote['choices']);
    $optionVoteChoiceCount += $choiceCount;

    $selectionCount = max(1, (int)($optionVote['selections_per_player'] ?? 1));
    if ($choiceCount <= $selectionCount) {
        $optionVoteIncompleteRounds[] = (int)$roundNumber;
    }
}

$configuredRoundCount = 0;
foreach ($roundSlots as $slot) {
    if ($slot['round_type'] !== '') {
        $configuredRoundCount++;
    }
}

$startButtonLabel = 'Start ' . $setupSeason['SeasonName'] . ' Voting';
$startVotingDisabled = (
    !$seasonBuilderReady
    || ($seasonHasBegun && !$overrideDates)
    || $configuredRoundCount !== $slotCount
    || ($q1Enabled && $configuredCategoryCount < $q1MinimumCategories)
    || ($madlibsEnabled && ($q2Part1Count < 2 || $q2Part2Count < 2))
    || !empty($optionVoteIncompleteRounds)
    || (!empty($optionVoteRounds) && !$optionVotePlayerStorageReady)
    || (!$q1Enabled && !$madlibsEnabled && empty($optionVoteRounds))
    || $submissionCount > 0
);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Season Options</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('season-builder/season-builder.css')) ?>">
    <?php require_once __DIR__ . '/../pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'admin'; include __DIR__ . '/../header.php'; ?>
<div class="wrapper">
    <div class="card admin-card admin-card-wide">
        <div class="admin-page-topline">
            <div>
                <div class="home-shell-kicker">Season options</div>
                <h1><?= htmlspecialchars($setupSeason['SeasonName']) ?></h1>
                <p>Step 2 of 2: configure the voting choices for the season structure you just saved.</p>
            </div>
            <div class="admin-section-actions">
                <a href="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . (int)$targetSeasonId . $overrideQuerySuffix)) ?>" class="button-secondary admin-back-link">&laquo; Edit Round Structure</a>
                <a href="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="button-secondary admin-back-link">Back to Admin</a>
            </div>
        </div>

        <?php if ($adminMessage !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($adminMessage) ?></div>
        <?php endif; ?>

        <?php if ($adminError !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($adminError) ?></div>
        <?php endif; ?>

        <?php if (!$seasonBuilderReady): ?>
            <div class="status-banner">
                Advanced season setup needs the new database tables first. Run <strong>db/ml_season_builder_schema.sql</strong>, then reload this page.
            </div>
        <?php endif; ?>

        <?php if (!empty($optionVoteRounds) && !$optionVoteStorageReady): ?>
            <div class="status-banner error">
                Option Vote configuration needs the new <strong>ML_SeasonRoundOptionChoices</strong> table before options can be saved.
            </div>
        <?php endif; ?>

        <?php if (!empty($optionVoteRounds) && !$optionVoteSelectionSettingReady): ?>
            <div class="status-banner error">
                Option Vote configuration also needs the <strong>OptionVoteSelections</strong> column on <strong>ML_SeasonRoundSlots</strong>.
            </div>
        <?php endif; ?>

        <?php if (!empty($optionVoteRounds) && !$optionVotePlayerStorageReady): ?>
            <div class="status-banner error">
                Player voting for Option Votes needs the new <strong>ML_SeasonRoundOptionVotes</strong> table. Run the supplied migration before starting voting.
            </div>
        <?php elseif (!empty($optionVoteRounds)): ?>
            <div class="status-banner success">
                Option Vote player voting is ready. Each configured Option Vote will appear as its own voting step using its saved selections-per-player setting.
            </div>
        <?php endif; ?>

        <?php if ($configuredRoundCount !== $slotCount): ?>
            <div class="status-banner error">
                The season structure is incomplete (<?= $configuredRoundCount ?> / <?= $slotCount ?> rounds configured). Edit the round structure before starting voting.
            </div>
        <?php endif; ?>

        <?php if (!$q1Enabled && !$madlibsEnabled && empty($optionVoteRounds)): ?>
            <div class="status-banner">
                This round structure does not contain any preseason voting rounds. The current voting workflow expects at least one of User Submitted Rounds, Madlibs, or Option Vote.
            </div>
        <?php endif; ?>

        <?php if ($submissionCount > 0): ?>
            <div class="status-banner">
                Player submissions already exist for this season, so voting options are now locked to protect submitted votes.
            </div>
        <?php endif; ?>

        <section class="admin-panel admin-panel-full">
            <div class="home-shell-kicker">Status</div>
            <p>
                <strong><?= htmlspecialchars($setupSeason['SeasonName']) ?></strong>
                <span class="pill <?= $setupVotingOpen ? 'pill-open' : 'pill-closed' ?>">
                    <?= $setupVotingOpen ? 'Voting Open' : 'Voting Closed' ?>
                </span>
            </p>
            <p>Submissions: <strong><?= $submissionCount ?> / <?= $totalUsers ?></strong></p>
            <p>Configured rounds: <strong><?= $configuredRoundCount ?> / <?= $slotCount ?></strong></p>
            <?php if ($q1Enabled): ?>
                <p>User Submitted Round ideas: <strong><?= $configuredCategoryCount ?></strong> / <?= (int)$q1MinimumCategories ?> minimum</p>
            <?php endif; ?>
            <?php if ($madlibsEnabled): ?>
                <p>Madlibs options: <strong><?= $q2Part1Count ?></strong> + <strong><?= $q2Part2Count ?></strong></p>
            <?php endif; ?>
            <p>
                Option Votes: <strong><?= count($optionVoteRounds) ?></strong>
                <?php if (!empty($optionVoteRounds)): ?>
                    · Choices: <strong><?= $optionVoteChoiceCount ?></strong>
                <?php endif; ?>
            </p>
            <?php if ($setupIsActive): ?>
                <p>This season is currently marked as the active voting target.</p>
            <?php endif; ?>
        </section>

        <form method="post" action="<?= htmlspecialchars(mlUrl('season-builder/season_options.php?season_id=' . (int)$targetSeasonId . $overrideQuerySuffix)) ?>" class="admin-season-setup-form">
            <input type="hidden" name="season_id" value="<?= (int)$targetSeasonId ?>">

            <?php if ($q1Enabled): ?>
                <?php
                $displayRoundIdeas = array_values($categorySlots);
                $minimumDisplayIdeas = max(3, (int)$q1MinimumCategories);
                while (count($displayRoundIdeas) < $minimumDisplayIdeas) {
                    $displayRoundIdeas[] = ['title' => '', 'description' => ''];
                }
                ?>
                <section class="admin-panel admin-panel-full" data-round-ideas-editor>
                    <div class="home-shell-kicker">User Submitted Rounds</div>
                    <h2>Round ideas users will rank</h2>
                    <p>
                        Enter the round ideas submitted by league members (or by the admin).
                        Players distribute 10 points across these ideas, with a maximum of 4 points on any one idea.
                        Your Round Builder uses results through <strong><?= mlOrdinalLabel((int)$questionRequirements['q1_max_rank']) ?></strong> place,
                        so configure at least <strong><?= (int)$q1MinimumCategories ?></strong> round ideas before starting voting.
                    </p>

                    <div class="admin-round-idea-list" data-round-idea-list>
                        <?php foreach ($displayRoundIdeas as $index => $slot): ?>
                            <div class="admin-category-card admin-round-idea-card" data-round-idea-row>
                                <div class="admin-round-idea-heading">
                                    <div class="admin-category-number" data-round-idea-number>Round Idea <?= $index + 1 ?></div>
                                    <button type="button" class="button-secondary admin-round-idea-remove" data-remove-round-idea>Remove</button>
                                </div>

                                <label class="admin-label" for="round-idea-title-<?= $index ?>">Title</label>
                                <input
                                    type="text"
                                    id="round-idea-title-<?= $index ?>"
                                    name="round_ideas[<?= $index ?>][title]"
                                    class="admin-input"
                                    value="<?= htmlspecialchars($slot['title']) ?>"
                                    placeholder="Round idea"
                                    data-round-idea-title
                                >

                                <label class="admin-label admin-label-spaced" for="round-idea-description-<?= $index ?>">Description <span class="note">(optional)</span></label>
                                <textarea
                                    id="round-idea-description-<?= $index ?>"
                                    name="round_ideas[<?= $index ?>][description]"
                                    class="admin-input admin-textarea"
                                    data-round-idea-description
                                ><?= htmlspecialchars($slot['description']) ?></textarea>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="admin-round-idea-actions">
                        <button type="button" class="button-secondary" data-add-round-idea>+ Add Round Idea</button>
                        <span class="admin-option-vote-hint">Save Options can be used while this list is still incomplete.</span>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($madlibsEnabled): ?>
            <section class="admin-panel admin-panel-full">
                <div class="home-shell-kicker">Madlibs</div>
                <h2>Madlibs options</h2>
                <p>Define the two pools players will vote on. The winning choices are combined into the Madlibs round.</p>
                <div class="admin-madlib-grid">
                    <?php
                    $madlibsPoolLabels = [
                        1 => 'Main Character',
                        2 => 'Doing a Thing',
                    ];
                    ?>
                    <?php foreach ($madlibsPoolLabels as $part => $poolLabel): ?>
                        <?php
                        $displayOptions = $q2OptionsForSetup[$part];
                        while (count($displayOptions) < 3) {
                            $displayOptions[] = '';
                        }
                        ?>
                        <div class="admin-subpanel admin-madlib-editor" data-madlib-editor data-part="<?= (int)$part ?>">
                            <h3><?= htmlspecialchars($poolLabel) ?></h3>
                            <div class="admin-madlib-list" data-madlib-list>
                                <?php foreach ($displayOptions as $index => $label): ?>
                                    <div class="admin-madlib-row" data-madlib-row>
                                        <span class="admin-madlib-index" data-madlib-index><?= (int)$index + 1 ?></span>
                                        <input
                                            type="text"
                                            id="q2-part<?= (int)$part ?>-<?= (int)$index ?>"
                                            name="q2_options[<?= (int)$part ?>][]"
                                            class="admin-input"
                                            value="<?= htmlspecialchars($label) ?>"
                                            maxlength="150"
                                            placeholder="Choice"
                                            aria-label="<?= htmlspecialchars($poolLabel) ?> choice <?= (int)$index + 1 ?>"
                                        >
                                        <button type="button" class="button-secondary admin-madlib-remove" data-remove-madlib-choice>Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="admin-madlib-actions">
                                <button type="button" class="button-secondary" data-add-madlib-choice>+ Add Choice</button>
                                <span class="admin-option-vote-hint">At least 2 nonblank choices are required before voting can begin.</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="admin-option-vote-hint admin-madlib-draft-hint">Save Options can be used while either list is still incomplete.</p>
            </section>
            <?php endif; ?>

            <?php foreach ($optionVoteRounds as $roundNumber => $optionVote): ?>
                <?php
                $displayChoices = $optionVote['choices'];
                while (count($displayChoices) < 3) {
                    $displayChoices[] = '';
                }
                ?>
                <section
                    class="admin-panel admin-panel-full"
                    data-option-vote-editor
                    data-round-number="<?= (int)$roundNumber ?>"
                >
                    <div class="home-shell-kicker">Option Vote · Round <?= (int)$roundNumber ?></div>
                    <h2><?= htmlspecialchars($optionVote['name'] !== '' ? $optionVote['name'] : 'Option Vote') ?></h2>
                    <p>
                        Define the choices users will vote on for this round.
                        Add as many choices as you need; blank rows are ignored when you save.
                    </p>

                    <div class="admin-option-vote-settings">
                        <label class="admin-label" for="option-vote-selections-<?= (int)$roundNumber ?>">Selections per player</label>
                        <div class="admin-option-vote-setting-row">
                            <input
                                type="number"
                                id="option-vote-selections-<?= (int)$roundNumber ?>"
                                name="option_vote_selections[<?= (int)$roundNumber ?>]"
                                class="admin-input admin-option-vote-selection-input"
                                value="<?= (int)$optionVote['selections_per_player'] ?>"
                                min="1"
                                max="255"
                                step="1"
                                required
                                data-option-vote-selection-count
                            >
                            <span class="admin-option-vote-setting-copy">How many choices each player must select.</span>
                        </div>
                        <div class="admin-option-vote-readiness" data-option-vote-readiness></div>
                    </div>

                    <div class="admin-option-vote-list" data-option-vote-list>
                        <?php foreach ($displayChoices as $choiceIndex => $choice): ?>
                            <div class="admin-option-vote-row" data-option-vote-row>
                                <span class="admin-option-vote-index" data-choice-index><?= $choiceIndex + 1 ?></span>
                                <input
                                    type="text"
                                    name="option_votes[<?= (int)$roundNumber ?>][]"
                                    class="admin-input"
                                    value="<?= htmlspecialchars($choice) ?>"
                                    maxlength="150"
                                    placeholder="Choice"
                                    aria-label="<?= htmlspecialchars($optionVote['name'] !== '' ? $optionVote['name'] : 'Option Vote') ?> choice <?= $choiceIndex + 1 ?>"
                                >
                                <button type="button" class="button-secondary admin-option-vote-remove" data-remove-choice>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="admin-option-vote-actions">
                        <button type="button" class="button-secondary" data-add-choice>+ Add Choice</button>
                        <span class="admin-option-vote-hint">
                            Save Options can be used while this round is still incomplete.
                        </span>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="admin-setup-actions">
                <a href="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . (int)$targetSeasonId . $overrideQuerySuffix)) ?>" class="button-secondary">&laquo; Edit Round Structure</a>
                <button type="submit" name="setup_action" value="save_options" class="button-secondary" <?= (!$seasonBuilderReady || $submissionCount > 0) ? 'disabled' : '' ?>>Save Options</button>
                <?php if (!$startVotingDisabled): ?>
                    <button type="submit" name="setup_action" value="start_voting" class="button-primary"><?= htmlspecialchars($startButtonLabel) ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function reindexRoundIdeas(editor) {
        const rows = editor.querySelectorAll('[data-round-idea-row]');

        rows.forEach(function (row, index) {
            const number = index + 1;
            const numberEl = row.querySelector('[data-round-idea-number]');
            const title = row.querySelector('[data-round-idea-title]');
            const description = row.querySelector('[data-round-idea-description]');

            if (numberEl) {
                numberEl.textContent = 'Round Idea ' + number;
            }

            if (title) {
                title.name = 'round_ideas[' + index + '][title]';
                title.id = 'round-idea-title-' + index;
                title.setAttribute('aria-label', 'Round Idea ' + number + ' title');
            }

            if (description) {
                description.name = 'round_ideas[' + index + '][description]';
                description.id = 'round-idea-description-' + index;
                description.setAttribute('aria-label', 'Round Idea ' + number + ' description');
            }

            const labels = row.querySelectorAll('label');
            if (labels[0] && title) {
                labels[0].htmlFor = title.id;
            }
            if (labels[1] && description) {
                labels[1].htmlFor = description.id;
            }
        });
    }

    function createRoundIdeaRow() {
        const row = document.createElement('div');
        row.className = 'admin-category-card admin-round-idea-card';
        row.setAttribute('data-round-idea-row', '');

        const heading = document.createElement('div');
        heading.className = 'admin-round-idea-heading';

        const number = document.createElement('div');
        number.className = 'admin-category-number';
        number.setAttribute('data-round-idea-number', '');

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button-secondary admin-round-idea-remove';
        remove.setAttribute('data-remove-round-idea', '');
        remove.textContent = 'Remove';

        heading.append(number, remove);

        const titleLabel = document.createElement('label');
        titleLabel.className = 'admin-label';
        titleLabel.textContent = 'Title';

        const title = document.createElement('input');
        title.type = 'text';
        title.className = 'admin-input';
        title.placeholder = 'Round idea';
        title.setAttribute('data-round-idea-title', '');

        const descriptionLabel = document.createElement('label');
        descriptionLabel.className = 'admin-label admin-label-spaced';
        descriptionLabel.textContent = 'Description (optional)';

        const description = document.createElement('textarea');
        description.className = 'admin-input admin-textarea';
        description.setAttribute('data-round-idea-description', '');

        row.append(heading, titleLabel, title, descriptionLabel, description);
        return row;
    }

    document.querySelectorAll('[data-round-ideas-editor]').forEach(function (editor) {
        const list = editor.querySelector('[data-round-idea-list]');
        const addButton = editor.querySelector('[data-add-round-idea]');

        if (!list || !addButton) {
            return;
        }

        addButton.addEventListener('click', function () {
            const row = createRoundIdeaRow();
            list.appendChild(row);
            reindexRoundIdeas(editor);

            const title = row.querySelector('[data-round-idea-title]');
            if (title) {
                title.focus();
            }
        });

        list.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-remove-round-idea]');
            if (!removeButton) {
                return;
            }

            const row = removeButton.closest('[data-round-idea-row]');
            if (row) {
                row.remove();
                reindexRoundIdeas(editor);
            }
        });

        reindexRoundIdeas(editor);
    });

    function reindexMadlibChoices(editor) {
        const part = editor.dataset.part;
        const heading = editor.querySelector('h3')?.textContent?.trim() || 'Madlibs';
        const rows = editor.querySelectorAll('[data-madlib-row]');

        rows.forEach(function (row, index) {
            const number = index + 1;
            const numberEl = row.querySelector('[data-madlib-index]');
            const input = row.querySelector('input[type="text"]');

            if (numberEl) {
                numberEl.textContent = String(number);
            }

            if (input) {
                input.name = 'q2_options[' + part + '][]';
                input.id = 'q2-part' + part + '-' + index;
                input.setAttribute('aria-label', heading + ' choice ' + number);
            }
        });
    }

    function createMadlibChoiceRow(editor) {
        const part = editor.dataset.part;
        const row = document.createElement('div');
        row.className = 'admin-madlib-row';
        row.setAttribute('data-madlib-row', '');

        const index = document.createElement('span');
        index.className = 'admin-madlib-index';
        index.setAttribute('data-madlib-index', '');

        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'q2_options[' + part + '][]';
        input.className = 'admin-input';
        input.maxLength = 150;
        input.placeholder = 'Choice';

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button-secondary admin-madlib-remove';
        remove.setAttribute('data-remove-madlib-choice', '');
        remove.textContent = 'Remove';

        row.append(index, input, remove);
        return row;
    }

    document.querySelectorAll('[data-madlib-editor]').forEach(function (editor) {
        const list = editor.querySelector('[data-madlib-list]');
        const addButton = editor.querySelector('[data-add-madlib-choice]');

        if (!list || !addButton) {
            return;
        }

        addButton.addEventListener('click', function () {
            const row = createMadlibChoiceRow(editor);
            list.appendChild(row);
            reindexMadlibChoices(editor);

            const input = row.querySelector('input');
            if (input) {
                input.focus();
            }
        });

        list.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-remove-madlib-choice]');
            if (!removeButton) {
                return;
            }

            const row = removeButton.closest('[data-madlib-row]');
            if (row) {
                row.remove();
                reindexMadlibChoices(editor);
            }
        });

        reindexMadlibChoices(editor);
    });

    function updateReadiness(editor) {
        const selectionInput = editor.querySelector('[data-option-vote-selection-count]');
        const readiness = editor.querySelector('[data-option-vote-readiness]');
        if (!selectionInput || !readiness) {
            return;
        }

        const selections = Math.max(1, parseInt(selectionInput.value, 10) || 1);
        const filledChoices = Array.from(editor.querySelectorAll('[data-option-vote-row] input[type="text"]'))
            .filter(function (input) { return input.value.trim() !== ''; })
            .length;
        const minimumChoices = selections + 1;

        if (filledChoices >= minimumChoices) {
            readiness.textContent = 'Ready: selecting ' + selections + ' requires at least ' + minimumChoices + ' choices, and ' + filledChoices + ' are configured.';
            readiness.classList.add('is-ready');
        } else {
            const needed = minimumChoices - filledChoices;
            readiness.textContent = 'Selecting ' + selections + ' requires at least ' + minimumChoices + ' choices before voting can begin. Add ' + needed + ' more nonblank ' + (needed === 1 ? 'choice' : 'choices') + '.';
            readiness.classList.remove('is-ready');
        }
    }

    function reindex(editor) {
        const rows = editor.querySelectorAll('[data-option-vote-row]');
        const roundName = editor.querySelector('h2')?.textContent?.trim() || 'Option Vote';

        rows.forEach(function (row, index) {
            const number = index + 1;
            const numberEl = row.querySelector('[data-choice-index]');
            const input = row.querySelector('input[type="text"]');

            if (numberEl) {
                numberEl.textContent = String(number);
            }

            if (input) {
                input.setAttribute('aria-label', roundName + ' choice ' + number);
            }
        });

        updateReadiness(editor);
    }

    function createChoiceRow(editor) {
        const roundNumber = editor.dataset.roundNumber;
        const row = document.createElement('div');
        row.className = 'admin-option-vote-row';
        row.setAttribute('data-option-vote-row', '');

        const index = document.createElement('span');
        index.className = 'admin-option-vote-index';
        index.setAttribute('data-choice-index', '');

        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'option_votes[' + roundNumber + '][]';
        input.className = 'admin-input';
        input.maxLength = 150;
        input.placeholder = 'Choice';

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button-secondary admin-option-vote-remove';
        remove.setAttribute('data-remove-choice', '');
        remove.textContent = 'Remove';

        row.append(index, input, remove);
        return row;
    }

    document.querySelectorAll('[data-option-vote-editor]').forEach(function (editor) {
        const list = editor.querySelector('[data-option-vote-list]');
        const addButton = editor.querySelector('[data-add-choice]');
        const selectionInput = editor.querySelector('[data-option-vote-selection-count]');

        if (!list || !addButton || !selectionInput) {
            return;
        }

        addButton.addEventListener('click', function () {
            const row = createChoiceRow(editor);
            list.appendChild(row);
            reindex(editor);

            const input = row.querySelector('input');
            if (input) {
                input.focus();
            }
        });

        list.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-remove-choice]');
            if (!removeButton) {
                return;
            }

            const row = removeButton.closest('[data-option-vote-row]');
            if (row) {
                row.remove();
                reindex(editor);
            }
        });

        list.addEventListener('input', function () {
            updateReadiness(editor);
        });

        selectionInput.addEventListener('input', function () {
            updateReadiness(editor);
        });

        reindex(editor);
    });
})();
</script>

</body>
</html>
