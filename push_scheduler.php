<?php
// Run every 15 minutes from the server scheduler.
// Sends personalized reminders and deadline notices only to players whose song or votes are unfinished.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script is for CLI use only.';
    exit;
}

$mode = 'live';
$dryRun = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--mode=qa') {
        $mode = 'qa';
    } elseif ($argument === '--mode=live') {
        $mode = 'live';
    } elseif ($argument === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}

if ($mode === 'qa') {
    $_GET['testing'] = 'qa';
}

if (!isset($_SERVER['DOCUMENT_ROOT']) || trim((string)$_SERVER['DOCUMENT_ROOT']) === '') {
    $_SERVER['DOCUMENT_ROOT'] = PHP_OS_FAMILY === 'Windows' ? dirname(__DIR__) : __DIR__;
}

if (PHP_OS_FAMILY === 'Windows') {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gameplay/schema.php';
require_once __DIR__ . '/gameplay/seasons.php';
require_once __DIR__ . '/integrations/push/push.php';

function mlPushSchedulerLog(string $mode, string $message): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] [' . strtoupper($mode) . '] ' . $message . PHP_EOL;
    $logDir = __DIR__ . '/logs';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    @file_put_contents($logDir . '/push_scheduler_' . $mode . '.log', $line, FILE_APPEND);
    echo $line;
}

try {
    if (mlPushGetDataMode($pdo) !== $mode) {
        throw new RuntimeException('The requested scheduler mode could not be verified.');
    }

    if (!mlPushServerReady($pdo)) {
        throw new RuntimeException('Push is disabled or its dependency, VAPID keys, or database tables are missing.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $earliestDue = $now->modify('-30 minutes')->format('Y-m-d H:i:s');
    $latestDue = $now->modify('+24 hours')->format('Y-m-d H:i:s');
    $roundTimingMode = strtolower(trim((string)mlGetSettingValue($pdo, 'playlist_build_mode', 'due'))) === 'wait'
        ? 'wait'
        : 'due';
    $roundStateSelect = mlSeasonRoundsHasStateColumns($pdo) ? ', sr.RoundState' : ', NULL AS RoundState';

    $roundStmt = $pdo->prepare(
        "SELECT sr.SeasonRoundID,
                sr.SeasonID,
                sr.RoundNumber,
                sr.Title,
                sr.SongsDue,
                sr.VotesDue,
                s.SeasonName,
                CASE WHEN rp.SeasonRoundID IS NULL THEN 0 ELSE 1 END AS HasPlaylist
                {$roundStateSelect}
         FROM ML_SeasonRounds sr
         INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
         LEFT JOIN ML_RoundPlaylists rp ON rp.SeasonRoundID = sr.SeasonRoundID
         WHERE s.IsActive = 1
           AND (
                (sr.SongsDue >= ? AND sr.SongsDue <= ?)
                OR (sr.VotesDue >= ? AND sr.VotesDue <= ?)
           )
         ORDER BY sr.RoundNumber ASC"
    );
    $roundStmt->execute([$earliestDue, $latestDue, $earliestDue, $latestDue]);
    $rounds = $roundStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $client = $dryRun ? null : mlPushCreateWebPushClient();
    $candidateCount = 0;
    $sentCount = 0;
    $failedCount = 0;
    $expiredCount = 0;

    foreach ($rounds as $round) {
        $seasonRoundId = (int)$round['SeasonRoundID'];
        $roundNumber = (int)$round['RoundNumber'];
        $roundTitle = trim((string)$round['Title']);
        $tasks = [];
        $songPhaseClosed = false;
        $votingPhaseClosed = false;

        $songsDue = mlCreateUtcDate((string)($round['SongsDue'] ?? ''));
        if ($songsDue instanceof DateTimeImmutable) {
            $window = mlPushResolveReminderWindow($songsDue, $now);
            if ($window !== null && ($window['key'] === 'deadline' || (int)$round['HasPlaylist'] === 0)) {
                if ($window['key'] === 'deadline') {
                    $songPhaseClosed = $roundTimingMode !== 'wait' || (int)$round['HasPlaylist'] === 1;
                    $notificationType = mlPushResolveDeadlineNotificationType('song', $roundTimingMode, $songPhaseClosed);
                } else {
                    $notificationType = $window['key'] === '2h' ? 'song_2h' : 'song_24h';
                }
                $notificationCopy = mlPushBuildNotificationCopy(
                    $notificationType,
                    $roundNumber,
                    $roundTitle
                );
                $tasks[] = [
                    'type' => 'song',
                    'due_at' => $songsDue,
                    'window' => $window,
                    'reminder_key' => $window['key'] === 'deadline'
                        ? mlPushBuildDeadlineDeliveryKey('song', $songPhaseClosed, $songsDue->format('Y-m-d H:i:s'))
                        : '',
                    'title' => $notificationCopy['title'],
                    'body' => $notificationCopy['body'],
                    'url' => mlUrl(
                        $songPhaseClosed
                            ? 'season.php?season_id=' . (int)$round['SeasonID']
                            : 'song.php?season_round_id=' . $seasonRoundId
                    ),
                ];
            }
        }

        if ((int)$round['HasPlaylist'] === 1) {
            $votesDue = mlCreateUtcDate((string)($round['VotesDue'] ?? ''));
            if ($votesDue instanceof DateTimeImmutable) {
                $window = mlPushResolveReminderWindow($votesDue, $now);
                if ($window !== null) {
                    if ($window['key'] === 'deadline') {
                        $votingPhaseClosed = strtolower(trim((string)($round['RoundState'] ?? ''))) === 'closed';
                        $notificationType = mlPushResolveDeadlineNotificationType('vote', $roundTimingMode, $votingPhaseClosed);
                    } else {
                        $notificationType = $window['key'] === '2h' ? 'vote_2h' : 'vote_24h';
                    }
                    $notificationCopy = mlPushBuildNotificationCopy(
                        $notificationType,
                        $roundNumber,
                        $roundTitle
                    );
                    $tasks[] = [
                        'type' => 'vote',
                        'due_at' => $votesDue,
                        'window' => $window,
                        'reminder_key' => $window['key'] === 'deadline'
                            ? mlPushBuildDeadlineDeliveryKey('vote', $roundTimingMode !== 'wait' || $votingPhaseClosed, $votesDue->format('Y-m-d H:i:s'))
                            : '',
                        'title' => $notificationCopy['title'],
                        'body' => $notificationCopy['body'],
                        'url' => mlUrl(
                            $roundTimingMode !== 'wait' || $votingPhaseClosed
                                ? 'season.php?season_id=' . (int)$round['SeasonID']
                                : 'vote.php?season_round_id=' . $seasonRoundId
                        ),
                    ];
                }
            }
        }

        foreach ($tasks as $task) {
            $deadlineScope = substr(hash('sha256', $task['due_at']->format('Y-m-d H:i:s')), 0, 12);
            $reminderKey = trim((string)($task['reminder_key'] ?? ''));
            if ($reminderKey === '') {
                $reminderKey = $task['type'] . '_' . $task['window']['key'] . '_' . $deadlineScope;
            }
            $subscriptions = mlPushLoadIncompleteRoundSubscriptions($pdo, $seasonRoundId, $task['type']);

            foreach ($subscriptions as $subscriptionRow) {
                $subscriptionId = (int)$subscriptionRow['PushSubscriptionID'];
                if (mlPushReminderWasSent($pdo, $subscriptionId, $seasonRoundId, $reminderKey)) {
                    continue;
                }

                $candidateCount++;
                if ($dryRun) {
                    continue;
                }

                $result = mlPushSendNotificationOnce($pdo, $client, $subscriptionRow, $seasonRoundId, $reminderKey, [
                    'title' => $task['title'],
                    'body' => $task['body'],
                    'url' => $task['url'],
                    'tag' => 'musicball-' . $reminderKey . '-' . $seasonRoundId,
                ]);

                if (empty($result['attempted'])) {
                    $candidateCount--;
                    continue;
                }

                if (!empty($result['success'])) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }

                if (!empty($result['expired'])) {
                    $expiredCount++;
                }
            }
        }
    }

    if ($dryRun) {
        mlPushSchedulerLog($mode, 'Dry run complete. Eligible device reminders: ' . $candidateCount . '.');
    } else {
        mlPushSchedulerLog(
            $mode,
            'Scheduler complete. Eligible: ' . $candidateCount
            . '; sent: ' . $sentCount
            . '; failed: ' . $failedCount
            . '; expired subscriptions disabled: ' . $expiredCount . '.'
        );
    }
    exit(0);
} catch (Throwable $e) {
    mlPushSchedulerLog($mode, 'Scheduler failed: ' . mlPushSanitizeDeliveryError($e->getMessage()));
    exit(1);
}
