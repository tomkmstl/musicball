<?php
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

function repairGetSpotifyPlaylistItems(PDO $pdo, string $playlistId): array
{
    if (function_exists('mlSpotifyGetPlaylistItems')) {
        return mlSpotifyGetPlaylistItems($pdo, $playlistId);
    }

    $items = [];
    $offset = 0;
    $limit = 100;

    do {
        $response = mlSpotifyApiRequest($pdo, 'GET', '/playlists/' . rawurlencode($playlistId) . '/items', [
            'limit' => $limit,
            'offset' => $offset,
            'fields' => 'total,items(track(uri,name,artists(name)))',
        ], []);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new RuntimeException($response['body']['error']['message'] ?? 'Spotify playlist item lookup failed.');
        }

        $batch = $response['body']['items'] ?? [];
        foreach ($batch as $item) {
            $items[] = $item;
        }

        $total = (int)($response['body']['total'] ?? count($items));
        $offset += $limit;
    } while ($offset < $total);

    return $items;
}

function repairRemoveSpotifyPlaylistItemAtPosition(PDO $pdo, string $playlistId, string $spotifyUri, int $position): void
{
    if (function_exists('mlSpotifyRemovePlaylistItemAtPosition')) {
        mlSpotifyRemovePlaylistItemAtPosition($pdo, $playlistId, $spotifyUri, $position);
        return;
    }

    $response = mlSpotifyApiRequest($pdo, 'DELETE', '/playlists/' . rawurlencode($playlistId) . '/tracks', [], [
        'tracks' => [
            [
                'uri' => $spotifyUri,
                'positions' => [$position],
            ],
        ],
    ]);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        throw new RuntimeException($response['body']['error']['message'] ?? 'Spotify playlist item removal failed.');
    }
}

echo "ANON ROUND 77 PLAYER PLAYLIST REPAIR\n";
echo "====================================\n\n";

try {
    $targetRoundPlaylistId = isset($_GET['round_playlist_id']) ? (int)$_GET['round_playlist_id'] : 77;
    $liveRun = (trim((string)($_GET['confirm'] ?? '')) === 'REMOVE');

    echo $liveRun ? "MODE: LIVE REMOVE\n" : "MODE: DRY RUN\n";
    echo "Target RoundPlaylistID: {$targetRoundPlaylistId}\n\n";

    if (!mlSpotifyAppConfigured() || !mlSpotifyIsConnected($pdo)) {
        echo "Spotify is not connected/configured.\n";
        exit;
    }

    $roundStmt = $pdo->prepare("
        SELECT RoundPlaylistID, SeasonRoundID
        FROM ML_RoundPlaylists
        WHERE RoundPlaylistID = ?
        LIMIT 1
    ");
    $roundStmt->execute([$targetRoundPlaylistId]);
    $round = $roundStmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        echo "No round playlist found.\n";
        exit;
    }

    $seasonRoundId = (int)$round['SeasonRoundID'];
    echo "Resolved SeasonRoundID: {$seasonRoundId}\n\n";

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

    $targetStmt = $pdo->prepare("
        SELECT 
            rpi.SpotifyURI,
            rs.TrackName,
            rs.ArtistName
        FROM ML_RoundPlaylistItems rpi
        LEFT JOIN ML_RoundSongs rs ON rs.RoundSongID = rpi.RoundSongID
        WHERE rpi.RoundPlaylistID = ?
          AND rpi.SpotifyURI IS NOT NULL
          AND rpi.SpotifyURI <> ''
        ORDER BY rpi.PlaylistPosition ASC
    ");
    $targetStmt->execute([$targetRoundPlaylistId]);
    $targetSongs = $targetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $targetUris = [];
    foreach ($targetSongs as $song) {
        $targetUris[trim((string)$song['SpotifyURI'])] = true;
    }

    echo "TARGET ROUND TRACKS\n";
    echo "-------------------\n";
    foreach ($targetSongs as $song) {
        echo "- " . trim((string)$song['TrackName']);
        if (trim((string)$song['ArtistName']) !== '') {
            echo " — " . trim((string)$song['ArtistName']);
        }
        echo "\n";
    }
    echo "\n";

    $playerStmt = $pdo->query("
        SELECT AggregatePlaylistID, SpotifyPlaylistID
        FROM ML_AggregatePlaylists
        WHERE PlaylistType = 'player'
          AND SpotifyPlaylistID IS NOT NULL
          AND SpotifyPlaylistID <> ''
        ORDER BY AggregatePlaylistID ASC
    ");
    $players = $playerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $updateStmt = $pdo->prepare("
        UPDATE ML_AggregatePlaylists
        SET LastSourceRoundPlaylistID = ?, UpdatedAt = UTC_TIMESTAMP()
        WHERE AggregatePlaylistID = ?
    ");

    $checked = 0;
    $matched = 0;
    $removed = 0;
    $notMatched = 0;
    $errors = 0;
    $wouldRemove = [];
    $lastTracksSeen = [];

    foreach ($players as $player) {
        $checked++;
        $playlistId = trim((string)$player['SpotifyPlaylistID']);
        $aggregatePlaylistId = (int)$player['AggregatePlaylistID'];

        try {
            $items = repairGetSpotifyPlaylistItems($pdo, $playlistId);

            if (empty($items)) {
                $notMatched++;
                continue;
            }

            $lastPosition = count($items) - 1;
            $lastItem = $items[$lastPosition];
            $lastUri = trim((string)($lastItem['track']['uri'] ?? ''));
            $lastTrack = trim((string)($lastItem['track']['name'] ?? ''));

            $artistNames = [];
            $artists = $lastItem['track']['artists'] ?? [];
            if (is_array($artists)) {
                foreach ($artists as $artist) {
                    $artistName = trim((string)($artist['name'] ?? ''));
                    if ($artistName !== '') {
                        $artistNames[] = $artistName;
                    }
                }
            }

            $label = $lastTrack !== '' ? $lastTrack : '[unknown track]';
            if (!empty($artistNames)) {
                $label .= ' — ' . implode(', ', $artistNames);
            }

            $lastTracksSeen[] = $label;

            if ($lastUri === '' || !isset($targetUris[$lastUri])) {
                $notMatched++;
                continue;
            }

            $matched++;
            $wouldRemove[] = $label;

            if ($liveRun) {
                repairRemoveSpotifyPlaylistItemAtPosition($pdo, $playlistId, $lastUri, $lastPosition);

                $updateStmt->execute([
                    $previousRoundPlaylistId > 0 ? $previousRoundPlaylistId : null,
                    $aggregatePlaylistId,
                ]);

                $removed++;
            }
        } catch (Throwable $e) {
            $errors++;
        }
    }

    echo "LAST TRACK CURRENTLY SEEN IN PLAYER PLAYLISTS\n";
    echo "---------------------------------------------\n";
    foreach ($lastTracksSeen as $trackLabel) {
        echo "- {$trackLabel}\n";
    }
    echo "\n";

    echo "TRACKS " . ($liveRun ? "REMOVED" : "THAT WOULD BE REMOVED") . "\n";
    echo "---------------------------------------------\n";
    if (empty($wouldRemove)) {
        echo "No last tracks matched the target round URIs.\n";
    } else {
        foreach ($wouldRemove as $trackLabel) {
            echo "- {$trackLabel}\n";
        }
    }

    echo "\nSUMMARY\n";
    echo "-------\n";
    echo "Anonymous player playlists checked: {$checked}\n";
    echo "Last tracks matching target round: {$matched}\n";
    echo "Last tracks not matching target round: {$notMatched}\n";
    echo "Spotify/API errors: {$errors}\n";
    if ($liveRun) {
        echo "Tracks removed: {$removed}\n";
        echo "DB checkpoints rewound to: " . ($previousRoundPlaylistId > 0 ? $previousRoundPlaylistId : "NULL") . "\n";
    } else {
        echo "No changes were made.\n";
        echo "\nTo execute after reviewing the track list, run:\n";
        echo "repair_player_playlists_round77.php?round_playlist_id={$targetRoundPlaylistId}&confirm=REMOVE\n";
    }
} catch (Throwable $e) {
    echo "\nFATAL ERROR\n";
    echo "-----------\n";
    echo $e->getMessage() . "\n";
}