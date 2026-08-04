<?php
require_once 'session_boot.php';
require_once 'config.php';
require_once __DIR__ . '/season-builder/sb_season_builder.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$targetSeasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;
if ($targetSeasonId <= 0) {
    $_SESSION['ml_admin_error'] = 'Choose a season to view its rounds.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$seasonStmt = $pdo->prepare(
    'SELECT SeasonID, SeasonName, IsActive FROM ML_Seasons WHERE SeasonID = ? LIMIT 1'
);
$seasonStmt->execute([$targetSeasonId]);
$seasonRow = $seasonStmt->fetch(PDO::FETCH_ASSOC);

if (!$seasonRow) {
    $_SESSION['ml_admin_error'] = 'That season could not be found.';
    header('Location: ' . mlUrl('admin.php'));
    exit;
}

$seasonId = (int)$seasonRow['SeasonID'];
$seasonName = (string)$seasonRow['SeasonName'];
$seasonIsActive = (int)$seasonRow['IsActive'] === 1;
$hasCommittedRounds = mlSeasonHasCommittedRounds($pdo, $seasonId, 12);
$builderLocked = mlIsSeasonBuilderLocked($pdo, $seasonId);
$votingOpen = mlIsSeasonVotingOpen($pdo, $seasonId);
$votingComplete = mlIsSeasonVotingComplete($pdo, $seasonId);
$votingClosedEarly = mlWasSeasonVotingClosedEarly($pdo, $seasonId);
$submittedCount = mlGetSeasonSubmissionCount($pdo, $seasonId);
$totalPlayers = mlGetTotalUserCount($pdo);

$roundSlots = mlLoadSeasonRoundSlots($pdo, $seasonId, 12);
$hasConfiguredRounds = false;
foreach ($roundSlots as $roundSlot) {
    if (trim((string)($roundSlot['round_type'] ?? '')) !== '') {
        $hasConfiguredRounds = true;
        break;
    }
}

$rounds = [];
if ($hasCommittedRounds || $hasConfiguredRounds) {
    $questionConfig = mlLoadSeasonQuestionConfig($pdo, $seasonId);
    $rounds = mlResolveSeasonRounds(
        $pdo,
        $seasonId,
        $seasonName,
        $questionConfig['q2Options'],
        $questionConfig['q3Options'],
        12
    );
}

if ($seasonIsActive || $hasCommittedRounds) {
    $statusLabel = 'Season rounds';
    $statusClass = 'pill-complete';
    $summary = 'These are the rounds currently set for this season.';
} elseif (!$hasConfiguredRounds) {
    $statusLabel = 'Setup in progress';
    $statusClass = 'pill-neutral';
    $summary = 'Finish the season setup to see its rounds here.';
} elseif ($votingOpen && !$votingComplete) {
    $statusLabel = 'Voting open';
    $statusClass = 'pill-open';
    $summary = $submittedCount . ' of ' . $totalPlayers
        . ' players have submitted. These rounds reflect the votes received so far.';
} elseif ($votingComplete) {
    $statusLabel = 'Voting complete';
    $statusClass = 'pill-complete';
    $summary = 'All ' . $totalPlayers
        . ' players have submitted. These are the final Season Builder results.';
} elseif ($votingClosedEarly) {
    $statusLabel = 'Voting closed early';
    $statusClass = 'pill-neutral';
    $summary = $submittedCount . ' of ' . $totalPlayers
        . ' players submitted before voting closed. These are the resolved rounds.';
} elseif ($builderLocked) {
    $statusLabel = 'Voting closed';
    $statusClass = 'pill-neutral';
    $summary = $submittedCount . ' of ' . $totalPlayers
        . ' players submitted. These rounds reflect the results currently on file.';
} else {
    $statusLabel = 'Ready for voting';
    $statusClass = 'pill-neutral';
    $summary = 'These rounds reflect the completed setup. Voting has not opened yet.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Musicball - View Rounds</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'admin'; include 'header.php'; ?>
<div class="wrapper">
    <div class="card final-wrapper">
        <div class="home-shell-kicker">View rounds</div>
        <h1 class="final-title"><?= htmlspecialchars($seasonName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><span class="pill <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></p>
        <p><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></p>

        <?php if (!empty($rounds)): ?>
            <div class="rounds-container" id="rounds-container">
                <?php foreach ($rounds as $i => $round): ?>
                    <div class="round-card">
                        <div class="round-number">Round <?= $i + 1 ?></div>
                        <div class="round-title"><?= htmlspecialchars($round['title'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($round['tag'])): ?>
                            <div class="round-tag"><?= htmlspecialchars($round['tag'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($round['schedule_left']) || !empty($round['schedule_right'])): ?>
                            <div class="round-schedule-row">
                                <?php if (!empty($round['schedule_is_utc'])): ?>
                                    <div class="round-schedule-left" data-utc-schedule-label="Songs Due" data-utc-schedule-value="<?= htmlspecialchars($round['schedule_left'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                                    <div class="round-schedule-right" data-utc-schedule-label="Votes Due" data-utc-schedule-value="<?= htmlspecialchars($round['schedule_right'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                                <?php else: ?>
                                    <div class="round-schedule-left"><?= htmlspecialchars($round['schedule_left'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="round-schedule-right"><?= htmlspecialchars($round['schedule_right'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function detectBrowserTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (error) {
            return 'UTC';
        }
    }

    function formatUtcSchedule(utcValue, label, timezone) {
        if (!utcValue) {
            return '';
        }

        const isoLike = utcValue.replace(' ', 'T') + 'Z';
        const date = new Date(isoLike);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        const formatted = new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            timeZone: timezone,
            timeZoneName: 'short'
        }).format(date);

        return label + ': ' + formatted;
    }

    const timezone = detectBrowserTimezone();
    document.querySelectorAll('[data-utc-schedule-value]').forEach(function (node) {
        const label = node.getAttribute('data-utc-schedule-label') || '';
        const value = node.getAttribute('data-utc-schedule-value') || '';
        node.textContent = formatUtcSchedule(value, label, timezone);
    });

    document.querySelectorAll('.round-card').forEach(function (card, index) {
        window.setTimeout(function () {
            card.classList.add('visible');
        }, 50 + (index * 50));
    });
});
</script>
</body>
</html>
