<?php
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: index.php');
    exit;
}

$confirm = trim((string)($_GET['confirm'] ?? ''));
$liveRun = ($confirm === 'REMOVE');

header('Content-Type: text/plain; charset=utf-8');

if (!mlSpotifyAppConfigured() || !mlSpotifyIsConnected($pdo)) {
    echo "Spotify is not connected.\n";
    exit;
}

$latestStmt = $pdo->query("
    SELECT MAX(LastSourceRoundPlaylistID)
    FROM ML_AggregatePlaylists
    WHERE PlaylistType = 'player'
      AND LastSourceRoundPlaylistID IS NOT NULL
");
$badRoundPlaylistId = (int)($latestStmt ? $latestStmt->fetchColumn() : 0);

if ($badRoundPlaylistId <= 0) {
    echo "No player playlist checkpoint found.\n";
    exit;
}

$roundStmt = $pdo->prepare("
    SELECT rp.RoundPlaylistID, rp.SeasonRoundID, sr.SeasonID, sr.RoundNumber, sr.Title, sr.VotesDue, sr.RoundState
    FROM ML_RoundPlaylists rp
    INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rp.SeasonRoundID
    WHERE rp.RoundPlaylistID = ?
    LIMIT 1
");
$roundStmt->execute([$badRoundPlaylistId]);
$badRound = $roundStmt->fetch(PDO::FETCH_ASSOC);

if (!$badRound) {
    echo "Could not find RoundPlaylistID {$badRoundPlaylistId}.\n";
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
$previousStmt->execute([$badRoundPlaylistId]);
$previousRoundPlaylistId = (int)$previousStmt->fetchColumn();

echo $liveRun ? "LIVE REPAIR\n" : "DRY RUN ONLY\n";
echo "Target RoundPlaylistID: {$badRoundPlaylistId}\n";
echo "Previous eligible RoundPlaylistID: " . ($previousRoundPlaylistId > 0 ? $previousRoundPlaylistId : 'NULL') . "\n\n";

$playerStmt = $pdo->prepare("
    SELECT ap.AggregatePlaylistID, ap.UserID, ap.PlaylistName, ap.SpotifyPlaylistID, u.UserName
    FROM ML_AggregatePlaylists ap
    INNER JOIN ML_Users u ON u.UserID = ap.UserID
    WHERE ap.PlaylistType = 'player'
      AND ap.LastSourceRoundPlaylistID = ?
      AND ap.SpotifyPlaylistID IS NOT NULL
      AND ap.SpotifyPlaylistID <> ''
    ORDER BY ap.UserID ASC
");
$playerStmt->execute([$badRoundPlaylistId]);
$players = $playerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (empty($players)) {
    echo "No player playlists currently point at this RoundPlaylistID.\n";
    exit;
}

$songStmt = $pdo->prepare("
    SELECT SpotifyURI, TrackName, ArtistName
    FROM ML_RoundPlaylistItems
    WHERE RoundPlaylistID = ?
      AND UserID = ?
    ORDER BY PlaylistPosition DESC, RoundSongID DESC
    LIMIT 1
");

$updateStmt = $pdo->prepare("
    UPDATE ML_AggregatePlaylists
    SET LastSourceRoundPlaylistID = ?, UpdatedAt = UTC_TIMESTAMP()
    WHERE AggregatePlaylistID = ?
");

foreach ($players as $player) {
    $aggregatePlaylistId = (int)$player['AggregatePlaylistID'];
    $userId = (int)$player['UserID'];
    $playlistId = trim((string)$player['SpotifyPlaylistID']);
    $userName = trim((string)$player['UserName']);

    $songStmt->execute([$badRoundPlaylistId, $userId]);
    $song = $songStmt->fetch(PDO::FETCH_ASSOC);

    if (!$song) {
        echo "{$userName}: no matching song found for this round playlist.\n";
        continue;
    }

    $spotifyUri = trim((string)$song['SpotifyURI']);
    $label = trim((string)$song['TrackName']) . ' - ' . trim((string)$song['ArtistName']);

    if ($spotifyUri === '') {
        echo "{$userName}: missing Spotify URI for {$label}.\n";
        continue;
    }

    $items = mlSpotifyGetPlaylistItems($pdo, $playlistId);
    $removePosition = -1;

    for ($i = count($items) - 1; $i >= 0; $i--) {
        $itemUri = trim((string)($items[$i]['track']['uri'] ?? ''));
        if ($itemUri === $spotifyUri) {
            $removePosition = $i;
            break;
        }
    }

    if ($removePosition < 0) {
        echo "{$userName}: {$label} was not found in Spotify playlist. DB checkpoint would be rewound.\n";

        if ($liveRun) {
            $updateStmt->execute([
                $previousRoundPlaylistId > 0 ? $previousRoundPlaylistId : null,
                $aggregatePlaylistId,
            ]);
        }

        continue;
    }

    echo "{$userName}: remove {$label} at Spotify position {$removePosition}; rewind checkpoint.\n";

    if ($liveRun) {
        mlSpotifyRemovePlaylistItemAtPosition($pdo, $playlistId, $spotifyUri, $removePosition);

        $updateStmt->execute([
            $previousRoundPlaylistId > 0 ? $previousRoundPlaylistId : null,
            $aggregatePlaylistId,
        ]);
    }
}

echo "\nDone.\n";

if (!$liveRun) {
    echo "To actually run it, open: repair_player_playlists_remove_latest.php?confirm=REMOVE\n";
}