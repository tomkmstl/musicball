<?php
require_once 'session_boot.php';
require_once 'config.php';
require_once __DIR__ . '/season-builder/sb_season_builder.php';
require_once __DIR__ . '/integrations/discord/discord.php';

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

function mlRoundsPageValidTimezone($timezone) {
    $timezone = trim((string)$timezone);
    return $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true);
}

function mlRoundsPageNormalizeSchedule($value, $browserTimezone) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $timezone = mlRoundsPageValidTimezone($browserTimezone)
        ? new DateTimeZone($browserTimezone)
        : new DateTimeZone('UTC');

    $formats = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value, $timezone);
        if ($date instanceof DateTime) {
            $date->setTimezone(new DateTimeZone('UTC'));
            return $date->format('Y-m-d H:i:s');
        }
    }

    throw new RuntimeException('Enter a valid date and time.');
}

function mlRoundsPageParseUtc($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

function mlRoundsPageValidateSchedule(array $roundRows, $requireComplete, $requireFuture) {
    $previousVotesDue = null;
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    foreach ($roundRows as $roundNumber => $roundRow) {
        $songsDueValue = trim((string)($roundRow['schedule_left'] ?? ''));
        $votesDueValue = trim((string)($roundRow['schedule_right'] ?? ''));

        if ($requireComplete && ($songsDueValue === '' || $votesDueValue === '')) {
            throw new RuntimeException('Round ' . (int)$roundNumber . ' needs both Songs Due and Votes Due before voting can begin.');
        }

        if ($songsDueValue === '' && $votesDueValue === '') {
            continue;
        }
        if ($songsDueValue === '' || $votesDueValue === '') {
            throw new RuntimeException('Round ' . (int)$roundNumber . ' must have both deadlines or neither deadline.');
        }

        $songsDue = mlRoundsPageParseUtc($songsDueValue);
        $votesDue = mlRoundsPageParseUtc($votesDueValue);
        if (!$songsDue || !$votesDue) {
            throw new RuntimeException('Round ' . (int)$roundNumber . ' contains an invalid deadline.');
        }
        if ($songsDue >= $votesDue) {
            throw new RuntimeException('Round ' . (int)$roundNumber . ' Songs Due must be earlier than Votes Due.');
        }
        if ($previousVotesDue && $songsDue <= $previousVotesDue) {
            throw new RuntimeException('Round ' . (int)$roundNumber . ' Songs Due must be later than the previous round\'s Votes Due.');
        }
        if ($requireFuture && $songsDue <= $now) {
            throw new RuntimeException('Round ' . (int)$roundNumber . ' Songs Due must be in the future before voting can begin.');
        }

        $previousVotesDue = $votesDue;
    }
}

function mlRoundsPageBuilderReadinessErrors(PDO $pdo, $seasonId, array $roundSlots, $slotCount) {
    $errors = [];
    $requirements = mlGetRoundQuestionRequirements($roundSlots);
    $configuredRoundCount = 0;
    foreach ($roundSlots as $slot) {
        if (($slot['round_type'] ?? '') !== '') {
            $configuredRoundCount++;
        }
    }

    if ($configuredRoundCount !== (int)$slotCount) {
        $errors[] = 'Configure all ' . (int)$slotCount . ' rounds before opening voting.';
    }

    if (!empty($requirements['q1_enabled'])) {
        $categoryStmt = $pdo->prepare("SELECT COUNT(*) FROM ML_Q1Categories WHERE SeasonID = ? AND TRIM(Title) <> ''");
        $categoryStmt->execute([(int)$seasonId]);
        if ((int)$categoryStmt->fetchColumn() < (int)$requirements['q1_minimum_categories']) {
            $errors[] = 'Add enough User Submitted Round ideas before opening voting.';
        }
    }

    $questionConfig = mlLoadSeasonQuestionConfig($pdo, (int)$seasonId);
    if (!empty($requirements['madlibs_enabled'])) {
        foreach ([1, 2] as $partNumber) {
            $configuredOptions = array_filter(
                $questionConfig['q2Options'][$partNumber] ?? [],
                static function ($label) { return trim((string)$label) !== ''; }
            );
            if (count($configuredOptions) < 2) {
                $errors[] = 'Each Madlibs column needs at least two choices before opening voting.';
                break;
            }
        }
    }

    $optionVoteCount = (int)($requirements['option_vote_count'] ?? 0);
    if ($optionVoteCount > 0) {
        if (!mlOptionVotePlayerStorageAvailable($pdo)) {
            $errors[] = 'Option Vote player storage is not ready.';
        } else {
            $optionVoteRounds = mlLoadOptionVoteRounds($pdo, (int)$seasonId, (int)$slotCount);
            if (count($optionVoteRounds) !== $optionVoteCount) {
                $errors[] = 'Finish configuring every Option Vote before opening voting.';
            } else {
                foreach ($optionVoteRounds as $roundNumber => $optionVote) {
                    $choiceCount = count($optionVote['choices'] ?? []);
                    $selectionCount = max(1, (int)($optionVote['selections_per_player'] ?? 1));
                    if ($choiceCount <= $selectionCount) {
                        $errors[] = 'Round ' . (int)$roundNumber . ' needs more Option Vote choices.';
                    }
                }
            }
        }
    }

    if (empty($requirements['q1_enabled']) && empty($requirements['madlibs_enabled']) && $optionVoteCount === 0) {
        $errors[] = 'The season structure needs at least one Season Builder voting section.';
    }

    return array_values(array_unique($errors));
}

function mlRoundsPageSaveSlotSchedule(PDO $pdo, $seasonId, array $roundRows, $slotCount) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ML_SeasonRoundSlots WHERE SeasonID = ?');
    $countStmt->execute([(int)$seasonId]);
    if ((int)$countStmt->fetchColumn() < (int)$slotCount) {
        throw new RuntimeException('Finish the round structure before saving the season schedule.');
    }

    $updateStmt = $pdo->prepare(
        'UPDATE ML_SeasonRoundSlots SET SongsDue = ?, VotesDue = ? WHERE SeasonID = ? AND RoundNumber = ?'
    );
    foreach ($roundRows as $roundNumber => $roundRow) {
        $updateStmt->execute([
            trim((string)$roundRow['schedule_left']) !== '' ? $roundRow['schedule_left'] : null,
            trim((string)$roundRow['schedule_right']) !== '' ? $roundRow['schedule_right'] : null,
            (int)$seasonId,
            (int)$roundNumber,
        ]);
    }
}

function mlRoundsPageSaveCommittedRounds(PDO $pdo, $seasonId, array $roundRows) {
    $existingStmt = $pdo->prepare(
        'SELECT SeasonRoundID, RoundNumber FROM ML_SeasonRounds WHERE SeasonID = ? ORDER BY RoundNumber, SeasonRoundID'
    );
    $existingStmt->execute([(int)$seasonId]);
    $existingByRoundNumber = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $existingRow) {
        $roundNumber = (int)$existingRow['RoundNumber'];
        if (isset($existingByRoundNumber[$roundNumber])) {
            throw new RuntimeException('Duplicate committed rounds were found. Resolve them before saving this season.');
        }
        $existingByRoundNumber[$roundNumber] = (int)$existingRow['SeasonRoundID'];
    }

    $updateStmt = $pdo->prepare(
        'UPDATE ML_SeasonRounds SET Title = ?, Tagline = ?, SongsDue = ?, VotesDue = ? WHERE SeasonRoundID = ?'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO ML_SeasonRounds (SeasonID, RoundNumber, Title, Tagline, SongsDue, VotesDue) VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($roundRows as $roundNumber => $roundRow) {
        $title = trim((string)($roundRow['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Every final round needs a title. Round ' . (int)$roundNumber . ' is blank.');
        }

        $values = [
            $title,
            trim((string)($roundRow['tag'] ?? '')) !== '' ? trim((string)$roundRow['tag']) : null,
            trim((string)($roundRow['schedule_left'] ?? '')) !== '' ? $roundRow['schedule_left'] : null,
            trim((string)($roundRow['schedule_right'] ?? '')) !== '' ? $roundRow['schedule_right'] : null,
        ];

        if (isset($existingByRoundNumber[$roundNumber])) {
            $updateStmt->execute(array_merge($values, [$existingByRoundNumber[$roundNumber]]));
        } else {
            $insertStmt->execute(array_merge([(int)$seasonId, (int)$roundNumber], $values));
        }
    }
}

function mlRoundsPageHasProgress(PDO $pdo, $seasonRoundId) {
    $seasonRoundId = (int)$seasonRoundId;
    foreach (['ML_RoundPlaylists', 'ML_RoundVotes', 'ML_RoundVoteSubmissions'] as $tableName) {
        if (!mlTableExists($pdo, $tableName)) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $tableName . ' WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}

function mlRoundsPageCurrentRoundNumber(PDO $pdo, array $committedRows, $slotCount) {
    $qaCurrentSeasonRoundId = function_exists('mlGetQaCurrentSeasonRoundId') ? mlGetQaCurrentSeasonRoundId($pdo) : 0;
    if ($qaCurrentSeasonRoundId > 0) {
        foreach ($committedRows as $roundRow) {
            if ((int)$roundRow['SeasonRoundID'] === $qaCurrentSeasonRoundId) {
                return (int)$roundRow['RoundNumber'];
            }
        }
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($committedRows as $roundRow) {
        $votesDue = mlRoundsPageParseUtc($roundRow['VotesDue'] ?? '');
        if (!$votesDue || $now <= $votesDue) {
            return (int)$roundRow['RoundNumber'];
        }
    }

    return (int)$slotCount;
}

function mlRoundsPageLoadCommittedRows(PDO $pdo, $seasonId) {
    $columns = 'SeasonRoundID, SeasonID, RoundNumber, Title, Tagline, SongsDue, VotesDue';
    foreach (['RoundState', 'StateMode', 'HoldForAllSongs', 'HoldForAllVotes'] as $columnName) {
        if (mlColumnExists($pdo, 'ML_SeasonRounds', $columnName)) {
            $columns .= ', ' . $columnName;
        }
    }

    $stmt = $pdo->prepare('SELECT ' . $columns . ' FROM ML_SeasonRounds WHERE SeasonID = ? ORDER BY RoundNumber FOR UPDATE');
    $stmt->execute([(int)$seasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mlRoundsPageSwapContent(PDO $pdo, $seasonId, $sourceRoundNumber, $targetRoundNumber, $seasonIsActive, $slotCount) {
    $rows = mlRoundsPageLoadCommittedRows($pdo, (int)$seasonId);
    $byRoundNumber = [];
    foreach ($rows as $row) {
        $roundNumber = (int)$row['RoundNumber'];
        if (isset($byRoundNumber[$roundNumber])) {
            throw new RuntimeException('Duplicate committed rounds were found. Resolve them before swapping round content.');
        }
        $byRoundNumber[$roundNumber] = $row;
    }

    if (!isset($byRoundNumber[$sourceRoundNumber], $byRoundNumber[$targetRoundNumber])) {
        throw new RuntimeException('Both rounds must be saved before their content can be swapped.');
    }

    $source = $byRoundNumber[$sourceRoundNumber];
    $target = $byRoundNumber[$targetRoundNumber];

    if ($seasonIsActive) {
        $currentRoundNumber = mlRoundsPageCurrentRoundNumber($pdo, $rows, (int)$slotCount);
        if ($sourceRoundNumber <= $currentRoundNumber || $targetRoundNumber <= $currentRoundNumber) {
            throw new RuntimeException('Only upcoming rounds can be swapped during a live season.');
        }
    }

    if (mlRoundsPageHasProgress($pdo, (int)$source['SeasonRoundID']) || mlRoundsPageHasProgress($pdo, (int)$target['SeasonRoundID'])) {
        throw new RuntimeException('Round content cannot be swapped after a playlist has been generated or voting activity exists.');
    }

    $scheduleColumns = ['SongsDue', 'VotesDue'];
    foreach (['RoundState', 'StateMode', 'HoldForAllSongs', 'HoldForAllVotes'] as $columnName) {
        if (array_key_exists($columnName, $source) && array_key_exists($columnName, $target)) {
            $scheduleColumns[] = $columnName;
        }
    }

    $setParts = ['RoundNumber = ?'];
    foreach ($scheduleColumns as $columnName) {
        $setParts[] = $columnName . ' = ?';
    }
    $updateStmt = $pdo->prepare(
        'UPDATE ML_SeasonRounds SET ' . implode(', ', $setParts) . ' WHERE SeasonRoundID = ?'
    );

    $temporaryRoundNumber = 1;
    foreach ($rows as $row) {
        $temporaryRoundNumber = max($temporaryRoundNumber, (int)$row['RoundNumber'] + 1);
    }
    $pdo->prepare('UPDATE ML_SeasonRounds SET RoundNumber = ? WHERE SeasonRoundID = ?')
        ->execute([$temporaryRoundNumber, (int)$source['SeasonRoundID']]);

    $sourceScheduleValues = [];
    $targetScheduleValues = [];
    foreach ($scheduleColumns as $columnName) {
        $sourceScheduleValues[] = $source[$columnName] ?? null;
        $targetScheduleValues[] = $target[$columnName] ?? null;
    }

    $updateStmt->execute(array_merge(
        [$sourceRoundNumber],
        $sourceScheduleValues,
        [(int)$target['SeasonRoundID']]
    ));
    $updateStmt->execute(array_merge(
        [$targetRoundNumber],
        $targetScheduleValues,
        [(int)$source['SeasonRoundID']]
    ));
}

$adminMessage = isset($_SESSION['ml_admin_message']) ? (string)$_SESSION['ml_admin_message'] : '';
unset($_SESSION['ml_admin_message']);
$adminError = isset($_SESSION['ml_admin_error']) ? (string)$_SESSION['ml_admin_error'] : '';
unset($_SESSION['ml_admin_error']);

$seasonStmt = $pdo->prepare('SELECT SeasonID, SeasonName, IsActive FROM ML_Seasons WHERE SeasonID = ? LIMIT 1');
$seasonStmt->execute([$targetSeasonId]);
$seasonRow = $seasonStmt->fetch(PDO::FETCH_ASSOC);
if (!$seasonRow) {
    $_SESSION['ml_admin_error'] = 'That season could not be found.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$slotCount = 12;
$seasonIsActive = ((int)($seasonRow['IsActive'] ?? 0) === 1);
$currentSeasonRow = mlGetCurrentSeason($pdo);
$currentSeasonId = $currentSeasonRow ? (int)$currentSeasonRow['SeasonID'] : 0;
$nextSeasonRow = mlGetNextSeason($pdo);
$nextSeasonId = $nextSeasonRow ? (int)$nextSeasonRow['SeasonID'] : 0;
$isNextSeason = !$seasonIsActive && $nextSeasonId > 0 && $targetSeasonId === $nextSeasonId;
$isPastSeason = !$seasonIsActive && $currentSeasonId > 0 && $targetSeasonId < $currentSeasonId;
$builderVotingOpen = mlIsSeasonVotingOpen($pdo, $targetSeasonId);
$builderLocked = mlIsSeasonBuilderLocked($pdo, $targetSeasonId);
$builderResultsFinal = $isNextSeason && mlCanStartNextSeason($pdo, $targetSeasonId);
$canStartBuilderVoting = $isNextSeason && !$builderLocked && !$builderResultsFinal;
$canStartSeasonHere = $isNextSeason && $builderResultsFinal;
$scheduleEditable = !$isPastSeason && ($seasonIsActive || $isNextSeason);
$contentEditable = !$isPastSeason && ($seasonIsActive || $builderResultsFinal);

$seasonBuilderReady = mlSeasonBuilderAvailable($pdo);
$roundsTableReady = mlSeasonRoundsAvailable($pdo);
$roundSlots = mlLoadSeasonRoundSlots($pdo, $targetSeasonId, $slotCount);
$builderReadinessErrors = mlRoundsPageBuilderReadinessErrors($pdo, $targetSeasonId, $roundSlots, $slotCount);
$questionConfig = mlLoadSeasonQuestionConfig($pdo, $targetSeasonId);
$committedRounds = mlLoadCommittedSeasonRounds($pdo, $targetSeasonId, $slotCount);
$hasCommittedRounds = !empty($committedRounds);
$resolvedRounds = [];
if ($seasonIsActive || $builderResultsFinal || $hasCommittedRounds) {
    $resolvedRounds = mlResolveSeasonRounds(
        $pdo,
        $targetSeasonId,
        (string)$seasonRow['SeasonName'],
        $questionConfig['q2Options'],
        $questionConfig['q3Options'],
        $slotCount
    );
}

$roundRows = [];
for ($roundNumber = 1; $roundNumber <= $slotCount; $roundNumber++) {
    $resolved = isset($resolvedRounds[$roundNumber - 1]) ? $resolvedRounds[$roundNumber - 1] : [];
    if (isset($committedRounds[$roundNumber])) {
        $resolved = $committedRounds[$roundNumber];
    }

    $slot = $roundSlots[$roundNumber] ?? [];
    $roundRows[$roundNumber] = [
        'round_number' => $roundNumber,
        'season_round_id' => (int)($resolved['season_round_id'] ?? 0),
        'title' => trim((string)($resolved['title'] ?? '')),
        'tag' => trim((string)($resolved['tag'] ?? '')),
        'schedule_left' => trim((string)($resolved['schedule_left'] ?? $slot['schedule_left'] ?? '')),
        'schedule_right' => trim((string)($resolved['schedule_right'] ?? $slot['schedule_right'] ?? '')),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roundsAction = trim((string)($_POST['rounds_action'] ?? ''));

    try {
        if (!$seasonBuilderReady || !$roundsTableReady) {
            throw new RuntimeException('Finish the Season Builder database setup before saving season rounds.');
        }

        if ($roundsAction === 'swap_content') {
            if (!$contentEditable || (!$seasonIsActive && !$canStartSeasonHere)) {
                throw new RuntimeException('Round content can only be swapped after Season Builder results are final.');
            }

            $sourceRoundNumber = (int)($_POST['source_round_number'] ?? 0);
            $targetRoundNumber = (int)($_POST['target_round_number'] ?? 0);
            if ($sourceRoundNumber < 1 || $sourceRoundNumber > $slotCount
                || $targetRoundNumber < 1 || $targetRoundNumber > $slotCount
                || $sourceRoundNumber === $targetRoundNumber) {
                throw new RuntimeException('Choose two different rounds to swap.');
            }

            $pdo->beginTransaction();
            if (!$seasonIsActive) {
                mlRoundsPageSaveCommittedRounds($pdo, $targetSeasonId, $roundRows);
            }
            mlRoundsPageSwapContent(
                $pdo,
                $targetSeasonId,
                $sourceRoundNumber,
                $targetRoundNumber,
                $seasonIsActive,
                $slotCount
            );
            $pdo->commit();

            $_SESSION['ml_admin_message'] = 'Round ' . $sourceRoundNumber . ' and Round ' . $targetRoundNumber
                . ' content were swapped. Their schedule positions kept the same deadlines.';
            header('Location: ' . mlUrl('season_rounds.php?season_id=' . $targetSeasonId));
            exit;
        }

        if (!in_array($roundsAction, ['save_schedule', 'start_builder_voting', 'save_rounds', 'start_season'], true)) {
            throw new RuntimeException('Unknown season rounds action.');
        }

        $browserTimezone = trim((string)($_POST['browser_timezone'] ?? ''));
        if (!mlRoundsPageValidTimezone($browserTimezone)) {
            $browserTimezone = 'UTC';
        }

        $postedRounds = isset($_POST['rounds']) && is_array($_POST['rounds']) ? $_POST['rounds'] : [];
        foreach ($roundRows as $roundNumber => &$roundRow) {
            $postedRound = isset($postedRounds[$roundNumber]) && is_array($postedRounds[$roundNumber])
                ? $postedRounds[$roundNumber]
                : [];

            if ($contentEditable) {
                $roundRow['title'] = trim((string)($postedRound['title'] ?? ''));
                $roundRow['tag'] = trim((string)($postedRound['tag'] ?? ''));
            }
            $roundRow['schedule_left'] = mlRoundsPageNormalizeSchedule(
                (string)($postedRound['schedule_left'] ?? ''),
                $browserTimezone
            );
            $roundRow['schedule_right'] = mlRoundsPageNormalizeSchedule(
                (string)($postedRound['schedule_right'] ?? ''),
                $browserTimezone
            );
        }
        unset($roundRow);

        $requiresCompleteSchedule = $roundsAction !== 'save_schedule' || $builderLocked;
        $requiresFutureSchedule = $roundsAction === 'start_builder_voting';
        mlRoundsPageValidateSchedule($roundRows, $requiresCompleteSchedule, $requiresFutureSchedule);

        if ($roundsAction === 'start_builder_voting') {
            if (!$canStartBuilderVoting) {
                throw new RuntimeException('Season Builder voting cannot be opened from the current season state.');
            }
            if (!empty($builderReadinessErrors)) {
                throw new RuntimeException($builderReadinessErrors[0]);
            }
        } elseif ($roundsAction === 'save_schedule') {
            if (!$scheduleEditable || $contentEditable) {
                throw new RuntimeException('This season is no longer in schedule-only setup.');
            }
        } elseif ($roundsAction === 'save_rounds') {
            if (!$contentEditable) {
                throw new RuntimeException('Final round content is not editable yet.');
            }
        } elseif ($roundsAction === 'start_season' && !$canStartSeasonHere) {
            throw new RuntimeException('The next season cannot be started until Season Builder results are final.');
        }

        $pdo->beginTransaction();
        mlRoundsPageSaveSlotSchedule($pdo, $targetSeasonId, $roundRows, $slotCount);

        if ($roundsAction === 'save_rounds' || $roundsAction === 'start_season') {
            mlRoundsPageSaveCommittedRounds($pdo, $targetSeasonId, $roundRows);
        }

        if ($roundsAction === 'start_builder_voting') {
            mlLockSeasonBuilder($pdo, $targetSeasonId);
            mlSetSeasonConfig($pdo, $targetSeasonId, 'voting_open', '1');
        }

        if ($roundsAction === 'start_season') {
            if (!$currentSeasonRow) {
                throw new RuntimeException('A current season is required before the next season can be started.');
            }
            $pdo->exec('UPDATE ML_Seasons SET IsActive = 0');
            $activateStmt = $pdo->prepare('UPDATE ML_Seasons SET IsActive = 1 WHERE SeasonID = ?');
            $activateStmt->execute([$targetSeasonId]);
            mlSetSeasonConfig($pdo, $targetSeasonId, 'voting_open', '0');
        }

        $pdo->commit();

        if ($roundsAction === 'start_builder_voting') {
            $_SESSION['ml_admin_message'] = 'Season Builder voting is now live for ' . $seasonRow['SeasonName'] . '.';
        } elseif ($roundsAction === 'start_season') {
            mlDiscordMaybeSendSeasonStarted($pdo, $targetSeasonId);
            $_SESSION['ml_admin_message'] = $seasonRow['SeasonName'] . ' is now the current season.';
            header('Location: ' . mlUrl('admin.php'));
            exit;
        } elseif ($roundsAction === 'save_schedule') {
            $_SESSION['ml_admin_message'] = 'Season schedule saved for ' . $seasonRow['SeasonName'] . '.';
        } else {
            $_SESSION['ml_admin_message'] = 'Season rounds updated for ' . $seasonRow['SeasonName'] . '.';
        }

        header('Location: ' . mlUrl('season_rounds.php?season_id=' . $targetSeasonId));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $adminError = $e->getMessage();
    }
}

$totalUsers = mlGetTotalUserCount($pdo);
$submissionCount = mlGetSeasonSubmissionCount($pdo, $targetSeasonId);

$swapEligibleRounds = [];
if ($contentEditable && $roundsTableReady) {
    if ($canStartSeasonHere) {
        if (!$hasCommittedRounds) {
            $swapEligibleRounds = $roundRows;
        } else {
            $eligibilityStmt = $pdo->prepare(
                'SELECT SeasonRoundID, RoundNumber FROM ML_SeasonRounds WHERE SeasonID = ? ORDER BY RoundNumber'
            );
            $eligibilityStmt->execute([$targetSeasonId]);
            $committedIdsByRound = [];
            foreach ($eligibilityStmt->fetchAll(PDO::FETCH_ASSOC) as $eligibilityRow) {
                $committedIdsByRound[(int)$eligibilityRow['RoundNumber']] = (int)$eligibilityRow['SeasonRoundID'];
            }

            foreach ($roundRows as $roundNumber => $roundRow) {
                $seasonRoundId = (int)($committedIdsByRound[$roundNumber] ?? 0);
                if ($seasonRoundId > 0 && mlRoundsPageHasProgress($pdo, $seasonRoundId)) {
                    continue;
                }
                $swapEligibleRounds[$roundNumber] = $roundRow;
            }
        }
    } elseif ($seasonIsActive && $hasCommittedRounds) {
        $committedRowsForEligibility = [];
        $eligibilityStmt = $pdo->prepare(
            'SELECT SeasonRoundID, RoundNumber, VotesDue FROM ML_SeasonRounds WHERE SeasonID = ? ORDER BY RoundNumber'
        );
        $eligibilityStmt->execute([$targetSeasonId]);
        $committedRowsForEligibility = $eligibilityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $currentRoundNumber = mlRoundsPageCurrentRoundNumber($pdo, $committedRowsForEligibility, $slotCount);

        foreach ($committedRowsForEligibility as $eligibilityRow) {
            $roundNumber = (int)$eligibilityRow['RoundNumber'];
            if ($roundNumber <= $currentRoundNumber || mlRoundsPageHasProgress($pdo, (int)$eligibilityRow['SeasonRoundID'])) {
                continue;
            }
            if (isset($roundRows[$roundNumber])) {
                $swapEligibleRounds[$roundNumber] = $roundRows[$roundNumber];
            }
        }
    }
}

$showSetupStep = $isNextSeason && !$builderResultsFinal;
$pageKicker = $seasonIsActive ? 'Manage season rounds' : ($canStartSeasonHere ? 'Review next season' : 'Season setup');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League - Season Rounds</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'admin'; include 'header.php'; ?>
<div class="wrapper">
    <div class="card admin-card admin-card-wide">
        <div class="admin-page-topline">
            <div>
                <div class="home-shell-kicker"><?= htmlspecialchars($pageKicker) ?></div>
                <h1><?= htmlspecialchars($seasonRow['SeasonName']) ?></h1>
                <p>
                    <?php if ($showSetupStep && !$builderVotingOpen): ?>
                        Step 3 of 3: set every Songs Due and Votes Due deadline before opening Season Builder voting.
                    <?php elseif ($builderVotingOpen): ?>
                        Season Builder voting is open. Structure and ballot options are locked, but the season schedule can still be adjusted here.
                    <?php elseif ($canStartSeasonHere): ?>
                        Season Builder results are final. Review the resolved rounds, adjust their placement if needed, then start the season.
                    <?php elseif ($seasonIsActive): ?>
                        Manage the live season's round content and schedule. Only untouched upcoming round content can be swapped.
                    <?php else: ?>
                        This season is read-only.
                    <?php endif; ?>
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
        <?php if (!$seasonBuilderReady || !$roundsTableReady): ?>
            <div class="status-banner error">Season Builder or committed round storage is not ready in this environment.</div>
        <?php endif; ?>
        <?php if ($canStartBuilderVoting && !empty($builderReadinessErrors)): ?>
            <div class="status-banner error">
                <?= htmlspecialchars($builderReadinessErrors[0]) ?> Return to the earlier setup steps before opening voting.
            </div>
        <?php endif; ?>

        <div class="admin-grid admin-grid-tight">
            <section class="admin-panel">
                <div class="home-shell-kicker">Status</div>
                <p>
                    <strong><?= htmlspecialchars($seasonRow['SeasonName']) ?></strong>
                    <?php if ($seasonIsActive): ?>
                        <span class="pill pill-open">Current</span>
                    <?php elseif ($builderVotingOpen): ?>
                        <span class="pill pill-open">Voting Open</span>
                    <?php elseif ($builderResultsFinal): ?>
                        <span class="pill pill-complete">Results Final</span>
                    <?php else: ?>
                        <span class="pill pill-neutral">Setup</span>
                    <?php endif; ?>
                </p>
                <p>Season Builder submissions: <strong><?= (int)$submissionCount ?> / <?= (int)$totalUsers ?></strong></p>
                <p>Gameplay rounds committed: <strong><?= $hasCommittedRounds ? 'Yes' : 'No' ?></strong></p>
            </section>

            <section class="admin-panel">
                <div class="home-shell-kicker">What stays together</div>
                <p>Round title, description, saved songs, and comments form one content package. Swapping content does not move the deadlines assigned to each round position.</p>
            </section>
        </div>

        <?php if (count($swapEligibleRounds) >= 2): ?>
            <section class="admin-panel admin-panel-full">
                <div class="home-shell-kicker">Round placement</div>
                <h2>Swap round content</h2>
                <p>Choose two eligible rounds. Their complete content packages will trade positions while each position keeps its current deadlines. Save any other round edits before swapping.</p>
                <form
                    method="post"
                    action="<?= htmlspecialchars(mlUrl('season_rounds.php?season_id=' . (int)$targetSeasonId)) ?>"
                    class="admin-form-stack"
                    onsubmit="return confirm('Swap these two round content packages? Their round positions will keep the same deadlines.');"
                >
                    <input type="hidden" name="season_id" value="<?= (int)$targetSeasonId ?>">
                    <input type="hidden" name="rounds_action" value="swap_content">
                    <div class="admin-round-grid">
                        <div>
                            <label class="admin-label" for="source_round_number">First round</label>
                            <select class="admin-input" id="source_round_number" name="source_round_number" required>
                                <option value="">Choose a round</option>
                                <?php foreach ($swapEligibleRounds as $roundNumber => $roundRow): ?>
                                    <option value="<?= (int)$roundNumber ?>">Round <?= (int)$roundNumber ?> - <?= htmlspecialchars($roundRow['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="admin-label" for="target_round_number">Swap with</label>
                            <select class="admin-input" id="target_round_number" name="target_round_number" required>
                                <option value="">Choose a round</option>
                                <?php foreach ($swapEligibleRounds as $roundNumber => $roundRow): ?>
                                    <option value="<?= (int)$roundNumber ?>">Round <?= (int)$roundNumber ?> - <?= htmlspecialchars($roundRow['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="button-secondary">Swap Content</button>
                </form>
            </section>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(mlUrl('season_rounds.php?season_id=' . (int)$targetSeasonId)) ?>" class="admin-season-setup-form">
            <input type="hidden" name="season_id" value="<?= (int)$targetSeasonId ?>">
            <input type="hidden" name="browser_timezone" value="" data-browser-timezone>

            <section class="admin-panel admin-panel-full">
                <div class="admin-section-header admin-section-header-stack-mobile">
                    <div>
                        <div class="home-shell-kicker">Rounds</div>
                        <h2><?= $contentEditable ? 'Round content and schedule' : 'Season schedule' ?></h2>
                        <p>Round positions own the dates. Final round content can be edited and swapped separately once Season Builder results are final.</p>
                    </div>
                    <?php if ($canStartBuilderVoting): ?>
                        <div class="admin-section-actions">
                            <button type="button" class="button-secondary admin-mini-action-btn" data-create-weekly-schedule>Create Weekly Schedule</button>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($canStartBuilderVoting): ?>
                    <p data-weekly-schedule-message>Set both Round 1 deadlines, then create the remaining weekly schedule.</p>
                <?php endif; ?>

                <div class="admin-round-list admin-round-list-committed">
                    <?php foreach ($roundRows as $roundNumber => $roundRow): ?>
                        <div class="admin-round-card admin-round-card-committed">
                            <div class="admin-round-card-top admin-round-card-top-static">
                                <div class="admin-category-number">Round <?= (int)$roundNumber ?></div>
                            </div>

                            <div class="admin-round-grid admin-round-grid-fixed admin-round-grid-committed">
                                <div>
                                    <label class="admin-label" for="season-round-title-<?= (int)$roundNumber ?>">Title</label>
                                    <?php if ($contentEditable): ?>
                                        <input type="text" id="season-round-title-<?= (int)$roundNumber ?>" name="rounds[<?= (int)$roundNumber ?>][title]" class="admin-input" value="<?= htmlspecialchars($roundRow['title']) ?>" required>
                                    <?php else: ?>
                                        <div class="admin-readonly-field"><?= htmlspecialchars($roundRow['title'] !== '' ? $roundRow['title'] : 'Pending Season Builder result') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="admin-label" for="season-round-tag-<?= (int)$roundNumber ?>">Description</label>
                                    <?php if ($contentEditable): ?>
                                        <textarea id="season-round-tag-<?= (int)$roundNumber ?>" name="rounds[<?= (int)$roundNumber ?>][tag]" class="admin-input admin-textarea" rows="3"><?= htmlspecialchars($roundRow['tag']) ?></textarea>
                                    <?php else: ?>
                                        <div class="admin-readonly-field"><?= htmlspecialchars($roundRow['tag'] !== '' ? $roundRow['tag'] : 'Final description will be resolved from voting.') ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="admin-round-grid admin-round-grid-common">
                                <div>
                                    <label class="admin-label" for="season-round-songs-<?= (int)$roundNumber ?>">Songs Due</label>
                                    <input type="datetime-local" id="season-round-songs-<?= (int)$roundNumber ?>" name="rounds[<?= (int)$roundNumber ?>][schedule_left]" class="admin-input" value="" data-utc-datetime="<?= htmlspecialchars($roundRow['schedule_left']) ?>" <?= $scheduleEditable ? 'required' : 'disabled' ?>>
                                </div>
                                <div>
                                    <label class="admin-label" for="season-round-votes-<?= (int)$roundNumber ?>">Votes Due</label>
                                    <input type="datetime-local" id="season-round-votes-<?= (int)$roundNumber ?>" name="rounds[<?= (int)$roundNumber ?>][schedule_right]" class="admin-input" value="" data-utc-datetime="<?= htmlspecialchars($roundRow['schedule_right']) ?>" <?= $scheduleEditable ? 'required' : 'disabled' ?>>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <p data-timezone-note>Deadlines are entered in your current timezone and saved in UTC.</p>

            <?php if ($scheduleEditable): ?>
                <div class="admin-setup-actions">
                    <?php if (!$builderLocked): ?>
                        <a href="<?= htmlspecialchars(mlUrl('season-builder/season_options.php?season_id=' . (int)$targetSeasonId)) ?>" class="button-secondary">&laquo; Edit Voting Options</a>
                    <?php elseif ($isNextSeason): ?>
                        <a href="<?= htmlspecialchars(mlUrl('season-builder/season_options.php?season_id=' . (int)$targetSeasonId)) ?>" class="button-secondary">View Voting Options</a>
                    <?php endif; ?>

                    <?php if (!$contentEditable): ?>
                        <button type="submit" name="rounds_action" value="save_schedule" class="button-secondary" formnovalidate>Save Schedule</button>
                        <?php if ($canStartBuilderVoting): ?>
                            <button
                                type="submit"
                                name="rounds_action"
                                value="start_builder_voting"
                                class="button-primary"
                                <?= !empty($builderReadinessErrors) ? 'disabled' : '' ?>
                                onclick="return confirm('Open Season Builder voting? Round structure and voting options will become permanently read-only.');"
                            >Start Season Builder Voting</button>
                        <?php endif; ?>
                    <?php elseif ($canStartSeasonHere): ?>
                        <button type="submit" name="rounds_action" value="save_rounds" class="button-secondary">Save Round Changes</button>
                        <button
                            type="submit"
                            name="rounds_action"
                            value="start_season"
                            class="button-primary"
                            onclick="return confirm('Start this season with these rounds and deadlines?');"
                        >Start <?= htmlspecialchars($seasonRow['SeasonName']) ?></button>
                    <?php elseif ($seasonIsActive): ?>
                        <button type="submit" name="rounds_action" value="save_rounds" class="button-primary">Save Season Changes</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<script>
(function () {
    function detectBrowserTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (error) {
            return 'UTC';
        }
    }

    function formatForDateTimeLocal(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        var hours = String(date.getHours()).padStart(2, '0');
        var minutes = String(date.getMinutes()).padStart(2, '0');
        return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
    }

    function hydrateUtcInputs() {
        document.querySelectorAll('[data-utc-datetime]').forEach(function (input) {
            var utcValue = input.getAttribute('data-utc-datetime');
            if (!utcValue) {
                input.value = '';
                return;
            }
            var date = new Date(utcValue.replace(' ', 'T') + 'Z');
            input.value = isNaN(date.getTime()) ? '' : formatForDateTimeLocal(date);
        });
    }

    function applyTimezoneMetadata() {
        var timezone = detectBrowserTimezone();
        document.querySelectorAll('[data-browser-timezone]').forEach(function (input) {
            input.value = timezone;
        });
        var note = document.querySelector('[data-timezone-note]');
        if (note) {
            note.textContent = 'Deadlines are entered in your current timezone (' + timezone + ') and saved in UTC.';
        }
    }

    function addDaysToLocalValue(localValue, dayCount) {
        if (!localValue) {
            return '';
        }
        var date = new Date(localValue);
        if (isNaN(date.getTime())) {
            return '';
        }
        date.setDate(date.getDate() + dayCount);
        return formatForDateTimeLocal(date);
    }

    function createWeeklySchedule() {
        var songsInputs = document.querySelectorAll('input[name^="rounds["][name$="[schedule_left]"]');
        var votesInputs = document.querySelectorAll('input[name^="rounds["][name$="[schedule_right]"]');
        var message = document.querySelector('[data-weekly-schedule-message]');
        if (!songsInputs.length || !votesInputs.length || !songsInputs[0].value || !votesInputs[0].value) {
            if (message) {
                message.textContent = 'Set both Round 1 deadlines first, then create the weekly schedule.';
                message.classList.add('error');
            }
            return;
        }

        for (var index = 1; index < songsInputs.length; index++) {
            songsInputs[index].value = addDaysToLocalValue(songsInputs[0].value, index * 7);
            votesInputs[index].value = addDaysToLocalValue(votesInputs[0].value, index * 7);
        }
        if (message) {
            message.textContent = 'Rounds 2-12 now follow Round 1 on a weekly cadence.';
            message.classList.remove('error');
        }
    }

    applyTimezoneMetadata();
    hydrateUtcInputs();

    var weeklyScheduleButton = document.querySelector('[data-create-weekly-schedule]');
    if (weeklyScheduleButton) {
        weeklyScheduleButton.addEventListener('click', createWeeklySchedule);
    }
})();
</script>
</body>
</html>
