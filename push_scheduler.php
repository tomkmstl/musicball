<?php
// Run every 15 minutes from the server scheduler.
// Sends personalized reminders only to players whose song or votes are unfinished.

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

function mlPushResolveReminderWindow(DateTimeImmutable $dueAt, DateTimeImmutable $now): ?array
{
    $remainingSeconds = $dueAt->getTimestamp() - $now->getTimestamp();
    if ($remainingSeconds <= 0 || $remainingSeconds > 86400) {
        return null;
    }

    if ($remainingSeconds <= 10800) {
        return ['key' => '3h', 'label' => 'about 3 hours'];
    }

    return ['key' => '24h', 'label' => 'about 24 hours'];
}

function mlPushLoadIncompleteSubscriptions(PDO $pdo, int $seasonRoundId, string $task): array
{
    if ($task === 'song') {
        $completionJoin = 'LEFT JOIN ML_RoundSongs completed ON completed.SeasonRoundID = ? AND completed.UserID = ps.UserID';
        $completionWhere = 'completed.RoundSongID IS NULL';
    } else {
        $completionJoin = 'LEFT JOIN ML_RoundVoteSubmissions completed ON completed.SeasonRoundID = ? AND completed.UserID = ps.UserID';
        $completionWhere = 'completed.RoundVoteSubmissionID IS NULL';
    }

    $stmt = $pdo->prepare(
        "SELECT ps.PushSubscriptionID,
                ps.UserID,
                ps.Endpoint,
                ps.PublicKey,
                ps.AuthToken,
                ps.ContentEncoding
         FROM ML_PushSubscriptions ps
         INNER JOIN ML_Users u ON u.UserID = ps.UserID
         {$completionJoin}
         WHERE ps.DisabledAt IS NULL
           AND {$completionWhere}
         ORDER BY ps.UserID ASC, ps.PushSubscriptionID ASC"
    );
    $stmt->execute([$seasonRoundId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    if (mlPushGetDataMode($pdo) !== $mode) {
        throw new RuntimeException('The requested scheduler mode could not be verified.');
    }

    if (!mlPushServerReady($pdo)) {
        throw new RuntimeException('Push is disabled or its dependency, VAPID keys, or database tables are missing.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $latestDue = $now->modify('+24 hours')->format('Y-m-d H:i:s');
    $nowSql = $now->format('Y-m-d H:i:s');

    $roundStmt = $pdo->prepare(
        "SELECT sr.SeasonRoundID,
                sr.SeasonID,
                sr.RoundNumber,
                sr.Title,
                sr.SongsDue,
                sr.VotesDue,
                s.SeasonName,
                CASE WHEN rp.SeasonRoundID IS NULL THEN 0 ELSE 1 END AS HasPlaylist
         FROM ML_SeasonRounds sr
         INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
         LEFT JOIN ML_RoundPlaylists rp ON rp.SeasonRoundID = sr.SeasonRoundID
         WHERE s.IsActive = 1
           AND (
                (sr.SongsDue > ? AND sr.SongsDue <= ?)
                OR (sr.VotesDue > ? AND sr.VotesDue <= ?)
           )
         ORDER BY sr.RoundNumber ASC"
    );
    $roundStmt->execute([$nowSql, $latestDue, $nowSql, $latestDue]);
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
        $roundLabel = 'Round ' . $roundNumber . ($roundTitle !== '' ? ': ' . $roundTitle : '');
        $tasks = [];

        if ((int)$round['HasPlaylist'] === 0) {
            $songsDue = mlCreateUtcDate((string)($round['SongsDue'] ?? ''));
            if ($songsDue instanceof DateTimeImmutable) {
                $window = mlPushResolveReminderWindow($songsDue, $now);
                if ($window !== null) {
                    $tasks[] = [
                        'type' => 'song',
                        'due_at' => $songsDue,
                        'window' => $window,
                        'title' => 'Song deadline approaching',
                        'body' => $roundLabel . ' is due in ' . $window['label'] . '. Choose your song.',
                        'url' => mlUrl('song.php?season_round_id=' . $seasonRoundId),
                    ];
                }
            }
        }

        if ((int)$round['HasPlaylist'] === 1) {
            $votesDue = mlCreateUtcDate((string)($round['VotesDue'] ?? ''));
            if ($votesDue instanceof DateTimeImmutable) {
                $window = mlPushResolveReminderWindow($votesDue, $now);
                if ($window !== null) {
                    $tasks[] = [
                        'type' => 'vote',
                        'due_at' => $votesDue,
                        'window' => $window,
                        'title' => 'Voting deadline approaching',
                        'body' => $roundLabel . ' closes in ' . $window['label'] . '. Finish your votes.',
                        'url' => mlUrl('vote.php?season_round_id=' . $seasonRoundId),
                    ];
                }
            }
        }

        foreach ($tasks as $task) {
            $deadlineScope = substr(hash('sha256', $task['due_at']->format('Y-m-d H:i:s')), 0, 12);
            $reminderKey = $task['type'] . '_' . $task['window']['key'] . '_' . $deadlineScope;
            $subscriptions = mlPushLoadIncompleteSubscriptions($pdo, $seasonRoundId, $task['type']);

            foreach ($subscriptions as $subscriptionRow) {
                $subscriptionId = (int)$subscriptionRow['PushSubscriptionID'];
                if (mlPushReminderWasSent($pdo, $subscriptionId, $seasonRoundId, $reminderKey)) {
                    continue;
                }

                $candidateCount++;
                if ($dryRun) {
                    continue;
                }

                $result = mlPushSendNotification($client, $subscriptionRow, [
                    'title' => $task['title'],
                    'body' => $task['body'],
                    'url' => $task['url'],
                    'tag' => 'musicball-' . $reminderKey . '-' . $seasonRoundId,
                ]);

                mlPushRecordDeliveryAttempt(
                    $pdo,
                    $subscriptionId,
                    (int)$subscriptionRow['UserID'],
                    $seasonRoundId,
                    $reminderKey,
                    $result
                );

                if (!empty($result['success'])) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }

                if (!empty($result['expired'])) {
                    mlPushDisableSubscriptionById($pdo, $subscriptionId);
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
