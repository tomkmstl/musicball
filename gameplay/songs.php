<?php
// gameplay/songs.php
// Song selection, duplicate detection, and song draft helpers.

function mlFetchRoundSongFromDatabase(PDO $pdo, int $seasonRoundId, int $userId, bool $refresh = false): array {
    static $cache = [];

    $cacheKey = $seasonRoundId . ':' . $userId;
    if (!$refresh && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        $cache[$cacheKey] = [];
        return [];
    }

    try {
        $selectComment = mlRoundSongsHasSongCommentColumn($pdo) ? ', SongComment' : ', NULL AS SongComment';
        $stmt = $pdo->prepare("
            SELECT RoundSongID, SpotifyTrackID, SpotifyURI, TrackName, ArtistName, AlbumName, ArtworkURL{$selectComment}, SubmittedAt, UpdatedAt
            FROM ML_RoundSongs
            WHERE SeasonRoundID = ? AND UserID = ?
            LIMIT 1
        ");
        $stmt->execute([$seasonRoundId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $cache[$cacheKey] = [];
            return [];
        }

        $cache[$cacheKey] = [
            'round_song_id' => (int)$row['RoundSongID'],
            'id' => (string)($row['SpotifyTrackID'] ?? ''),
            'uri' => (string)($row['SpotifyURI'] ?? ''),
            'title' => (string)$row['TrackName'],
            'artist' => (string)$row['ArtistName'],
            'album' => (string)($row['AlbumName'] ?? ''),
            'artwork' => (string)($row['ArtworkURL'] ?? ''),
            'comment' => trim((string)($row['SongComment'] ?? '')),
            'saved_at' => (string)($row['UpdatedAt'] ?? $row['SubmittedAt'] ?? ''),
            'source' => 'db',
        ];

        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = [];
        return [];
    }
}
function mlGetRoundSongDraft(PDO $pdo, int $userId, int $seasonId, int $seasonRoundId): array {
    $dbSong = mlFetchRoundSongFromDatabase($pdo, $seasonRoundId, $userId);
    if (!empty($dbSong)) {
        return $dbSong;
    }

    if (!isset($_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId])) {
        return [];
    }

    $draft = $_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId];
    return is_array($draft) ? $draft : [];
}
function mlNormalizeSongDuplicateMatchValue(string $value): string {
    return trim($value);
}
function mlFindCurrentRoundSongDuplicate(PDO $pdo, int $seasonRoundId, int $currentUserId, string $spotifyTrackId, string $trackName, string $artistName): ?array {
    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        return null;
    }

    $spotifyTrackId = mlNormalizeSongDuplicateMatchValue($spotifyTrackId);
    $trackName = mlNormalizeSongDuplicateMatchValue($trackName);
    $artistName = mlNormalizeSongDuplicateMatchValue($artistName);

    try {
        if ($spotifyTrackId !== '') {
            $stmt = $pdo->prepare("
                SELECT rs.RoundSongID, rs.SeasonRoundID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI,
                       rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL, rs.SubmittedAt, rs.UpdatedAt,
                       u.UserName, sr.RoundNumber, sr.Title, s.SeasonID, s.SeasonName,
                       'spotify_id' AS MatchType
                FROM ML_RoundSongs rs
                LEFT JOIN ML_Users u ON u.UserID = rs.UserID
                LEFT JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rs.SeasonRoundID
                LEFT JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
                WHERE rs.SeasonRoundID = ?
                  AND rs.UserID <> ?
                  AND rs.SpotifyTrackID = ?
                ORDER BY rs.RoundSongID ASC
                LIMIT 1
            ");
            $stmt->execute([$seasonRoundId, $currentUserId, $spotifyTrackId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($match) && !empty($match)) {
                return $match;
            }
        }

        if ($trackName !== '' && $artistName !== '') {
            $stmt = $pdo->prepare("
                SELECT rs.RoundSongID, rs.SeasonRoundID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI,
                       rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL, rs.SubmittedAt, rs.UpdatedAt,
                       u.UserName, sr.RoundNumber, sr.Title, s.SeasonID, s.SeasonName,
                       'track_artist' AS MatchType
                FROM ML_RoundSongs rs
                LEFT JOIN ML_Users u ON u.UserID = rs.UserID
                LEFT JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rs.SeasonRoundID
                LEFT JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
                WHERE rs.SeasonRoundID = ?
                  AND rs.UserID <> ?
                  AND rs.TrackName = ?
                  AND rs.ArtistName = ?
                ORDER BY rs.RoundSongID ASC
                LIMIT 1
            ");
            $stmt->execute([$seasonRoundId, $currentUserId, $trackName, $artistName]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($match) && !empty($match)) {
                return $match;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}
function mlFindHistoricalSongDuplicate(PDO $pdo, int $seasonRoundId, int $currentUserId, string $spotifyTrackId, string $trackName, string $artistName): ?array {
    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        return null;
    }

    $spotifyTrackId = mlNormalizeSongDuplicateMatchValue($spotifyTrackId);
    $trackName = mlNormalizeSongDuplicateMatchValue($trackName);
    $artistName = mlNormalizeSongDuplicateMatchValue($artistName);

    try {
        if ($spotifyTrackId !== '') {
            $stmt = $pdo->prepare("
                SELECT rs.RoundSongID, rs.SeasonRoundID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI,
                       rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL, rs.SubmittedAt, rs.UpdatedAt,
                       u.UserName, sr.RoundNumber, sr.Title, s.SeasonID, s.SeasonName,
                       'spotify_id' AS MatchType
                FROM ML_RoundSongs rs
                LEFT JOIN ML_Users u ON u.UserID = rs.UserID
                LEFT JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rs.SeasonRoundID
                LEFT JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
                WHERE rs.SpotifyTrackID = ?
                  AND NOT (rs.SeasonRoundID = ? AND rs.UserID = ?)
                ORDER BY rs.SubmittedAt ASC, rs.RoundSongID ASC
                LIMIT 1
            ");
            $stmt->execute([$spotifyTrackId, $seasonRoundId, $currentUserId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($match) && !empty($match)) {
                return $match;
            }
        }

        if ($trackName !== '' && $artistName !== '') {
            $stmt = $pdo->prepare("
                SELECT rs.RoundSongID, rs.SeasonRoundID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI,
                       rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL, rs.SubmittedAt, rs.UpdatedAt,
                       u.UserName, sr.RoundNumber, sr.Title, s.SeasonID, s.SeasonName,
                       'track_artist' AS MatchType
                FROM ML_RoundSongs rs
                LEFT JOIN ML_Users u ON u.UserID = rs.UserID
                LEFT JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rs.SeasonRoundID
                LEFT JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
                WHERE rs.TrackName = ?
                  AND rs.ArtistName = ?
                  AND NOT (rs.SeasonRoundID = ? AND rs.UserID = ?)
                ORDER BY rs.SubmittedAt ASC, rs.RoundSongID ASC
                LIMIT 1
            ");
            $stmt->execute([$trackName, $artistName, $seasonRoundId, $currentUserId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($match) && !empty($match)) {
                return $match;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}
function mlFindCurrentSeasonArtistDuplicate(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId, string $artistName): ?array {
    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        return null;
    }

    $artistName = mlNormalizeSongDuplicateMatchValue($artistName);
    if ($artistName === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT rs.RoundSongID, rs.SeasonRoundID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI,
                   rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL, rs.SubmittedAt, rs.UpdatedAt,
                   u.UserName, sr.RoundNumber, sr.Title, s.SeasonID, s.SeasonName
            FROM ML_RoundSongs rs
            INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rs.SeasonRoundID
            LEFT JOIN ML_Users u ON u.UserID = rs.UserID
            LEFT JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
            WHERE sr.SeasonID = ?
              AND rs.ArtistName = ?
              AND NOT (rs.SeasonRoundID = ? AND rs.UserID = ?)
            ORDER BY sr.RoundNumber ASC, rs.SubmittedAt ASC, rs.RoundSongID ASC
            LIMIT 1
        ");
        $stmt->execute([$seasonId, $artistName, $seasonRoundId, $currentUserId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($match) && !empty($match)) {
            return $match;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}
function mlCountArtistSelectionsInPastRounds(PDO $pdo, int $seasonRoundId, string $artistName): int {
    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        return 0;
    }

    $artistName = mlNormalizeSongDuplicateMatchValue($artistName);
    if ($artistName === '') {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM ML_RoundSongs rs
            INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rs.SeasonRoundID
            INNER JOIN ML_SeasonRounds current_sr ON current_sr.SeasonRoundID = ?
            WHERE rs.ArtistName = ?
              AND rs.SeasonRoundID <> ?
              AND (
                  sr.SeasonID < current_sr.SeasonID
                  OR (sr.SeasonID = current_sr.SeasonID AND sr.RoundNumber < current_sr.RoundNumber)
              )
        ");
        $stmt->execute([$seasonRoundId, $artistName, $seasonRoundId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
function mlFetchSongSubmissionCount(PDO $pdo, int $seasonRoundId): int {
    static $cache = [];

    if (array_key_exists($seasonRoundId, $cache)) {
        return $cache[$seasonRoundId];
    }

    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        $cache[$seasonRoundId] = 0;
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ML_RoundSongs WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $cache[$seasonRoundId] = (int)$stmt->fetchColumn();
        return $cache[$seasonRoundId];
    } catch (Throwable $e) {
        $cache[$seasonRoundId] = 0;
        return 0;
    }
}
function mlSaveRoundSongDraft(int $userId, int $seasonId, int $seasonRoundId, array $track): void {
    $pdo = mlGameplayPdo();

    if ($pdo && mlTableExists($pdo, 'ML_RoundSongs')) {
        try {
            $stmt = $pdo->prepare("\n                INSERT INTO ML_RoundSongs\n                    (SeasonRoundID, UserID, SpotifyTrackID, SpotifyURI, TrackName, ArtistName, AlbumName, ArtworkURL)\n                VALUES\n                    (?, ?, ?, ?, ?, ?, ?, ?)\n                ON DUPLICATE KEY UPDATE\n                    SpotifyTrackID = VALUES(SpotifyTrackID),\n                    SpotifyURI = VALUES(SpotifyURI),\n                    TrackName = VALUES(TrackName),\n                    ArtistName = VALUES(ArtistName),\n                    AlbumName = VALUES(AlbumName),\n                    ArtworkURL = VALUES(ArtworkURL),\n                    UpdatedAt = CURRENT_TIMESTAMP\n            ");
            $stmt->execute([
                $seasonRoundId,
                $userId,
                (string)($track['id'] ?? ''),
                (string)($track['uri'] ?? ''),
                (string)($track['title'] ?? ''),
                (string)($track['artist'] ?? ''),
                (string)($track['album'] ?? ''),
                (string)($track['artwork'] ?? ''),
            ]);

            mlFetchRoundSongFromDatabase($pdo, $seasonRoundId, $userId, true);
            return;
        } catch (Throwable $e) {
            // Fall back to session below.
        }
    }

    if (!isset($_SESSION['ml_round_songs'])) {
        $_SESSION['ml_round_songs'] = [];
    }
    if (!isset($_SESSION['ml_round_songs'][$userId])) {
        $_SESSION['ml_round_songs'][$userId] = [];
    }
    if (!isset($_SESSION['ml_round_songs'][$userId][$seasonId])) {
        $_SESSION['ml_round_songs'][$userId][$seasonId] = [];
    }

    $track['saved_at'] = gmdate('Y-m-d H:i:s');
    $_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId] = $track;
}
function mlSaveRoundSongComment(int $userId, int $seasonId, int $seasonRoundId, string $comment): void {
    $comment = trim($comment);
    $pdo = mlGameplayPdo();

    if ($pdo && mlTableExists($pdo, 'ML_RoundSongs') && mlRoundSongsHasSongCommentColumn($pdo)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE ML_RoundSongs
                SET SongComment = ?, UpdatedAt = CURRENT_TIMESTAMP
                WHERE SeasonRoundID = ? AND UserID = ?
            ");
            $stmt->execute([$comment, $seasonRoundId, $userId]);

            mlFetchRoundSongFromDatabase($pdo, $seasonRoundId, $userId, true);
            return;
        } catch (Throwable $e) {
            // Fall back to session below.
        }
    }

    if (!isset($_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId])) {
        $_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId] = [];
    }

    if (!is_array($_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId])) {
        $_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId] = [];
    }

    $_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId]['comment'] = $comment;
    $_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId]['saved_at'] = gmdate('Y-m-d H:i:s');
}
function mlDeleteRoundSongDraft(int $userId, int $seasonId, int $seasonRoundId): void {
    $pdo = mlGameplayPdo();

    if ($pdo && mlTableExists($pdo, 'ML_RoundSongs')) {
        try {
            $stmt = $pdo->prepare('DELETE FROM ML_RoundSongs WHERE SeasonRoundID = ? AND UserID = ?');
            $stmt->execute([$seasonRoundId, $userId]);

            mlFetchRoundSongFromDatabase($pdo, $seasonRoundId, $userId, true);
        } catch (Throwable $e) {
            // Ignore and still clear session fallback.
        }
    }

    unset($_SESSION['ml_round_songs'][$userId][$seasonId][$seasonRoundId]);
}
