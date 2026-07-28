<?php
// submit.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../integrations/discord/discord.php';

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

$cookieName = 'ml_league_user';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mlUrl('season-builder/sb_vote.php'));
    exit;
}

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

$check = $pdo->prepare('SELECT UserID FROM ML_Users WHERE UserID = ?');
$check->execute([$userId]);
if (!$check->fetch()) {
    setcookie($cookieName, '', time() - 3600, '/');
    setcookie('ml_user_id', '', time() - 3600, '/');
    header('Location: ' . mlUrl('season-builder/sb_vote.php'));
    exit;
}

if (!empty($optionVoteRounds) && !$useGenericOptionVotes && !$legacyQ3Enabled) {
    die('Configuration error: Option Vote player storage is not available. Run the Option Vote votes migration before opening voting.');
}

// Q1 validation only when the saved round structure uses ranked-category rounds.
$votes = [];
if ($q1Enabled) {
    $stmt = $pdo->prepare(
        'SELECT CategoryIndex
         FROM ML_Q1Categories
         WHERE SeasonID = ?
         ORDER BY CategoryIndex'
    );
    $stmt->execute([$seasonId]);
    $categoryIndexes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $q1Data = isset($_POST['q1']) && is_array($_POST['q1']) ? $_POST['q1'] : [];
    $totalPoints = 0;

    foreach ($categoryIndexes as $catIndex) {
        $catIndex = (int)$catIndex;
        $val = isset($q1Data[$catIndex]) ? (int)$q1Data[$catIndex] : 0;
        if ($val < 0 || $val > 4) {
            die('Invalid User Submitted Rounds value. Each round idea must be between 0 and 4 points.');
        }
        $totalPoints += $val;
        if ($val > 0) {
            $votes[$catIndex] = $val;
        }
    }

    if ($totalPoints !== 10) {
        die('You must allocate exactly 10 points across User Submitted Rounds.');
    }
}

// Madlibs validation only when the round structure includes a Madlibs winner.
$q2Answers = [];
if ($madlibsEnabled) {
    $q2Input = isset($_POST['q2']) && is_array($_POST['q2']) ? $_POST['q2'] : [];
    foreach ([1, 2] as $qNum) {
        if (!isset($q2Options[$qNum]) || !is_array($q2Options[$qNum])) {
            die('Configuration error: the Madlibs question is not fully defined.');
        }

        $choices = isset($q2Input[$qNum]) && is_array($q2Input[$qNum])
            ? array_values(array_unique(array_map('intval', $q2Input[$qNum])))
            : [];

        if (count($choices) !== 2) {
            die('You must select exactly 2 options for each Madlibs part.');
        }

        foreach ($choices as $choiceIndex) {
            if (!isset($q2Options[$qNum][$choiceIndex])) {
                die('Invalid Madlibs option selected.');
            }
        }

        $q2Answers[$qNum] = $choices;
    }
}

// Generic round-specific Option Vote validation.
$optionVoteAnswers = [];
if ($useGenericOptionVotes) {
    $postedOptionVotes = isset($_POST['option_votes']) && is_array($_POST['option_votes'])
        ? $_POST['option_votes']
        : [];

    foreach ($optionVoteRounds as $roundNumber => $optionVote) {
        $requiredSelections = max(1, (int)$optionVote['selections_per_player']);
        $postedChoices = isset($postedOptionVotes[$roundNumber]) && is_array($postedOptionVotes[$roundNumber])
            ? $postedOptionVotes[$roundNumber]
            : [];
        $postedChoices = array_values(array_unique(array_map('intval', $postedChoices)));

        if (count($postedChoices) !== $requiredSelections) {
            die(
                'For ' . htmlspecialchars($optionVote['name']) . ', select exactly ' .
                $requiredSelections . ' option' . ($requiredSelections === 1 ? '' : 's') . '.'
            );
        }

        foreach ($postedChoices as $choiceIndex) {
            if (!isset($optionVote['choices'][$choiceIndex])) {
                die('Invalid option selected for ' . htmlspecialchars($optionVote['name']) . '.');
            }
        }

        $optionVoteAnswers[(int)$roundNumber] = $postedChoices;
    }
}

// Legacy single-Q3 validation for older seasons only.
$q3Choices = [];
if ($legacyQ3Enabled) {
    $q3Input = isset($_POST['q3']) && is_array($_POST['q3']) ? $_POST['q3'] : [];
    $q3Choices = array_values(array_unique(array_map('intval', $q3Input)));

    if (count($q3Choices) !== 2) {
        die('You must select exactly 2 options for the Option Vote.');
    }
    foreach ($q3Choices as $choiceIndex) {
        if (!isset($q3Options[$choiceIndex])) {
            die('Invalid Option Vote selection.');
        }
    }
}

try {
    $pdo->beginTransaction();

    // Clear prior answers so a user can resubmit cleanly.
    $pdo->prepare('DELETE FROM ML_Q1Votes WHERE SeasonID = ? AND UserID = ?')->execute([$seasonId, $userId]);
    $pdo->prepare('DELETE FROM ML_Q2Answers WHERE SeasonID = ? AND UserID = ?')->execute([$seasonId, $userId]);
    $pdo->prepare('DELETE FROM ML_Q3Answers WHERE SeasonID = ? AND UserID = ?')->execute([$seasonId, $userId]);
    if (mlTableExists($pdo, 'ML_SeasonRoundOptionVotes')) {
        $pdo->prepare('DELETE FROM ML_SeasonRoundOptionVotes WHERE SeasonID = ? AND UserID = ?')->execute([$seasonId, $userId]);
    }

    if (!empty($votes)) {
        $insQ1 = $pdo->prepare(
            'INSERT INTO ML_Q1Votes (SeasonID, UserID, CategoryIndex, Points)
             VALUES (:SeasonID, :UserID, :CategoryIndex, :Points)'
        );
        foreach ($votes as $catIndex => $points) {
            $insQ1->execute([
                ':SeasonID' => $seasonId,
                ':UserID' => $userId,
                ':CategoryIndex' => $catIndex,
                ':Points' => $points,
            ]);
        }
    }

    if (!empty($q2Answers)) {
        $insQ2 = $pdo->prepare(
            'INSERT INTO ML_Q2Answers (SeasonID, UserID, QuestionNumber, Choice1Index, Choice2Index)
             VALUES (:SeasonID, :UserID, :QuestionNumber, :Choice1Index, :Choice2Index)'
        );
        foreach ($q2Answers as $qNum => $choices) {
            $insQ2->execute([
                ':SeasonID' => $seasonId,
                ':UserID' => $userId,
                ':QuestionNumber' => $qNum,
                ':Choice1Index' => $choices[0],
                ':Choice2Index' => $choices[1],
            ]);
        }
    }

    if ($useGenericOptionVotes) {
        $insOptionVote = $pdo->prepare(
            'INSERT INTO ML_SeasonRoundOptionVotes (SeasonID, RoundNumber, UserID, OptionIndex)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($optionVoteAnswers as $roundNumber => $choices) {
            foreach ($choices as $choiceIndex) {
                $insOptionVote->execute([$seasonId, $roundNumber, $userId, $choiceIndex]);
            }
        }
    } elseif ($legacyQ3Enabled) {
        $insQ3 = $pdo->prepare(
            'INSERT INTO ML_Q3Answers (SeasonID, UserID, Choice1Index, Choice2Index)
             VALUES (:SeasonID, :UserID, :Choice1Index, :Choice2Index)'
        );
        $insQ3->execute([
            ':SeasonID' => $seasonId,
            ':UserID' => $userId,
            ':Choice1Index' => $q3Choices[0],
            ':Choice2Index' => $q3Choices[1],
        ]);
    }

    $insSub = $pdo->prepare(
        'INSERT INTO ML_Submissions (SeasonID, UserID, SubmittedAt)
         VALUES (:SeasonID, :UserID, NOW())
         ON DUPLICATE KEY UPDATE SubmittedAt = VALUES(SubmittedAt)'
    );
    $insSub->execute([
        ':SeasonID' => $seasonId,
        ':UserID' => $userId,
    ]);

    $pdo->commit();
    mlDiscordMaybeSendSeasonBuilderVotingComplete($pdo, $seasonId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die('Error saving your answers: ' . htmlspecialchars($e->getMessage()));
}

header('Location: ' . mlUrl('season-builder/sb_vote.php'));
exit;
