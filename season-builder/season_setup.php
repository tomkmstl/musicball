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

$slotCount = 12;
$seasonBuilderReady = mlSeasonBuilderAvailable($pdo);
$optionVoteQuestionColumnReady = mlColumnExists($pdo, 'ML_SeasonRoundSlots', 'OptionVoteQuestion');

function mlGetDefaultBuilderRoundSlots($slotCount) {
    $defaults = [];
    for ($i = 1; $i <= $slotCount; $i++) {
        $defaults[$i] = [
            'round_number' => $i,
            'round_type' => '',
            'fixed_round_id' => '',
            'q1_rank' => '',
            'title_override' => '',
            'tag_override' => '',
            'option_vote_question' => '',
            'schedule_left' => '',
            'schedule_right' => '',
        ];
    }

    $seed = [
        1  => ['round_type' => 'fixed'],
        2  => ['round_type' => 'q1_ranked_category', 'q1_rank' => '4'],
        3  => ['round_type' => 'walkman'],
        4  => ['round_type' => 'fixed'],
        5  => ['round_type' => 'q1_ranked_category', 'q1_rank' => '1'],
        6  => ['round_type' => 'q1_ranked_category', 'q1_rank' => '5'],
        7  => ['round_type' => 'q3_era'],
        8  => ['round_type' => 'q1_ranked_category', 'q1_rank' => '6'],
        9  => ['round_type' => 'q1_ranked_category', 'q1_rank' => '3'],
        10 => ['round_type' => 'q2_madlib'],
        11 => ['round_type' => 'fixed'],
        12 => ['round_type' => 'q1_ranked_category', 'q1_rank' => '2'],
    ];

    foreach ($seed as $roundNumber => $seedRow) {
        if (isset($defaults[$roundNumber])) {
            $defaults[$roundNumber] = array_merge($defaults[$roundNumber], $seedRow);
        }
    }

    return $defaults;
}

function mlBuildRoundSlotsFromPost($slotCount, array $existingSlots = []) {
    $slots = [];
    $posted = isset($_POST['rounds']) && is_array($_POST['rounds']) ? $_POST['rounds'] : [];

    for ($i = 1; $i <= $slotCount; $i++) {
        $row = isset($posted[$i]) && is_array($posted[$i]) ? $posted[$i] : [];
        $existingRow = isset($existingSlots[$i]) && is_array($existingSlots[$i]) ? $existingSlots[$i] : [];

        $slots[$i] = [
            'round_number' => $i,
            'round_type' => trim((string)($row['round_type'] ?? '')),
            'fixed_round_id' => trim((string)($row['fixed_round_id'] ?? '')),
            'q1_rank' => trim((string)($row['q1_rank'] ?? '')),
            'title_override' => trim((string)($row['title_override'] ?? '')),
            'tag_override' => trim((string)($row['tag_override'] ?? '')),
            'option_vote_question' => trim((string)($row['option_vote_question'] ?? '')),
            'schedule_left' => trim((string)($existingRow['schedule_left'] ?? '')),
            'schedule_right' => trim((string)($existingRow['schedule_right'] ?? '')),
        ];
    }

    return $slots;
}

$seasonStmt = $pdo->prepare('SELECT SeasonID, SeasonName, IsActive FROM ML_Seasons WHERE SeasonID = ? LIMIT 1');
$seasonStmt->execute([$targetSeasonId]);
$setupSeason = $seasonStmt->fetch(PDO::FETCH_ASSOC);

if (!$setupSeason) {
    $_SESSION['ml_admin_error'] = 'That season could not be found.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$fixedRoundLibrary = mlLoadFixedRoundLibrary($pdo);
$roundSlots = mlLoadSeasonRoundSlots($pdo, $targetSeasonId, $slotCount);
$hasSavedSlots = false;
foreach ($roundSlots as $slot) {
    if ($slot['round_type'] !== '') {
        $hasSavedSlots = true;
        break;
    }
}
if (!$hasSavedSlots) {
    $roundSlots = mlGetDefaultBuilderRoundSlots($slotCount);
}

$setupLocked = mlIsSeasonBuilderLocked($pdo, $targetSeasonId);
$loadedRoundSlots = $roundSlots;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setupAction = isset($_POST['setup_action']) ? (string)$_POST['setup_action'] : '';

    try {
        if (!$seasonBuilderReady) {
            throw new RuntimeException('Run the database migration first: db/ml_season_builder_schema.sql');
        }
        if (!$optionVoteQuestionColumnReady) {
            throw new RuntimeException('Run db/ml_option_vote_question.sql before saving the season structure.');
        }
        if ($setupLocked) {
            throw new RuntimeException('Season structure is read-only because Season Builder voting has already opened.');
        }

        if ($setupAction === 'create_fixed_round') {
            $newFixedTitle = trim((string)($_POST['new_fixed_title'] ?? ''));
            $newFixedTag = trim((string)($_POST['new_fixed_tagline'] ?? ''));

            if ($newFixedTitle === '') {
                throw new RuntimeException('Enter a fixed round title before saving it to the library.');
            }

            $insertFixedStmt = $pdo->prepare('INSERT INTO ML_FixedRounds (Title, Tagline, CreatedSeasonID, IsActive) VALUES (?, ?, ?, 1)');
            $insertFixedStmt->execute([$newFixedTitle, $newFixedTag !== '' ? $newFixedTag : null, $targetSeasonId]);

            $_SESSION['ml_admin_message'] = 'Fixed round saved to the library: ' . $newFixedTitle;
            header('Location: ' . mlUrl('season-builder/season_setup.php?season_id=' . $targetSeasonId));
            exit;
        }

        if ($setupAction !== 'save_structure_continue') {
            throw new RuntimeException('Unknown season setup action.');
        }

        $postedSeasonName = trim((string)($_POST['season_name'] ?? ''));
        $roundSlots = mlBuildRoundSlotsFromPost($slotCount, $loadedRoundSlots);
        foreach ($roundSlots as $roundNumber => &$slot) {
            if ($slot['round_type'] === 'q3_era') {
                // Option Vote stores its player-facing instruction separately
                // from final round title/tagline overrides.
                $slot['title_override'] = '';
                $slot['tag_override'] = '';
            } elseif ($slot['round_type'] !== 'fixed') {
                $slot['title_override'] = '';
                $slot['tag_override'] = '';
                $slot['option_vote_question'] = '';
            } else {
                $slot['option_vote_question'] = '';
            }

            if ($slot['round_type'] === 'fixed') {
                $slot['q1_rank'] = '';
            } elseif ($slot['round_type'] === 'q1_ranked_category') {
                $slot['fixed_round_id'] = '';
            } else {
                $slot['fixed_round_id'] = '';
                $slot['q1_rank'] = '';
            }
        }
        unset($slot);

        if ($postedSeasonName === '') {
            throw new RuntimeException('Season name is required.');
        }

        $validRoundTypes = ['fixed', 'q1_ranked_category', 'q2_madlib', 'q3_era', 'walkman'];
        $usedQ1Ranks = [];
        $configuredRoundCount = 0;
        $madlibsRoundCount = 0;

        foreach ($roundSlots as $roundNumber => $slot) {
            $roundType = $slot['round_type'];
            if ($roundType === '') {
                throw new RuntimeException('Choose a round type for round ' . $roundNumber . '.');
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
                if (isset($usedQ1Ranks[$rank])) {
                    throw new RuntimeException('The ' . mlOrdinalLabel($rank) . '-place User Submitted Round result is used more than once in the round order.');
                }
                $usedQ1Ranks[$rank] = true;
            }

            if ($roundType === 'q2_madlib') {
                $madlibsRoundCount++;
            }

            if ($roundType === 'q3_era') {
                if (!$optionVoteQuestionColumnReady) {
                    throw new RuntimeException('Run the Option Vote question migration before saving this structure.');
                }
                if (trim((string)$slot['option_vote_question']) === '') {
                    throw new RuntimeException('Round ' . $roundNumber . ' Option Vote needs a voting question.');
                }
            }
        }

        if ($madlibsRoundCount > 1) {
            throw new RuntimeException('Madlibs Winner can only be used once per season.');
        }

        if ($configuredRoundCount !== $slotCount) {
            throw new RuntimeException('Configure all ' . $slotCount . ' rounds before continuing.');
        }

        $pdo->beginTransaction();

        $updateSeasonStmt = $pdo->prepare('UPDATE ML_Seasons SET SeasonName = ? WHERE SeasonID = ?');
        $updateSeasonStmt->execute([$postedSeasonName, $targetSeasonId]);

        // Keep the existing round-slot rows in place so child configuration
        // (such as ML_SeasonRoundOptionChoices) is not cascade-deleted when
        // the admin edits and re-saves the season structure.
        $saveRoundStmt = $pdo->prepare(
            'INSERT INTO ML_SeasonRoundSlots
                (SeasonID, RoundNumber, RoundType, FixedRoundID, Q1Rank, TitleOverride, TagOverride, OptionVoteQuestion, SongsDue, VotesDue)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                RoundType = VALUES(RoundType),
                FixedRoundID = VALUES(FixedRoundID),
                Q1Rank = VALUES(Q1Rank),
                TitleOverride = VALUES(TitleOverride),
                TagOverride = VALUES(TagOverride),
                OptionVoteQuestion = VALUES(OptionVoteQuestion),
                SongsDue = VALUES(SongsDue),
                VotesDue = VALUES(VotesDue)'
        );
        foreach ($roundSlots as $roundNumber => $slot) {
            $saveRoundStmt->execute([
                $targetSeasonId,
                $roundNumber,
                $slot['round_type'],
                $slot['fixed_round_id'] !== '' ? (int)$slot['fixed_round_id'] : null,
                $slot['q1_rank'] !== '' ? (int)$slot['q1_rank'] : null,
                $slot['title_override'] !== '' ? $slot['title_override'] : null,
                $slot['tag_override'] !== '' ? $slot['tag_override'] : null,
                $slot['option_vote_question'] !== '' ? $slot['option_vote_question'] : null,
                $slot['schedule_left'] !== '' ? $slot['schedule_left'] : null,
                $slot['schedule_right'] !== '' ? $slot['schedule_right'] : null,
            ]);
        }

        $_SESSION['ml_admin_message'] = 'Season structure saved for ' . $postedSeasonName . '. Now configure the voting options.';

        $pdo->commit();
        header('Location: ' . mlUrl('season-builder/season_options.php?season_id=' . $targetSeasonId));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $adminError = $e->getMessage();
        if (isset($postedSeasonName)) {
            $setupSeason['SeasonName'] = $postedSeasonName;
        }
        $fixedRoundLibrary = mlLoadFixedRoundLibrary($pdo);
    }
}

$seasonStmt->execute([$targetSeasonId]);
$setupSeason = $seasonStmt->fetch(PDO::FETCH_ASSOC);
$setupVotingOpen = ((string)mlGetSeasonConfig($pdo, $targetSeasonId, 'voting_open', '0') === '1');
$setupIsActive = ((int)$setupSeason['IsActive'] === 1);

$totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM ML_Users');
$totalUsers = (int)$totalUsersStmt->fetchColumn();

$submissionStmt = $pdo->prepare('SELECT COUNT(DISTINCT UserID) FROM ML_Submissions WHERE SeasonID = ?');
$submissionStmt->execute([$targetSeasonId]);
$submissionCount = (int)$submissionStmt->fetchColumn();

$configuredRoundCount = 0;
foreach ($roundSlots as $slot) {
    if ($slot['round_type'] !== '') {
        $configuredRoundCount++;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Season Setup</title>
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
                <div class="home-shell-kicker">Season setup</div>
                <h1><?= htmlspecialchars($setupSeason['SeasonName']) ?></h1>
                <p>
                    Step 1 of 3: define the season basics and round structure. Save the structure to continue to the voting options.
                </p>
            </div>
            <a href="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="button-secondary admin-back-link">&laquo; Back to Admin</a>
        </div>

        <?php if ($adminMessage !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($adminMessage) ?></div>
        <?php endif; ?>

        <?php if ($adminError !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($adminError) ?></div>
        <?php endif; ?>

        <?php if ($setupLocked): ?>
            <div class="status-banner">Season Builder voting has already opened. The season structure is now read-only.</div>
        <?php endif; ?>

        <?php if (!$optionVoteQuestionColumnReady): ?>
            <div class="status-banner">
                Option Vote questions need the <strong>OptionVoteQuestion</strong> column on <strong>ML_SeasonRoundSlots</strong>. Run the supplied migration before saving an Option Vote round.
            </div>
        <?php endif; ?>

        <?php if (!$seasonBuilderReady): ?>
            <div class="status-banner">
                Advanced season setup needs the new database tables first. Run <strong>db/ml_season_builder_schema.sql</strong>, then reload this page.
            </div>
        <?php endif; ?>

        <div class="admin-grid admin-grid-tight">
            <section class="admin-panel">
                <div class="home-shell-kicker">Status</div>
                <p>
                    <strong><?= htmlspecialchars($setupSeason['SeasonName']) ?></strong>
                    <span class="pill <?= $setupVotingOpen ? 'pill-open' : 'pill-closed' ?>">
                        <?= $setupVotingOpen ? 'Voting Open' : 'Voting Closed' ?>
                    </span>
                </p>
                <p>Submissions: <strong><?= $submissionCount ?> / <?= $totalUsers ?></strong></p>
                <p>Configured rounds: <strong><?= $configuredRoundCount ?> / <?= $slotCount ?></strong></p>
                <?php if ($setupIsActive): ?>
                    <p>This season is currently marked as the active voting target.</p>
                <?php endif; ?>
            </section>

            <section class="admin-panel">
                <div class="home-shell-kicker">Reusable fixed rounds</div>
                <h2>Add to the fixed round library</h2>
                <form method="post" action="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . (int)$targetSeasonId)) ?>" class="admin-form-stack admin-form-stack-tight">
                    <input type="hidden" name="season_id" value="<?= (int)$targetSeasonId ?>">
                    <input type="hidden" name="setup_action" value="create_fixed_round">

                    <div>
                        <label class="admin-label" for="new_fixed_title">New fixed round title</label>
                        <input type="text" name="new_fixed_title" id="new_fixed_title" class="admin-input" placeholder="Songs in the Queue s5e1" <?= $setupLocked ? 'disabled' : '' ?>>
                    </div>
                    <div>
                        <label class="admin-label" for="new_fixed_tagline">Optional tagline</label>
                        <input type="text" name="new_fixed_tagline" id="new_fixed_tagline" class="admin-input" placeholder="Optional subtitle / instruction" <?= $setupLocked ? 'disabled' : '' ?>>
                    </div>
                    <button type="submit" class="button-secondary" <?= (!$seasonBuilderReady || $setupLocked) ? 'disabled' : '' ?>>Save to Fixed Library</button>
                </form>

                <div class="admin-mini-library">
                    <?php if (empty($fixedRoundLibrary)): ?>
                        <p>No fixed rounds saved yet.</p>
                    <?php else: ?>
                        <?php foreach ($fixedRoundLibrary as $fixedRound): ?>
                            <div class="admin-mini-library-tag"<?php if (!empty($fixedRound['Tagline'])): ?> title="<?= htmlspecialchars($fixedRound['Tagline']) ?>"<?php endif; ?>>
                                <span class="admin-mini-library-tag-title"><?= htmlspecialchars($fixedRound['Title']) ?></span>
                                <?php if (!empty($fixedRound['Tagline'])): ?>
                                    <span class="admin-mini-library-tagline"><?= htmlspecialchars($fixedRound['Tagline']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <form method="post" action="<?= htmlspecialchars(mlUrl('season-builder/season_setup.php?season_id=' . (int)$targetSeasonId)) ?>" class="admin-season-setup-form">
            <input type="hidden" name="season_id" value="<?= (int)$targetSeasonId ?>">
            <fieldset class="admin-readonly-fieldset" <?= $setupLocked ? 'disabled' : '' ?>>

            <section class="admin-panel admin-panel-full">
                <div class="home-shell-kicker">Basics</div>
                <div class="admin-basics-grid">
                    <div>
                        <label class="admin-label" for="season_name">Season name</label>
                        <input type="text" name="season_name" id="season_name" class="admin-input" value="<?= htmlspecialchars($setupSeason['SeasonName']) ?>" required>
                    </div>
                    <div>
                        <label class="admin-label">Season ID</label>
                        <div class="admin-readonly-field"><?= (int)$setupSeason['SeasonID'] ?></div>
                    </div>
                </div>
            </section>

            <section class="admin-panel admin-panel-full">
                <div class="admin-section-header admin-section-header-stack-mobile">
                    <div>
                        <div class="home-shell-kicker">Round builder</div>
                        <h2>Define the season structure</h2>
                        <p>Use fixed rounds, User Submitted Rounds, Madlibs, Option Vote, and Walkman to build the reveal. The seeded order mirrors the current app flow, but you can change any round.</p>
                    </div>
                </div>

                <div class="status-banner" data-madlibs-limit-message hidden>Madlibs Winner can only be used once per season.</div>

                <div class="admin-round-list">
                    <?php foreach ($roundSlots as $roundNumber => $slot): ?>
                        <div class="admin-round-card" data-round-card>
                            <div class="admin-round-card-top">
                                <div class="admin-category-number">Round <?= $roundNumber ?></div>
                                <select name="rounds[<?= $roundNumber ?>][round_type]" class="admin-input admin-round-type-select" data-round-type-select>
                                    <option value="" <?= $slot['round_type'] === '' ? 'selected' : '' ?>>Select round type</option>
                                    <option value="fixed" <?= $slot['round_type'] === 'fixed' ? 'selected' : '' ?>>Fixed round</option>
                                    <option value="q1_ranked_category" <?= $slot['round_type'] === 'q1_ranked_category' ? 'selected' : '' ?>>User Submitted Round</option>
                                    <option value="q2_madlib" <?= $slot['round_type'] === 'q2_madlib' ? 'selected' : '' ?>>Madlibs winner</option>
                                    <option value="q3_era" <?= $slot['round_type'] === 'q3_era' ? 'selected' : '' ?>>Option Vote</option>
                                    <option value="walkman" <?= $slot['round_type'] === 'walkman' ? 'selected' : '' ?>>Walkman</option>
                                </select>
                            </div>

                            <div class="admin-round-type-panels">
                                <div class="admin-round-type-panel" data-round-panel="fixed">
                                    <label class="admin-label">Saved fixed round</label>
                                    <select name="rounds[<?= $roundNumber ?>][fixed_round_id]" class="admin-input" data-round-config-input>
                                        <option value="">Select a saved fixed round</option>
                                        <?php foreach ($fixedRoundLibrary as $fixedRound): ?>
                                            <option value="<?= (int)$fixedRound['FixedRoundID'] ?>" <?= (string)$slot['fixed_round_id'] === (string)$fixedRound['FixedRoundID'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($fixedRound['Title']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p>Use a saved fixed round from your library for this slot.</p>

                                    <div class="admin-round-grid admin-round-grid-fixed">
                                        <div>
                                            <label class="admin-label">Title override</label>
                                            <input type="text" name="rounds[<?= $roundNumber ?>][title_override]" class="admin-input" value="<?= htmlspecialchars($slot['title_override']) ?>" placeholder="Optional override title" data-round-config-input>
                                        </div>
                                        <div>
                                            <label class="admin-label">Tag override</label>
                                            <input type="text" name="rounds[<?= $roundNumber ?>][tag_override]" class="admin-input" value="<?= htmlspecialchars($slot['tag_override']) ?>" placeholder="Optional subtitle / annotation" data-round-config-input>
                                        </div>
                                    </div>
                                </div>

                                <div class="admin-round-type-panel" data-round-panel="q1_ranked_category">
                                    <label class="admin-label">Finishing position</label>
                                    <select name="rounds[<?= $roundNumber ?>][q1_rank]" class="admin-input" data-round-config-input>
                                        <option value="">Select finishing position</option>
                                        <?php for ($rank = 1; $rank <= $slotCount; $rank++): ?>
                                            <option value="<?= $rank ?>" <?= (string)$slot['q1_rank'] === (string)$rank ? 'selected' : '' ?>><?= mlOrdinalLabel($rank) ?> place</option>
                                        <?php endfor; ?>
                                    </select>
                                    <p>This round will use whichever User Submitted Round idea finishes in this position.</p>
                                </div>

                                <div class="admin-round-type-panel" data-round-panel="q2_madlib">
                                    <p>This slot will use the winning Madlibs result. Its voting options are configured on the next step.</p>
                                </div>

                                <div class="admin-round-type-panel" data-round-panel="q3_era">
                                    <label class="admin-label" for="option-vote-question-<?= $roundNumber ?>">Voting question</label>
                                    <input type="text" id="option-vote-question-<?= $roundNumber ?>" name="rounds[<?= $roundNumber ?>][option_vote_question]" class="admin-input" value="<?= htmlspecialchars($slot['option_vote_question']) ?>" placeholder="Example: Select one of these global scenes" maxlength="255" required data-round-config-input>
                                    <p>This becomes the main heading players see while voting. The choices themselves are configured on the next step.</p>
                                </div>

                                <div class="admin-round-type-panel" data-round-panel="walkman">
                                    <p>This slot will use the Walkman round logic for the season.</p>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="admin-setup-actions">
                <?php if ($setupLocked): ?>
                    <a href="<?= htmlspecialchars(mlUrl('season-builder/season_options.php?season_id=' . (int)$targetSeasonId)) ?>" class="button-primary">View Voting Options &rarr;</a>
                <?php else: ?>
                    <button type="submit" name="setup_action" value="save_structure_continue" class="button-primary" <?= !$seasonBuilderReady ? 'disabled' : '' ?>>Save Structure &amp; Continue &rarr;</button>
                <?php endif; ?>
            </div>
            </fieldset>
        </form>
    </div>
</div>
<script>
(function () {
    function clearInputValue(input) {
        if (input.tagName === 'SELECT') {
            input.selectedIndex = 0;
            return;
        }

        if (input.type === 'checkbox' || input.type === 'radio') {
            input.checked = false;
            return;
        }

        input.value = '';
    }

    function syncRoundCard(card) {
        var typeSelect = card.querySelector('[data-round-type-select]');
        if (!typeSelect) {
            return;
        }

        var selectedType = typeSelect.value;
        var panels = card.querySelectorAll('[data-round-panel]');

        panels.forEach(function (panel) {
            var isActive = panel.getAttribute('data-round-panel') === selectedType;
            panel.style.display = isActive ? 'block' : 'none';

            panel.querySelectorAll('[data-round-config-input]').forEach(function (input) {
                if (!isActive) {
                    clearInputValue(input);
                }
                input.disabled = !isActive;
            });
        });
    }

    function syncMadlibsAvailability() {
        var typeSelects = Array.prototype.slice.call(document.querySelectorAll('[data-round-type-select]'));
        var madlibsSelections = typeSelects.filter(function (select) {
            return select.value === 'q2_madlib';
        });
        var hasMultipleMadlibs = madlibsSelections.length > 1;
        var hasOneMadlibs = madlibsSelections.length === 1;
        var message = document.querySelector('[data-madlibs-limit-message]');

        typeSelects.forEach(function (select) {
            var madlibsOption = select.querySelector('option[value="q2_madlib"]');
            if (!madlibsOption) {
                return;
            }

            // Keep an existing Madlibs selection editable, but prevent a second
            // slot from choosing the same season-wide winner.
            madlibsOption.disabled = hasOneMadlibs && select.value !== 'q2_madlib';
        });

        if (message) {
            if (madlibsSelections.length === 0) {
                message.hidden = true;
                message.classList.remove('error');
            } else if (hasMultipleMadlibs) {
                message.hidden = false;
                message.classList.add('error');
                message.textContent = 'Madlibs Winner can only be used once per season. Choose a different round type for all but one Madlibs slot.';
            } else {
                var assignedRound = typeSelects.indexOf(madlibsSelections[0]) + 1;
                message.hidden = false;
                message.classList.remove('error');
                message.textContent = 'Madlibs Winner is assigned to Round ' + assignedRound + ' and can only be used once per season.';
            }
        }
    }

    document.querySelectorAll('[data-round-card]').forEach(function (card) {
        syncRoundCard(card);

        var typeSelect = card.querySelector('[data-round-type-select]');
        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                syncRoundCard(card);
                syncMadlibsAvailability();
            });
        }
    });

    syncMadlibsAvailability();
})();
</script>
</body>
</html>
