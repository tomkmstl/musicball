<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

function mbLeagueSongDatabaseUsageRow(array $row): array {
    $playlistPosition = isset($row['PlaylistPosition']) && $row['PlaylistPosition'] !== null ? (int)$row['PlaylistPosition'] : null;
    $playerName = trim((string)($row['PlayerName'] ?? ''));

    return [
        'round_song_id' => (int)($row['RoundSongID'] ?? 0),
        'track_id' => (string)($row['SpotifyTrackID'] ?? ''),
        'track_uri' => (string)($row['SpotifyURI'] ?? ''),
        'title' => (string)($row['TrackName'] ?? ''),
        'artist' => (string)($row['ArtistName'] ?? ''),
        'album' => (string)($row['AlbumName'] ?? ''),
        'artwork' => (string)($row['ArtworkURL'] ?? ''),
        'player_id' => (int)($row['UserID'] ?? 0),
        'player' => $playerName !== '' ? $playerName : 'Unknown player',
        'season_id' => (int)($row['SeasonID'] ?? 0),
        'season' => (string)($row['SeasonName'] ?? ''),
        'round_number' => (int)($row['RoundNumber'] ?? 0),
        'round' => (string)($row['RoundTitle'] ?? ''),
        'playlist_order' => $playlistPosition,
    ];
}

try {
    mlRequireAuthenticatedUser($pdo);

    $query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($query === '') {
        echo json_encode([
            'ok' => true,
            'query' => '',
            'results' => [],
        ]);
        exit;
    }

    if (mb_strlen($query) < 2) {
        echo json_encode([
            'ok' => true,
            'query' => $query,
            'results' => [],
        ]);
        exit;
    }

    if (!mlTableExists($pdo, 'ML_RoundSongs') || !mlTableExists($pdo, 'ML_SeasonRounds') || !mlTableExists($pdo, 'ML_Seasons') || !mlTableExists($pdo, 'ML_Users')) {
        throw new RuntimeException('The league song database is not available yet.');
    }

    $hasPlaylistItems = mlTableExists($pdo, 'ML_RoundPlaylistItems');
    $like = '%' . $query . '%';
    $results = [];

    $playlistSelect = $hasPlaylistItems ? 'rpi.PlaylistPosition' : 'NULL AS PlaylistPosition';
    $playlistJoin = $hasPlaylistItems ? 'LEFT JOIN ML_RoundPlaylistItems rpi ON rpi.RoundSongID = rs.RoundSongID AND rpi.SeasonRoundID = rs.SeasonRoundID' : '';

    // Only include completed/past rounds. This intentionally excludes current submission/voting rounds
    // and future rounds, even if players have already chosen songs for them.
    $pastRoundWhere = mlSeasonRoundsHasStateColumns($pdo)
        ? "(sr.RoundState = 'closed' OR (sr.VotesDue IS NOT NULL AND sr.VotesDue < UTC_TIMESTAMP()))"
        : "(sr.VotesDue IS NOT NULL AND sr.VotesDue < UTC_TIMESTAMP())";

    $baseSelect = "
        SELECT rs.RoundSongID, rs.SeasonRoundID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI,
               rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL,
               u.UserName AS PlayerName,
               sr.RoundNumber, sr.Title AS RoundTitle, s.SeasonID, s.SeasonName,
               $playlistSelect
        FROM ML_RoundSongs rs
        INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rs.SeasonRoundID
        INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
        INNER JOIN ML_Users u ON u.UserID = rs.UserID
        $playlistJoin
        WHERE $pastRoundWhere
    ";

    $songStmt = $pdo->prepare($baseSelect . "
          AND rs.TrackName LIKE ?
        ORDER BY rs.TrackName ASC, rs.ArtistName ASC, s.SeasonID ASC, sr.RoundNumber ASC, rs.RoundSongID ASC
        LIMIT 300
    ");
    $songStmt->execute([$like]);
    $songRows = $songStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $songGroups = [];
    foreach ($songRows as $row) {
        $trackId = trim((string)($row['SpotifyTrackID'] ?? ''));
        $groupKey = $trackId !== '' ? 'spotify:' . $trackId : 'text:' . mb_strtolower((string)$row['TrackName']) . '|' . mb_strtolower((string)$row['ArtistName']);
        if (!isset($songGroups[$groupKey])) {
            $songGroups[$groupKey] = [
                'type' => 'song',
                'key' => $groupKey,
                'title' => (string)$row['TrackName'],
                'artist' => (string)$row['ArtistName'],
                'album' => (string)($row['AlbumName'] ?? ''),
                'artwork' => (string)($row['ArtworkURL'] ?? ''),
                'usages' => [],
            ];
        }
        $songGroups[$groupKey]['usages'][] = mbLeagueSongDatabaseUsageRow($row);
    }

    foreach ($songGroups as $group) {
        $group['usage_count'] = count($group['usages']);
        $results[] = $group;
    }

    $artistStmt = $pdo->prepare($baseSelect . "
          AND rs.ArtistName LIKE ?
        ORDER BY rs.ArtistName ASC, rs.TrackName ASC, s.SeasonID ASC, sr.RoundNumber ASC, rs.RoundSongID ASC
        LIMIT 500
    ");
    $artistStmt->execute([$like]);
    $artistRows = $artistStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $artistGroups = [];
    foreach ($artistRows as $row) {
        $artistName = trim((string)($row['ArtistName'] ?? ''));
        if ($artistName === '') {
            continue;
        }
        $groupKey = 'artist:' . mb_strtolower($artistName);
        if (!isset($artistGroups[$groupKey])) {
            $artistGroups[$groupKey] = [
                'type' => 'artist',
                'key' => $groupKey,
                'title' => $artistName,
                'artist' => $artistName,
                'album' => '',
                'artwork' => (string)($row['ArtworkURL'] ?? ''),
                'usages' => [],
            ];
        }
        if ($artistGroups[$groupKey]['artwork'] === '' && trim((string)($row['ArtworkURL'] ?? '')) !== '') {
            $artistGroups[$groupKey]['artwork'] = (string)$row['ArtworkURL'];
        }
        $artistGroups[$groupKey]['usages'][] = mbLeagueSongDatabaseUsageRow($row);
    }

    foreach ($artistGroups as $group) {
        $group['usage_count'] = count($group['usages']);
        $results[] = $group;
    }

    usort($results, static function (array $a, array $b): int {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'song' ? -1 : 1;
        }
        return strcasecmp((string)$a['title'], (string)$b['title']);
    });

    echo json_encode([
        'ok' => true,
        'query' => $query,
        'results' => array_slice($results, 0, 24),
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
    exit;
}
