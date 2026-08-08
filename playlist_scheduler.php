<?php
// playlist_scheduler.php
// Run every 15 minutes from the server scheduler.
// At SongsDue, builds from received songs in "due" mode or waits for everyone in "wait" mode.
// Voting closes at VotesDue in "due" mode or waits for everyone in "wait" mode.
// Wait mode falls back 12 hours before the next phase deadline and changes the league to due mode.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script is for CLI use only.';
    exit;
}

if (!isset($_SERVER['DOCUMENT_ROOT']) || trim((string)$_SERVER['DOCUMENT_ROOT']) === '') {
    $_SERVER['DOCUMENT_ROOT'] = __DIR__;
}

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';
require_once __DIR__ . '/integrations/push/push.php';

function mlSchedulerLog(string $message): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $message . PHP_EOL;

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    @file_put_contents($logDir . '/playlist_scheduler.log', $line, FILE_APPEND);
    echo $line;
}

function mlSchedulerSendAdminFallbackPush(
    PDO $pdo,
    array $round,
    string $notificationType,
    string $reminderKey,
    string $tagScope
): array
{
    $result = [
        'available' => false,
        'eligible' => 0,
        'sent' => 0,
        'failed' => 0,
        'expired' => 0,
    ];

    if (!mlUsersHasIsAdminColumn($pdo) || !mlPushServerReady($pdo)) {
        return $result;
    }

    $subscriptions = mlPushLoadActiveAdminSubscriptions($pdo);
    $result['available'] = true;
    $result['eligible'] = count($subscriptions);

    if (empty($subscriptions)) {
        return $result;
    }

    $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
    $copy = mlPushBuildNotificationCopy(
        $notificationType,
        (int)($round['RoundNumber'] ?? 0),
        trim((string)($round['Title'] ?? ''))
    );
    $client = mlPushCreateWebPushClient();

    foreach ($subscriptions as $subscription) {
        $subscriptionId = (int)$subscription['PushSubscriptionID'];
        if (mlPushReminderWasSent($pdo, $subscriptionId, $seasonRoundId, $reminderKey)) {
            continue;
        }

        $delivery = mlPushSendNotification($client, $subscription, [
            'title' => $copy['title'],
            'body' => $copy['body'],
            'url' => mlUrl('season.php?season_id=' . (int)($round['SeasonID'] ?? 0)),
            'tag' => 'musicball-' . $tagScope . '-' . $seasonRoundId,
        ]);

        mlPushRecordDeliveryAttempt(
            $pdo,
            $subscriptionId,
            (int)$subscription['UserID'],
            $seasonRoundId,
            $reminderKey,
            $delivery
        );

        if (!empty($delivery['success'])) {
            $result['sent']++;
        } else {
            $result['failed']++;
        }

        if (!empty($delivery['expired'])) {
            mlPushDisableSubscriptionById($pdo, $subscriptionId);
            $result['expired']++;
        }
    }

    return $result;
}

try {
    if (!mlTableExists($pdo, 'ML_SeasonRounds')) {
        throw new RuntimeException('ML_SeasonRounds does not exist.');
    }

    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        throw new RuntimeException('ML_RoundSongs does not exist.');
    }

    if (!mlTableExists($pdo, 'ML_RoundPlaylists')) {
        throw new RuntimeException('ML_RoundPlaylists does not exist.');
    }

    $playlistBuildMode = mlGetPlaylistBuildMode($pdo);
    $expectedPlayers = mlGetExpectedPlayerCount($pdo);
    if ($playlistBuildMode === 'wait' && $expectedPlayers <= 0) {
        throw new RuntimeException('Expected player count is 0.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $nowSql = $now->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "SELECT sr.SeasonRoundID,
                sr.SeasonID,
                sr.RoundNumber,
                sr.Title,
                sr.Tagline,
                sr.SongsDue,
                sr.VotesDue,
                s.SeasonName,
                COUNT(rs.RoundSongID) AS SongSubmissionCount
         FROM ML_SeasonRounds sr
         INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
         LEFT JOIN ML_RoundSongs rs ON rs.SeasonRoundID = sr.SeasonRoundID
         LEFT JOIN ML_RoundPlaylists rp ON rp.SeasonRoundID = sr.SeasonRoundID
         WHERE rp.SeasonRoundID IS NULL
           AND s.IsActive = 1
           AND sr.SongsDue IS NOT NULL
           AND sr.SongsDue <= ?
           AND (sr.VotesDue IS NULL OR sr.VotesDue > ?)
         GROUP BY sr.SeasonRoundID, sr.SeasonID, sr.RoundNumber, sr.Title, sr.Tagline, sr.SongsDue, sr.VotesDue, s.SeasonName
         ORDER BY sr.SongsDue ASC, sr.SeasonRoundID ASC"
    );
    $stmt->execute([$nowSql, $nowSql]);
    $candidateRounds = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $generatedCount = 0;
    if (empty($candidateRounds)) {
        mlSchedulerLog('No playlists are due for scheduler review.');
    }

    foreach ($candidateRounds as $round) {
        $seasonRoundId = (int)$round['SeasonRoundID'];
        $songSubmissionCount = (int)$round['SongSubmissionCount'];
        $title = trim((string)($round['Title'] ?? 'Round ' . (int)$round['RoundNumber']));

        $existingPlaylist = mlGetRoundPlaylistRecord($pdo, $seasonRoundId, true);
        if (!empty($existingPlaylist)) {
            mlSchedulerLog('Skipped ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): playlist already exists.');
            continue;
        }

        if ($songSubmissionCount <= 0) {
            mlSchedulerLog('Skipped ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): no songs have been submitted; the round will be skipped if none arrive before Votes Due.');
            continue;
        }

        if ($playlistBuildMode === 'wait' && $songSubmissionCount < $expectedPlayers) {
            $fallbackAt = mlGetPlaylistWaitFallbackAt($round);
            if (!$fallbackAt instanceof DateTimeImmutable) {
                mlSchedulerLog('Skipped ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): the 12-hour fallback could not be calculated from the round deadlines.');
                continue;
            }

            if ($now < $fallbackAt) {
                mlSchedulerLog(
                    'Skipped ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): only '
                    . $songSubmissionCount . ' of ' . $expectedPlayers
                    . ' songs submitted; partial-build fallback begins at '
                    . $fallbackAt->format('Y-m-d H:i:s') . ' UTC.'
                );
                continue;
            }

            mlSetSettingValue($pdo, 'playlist_build_mode', 'due');
            $playlistBuildMode = 'due';
            mlSchedulerLog(
                'Changed round timing to Build at Songs Due after ' . $title
                . ' reached its playlist fallback with ' . $songSubmissionCount
                . ' of ' . $expectedPlayers . ' songs submitted.'
            );

            try {
                $pushResult = mlSchedulerSendAdminFallbackPush(
                    $pdo,
                    $round,
                    'playlist_mode_fallback',
                    'playlist_mode_fallback_12h_v1',
                    'playlist-mode-fallback'
                );
                if (empty($pushResult['available'])) {
                    mlSchedulerLog('Admin push skipped for the round timing change: push is unavailable in this data mode.');
                } else {
                    mlSchedulerLog(
                        'Admin push for the round timing change: eligible ' . (int)$pushResult['eligible']
                        . '; sent ' . (int)$pushResult['sent']
                        . '; failed ' . (int)$pushResult['failed']
                        . '; expired subscriptions disabled ' . (int)$pushResult['expired'] . '.'
                    );
                }
            } catch (Throwable $pushError) {
                mlSchedulerLog('Admin push failed for the round timing change: ' . mlPushSanitizeDeliveryError($pushError->getMessage()));
            }
        }

        try {
            $createdPlaylist = mlGeneratePlaylistForRound($pdo, $round, false);
            $playlistUrl = trim((string)($createdPlaylist['SpotifyPlaylistURL'] ?? ''));
            $generatedCount++;
            mlSchedulerLog('Generated playlist for ' . $title . ' (SeasonRoundID ' . $seasonRoundId . ')' . ($playlistUrl !== '' ? ': ' . $playlistUrl : '.'));
        } catch (Throwable $roundError) {
            mlSchedulerLog('Failed generating playlist for ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): ' . $roundError->getMessage());
        }
    }

    $finalizedCount = 0;
    $skippedVotingCount = 0;
    $votingFallbackCount = 0;

    if (!mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
        mlSchedulerLog('Voting finalization skipped: ML_RoundVoteSubmissions does not exist.');
    } else {
        $hasRoundState = mlSeasonRoundsHasStateColumns($pdo);
        $roundStateSelect = $hasRoundState ? ', sr.RoundState' : ', NULL AS RoundState';
        $roundStateGroup = $hasRoundState ? ', sr.RoundState' : '';
        $roundStateWhere = $hasRoundState ? "AND (sr.RoundState IS NULL OR sr.RoundState <> 'closed')" : '';
        $votingStmt = $pdo->prepare(
            "SELECT sr.SeasonRoundID,
                    sr.SeasonID,
                    sr.RoundNumber,
                    sr.Title,
                    sr.VotesDue,
                    next_sr.SongsDue AS NextSongsDue,
                    COUNT(DISTINCT rvs.UserID) AS VoteSubmissionCount
                    {$roundStateSelect}
             FROM ML_SeasonRounds sr
             INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
             INNER JOIN ML_RoundPlaylists rp ON rp.SeasonRoundID = sr.SeasonRoundID
             LEFT JOIN ML_RoundVoteSubmissions rvs ON rvs.SeasonRoundID = sr.SeasonRoundID
             LEFT JOIN ML_SeasonRounds next_sr
                    ON next_sr.SeasonID = sr.SeasonID
                   AND next_sr.RoundNumber = sr.RoundNumber + 1
             WHERE s.IsActive = 1
               AND sr.VotesDue IS NOT NULL
               AND sr.VotesDue < ?
               {$roundStateWhere}
             GROUP BY sr.SeasonRoundID, sr.SeasonID, sr.RoundNumber, sr.Title, sr.VotesDue, next_sr.SongsDue{$roundStateGroup}
             ORDER BY sr.VotesDue ASC, sr.SeasonRoundID ASC"
        );
        $votingStmt->execute([$nowSql]);
        $votingRounds = $votingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($votingRounds)) {
            mlSchedulerLog('No voting rounds are due for scheduler review.');
        }

        foreach ($votingRounds as $round) {
            $seasonRoundId = (int)$round['SeasonRoundID'];
            $voteSubmissionCount = (int)$round['VoteSubmissionCount'];
            $title = trim((string)($round['Title'] ?? 'Round ' . (int)$round['RoundNumber']));

            if ($playlistBuildMode !== 'wait' || mlRoundHasAllExpectedVotes($voteSubmissionCount, $expectedPlayers)) {
                if (mlMarkRoundClosed($pdo, $seasonRoundId)) {
                    $finalizedCount++;
                }
                continue;
            }

            $nextRound = ['SongsDue' => (string)($round['NextSongsDue'] ?? '')];
            $fallbackAt = mlGetVotingWaitFallbackAt($round, $nextRound);
            $nextSongsDue = mlCreateUtcDate($nextRound['SongsDue']);
            if (!$fallbackAt instanceof DateTimeImmutable || !$nextSongsDue instanceof DateTimeImmutable) {
                mlSchedulerLog(
                    'Waiting to finalize ' . $title . ' (SeasonRoundID ' . $seasonRoundId
                    . '): no following Songs Due deadline is available for an automatic fallback.'
                );
                continue;
            }

            if ($now >= $nextSongsDue) {
                if (mlMarkRoundClosed($pdo, $seasonRoundId)) {
                    if ($voteSubmissionCount <= 0) {
                        $skippedVotingCount++;
                        mlSchedulerLog(
                            'Skipped voting results for ' . $title . ' (SeasonRoundID ' . $seasonRoundId
                            . '): no votes were submitted before the following Songs Due deadline.'
                        );
                    } else {
                        $finalizedCount++;
                        mlSchedulerLog(
                            'Finalized overdue voting results for ' . $title . ' (SeasonRoundID ' . $seasonRoundId
                            . ') after the following Songs Due deadline had already arrived.'
                        );
                    }
                } else {
                    mlSchedulerLog(
                        'Could not close overdue voting for ' . $title . ' (SeasonRoundID ' . $seasonRoundId
                        . '): round finalization storage is unavailable.'
                    );
                }
                continue;
            }

            if ($now < $fallbackAt) {
                mlSchedulerLog(
                    'Waiting to finalize ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): '
                    . $voteSubmissionCount . ' of ' . $expectedPlayers
                    . ' players voted; partial-results fallback begins at '
                    . $fallbackAt->format('Y-m-d H:i:s') . ' UTC.'
                );
                continue;
            }

            if ($voteSubmissionCount <= 0) {
                mlSchedulerLog(
                    'Waiting to finalize ' . $title . ' (SeasonRoundID ' . $seasonRoundId
                    . '): no votes have been submitted; the round will be skipped if none arrive before the following Songs Due deadline.'
                );
                continue;
            }

            $pdo->beginTransaction();
            try {
                mlSetSettingValue($pdo, 'playlist_build_mode', 'due');
                if ($hasRoundState && !mlMarkRoundClosed($pdo, $seasonRoundId)) {
                    throw new RuntimeException('The round could not be marked closed.');
                }
                $pdo->commit();
            } catch (Throwable $transitionError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $transitionError;
            }

            $playlistBuildMode = 'due';
            $finalizedCount++;
            $votingFallbackCount++;
            mlSchedulerLog(
                'Changed round timing to Build at Songs Due after ' . $title
                . ' reached its voting fallback with ' . $voteSubmissionCount
                . ' of ' . $expectedPlayers . ' players voted.'
            );

            try {
                $pushResult = mlSchedulerSendAdminFallbackPush(
                    $pdo,
                    $round,
                    'voting_mode_fallback',
                    'voting_mode_fallback_12h_v1',
                    'voting-mode-fallback'
                );
                if (empty($pushResult['available'])) {
                    mlSchedulerLog('Admin push skipped for the voting timing change: push is unavailable in this data mode.');
                } else {
                    mlSchedulerLog(
                        'Admin push for the voting timing change: eligible ' . (int)$pushResult['eligible']
                        . '; sent ' . (int)$pushResult['sent']
                        . '; failed ' . (int)$pushResult['failed']
                        . '; expired subscriptions disabled ' . (int)$pushResult['expired'] . '.'
                    );
                }
            } catch (Throwable $pushError) {
                mlSchedulerLog('Admin push failed for the voting timing change: ' . mlPushSanitizeDeliveryError($pushError->getMessage()));
            }
        }
    }

    mlSchedulerLog(
        'Scheduler complete. Playlists generated: ' . $generatedCount
        . '; voting rounds finalized: ' . $finalizedCount
        . '; voting rounds skipped: ' . $skippedVotingCount
        . '; voting fallbacks: ' . $votingFallbackCount . '.'
    );
    exit(0);
} catch (Throwable $e) {
    mlSchedulerLog('Scheduler failed: ' . $e->getMessage());
    exit(1);
}
