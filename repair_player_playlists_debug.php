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
    $targetRoundPlaylistId = isset($_GET['round_playlist_id']) ? (int)$_GET['round_playlist_id'] : 77;

    echo "Target RoundPlaylistID: {$targetRoundPlaylistId}\n";
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

    $previousStmt = $pdo->prepare("
        SELECT MAX(rp.RoundPlaylistID)
        FROM ML_RoundPlaylists rp
        INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rp.SeasonRoundID
        WHERE rp.RoundPlaylistID < ?
          AND (
              sr.RoundState = 'closed'
              OR (sr.VotesDue IS NOT NULL AND sr.VotesDue < UTC_TIMESTAMP())
          )
    ");
    $previousStmt->execute([$targetRoundPlaylistId]);
    $previousRoundPlaylistId = (int)$previousStmt->fetchColumn();

    echo "Previous eligible RoundPlaylistID: " . ($previousRoundPlaylistId > 0 ? $previousRoundPlaylistId : "NULL") . "\n\n";

    $aggregateStmt = $pdo->query("
        SELECT 
            ap.AggregatePlaylistID,
            ap.UserID,
            ap.SpotifyPlaylistID,
            ap.LastSourceRoundPlaylistID
        FROM ML_AggregatePlaylists ap
        WHERE ap.PlaylistType = 'player'
          AND ap.SpotifyPlaylistID IS NOT NULL
          AND ap.SpotifyPlaylistID <> ''
        ORDER BY ap.UserID ASC
    ");
    $playerRows = $aggregateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Anonymous player playlist rows checked: " . count($playerRows) . "\n\n";

    $itemsStmt = $pdo->prepare("
        SELECT 
            rpi.UserID,
            rpi.SpotifyURI,
            rs.TrackName,
            rs.ArtistName
        FROM ML_RoundPlaylistItems rpi
        INNER JOIN ML_RoundSongs rs ON rs.RoundSongID = rpi.RoundSongID
        WHERE rpi.RoundPlaylistID = ?
          AND rpi.SpotifyURI IS NOT NULL
          AND rpi.SpotifyURI <> ''
    ");
    $itemsStmt->execute([$targetRoundPlaylistId]);
    $roundItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $itemsByUserId = [];
    foreach ($roundItems as $item) {
        $itemsByUserId[(int)$item['UserID']] = $item;
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

        if (!isset($itemsByUserId[$userId])) {
            $missingCount++;
            continue;
        }

        $targetSong = $itemsByUserId[$userId];
        $targetUri = trim((string)$targetSong['SpotifyURI']);

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