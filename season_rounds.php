<?php
require_once 'ml_session_boot.php';
require_once 'ml_config.php';
require_once __DIR__ . '/season-builder/ml_questions.php';
require_once __DIR__ . '/season-builder/ml_season_builder.php';
require_once 'ml_discord.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: index.php');
    exit;
}

$targetSeasonId = 0;
if (isset($_GET['season_id'])) {
    $targetSeasonId = (int)$_GET['season_id'];
} elseif (isset($_POST['season_id'])) {
    $targetSeasonId = (int)$_POST['season_id'];
}

if ($targetSeasonId <= 0) {
    header('Location: admin.php');
    exit;
}

function mlRoundsPageValidTimezone($timezone) {
    $timezone = trim((string)$timezone);
    if ($timezone === '') {
        return false;
    }

    return in_array($timezone, DateTimeZone::listIdentifiers(), true);
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
        $dt = DateTime::createFromFormat($format, $value, $timezone);
        if ($dt instanceof DateTime) {
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        }
    }

    try {
        $dt = new DateTime($value, $timezone);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
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
    header('Location: admin.php');
    exit;
}

$seasonIsActive = ((int)($seasonRow['IsActive'] ?? 0) === 1);
$currentSeasonRow = mlGetCurrentSeason($pdo);
$currentSeasonId = $currentSeasonRow ? (int)$currentSeasonRow['SeasonID'] : 0;
$nextSeasonRow = mlGetNextSeason($pdo);
$nextSeasonId = $nextSeasonRow ? (int)$nextSeasonRow['SeasonID'] : 0;
$isReviewableNextSeason = !$seasonIsActive && $nextSeasonId > 0 && $targetSeasonId === $nextSeasonId;
$canStartSeasonHere = $isReviewableNextSeason && mlCanStartNextSeason($pdo, $targetSeasonId);

$roundsTableReady = mlSeasonRoundsAvailable($pdo);
$slotCount = 12;
$questionConfig = mlLoadSeasonQuestionConfig($pdo, $targetSeasonId);
$committedRounds = mlLoadCommittedSeasonRounds($pdo, $targetSeasonId, $slotCount);
$hasCommittedRounds = !empty($committedRounds);
$resolvedRounds = mlResolveSeasonRounds($pdo, $targetSeasonId, (string)$seasonRow['SeasonName'], $questionConfig['q2Options'], $questionConfig['q3Options'], $slotCount);

$roundRows = [];
for ($i = 1; $i <= $slotCount; $i++) {
    $resolved = isset($resolvedRounds[$i - 1]) ? $resolvedRounds[$i - 1] : [
        'round_number' => $i,
        'title' => '',
        'tag' => '',
        'schedule_left' => '',
        'schedule_right' => '',
        'schedule_is_utc' => true,
    ];

    if (isset($committedRounds[$i])) {
        $resolved = $committedRounds[$i];
    }

    $roundRows[$i] = [
        'round_number' => $i,
        'title' => (string)($resolved['title'] ?? ''),
        'tag' => (string)($resolved['tag'] ?? ''),
        'schedule_left' => (string)($resolved['schedule_left'] ?? ''),
        'schedule_right' => (string)($resolved['schedule_right'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$roundsTableReady) {
            throw new RuntimeException('Run the database migration first: db/ml_season_rounds_schema.sql');
        }

        $browserTimezone = trim((string)($_POST['browser_timezone'] ?? ''));
        if (!mlRoundsPageValidTimezone($browserTimezone)) {
            $browserTimezone = 'UTC';
        }

        $postedRounds = isset($_POST['rounds']) && is_array($_POST['rounds']) ? $_POST['rounds'] : [];
        $roundRows = [];
        for ($i = 1; $i <= $slotCount; $i++) {
            $row = isset($postedRounds[$i]) && is_array($postedRounds[$i]) ? $postedRounds[$i] : [];
            $title = trim((string)($row['title'] ?? ''));
            $tag = trim((string)($row['tag'] ?? ''));
            $songsDue = mlRoundsPageNormalizeSchedule((string)($row['schedule_left'] ?? ''), $browserTimezone);
            $votesDue = mlRoundsPageNormalizeSchedule((string)($row['schedule_right'] ?? ''), $browserTimezone);

            if ($title === '') {
                throw new RuntimeException('Every round needs a title before the season can be saved. Round ' . $i . ' is blank.');
            }

            $roundRows[$i] = [
                'round_number' => $i,
                'title' => $title,
                'tag' => $tag,
                'schedule_left' => $songsDue,
                'schedule_right' => $votesDue,
            ];
        }

        $pdo->beginTransaction();

        $existingStmt = $pdo->prepare('SELECT SeasonRoundID, RoundNumber FROM ML_SeasonRounds WHERE SeasonID = ? ORDER BY RoundNumber ASC, SeasonRoundID ASC');
        $existingStmt->execute([$targetSeasonId]);
        $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

        $existingByRoundNumber = [];
        $duplicateIdsToDelete = [];
        foreach ($existingRows as $existingRow) {
            $existingRoundNumber = (int)$existingRow['RoundNumber'];
            $existingSeasonRoundId = (int)$existingRow['SeasonRoundID'];

            if (!isset($existingByRoundNumber[$existingRoundNumber])) {
                $existingByRoundNumber[$existingRoundNumber] = $existingSeasonRoundId;
            } else {
                $duplicateIdsToDelete[] = $existingSeasonRoundId;
            }
        }

        if (!empty($duplicateIdsToDelete)) {
            $duplicatePlaceholders = implode(',', array_fill(0, count($duplicateIdsToDelete), '?'));
            $deleteDuplicateStmt = $pdo->prepare('DELETE FROM ML_SeasonRounds WHERE SeasonRoundID IN (' . $duplicatePlaceholders . ')');
            $deleteDuplicateStmt->execute($duplicateIdsToDelete);
        }

        $updateStmt = $pdo->prepare('UPDATE ML_SeasonRounds SET RoundNumber = ?, Title = ?, Tagline = ?, SongsDue = ?, VotesDue = ? WHERE SeasonRoundID = ?');
        $insertStmt = $pdo->prepare('INSERT INTO ML_SeasonRounds (SeasonID, RoundNumber, Title, Tagline, SongsDue, VotesDue) VALUES (?, ?, ?, ?, ?, ?)');

        foreach ($roundRows as $roundRow) {
            $roundNumber = (int)$roundRow['round_number'];
            $title = $roundRow['title'];
            $tagline = $roundRow['tag'] !== '' ? $roundRow['tag'] : null;
            $songsDue = $roundRow['schedule_left'] !== '' ? $roundRow['schedule_left'] : null;
            $votesDue = $roundRow['schedule_right'] !== '' ? $roundRow['schedule_right'] : null;

            if (isset($existingByRoundNumber[$roundNumber])) {
                $updateStmt->execute([
                    $roundNumber,
                    $title,
                    $tagline,
                    $songsDue,
                    $votesDue,
                    $existingByRoundNumber[$roundNumber],
                ]);
            } else {
                $insertStmt->execute([
                    $targetSeasonId,
                    $roundNumber,
                    $title,
                    $tagline,
                    $songsDue,
                    $votesDue,
                ]);
            }
        }

        if ($canStartSeasonHere) {
            if (!$currentSeasonRow) {
                throw new RuntimeException('A current season and a next season are both required before you can start the season.');
            }

            $pdo->exec('UPDATE ML_Seasons SET IsActive = 0');
            $activateStmt = $pdo->prepare('UPDATE ML_Seasons SET IsActive = 1 WHERE SeasonID = ?');
            $activateStmt->execute([$targetSeasonId]);
            mlSetSeasonConfig($pdo, $targetSeasonId, 'voting_open', '0');
        }

        $pdo->commit();

        if ($canStartSeasonHere) {
            mlDiscordMaybeSendSeasonStarted($pdo, $targetSeasonId);
            $_SESSION['ml_admin_message'] = $seasonRow['SeasonName'] . ' is now the current season.';
            header('Location: ' . mlUrl('admin.php'));
            exit;
        }

        $_SESSION['ml_admin_message'] = 'Season rounds updated for ' . $seasonRow['SeasonName'] . '.';

        header('Location: ' . mlUrl('season_rounds.php?season_id=' . $targetSeasonId));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $adminError = $e->getMessage();
    }
}

$committedRounds = mlLoadCommittedSeasonRounds($pdo, $targetSeasonId, $slotCount);
$hasCommittedRounds = !empty($committedRounds);
$votingOpenForSeason = ((string)mlGetSeasonConfig($pdo, $targetSeasonId, 'voting_open', '0') === '1');
$totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM ML_Users');
$totalUsers = (int)$totalUsersStmt->fetchColumn();
$submissionStmt = $pdo->prepare('SELECT COUNT(DISTINCT UserID) FROM ML_Submissions WHERE SeasonID = ?');
$submissionStmt->execute([$targetSeasonId]);
$submissionCount = (int)$submissionStmt->fetchColumn();
$seasonActionLabel = $canStartSeasonHere
    ? 'Start ' . (string)$seasonRow['SeasonName']
    : 'Save Season Changes';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Season Rounds Editor</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'admin'; include 'header.php'; ?>
<div class="wrapper">
    <div class="card admin-card admin-card-wide">
        <div class="admin-page-topline">
            <div>
                <div class="home-shell-kicker"><?= $seasonIsActive ? 'Edit season' : ($canStartSeasonHere ? 'Review next season' : 'Season rounds') ?></div>
                <h1><?= htmlspecialchars($seasonRow['SeasonName']) ?></h1>
                <p>
                    <?php if ($seasonIsActive): ?>
                        These saved rounds define the live season. You can still intentionally edit them here.
                    <?php elseif ($canStartSeasonHere): ?>
                        These rounds are prefilled from the current voting results. Review them, make any manual edits you want, then start <?= htmlspecialchars((string)$seasonRow['SeasonName']) ?> from this page.
                    <?php else: ?>
                        These rounds are prefilled from the current voting results. Review them and save any manual edits here.
                    <?php endif; ?>
                </p>
            </div>
            <a href="admin.php" class="button-secondary admin-back-link">&laquo; Back to Admin</a>
        </div>

        <?php if ($adminMessage !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($adminMessage) ?></div>
        <?php endif; ?>

        <?php if ($adminError !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($adminError) ?></div>
        <?php endif; ?>

        <?php if (!$roundsTableReady): ?>
            <div class="status-banner">
                Season start/edit needs the new database table first. Run <strong>db/ml_season_rounds_schema.sql</strong>, then reload this page.
            </div>
        <?php endif; ?>

        <div class="admin-grid admin-grid-tight">
            <section class="admin-panel">
                <div class="home-shell-kicker">Status</div>
                <p>
                    <strong><?= htmlspecialchars($seasonRow['SeasonName']) ?></strong>
                    <span class="pill <?= $votingOpenForSeason ? 'pill-open' : 'pill-closed' ?>">
                        <?= $votingOpenForSeason ? 'Voting Open' : 'Voting Closed' ?>
                    </span>
                </p>
                <p>Submissions: <strong><?= $submissionCount ?> / <?= $totalUsers ?></strong></p>
                <p>Season saved: <strong><?= $hasCommittedRounds ? 'Yes' : 'No' ?></strong></p>
                <p>
                    <?php if ($seasonIsActive): ?>
                        Editing here updates the committed season rounds for the live season.
                    <?php elseif ($canStartSeasonHere): ?>
                        Starting here writes these rounds to the database, closes voting for this season, and makes it the current season.
                    <?php else: ?>
                        Saving here writes these rounds to the database without changing which season is current.
                    <?php endif; ?>
                </p>
            </section>

            <section class="admin-panel">
                <div class="home-shell-kicker">How this works</div>
                <h2>Round source</h2>
                <p>
                    Until the season is saved, this page pulls the current round results from your configured builder and the votes already in. Saving stores those 12 rounds in <code>ML_SeasonRounds</code>. If this is the review step for the next season, the start button here also closes voting and makes this season current.
                </p>
            </section>
        </div>

        <form method="post" action="season_rounds.php?season_id=<?= (int)$targetSeasonId ?>" class="admin-season-setup-form">
            <input type="hidden" name="season_id" value="<?= (int)$targetSeasonId ?>">
            <input type="hidden" name="browser_timezone" value="" data-browser-timezone>

            <section class="admin-panel admin-panel-full">
                <div class="admin-section-header admin-section-header-stack-mobile">
                    <div>
                        <div class="home-shell-kicker">Rounds</div>
                        <h2>Review and finalize the season</h2>
                        <p>These are the 12 rounds that will define the season for gameplay. Titles, tags, and deadlines can all be adjusted here before the season is started, and can still be edited later if needed.</p>
                    </div>
                    <div class="admin-section-actions">
                        <button type="button" class="button-secondary admin-mini-action-btn" data-create-weekly-schedule>Create Weekly Schedule</button>
                    </div>
                </div>
                <p data-weekly-schedule-message>Use round 1 as the template. This fills rounds 2-12 one week apart for both Songs Due and Votes Due.</p>

                <div class="admin-round-list admin-round-list-committed">
                    <?php foreach ($roundRows as $roundNumber => $roundRow): ?>
                        <div class="admin-round-card admin-round-card-committed">
                            <div class="admin-round-card-top admin-round-card-top-static">
                                <div class="admin-category-number">Round <?= $roundNumber ?></div>
                            </div>

                            <div class="admin-round-grid admin-round-grid-fixed admin-round-grid-committed">
                                <div>
                                    <label class="admin-label" for="season-round-title-<?= $roundNumber ?>">Title</label>
                                    <input type="text" id="season-round-title-<?= $roundNumber ?>" name="rounds[<?= $roundNumber ?>][title]" class="admin-input" value="<?= htmlspecialchars($roundRow['title']) ?>" required>
                                </div>
                                <div>
                                    <label class="admin-label" for="season-round-tag-<?= $roundNumber ?>">Tag</label>
                                    <input type="text" id="season-round-tag-<?= $roundNumber ?>" name="rounds[<?= $roundNumber ?>][tag]" class="admin-input" value="<?= htmlspecialchars($roundRow['tag']) ?>">
                                </div>
                            </div>

                            <div class="admin-round-grid admin-round-grid-common">
                                <div>
                                    <label class="admin-label" for="season-round-songs-<?= $roundNumber ?>">Songs Due</label>
                                    <input type="datetime-local" id="season-round-songs-<?= $roundNumber ?>" name="rounds[<?= $roundNumber ?>][schedule_left]" class="admin-input" value="" data-utc-datetime="<?= htmlspecialchars($roundRow['schedule_left']) ?>">
                                </div>
                                <div>
                                    <label class="admin-label" for="season-round-votes-<?= $roundNumber ?>">Votes Due</label>
                                    <input type="datetime-local" id="season-round-votes-<?= $roundNumber ?>" name="rounds[<?= $roundNumber ?>][schedule_right]" class="admin-input" value="" data-utc-datetime="<?= htmlspecialchars($roundRow['schedule_right']) ?>">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <p data-timezone-note>Deadlines are entered in your current timezone and saved in UTC.</p>

            <div class="admin-setup-actions">
                <button type="submit" class="button-primary" <?= !$roundsTableReady ? 'disabled' : '' ?>><?= htmlspecialchars($seasonActionLabel) ?></button>
            </div>
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

            var isoLike = utcValue.replace(' ', 'T') + 'Z';
            var date = new Date(isoLike);
            if (isNaN(date.getTime())) {
                input.value = '';
                return;
            }

            input.value = formatForDateTimeLocal(date);
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

    function setWeeklyScheduleMessage(message, isError) {
        var node = document.querySelector('[data-weekly-schedule-message]');
        if (!node) {
            return;
        }

        node.textContent = message;
        node.classList.toggle('admin-schedule-helper-error', !!isError);
        node.classList.toggle('admin-schedule-helper-success', !isError);
    }

    function createWeeklySchedule() {
        var songsInputs = document.querySelectorAll('input[name^="rounds["][name$="[schedule_left]"]');
        var votesInputs = document.querySelectorAll('input[name^="rounds["][name$="[schedule_right]"]');

        if (!songsInputs.length || !votesInputs.length) {
            setWeeklyScheduleMessage('Round deadline inputs could not be found on this page.', true);
            return;
        }

        var baseSongs = songsInputs[0].value;
        var baseVotes = votesInputs[0].value;

        if (!baseSongs || !baseVotes) {
            setWeeklyScheduleMessage('Set both round 1 deadlines first, then create the weekly schedule.', true);
            return;
        }

        for (var i = 1; i < songsInputs.length; i++) {
            songsInputs[i].value = addDaysToLocalValue(baseSongs, i * 7);
        }

        for (var j = 1; j < votesInputs.length; j++) {
            votesInputs[j].value = addDaysToLocalValue(baseVotes, j * 7);
        }

        setWeeklyScheduleMessage('Rounds 2-12 now follow round 1 on a weekly cadence for both Songs Due and Votes Due.', false);
    }

    applyTimezoneMetadata();
    hydrateUtcInputs();

    var weeklyScheduleButton = document.querySelector('[data-create-weekly-schedule]');
    if (weeklyScheduleButton) {
        weeklyScheduleButton.addEventListener('click', function () {
            createWeeklySchedule();
        });
    }
})();
</script>
</body>
</html>
