<?php
require_once 'session_boot.php';
require_once 'config.php';

$preview = isset($_GET['preview']) && $_GET['preview'] == '1';
$votingSeason = mlGetVotingSeason($pdo);

if (!$votingSeason && $preview && isset($_SESSION['UserID']) && mlIsAdminUserId($pdo, (int)$_SESSION['UserID'])) {
    $votingSeason = mlGetNextSeason($pdo);
}

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

if ($submittedCount < $totalPlayers && !$preview) {
    header('Location: ./');
    exit;
}

$rounds = [];
$useBuilderRounds = false;

if (mlSeasonBuilderAvailable($pdo)) {
    $builderSlots = mlLoadSeasonRoundSlots($pdo, $seasonId, 12);
    foreach ($builderSlots as $slot) {
        if ($slot['round_type'] !== '') {
            $useBuilderRounds = true;
            break;
        }
    }

    if ($useBuilderRounds) {
        $maxRank = 0;
        foreach ($builderSlots as $slot) {
            if ($slot['round_type'] === 'q1_ranked_category') {
                $maxRank = max($maxRank, (int)$slot['q1_rank']);
            }
        }
        if ($maxRank <= 0) {
            $maxRank = 12;
        }

        $topQ1Rows = mlComputeTopQ1ByRank($pdo, $seasonId, $maxRank);
        $q1ByRank = [];
        foreach ($topQ1Rows as $index => $row) {
            $q1ByRank[$index + 1] = $row;
        }

        $eraLabel = mlComputeWinningEraLabel($pdo, $seasonId, $q3Options);
        $q2CombinedLabel = mlComputeWinningQ2MadlibLabel($pdo, $seasonId, $q2Options);
        $walkmanSlotCount = 0;
        foreach ($builderSlots as $walkmanSlot) {
            if ($walkmanSlot['round_type'] === 'walkman') {
                $walkmanSlotCount++;
            }
        }
        $walkmanDisplays = mlComputeWalkmanDisplays($pdo, $seasonId, max(1, $walkmanSlotCount));

        $fixedRoundLibrary = [];
        foreach (mlLoadFixedRoundLibrary($pdo) as $fixedRound) {
            $fixedRoundLibrary[(int)$fixedRound['FixedRoundID']] = $fixedRound;
        }

        foreach ($builderSlots as $roundNumber => $slot) {
            $title = 'TBD Round';
            $tag = '';

            switch ($slot['round_type']) {
                case 'fixed':
                    $fixedRoundId = (int)$slot['fixed_round_id'];
                    if ($fixedRoundId > 0 && isset($fixedRoundLibrary[$fixedRoundId])) {
                        $title = (string)$fixedRoundLibrary[$fixedRoundId]['Title'];
                        $tag = (string)($fixedRoundLibrary[$fixedRoundId]['Tagline'] ?? '');
                    }
                    break;

                case 'q1_ranked_category':
                    $rank = (int)$slot['q1_rank'];
                    if ($rank > 0 && isset($q1ByRank[$rank]['Title'])) {
                        $title = (string)$q1ByRank[$rank]['Title'];
                    }
                    if ($rank > 0) {
                        $tag = mlOrdinalLabel($rank) . ' most-voted category';
                    }
                    break;

                case 'q2_madlib':
                    $title = $q2CombinedLabel;
                    $tag = 'Madlib Playlist Creator';
                    break;

                case 'q3_era':
                    $title = $eraLabel;
                    $tag = 'Era';
                    break;

                case 'walkman':
                    $title = !empty($walkmanDisplays) ? array_shift($walkmanDisplays) : "A League Member's Walkman";
                    $tag = 'Something that would fit right into a playlist of this randomly-selected MLP';
                    break;
            }

            if ($slot['title_override'] !== '') {
                $title = $slot['title_override'];
            }
            if ($slot['tag_override'] !== '') {
                $tag = $slot['tag_override'];
            }

            $rounds[] = [
                'title' => $title,
                'tag' => $tag,
                'schedule_left' => $slot['SongsDue'],
				'schedule_right' => $slot['VotesDue'],
                'schedule_is_utc' => true,
            ];
        }
    }
}

if (!$useBuilderRounds) {
    $q1TopStmt = $pdo->prepare("\n        SELECT c.CategoryIndex,\n               c.Title,\n               SUM(v.Points) AS TotalPoints\n        FROM ML_Q1Votes v\n        JOIN ML_Q1Categories c\n          ON v.SeasonID = c.SeasonID\n         AND v.CategoryIndex = c.CategoryIndex\n        WHERE v.SeasonID = ?\n        GROUP BY c.CategoryIndex, c.Title\n        ORDER BY TotalPoints DESC, c.CategoryIndex ASC\n        LIMIT 6\n    ");
    $q1TopStmt->execute([$seasonId]);
    $topQ1 = $q1TopStmt->fetchAll(PDO::FETCH_ASSOC);

    $defaultCategory = [
        'CategoryIndex' => null,
        'Title' => 'TBD Round',
    ];
    for ($i = 0; $i < 6; $i++) {
        if (!isset($topQ1[$i])) {
            $topQ1[$i] = $defaultCategory;
        }
    }

    $eraLabel = mlComputeWinningEraLabel($pdo, $seasonId, $q3Options);
    $q2CombinedLabel = mlComputeWinningQ2MadlibLabel($pdo, $seasonId, $q2Options);
    $walkmanDisplay = mlComputeWalkmanDisplay($pdo, $seasonId);

    $rounds[] = [
        'title' => 'My Current Jam ' . $seasonName,
        'tag' => '',
        'schedule_left' => 'submit on 1/9',
        'schedule_right' => 'vote by 1/14',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $topQ1[3]['Title'],
        'tag' => '4th most-voted category',
        'schedule_left' => 'submit on 1/16',
        'schedule_right' => 'vote by 1/21',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $walkmanDisplay,
        'tag' => 'Something that would fit right into a playlist of this randomly-selected MLP',
        'schedule_left' => 'submit on 1/23',
        'schedule_right' => 'vote by 1/28',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => 'Songs in the Queue s4e1',
        'tag' => '',
        'schedule_left' => 'submit on 1/30',
        'schedule_right' => 'vote by 2/4',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $topQ1[0]['Title'],
        'tag' => '1st most-voted category',
        'schedule_left' => 'submit on 2/6',
        'schedule_right' => 'vote by 2/11',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $topQ1[4]['Title'],
        'tag' => '5th most-voted category',
        'schedule_left' => 'submit on 2/13',
        'schedule_right' => 'vote by 2/18',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $eraLabel,
        'tag' => 'Era',
        'schedule_left' => 'submit on 2/20',
        'schedule_right' => 'vote by 2/25',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $topQ1[5]['Title'],
        'tag' => '6th most-voted category',
        'schedule_left' => 'submit on 2/27',
        'schedule_right' => 'vote by 3/4',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $topQ1[2]['Title'],
        'tag' => '3rd most-voted category',
        'schedule_left' => 'submit on 3/6',
        'schedule_right' => 'vote by 3/11',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $q2CombinedLabel,
        'tag' => 'Madlib Playlist Creator',
        'schedule_left' => 'submit on 3/13',
        'schedule_right' => 'vote by 3/18',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => 'Songs in the Queue s4e2',
        'tag' => '',
        'schedule_left' => 'submit on 3/20',
        'schedule_right' => 'vote by 3/25',
        'schedule_is_utc' => false,
    ];
    $rounds[] = [
        'title' => $topQ1[1]['Title'],
        'tag' => '2nd most-voted category',
        'schedule_left' => 'submit on 3/27',
        'schedule_right' => 'vote by 4/1',
        'schedule_is_utc' => false,
    ];
}
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
