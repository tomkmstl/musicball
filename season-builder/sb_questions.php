<?php
// sb_questions.php
require_once __DIR__ . '/sb_season_builder.php';

$mlQuestionConfig = mlLoadSeasonQuestionConfig($pdo, $seasonId);
$mlHeadings = $mlQuestionConfig['headings'];
$q2Options = $mlQuestionConfig['q2Options'];
$q3Options = $mlQuestionConfig['q3Options'];

// The saved round structure now determines which preseason question families
// actually exist. Legacy seasons without a configured builder keep the original
// Q1 + Madlibs + optional Q3 behavior.
$builderSlotsForQuestions = mlLoadSeasonRoundSlots($pdo, $seasonId, 12);
$questionRequirements = mlGetRoundQuestionRequirements($builderSlotsForQuestions);
$builderHasConfiguredStructure = (bool)$questionRequirements['has_configured_structure'];

$q1Enabled = $builderHasConfiguredStructure
    ? (bool)$questionRequirements['q1_enabled']
    : true;
$madlibsEnabled = $builderHasConfiguredStructure
    ? (bool)$questionRequirements['madlibs_enabled']
    : true;
$q1MinimumCategories = $builderHasConfiguredStructure
    ? (int)$questionRequirements['q1_minimum_categories']
    : 6;

// Generic Option Votes are defined by the saved round structure. Each configured
// Option Vote gets its own player-facing voting step and selection requirement.
$optionVoteRounds = mlLoadOptionVoteRounds($pdo, $seasonId, 12);
$genericOptionChoiceCount = 0;
foreach ($optionVoteRounds as $optionVoteRound) {
    $genericOptionChoiceCount += count($optionVoteRound['choices'] ?? []);
}
$useGenericOptionVotes = !empty($optionVoteRounds)
    && $genericOptionChoiceCount > 0
    && mlOptionVotePlayerStorageAvailable($pdo);

// Keep the old single-Q3 path available for legacy seasons, plus the narrow
// compatibility case of an older builder season that still has one q3_era slot
// but has not been migrated to round-specific Option Vote choices.
$legacyQ3ChoiceCount = 0;
foreach ($q3Options as $legacyQ3Label) {
    if (trim((string)$legacyQ3Label) !== '') {
        $legacyQ3ChoiceCount++;
    }
}

$legacyQ3Enabled = !$useGenericOptionVotes
    && $legacyQ3ChoiceCount >= 2
    && (
        !$builderHasConfiguredStructure
        || ((int)$questionRequirements['option_vote_count'] === 1 && $genericOptionChoiceCount === 0)
    );
