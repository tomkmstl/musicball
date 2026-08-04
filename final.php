<?php
require_once 'session_boot.php';
require_once 'config.php';

$votingSeason = mlGetVotingSeason($pdo);

if (!$votingSeason) {
    $_SESSION['ml_notice'] = 'Voting for the next season is currently closed.';
    header('Location: index.php');
    exit;
}

$seasonId = (int)$votingSeason['SeasonID'];
$seasonName = (string)$votingSeason['SeasonName'];
$votingOpen = true;
require_once __DIR__ . '/season-builder/sb_questions.php';
require_once __DIR__ . '/season-builder/sb_season_builder.php';

$totalPlayersStmt = $pdo->query('SELECT COUNT(*) FROM ML_Users');
$totalPlayers = (int)$totalPlayersStmt->fetchColumn();

$submittedCountStmt = $pdo->prepare('SELECT COUNT(*) FROM ML_Submissions WHERE SeasonID = ?');
$submittedCountStmt->execute([$seasonId]);
$submittedCount = (int)$submittedCountStmt->fetchColumn();

if ($submittedCount < $totalPlayers) {
    header('Location: ./');
    exit;
}

$rounds = mlResolveSeasonRounds(
    $pdo,
    $seasonId,
    $seasonName,
    $q2Options,
    $q3Options,
    12
);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music League – Season Reveal</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'vote'; include 'header.php'; ?>
<div class="wrapper">
    <div class="card final-wrapper">
        <h1 class="final-title">let's look at <?= htmlspecialchars(strtolower($seasonName), ENT_QUOTES, 'UTF-8') ?>...</h1>
        <p>
            All <?= (int)$totalPlayers ?> players have submitted. Here’s how the season shakes out.
        </p>

        <div class="rounds-container" id="rounds-container">
            <?php foreach ($rounds as $i => $r): ?>
                <div class="round-card">
                    <div class="round-number">Round <?= $i + 1 ?></div>
                    <div class="round-title"><?= htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($r['tag'])): ?>
                        <div class="round-tag"><?= htmlspecialchars($r['tag'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['schedule_left']) || !empty($r['schedule_right'])): ?>
                        <div class="round-schedule-row">
                            <?php if (!empty($r['schedule_is_utc'])): ?>
                                <div class="round-schedule-left" data-utc-schedule-label="Songs Due" data-utc-schedule-value="<?= htmlspecialchars($r['schedule_left'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="round-schedule-right" data-utc-schedule-label="Votes Due" data-utc-schedule-value="<?= htmlspecialchars($r['schedule_right'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                            <?php else: ?>
                                <div class="round-schedule-left"><?= htmlspecialchars($r['schedule_left'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="round-schedule-right"><?= htmlspecialchars($r['schedule_right'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <p><?= htmlspecialchars($seasonName, ENT_QUOTES, 'UTF-8') ?> reveal 🎧</p>
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

    const cards = document.querySelectorAll('.round-card');
    const delayStep = 3000;
    const baseDelay = 4000;

    cards.forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('visible');
        }, baseDelay + delayStep * index);
    });
});
</script>
</body>
</html>
