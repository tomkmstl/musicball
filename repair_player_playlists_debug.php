<?php
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "PLAYER PLAYLIST REPAIR DEBUG\n";
echo "============================\n\n";

try {
    echo "Spotify app configured: " . (mlSpotifyAppConfigured() ? "YES" : "NO") . "\n";
    echo "Spotify connected: " . (mlSpotifyIsConnected($pdo) ? "YES" : "NO") . "\n\n";

    $targetRoundPlaylistId = isset($_GET['round_playlist_id'])
        ? (int)$_GET['round_playlist_id']
        : 77;

    echo "Target RoundPlaylistID: {$targetRoundPlaylistId}\n\n";

    $roundStmt = $pdo->prepare("
        SELECT 
            rp.RoundPlaylistID,
            rp.SeasonRoundID,
            rp.SpotifyPlaylistID,
            rp.PlaylistName,
            sr.SeasonID,
            sr.RoundNumber,
            sr.Title,
            sr.RoundState,
            sr.SubmissionsDue,
            sr.VotesDue
        FROM ML_RoundPlaylists rp
        INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rp.SeasonRoundID
        WHERE rp.RoundPlaylistID = ?
        LIMIT 1
    ");
    $roundStmt->execute([$targetRoundPlaylistId]);
    $round = $roundStmt->fetch(PDO::FETCH_ASSOC);

    echo "ROUND PLAYLIST ROW\n";
    echo "------------------\n";

    if (!$round) {
        echo "No ML_RoundPlaylists row found for RoundPlaylistID {$targetRoundPlaylistId}.\n";
        exit;
    }

    print_r($round);
    echo "\n";

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

    echo "AGGREGATE PLAYER PLAYLIST ROWS\n";
    echo "------------------------------\n";

    $aggregateStmt = $pdo->query("
        SELECT 
            ap.AggregatePlaylistID,
            ap.PlaylistType,
            ap.UserID,
            u.UserName,
            ap.PlaylistName,
            ap.SpotifyPlaylistID,
            ap.LastSourceRoundPlaylistID,
            ap.CreatedAt,
            ap.UpdatedAt
        FROM ML_AggregatePlaylists ap
        LEFT JOIN ML_Users u ON u.UserID = ap.UserID
        WHERE ap.PlaylistType = 'player'
        ORDER BY ap.UserID ASC
    ");

    $aggregateRows = $aggregateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Total player aggregate rows: " . count($aggregateRows) . "\n\n";

    if (empty($aggregateRows)) {
        echo "No rows found where ML_AggregatePlaylists.PlaylistType = 'player'.\n";
        echo "This means the earlier repair script had no player rows to process.\n";
        exit;
    }

    foreach ($aggregateRows as $row) {
        echo "AggregatePlaylistID: " . $row['AggregatePlaylistID'] . "\n";
        echo "UserID: " . $row['UserID'] . "\n";
        echo "UserName: " . ($row['UserName'] ?? '') . "\n";
        echo "PlaylistName: " . ($row['PlaylistName'] ?? '') . "\n";
        echo "SpotifyPlaylistID: " . ($row['SpotifyPlaylistID'] ?? '') . "\n";
        echo "LastSourceRoundPlaylistID: " . ($row['LastSourceRoundPlaylistID'] ?? 'NULL') . "\n";
        echo "UpdatedAt: " . ($row['UpdatedAt'] ?? '') . "\n";
        echo "---\n";
    }

    echo "\nROWS CURRENTLY POINTING AT TARGET ROUND {$targetRoundPlaylistId}\n";
    echo "---------------------------------------------------------------\n";

    $pointingRows = array_values(array_filter($aggregateRows, function ($row) use ($targetRoundPlaylistId) {
        return (int)($row['LastSourceRoundPlaylistID'] ?? 0) === $targetRoundPlaylistId;
    }));

    echo "Count: " . count($pointingRows) . "\n\n";

    if (empty($pointingRows)) {
        echo "No player aggregate rows currently have LastSourceRoundPlaylistID = {$targetRoundPlaylistId}.\n";
        echo "That explains why the previous repair script did not print removal lines.\n";
        echo "Next step would be a direct repair that targets RoundPlaylistID {$targetRoundPlaylistId}, regardless of current checkpoint.\n\n";
    }

    echo "ROUND PLAYLIST ITEMS FOR TARGET ROUND\n";
    echo "-------------------------------------\n";

    $itemsStmt = $pdo->prepare("
        SELECT 
            rpi.RoundPlaylistItemID,
            rpi.RoundPlaylistID,
            rpi.UserID,
            u.UserName,
            rpi.PlaylistPosition,
            rpi.SpotifyURI,
            rpi.TrackName,
            rpi.ArtistName
        FROM ML_RoundPlaylistItems rpi
        LEFT JOIN ML_Users u ON u.UserID = rpi.UserID
        WHERE rpi.RoundPlaylistID = ?
        ORDER BY rpi.UserID ASC, rpi.PlaylistPosition ASC
    ");
    $itemsStmt->execute([$targetRoundPlaylistId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Total target round items: " . count($items) . "\n\n";

    foreach ($items as $item) {
        echo "UserID: " . $item['UserID'] . "\n";
        echo "UserName: " . ($item['UserName'] ?? '') . "\n";
        echo "Position: " . ($item['PlaylistPosition'] ?? '') . "\n";
        echo "Track: " . ($item['TrackName'] ?? '') . "\n";
        echo "Artist: " . ($item['ArtistName'] ?? '') . "\n";
        echo "SpotifyURI: " . ($item['SpotifyURI'] ?? '') . "\n";
        echo "---\n";
    }

    echo "\nPLAYER-BY-PLAYER MATCH CHECK\n";
    echo "----------------------------\n";

    foreach ($aggregateRows as $player) {
        $userId = (int)$player['UserID'];
        $userName = trim((string)($player['UserName'] ?? ''));
        $spotifyPlaylistId = trim((string)($player['SpotifyPlaylistID'] ?? ''));

        echo "Player: {$userName} / UserID {$userId}\n";
        echo "AggregatePlaylistID: " . $player['AggregatePlaylistID'] . "\n";
        echo "Current checkpoint: " . ($player['LastSourceRoundPlaylistID'] ?? 'NULL') . "\n";

        if ($spotifyPlaylistId === '') {
            echo "Problem: no SpotifyPlaylistID on aggregate playlist row.\n";
            echo "---\n";
            continue;
        }

        $matchingSong = null;
        foreach ($items as $item) {
            if ((int)$item['UserID'] === $userId) {
                $matchingSong = $item;
                break;
            }
        }

        if (!$matchingSong) {
            echo "Problem: no RoundPlaylistItem found for this user in RoundPlaylistID {$targetRoundPlaylistId}.\n";
            echo "---\n";
            continue;
        }

        echo "Target song: " . ($matchingSong['TrackName'] ?? '') . " - " . ($matchingSong['ArtistName'] ?? '') . "\n";
        echo "Target SpotifyURI: " . ($matchingSong['SpotifyURI'] ?? '') . "\n";

        if (!mlSpotifyAppConfigured() || !mlSpotifyIsConnected($pdo)) {
            echo "Skipped Spotify lookup because Spotify is not connected/configured.\n";
            echo "---\n";
            continue;
        }

        if (!function_exists('mlSpotifyGetPlaylistItems')) {
            echo "Problem: mlSpotifyGetPlaylistItems() does not exist in integrations/spotify/client.php.\n";
            echo "That means the Spotify helper added earlier is missing or not deployed.\n";
            echo "---\n";
            continue;
        }

        try {
            $playlistItems = mlSpotifyGetPlaylistItems($pdo, $spotifyPlaylistId);
            echo "Spotify playlist item count fetched: " . count($playlistItems) . "\n";

            $foundPositions = [];
            $targetUri = trim((string)($matchingSong['SpotifyURI'] ?? ''));

            foreach ($playlistItems as $index => $playlistItem) {
                $playlistUri = trim((string)($playlistItem['track']['uri'] ?? ''));
                if ($targetUri !== '' && $playlistUri === $targetUri) {
                    $foundPositions[] = $index;
                }
            }

            if (empty($foundPositions)) {
                echo "Spotify match: NOT FOUND in player playlist.\n";
            } else {
                echo "Spotify match positions: " . implode(', ', $foundPositions) . "\n";
                echo "Most likely removal position: " . end($foundPositions) . "\n";
            }
        } catch (Throwable $spotifyError) {
            echo "Spotify lookup error: " . $spotifyError->getMessage() . "\n";
        }

        echo "---\n";
    }

    echo "\nDEBUG COMPLETE\n";
} catch (Throwable $e) {
    echo "\nFATAL DEBUG ERROR\n";
    echo "-----------------\n";
    echo $e->getMessage() . "\n";
}