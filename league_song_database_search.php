<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

function mbLeagueSongDatabasePlaceLabel(int $place): string {
    if ($place <= 0) {
        return '';
    }

    $lastTwo = $place % 100;
    if ($lastTwo >= 11 && $lastTwo <= 13) {
        return $place . 'th';
    }

    switch ($place % 10) {
        case 1:
            return $place . 'st';
        case 2:
            return $place . 'nd';
        case 3:
            return $place . 'rd';
        default:
            return $place . 'th';
    }
}

function mbLeagueSongDatabaseHydrateRoundResults(PDO $pdo, array &$rows): void {
    if (empty($rows) || !mlTableExists($pdo, 'ML_RoundVotes')) {
        return;
    }

    $seasonRoundIds = [];
    foreach ($rows as $row) {
        $seasonRoundId = (int)($row['SeasonRoundID'] ?? 0);
        if ($seasonRoundId > 0) {
            $seasonRoundIds[$seasonRoundId] = $seasonRoundId;
        }
    }

    if (empty($seasonRoundIds)) {
        return;
    }

    $roundIds = array_values($seasonRoundIds);
    $placeholders = implode(',', array_fill(0, count($roundIds), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT rs.SeasonRoundID,
                   rs.RoundSongID,
                   rs.UserID,
                   COALESCE(SUM(rv.Score), 0) AS TotalVotes,
                   COUNT(DISTINCT CASE WHEN rv.Score > 0 THEN rv.VoterUserID END) AS PositiveVoterCount
            FROM ML_RoundSongs rs
            LEFT JOIN ML_RoundVotes rv
              ON rv.RoundSongID = rs.RoundSongID
             AND rv.SeasonRoundID = rs.SeasonRoundID
            WHERE rs.SeasonRoundID IN ($placeholders)
            GROUP BY rs.SeasonRoundID, rs.RoundSongID, rs.UserID
            ORDER BY rs.SeasonRoundID ASC, rs.RoundSongID ASC
        ");
        $stmt->execute($roundIds);
        $resultRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }

    $byRound = [];
    foreach ($resultRows as $resultRow) {
        $seasonRoundId = (int)($resultRow['SeasonRoundID'] ?? 0);
        $roundSongId = (int)($resultRow['RoundSongID'] ?? 0);

        if ($seasonRoundId <= 0 || $roundSongId <= 0) {
            continue;
        }

        $byRound[$seasonRoundId][] = [
            'round_song_id' => $roundSongId,
            'user_id' => (int)($resultRow['UserID'] ?? 0),
            'total_votes' => (int)($resultRow['TotalVotes'] ?? 0),
            'positive_voter_count' => (int)($resultRow['PositiveVoterCount'] ?? 0),
        ];
    }

    $statsByRoundSongId = [];
    foreach ($byRound as $seasonRoundId => $roundStats) {
        usort($roundStats, static function (array $a, array $b): int {
            $votesA = (int)($a['total_votes'] ?? 0);
            $votesB = (int)($b['total_votes'] ?? 0);
            if ($votesA !== $votesB) {
                return ($votesA > $votesB) ? -1 : 1;
            }

            $votersA = (int)($a['positive_voter_count'] ?? 0);
            $votersB = (int)($b['positive_voter_count'] ?? 0);
            if ($votersA !== $votersB) {
                return ($votersA > $votersB) ? -1 : 1;
            }

            $userIdA = (int)($a['user_id'] ?? 0);
            $userIdB = (int)($b['user_id'] ?? 0);
            if ($userIdA !== $userIdB) {
                return ($userIdA > $userIdB) ? -1 : 1;
            }

            return 0;
        });

        foreach ($roundStats as $index => $roundStat) {
            $roundSongId = (int)($roundStat['round_song_id'] ?? 0);
            if ($roundSongId <= 0) {
                continue;
            }

            $place = $index + 1;
            $statsByRoundSongId[$roundSongId] = [
                'finish_place' => $place,
                'finish_label' => mbLeagueSongDatabasePlaceLabel($place),
                'total_votes' => (int)($roundStat['total_votes'] ?? 0),
            ];
        }
    }

    foreach ($rows as &$row) {
        $roundSongId = (int)($row['RoundSongID'] ?? 0);
        if ($roundSongId <= 0 || empty($statsByRoundSongId[$roundSongId])) {
            $row['FinishPlace'] = null;
            $row['FinishLabel'] = '';
            $row['TotalVotes'] = null;
            continue;
        }

        $row['FinishPlace'] = (int)$statsByRoundSongId[$roundSongId]['finish_place'];
        $row['FinishLabel'] = (string)$statsByRoundSongId[$roundSongId]['finish_label'];
        $row['TotalVotes'] = (int)$statsByRoundSongId[$roundSongId]['total_votes'];
    }
    unset($row);
}

function mbLeagueSongDatabaseUsageRow(array $row): array {
    $playlistPosition = isset($row['PlaylistPosition']) && $row['PlaylistPosition'] !== null ? (int)$row['PlaylistPosition'] : null;
    $playerName = trim((string)($row['PlayerName'] ?? ''));
    $totalVotes = isset($row['TotalVotes']) && $row['TotalVotes'] !== null ? (int)$row['TotalVotes'] : null;

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
        'finish_place' => isset($row['FinishPlace']) && $row['FinishPlace'] !== null ? (int)$row['FinishPlace'] : null,
        'finish_label' => (string)($row['FinishLabel'] ?? ''),
        'total_votes' => $totalVotes,
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
    mbLeagueSongDatabaseHydrateRoundResults($pdo, $songRows);

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
    mbLeagueSongDatabaseHydrateRoundResults($pdo, $artistRows);

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
