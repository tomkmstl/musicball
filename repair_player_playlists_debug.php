<?php
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "ANON PLAYER PLAYLIST REPAIR DEBUG\n";
echo "=================================\n\n";

try {
    $targetSeasonRoundId = isset($_GET['season_round_id']) ? (int)$_GET['season_round_id'] : 77;

    echo "Target SeasonRoundID: {$targetSeasonRoundId}\n";
    echo "Spotify app configured: " . (mlSpotifyAppConfigured() ? "YES" : "NO") . "\n";
    echo "Spotify connected: " . (mlSpotifyIsConnected($pdo) ? "YES" : "NO") . "\n\n";

    if (!mlSpotifyAppConfigured() || !mlSpotifyIsConnected($pdo)) {
        echo "Spotify is not ready. Stopping.\n";
        exit;
    }

    if (!function_exists('mlSpotifyGetPlaylistItems')) {
        echo "Missing helper: mlSpotifyGetPlaylistItems() is not available in integrations/spotify/client.php.\n";
        exit;
    }

    $aggregateStmt = $pdo->query("
        SELECT 
            AggregatePlaylistID,
            UserID,
            SpotifyPlaylistID,
            LastSourceRoundPlaylistID
        FROM ML_AggregatePlaylists
        WHERE PlaylistType = 'player'
          AND SpotifyPlaylistID IS NOT NULL
          AND SpotifyPlaylistID <> ''
        ORDER BY UserID ASC
    ");
    $playerRows = $aggregateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Anonymous player playlist rows checked: " . count($playerRows) . "\n\n";

    $songsStmt = $pdo->prepare("
        SELECT 
            UserID,
            SpotifyURI,
            TrackName,
            ArtistName
        FROM ML_RoundSongs
        WHERE SeasonRoundID = ?
          AND SpotifyURI IS NOT NULL
          AND SpotifyURI <> ''
    ");
    $songsStmt->execute([$targetSeasonRoundId]);
    $roundSongs = $songsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $songsByUserId = [];
    foreach ($roundSongs as $song) {
        $songsByUserId[(int)$song['UserID']] = $song;
    }

    $wouldRemove = [];
    $checkedCount = 0;
    $foundCount = 0;
    $missingCount = 0;
    $errorCount = 0;

    foreach ($playerRows as $playerRow) {
        $checkedCount++;

        $userId = (int)$playerRow['UserID'];
        $playlistId = trim((string)$playerRow['SpotifyPlaylistID']);

        if (!isset($songsByUserId[$userId])) {
            $missingCount++;
            continue;
        }

        $targetSong = $songsByUserId[$userId];
        $targetUri = trim((string)$targetSong['SpotifyURI']);

        if ($targetUri === '') {
            $missingCount++;
            continue;
        }

        try {
            $playlistItems = mlSpotifyGetPlaylistItems($pdo, $playlistId);

            $positions = [];
            foreach ($playlistItems as $index => $playlistItem) {
                $uri = trim((string)($playlistItem['track']['uri'] ?? ''));
                if ($uri !== '' && $uri === $targetUri) {
                    $positions[] = $index;
                }
            }

            if (empty($positions)) {
                $missingCount++;
                continue;
            }

            $foundCount++;

            $trackName = trim((string)($targetSong['TrackName'] ?? ''));
            $artistName = trim((string)($targetSong['ArtistName'] ?? ''));

            $wouldRemove[] = [
                'track' => $trackName,
                'artist' => $artistName,
                'position' => end($positions),
            ];
        } catch (Throwable $e) {
            $errorCount++;
            continue;
        }
    }

    echo "SUMMARY\n";
    echo "-------\n";
    echo "Anonymous player playlists checked: {$checkedCount}\n";
    echo "Submitted songs for target round: " . count($roundSongs) . "\n";
    echo "Songs found in Spotify player playlists: {$foundCount}\n";
    echo "Songs not found / no matching target song: {$missingCount}\n";
    echo "Spotify lookup errors: {$errorCount}\n\n";

    echo "TRACKS THAT WOULD BE REMOVED\n";
    echo "----------------------------\n";

    if (empty($wouldRemove)) {
        echo "No matching tracks found to remove.\n";
    } else {
        foreach ($wouldRemove as $row) {
            echo "- " . $row['track'];
            if ($row['artist'] !== '') {
                echo " — " . $row['artist'];
            }
            echo "\n";
        }
    }

    echo "\nNo changes were made by this debug file.\n";
} catch (Throwable $e) {
    echo "\nFATAL DEBUG ERROR\n";
    echo "-----------------\n";
    echo $e->getMessage() . "\n";
}