<?php
// playlist_scheduler.php
// Run from Windows Task Scheduler every 30 minutes.
// Auto-generates a playlist only when SongsDue has passed AND every player has submitted.

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

    if (!mlSpotifyAppConfigured()) {
        throw new RuntimeException('Spotify is not configured.');
    }

    if (!mlSpotifyIsConnected($pdo)) {
        throw new RuntimeException('Spotify is not connected.');
    }

    $expectedPlayers = mlGetExpectedPlayerCount($pdo);
    if ($expectedPlayers <= 0) {
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
         GROUP BY sr.SeasonRoundID, sr.SeasonID, sr.RoundNumber, sr.Title, sr.Tagline, sr.SongsDue, sr.VotesDue, s.SeasonName
         ORDER BY sr.SongsDue ASC, sr.SeasonRoundID ASC"
    );
    $stmt->execute([$nowSql]);
    $candidateRounds = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($candidateRounds)) {
        mlSchedulerLog('No rounds are due for scheduler review.');
        exit(0);
    }

    $generatedCount = 0;
    foreach ($candidateRounds as $round) {
        $seasonRoundId = (int)$round['SeasonRoundID'];
        $songSubmissionCount = (int)$round['SongSubmissionCount'];
        $title = trim((string)($round['Title'] ?? 'Round ' . (int)$round['RoundNumber']));

        if ($songSubmissionCount < $expectedPlayers) {
            mlSchedulerLog('Skipped ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): only ' . $songSubmissionCount . ' of ' . $expectedPlayers . ' songs submitted.');
            continue;
        }

        $existingPlaylist = mlGetRoundPlaylistRecord($pdo, $seasonRoundId);
        if (!empty($existingPlaylist)) {
            mlSchedulerLog('Skipped ' . $title . ' (SeasonRoundID ' . $seasonRoundId . '): playlist already exists.');
            continue;
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

    mlSchedulerLog('Scheduler complete. Playlists generated: ' . $generatedCount . '.');
    exit(0);
} catch (Throwable $e) {
    mlSchedulerLog('Scheduler failed: ' . $e->getMessage());
    exit(1);
}
