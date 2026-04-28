<?php
// submit.php
require_once __DIR__ . '/../ml_config.php';
require_once __DIR__ . '/../ml_discord.php';

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

$cookieName = 'ml_league_user';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // If someone hits this directly, bounce them to the app root
    header('Location: ' . mlUrl('season-builder/vote.php'));
    exit;
}

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

// Validate user
$check = $pdo->prepare("SELECT UserID FROM ML_Users WHERE UserID = ?");
$check->execute([$userId]);
if (!$check->fetch()) {
    // Invalid user: clear any bad cookie and send them back to root
    setcookie($cookieName, '', time() - 3600, '/');
    setcookie('ml_user_id', '', time() - 3600, '/');
    header('Location: ' . mlUrl('season-builder/vote.php'));
    exit;
}

// Fetch categories for Q1
$stmt = $pdo->prepare("
    SELECT CategoryIndex
    FROM ML_Q1Categories
    WHERE SeasonID = ?
    ORDER BY CategoryIndex
");
$stmt->execute([$seasonId]);
$categoryIndexes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// --- Q1 validation ---
$q1Data = isset($_POST['q1']) && is_array($_POST['q1']) ? $_POST['q1'] : [];
$totalPoints = 0;
$votes = [];

foreach ($categoryIndexes as $catIndex) {
    $catIndex = (int)$catIndex;
    $val = isset($q1Data[$catIndex]) ? (int)$q1Data[$catIndex] : 0;
    if ($val < 0 || $val > 4) {
        die("Invalid Q1 value for category $catIndex. Must be between 0 and 4.");
    }
    $totalPoints += $val;
    if ($val > 0) {
        $votes[$catIndex] = $val;
    }
}

if ($totalPoints !== 10) {
    die("You must allocate exactly 10 points in Question 1. You allocated: $totalPoints.");
}

// --- Q2 validation (now only parts 1 and 2) ---
$q2Input = isset($_POST['q2']) && is_array($_POST['q2']) ? $_POST['q2'] : [];
$q2Answers = []; // [questionNumber => [choice1, choice2]]

// Explicitly require Q2 parts 1 and 2
$requiredQ2Parts = [1, 2];

foreach ($requiredQ2Parts as $qNum) {
    if (!isset($q2Options[$qNum]) || !is_array($q2Options[$qNum])) {
        die("Configuration error: Question 2 part $qNum is not defined.");
    }
    $opts = $q2Options[$qNum];

    if (!isset($q2Input[$qNum]) || !is_array($q2Input[$qNum])) {
        die("Missing selections for Question 2 part $qNum.");
    }

    $choices = array_map('intval', $q2Input[$qNum]);
    $choices = array_values(array_unique($choices));
    if (count($choices) !== 2) {
        die("You must select exactly 2 options for Question 2 part $qNum.");
    }

    foreach ($choices as $c) {
        if (!isset($opts[$c])) {
            die("Invalid option selected for Question 2 part $qNum.");
        }
    }
    $q2Answers[$qNum] = $choices;
}

// --- Q3 validation ---
$q3Input = isset($_POST['q3']) && is_array($_POST['q3']) ? $_POST['q3'] : [];
$q3Choices = array_map('intval', $q3Input);
$q3Choices = array_values(array_unique($q3Choices));

if (count($q3Choices) !== 2) {
    die("You must select exactly 2 options for Question 3.");
}
foreach ($q3Choices as $c) {
    if (!isset($q3Options[$c])) {
        die("Invalid option selected for Question 3.");
    }
}

try {
    $pdo->beginTransaction();

    // Clear any existing answers for this user in the active season (allows re-submission)
    $del = $pdo->prepare("DELETE FROM ML_Q1Votes WHERE SeasonID = ? AND UserID = ?");
    $del->execute([$seasonId, $userId]);

    $del = $pdo->prepare("DELETE FROM ML_Q2Answers WHERE SeasonID = ? AND UserID = ?");
    $del->execute([$seasonId, $userId]);

    $del = $pdo->prepare("DELETE FROM ML_Q3Answers WHERE SeasonID = ? AND UserID = ?");
    $del->execute([$seasonId, $userId]);

    // Insert Q1 votes
    if (!empty($votes)) {
        $insQ1 = $pdo->prepare(
            "INSERT INTO ML_Q1Votes (SeasonID, UserID, CategoryIndex, Points)
             VALUES (:SeasonID, :UserID, :CategoryIndex, :Points)"
        );
        foreach ($votes as $catIndex => $points) {
            $insQ1->execute([
                ':SeasonID'      => $seasonId,
                ':UserID'        => $userId,
                ':CategoryIndex' => $catIndex,
                ':Points'        => $points
            ]);
        }
    }

    // Insert Q2 (only parts 1 and 2)
    if (!empty($q2Answers)) {
        $insQ2 = $pdo->prepare(
            "INSERT INTO ML_Q2Answers (SeasonID, UserID, QuestionNumber, Choice1Index, Choice2Index)
             VALUES (:SeasonID, :UserID, :QuestionNumber, :Choice1Index, :Choice2Index)"
        );
        foreach ($q2Answers as $qNum => $choices) {
            $insQ2->execute([
                ':SeasonID'       => $seasonId,
                ':UserID'         => $userId,
                ':QuestionNumber' => $qNum,
                ':Choice1Index'   => $choices[0],
                ':Choice2Index'   => $choices[1],
            ]);
        }
    }

    // Insert Q3
    $insQ3 = $pdo->prepare(
        "INSERT INTO ML_Q3Answers (SeasonID, UserID, Choice1Index, Choice2Index)
         VALUES (:SeasonID, :UserID, :Choice1Index, :Choice2Index)"
    );
    $insQ3->execute([
        ':SeasonID'     => $seasonId,
        ':UserID'       => $userId,
        ':Choice1Index' => $q3Choices[0],
        ':Choice2Index' => $q3Choices[1],
    ]);

    // Mark as submitted
    $insSub = $pdo->prepare(
        "INSERT INTO ML_Submissions (SeasonID, UserID, SubmittedAt)
         VALUES (:SeasonID, :UserID, NOW())
         ON DUPLICATE KEY UPDATE SubmittedAt = VALUES(SubmittedAt)"
    );
    $insSub->execute([
        ':SeasonID' => $seasonId,
        ':UserID'   => $userId
    ]);

    $pdo->commit();

    mlDiscordMaybeSendSeasonBuilderVotingComplete($pdo, $seasonId);

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error saving your answers: " . htmlspecialchars($e->getMessage()));
}

// Go back to app root; index.php router will include choice.php
header('Location: ' . mlUrl('season-builder/vote.php'));
exit;
