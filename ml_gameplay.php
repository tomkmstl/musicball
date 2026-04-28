<?php
// ml_gameplay.php
// Shared helpers for the live-game frontend shell.

require_once __DIR__ . '/ml_session_boot.php';
require_once __DIR__ . '/ml_config.php';

function mlRequireAuthenticatedUser(PDO $pdo): array {
    $userId = 0;

    if (isset($_SESSION['UserID'])) {
        $userId = (int)$_SESSION['UserID'];
    } elseif (isset($_SESSION['ml_user_id'])) {
        $userId = (int)$_SESSION['ml_user_id'];
    }

    if ($userId <= 0) {
        header('Location: ./?resetuser=true');
        exit;
    }

    $select = 'UserID, UserName, Email';
    if (mlUsersHasIsAdminColumn($pdo)) {
        $select .= ', IsAdmin';
    }
    if (mlUsersHasProfileImageColumn($pdo)) {
        $select .= ', ProfileImageFilename';
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM ML_Users WHERE UserID = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        unset($_SESSION['UserID'], $_SESSION['UserName'], $_SESSION['ml_user_id']);
        header('Location: ./?resetuser=true');
        exit;
    }

    $user['profile_image_path'] = mlGetUserProfilePath((int)$user['UserID'], $user['ProfileImageFilename'] ?? null);

    return $user;
}

function mlCloseSessionReadOnly(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function mlEnsureSessionWritable(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function mlLoadSeasonSummaries(PDO $pdo): array {
    $sql = "
        SELECT s.SeasonID,
               s.SeasonName,
               s.IsActive,
               (SELECT COUNT(*) FROM ML_SeasonRounds sr WHERE sr.SeasonID = s.SeasonID) AS RoundCount,
               (SELECT COUNT(DISTINCT sub.UserID) FROM ML_Submissions sub WHERE sub.SeasonID = s.SeasonID) AS SubmissionCount
        FROM ML_Seasons s
        ORDER BY s.SeasonID DESC
    ";

    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $result;
}

function mlResolveGameplaySeasonId(PDO $pdo, int $requestedSeasonId, int $activeSeasonId): int {
    $seasonList = mlLoadSeasonSummaries($pdo);
    $hasRequested = false;
    $firstSeasonWithRounds = 0;
    $firstAnySeason = 0;

    foreach ($seasonList as $seasonRow) {
        $rowSeasonId = (int)$seasonRow['SeasonID'];
        if ($firstAnySeason === 0) {
            $firstAnySeason = $rowSeasonId;
        }
        if ($firstSeasonWithRounds === 0 && (int)$seasonRow['RoundCount'] > 0) {
            $firstSeasonWithRounds = $rowSeasonId;
        }
        if ($requestedSeasonId > 0 && $rowSeasonId === $requestedSeasonId) {
            $hasRequested = true;
        }
    }

    if ($requestedSeasonId > 0 && $hasRequested) {
        return $requestedSeasonId;
    }

    if ($activeSeasonId > 0) {
        foreach ($seasonList as $seasonRow) {
            if ((int)$seasonRow['SeasonID'] === $activeSeasonId && (int)$seasonRow['RoundCount'] > 0) {
                return $activeSeasonId;
            }
        }
    }

    if ($firstSeasonWithRounds > 0) {
        return $firstSeasonWithRounds;
    }

    if ($activeSeasonId > 0) {
        return $activeSeasonId;
    }

    return $firstAnySeason;
}

function mlLoadSeasonById(PDO $pdo, ?int $seasonId): ?array {
    if ($seasonId === null || $seasonId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT SeasonID, SeasonName, IsActive FROM ML_Seasons WHERE SeasonID = ? LIMIT 1');
    $stmt->execute([$seasonId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $result = $row ?: null;
    return $result;
}

function mlSeasonRoundsHasStateColumns(PDO $pdo): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_SeasonRounds LIKE 'RoundState'");
        $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $checked = false;
    }

    return $checked;
}

if (!function_exists('mlTableExists')) {
    function mlTableExists(PDO $pdo, string $tableName): bool {
        static $tableLookup = null;

        if ($tableLookup === null) {
            $tableLookup = [];

            try {
                $stmt = $pdo->query('SHOW TABLES');
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];

                foreach ($rows as $row) {
                    if (!isset($row[0])) {
                        continue;
                    }

                    $tableLookup[(string)$row[0]] = true;
                }
            } catch (Throwable $e) {
                $tableLookup = [];
            }
        }

        return isset($tableLookup[$tableName]);
    }
}


function mlLoadSeasonRoundsForGameplay(PDO $pdo, int $seasonId): array {
    $select = 'SeasonRoundID, SeasonID, RoundNumber, Title, Tagline, SongsDue, VotesDue';
    if (mlSeasonRoundsHasStateColumns($pdo)) {
        $select .= ', RoundState, StateMode, HoldForAllSongs, HoldForAllVotes';
    }

    $stmt = $pdo->prepare("\n        SELECT {$select}\n        FROM ML_SeasonRounds\n        WHERE SeasonID = ?\n        ORDER BY RoundNumber ASC\n    ");
    $stmt->execute([$seasonId]);
    $rounds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = $rounds ?: [];
    return $result;
}

function mlFindRoundById(PDO $pdo, int $seasonRoundId): ?array {
    $select = 'SeasonRoundID, SeasonID, RoundNumber, Title, Tagline, SongsDue, VotesDue';
    if (mlSeasonRoundsHasStateColumns($pdo)) {
        $select .= ', RoundState, StateMode, HoldForAllSongs, HoldForAllVotes';
    }

    $stmt = $pdo->prepare("\n        SELECT {$select}\n        FROM ML_SeasonRounds\n        WHERE SeasonRoundID = ?\n        LIMIT 1\n    ");
    $stmt->execute([$seasonRoundId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mlCreateUtcDate(?string $value): ?DateTimeImmutable {
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

function mlFormatRoundDate(?string $value): string {
    $dt = mlCreateUtcDate($value);
    if (!$dt) {
        return 'TBD';
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('M j, Y g:i A') . ' UTC';
}

function mlGetExpectedPlayerCount(PDO $pdo): int {
    static $count = null;
    global $totalPlayers;

    if ($count !== null) {
        return $count;
    }

    if (isset($totalPlayers) && (int)$totalPlayers > 0) {
        $count = (int)$totalPlayers;
        return $count;
    }

    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM ML_Users')->fetchColumn();
    } catch (Throwable $e) {
        $count = 0;
    }

    return $count;
}


function mlGameplayPdo(): ?PDO {
    global $pdo;
    return ($pdo instanceof PDO) ? $pdo : null;
}

function mlRoundSongsHasSongCommentColumn(PDO $pdo): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        $checked = false;
        return false;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_RoundSongs LIKE 'SongComment'");
        $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $checked = false;
    }

    return $checked;
}

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


function mlFetchVoteSubmissionCount(PDO $pdo, int $seasonRoundId): int {
    static $cache = [];

    if (array_key_exists($seasonRoundId, $cache)) {
        return $cache[$seasonRoundId];
    }

    if (!mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
        $cache[$seasonRoundId] = 0;
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $cache[$seasonRoundId] = (int)$stmt->fetchColumn();
        return $cache[$seasonRoundId];
    } catch (Throwable $e) {
        $cache[$seasonRoundId] = 0;
        return 0;
    }
}


function mlFetchCurrentUserVoteSubmission(PDO $pdo, int $seasonRoundId, int $userId): bool {
    static $cache = [];

    $cacheKey = $seasonRoundId . ':' . $userId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
        $cache[$cacheKey] = false;
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? AND UserID = ? LIMIT 1');
        $stmt->execute([$seasonRoundId, $userId]);
        $cache[$cacheKey] = (bool)$stmt->fetchColumn();
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
        return false;
    }
}


function mlGetRoundPlaylistRecord(PDO $pdo, int $seasonRoundId, bool $refresh = false): array {
    static $cache = [];

    if (!$refresh && array_key_exists($seasonRoundId, $cache)) {
        return $cache[$seasonRoundId];
    }

    if (!mlTableExists($pdo, 'ML_RoundPlaylists')) {
        $cache[$seasonRoundId] = [];
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT RoundPlaylistID, SpotifyPlaylistID, SpotifyPlaylistURL, CreatedAt, UpdatedAt FROM ML_RoundPlaylists WHERE SeasonRoundID = ? LIMIT 1');
        $stmt->execute([$seasonRoundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$seasonRoundId] = $row ?: [];
        return $cache[$seasonRoundId];
    } catch (Throwable $e) {
        $cache[$seasonRoundId] = [];
        return [];
    }
}


function mlFetchPlaylistRecordsForRounds(PDO $pdo, array $seasonRoundIds): array {
    static $cache = [];

    $seasonRoundIds = array_values(array_unique(array_map('intval', $seasonRoundIds)));
    $seasonRoundIds = array_values(array_filter($seasonRoundIds, static function ($id) {
        return $id > 0;
    }));

    if (empty($seasonRoundIds) || !mlTableExists($pdo, 'ML_RoundPlaylists')) {
        return [];
    }

    $missingIds = [];
    foreach ($seasonRoundIds as $seasonRoundId) {
        if (!array_key_exists($seasonRoundId, $cache)) {
            $missingIds[] = $seasonRoundId;
        }
    }

    if (!empty($missingIds)) {
        $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
        try {
            $stmt = $pdo->prepare("SELECT RoundPlaylistID, SeasonRoundID, SpotifyPlaylistID, SpotifyPlaylistURL, CreatedAt, UpdatedAt FROM ML_RoundPlaylists WHERE SeasonRoundID IN ($placeholders)");
            $stmt->execute($missingIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $found = [];
            foreach ($rows as $row) {
                $seasonRoundId = (int)$row['SeasonRoundID'];
                $cache[$seasonRoundId] = $row;
                $found[$seasonRoundId] = true;
            }
            foreach ($missingIds as $seasonRoundId) {
                if (!isset($found[$seasonRoundId])) {
                    $cache[$seasonRoundId] = [];
                }
            }
        } catch (Throwable $e) {
            foreach ($missingIds as $seasonRoundId) {
                $cache[$seasonRoundId] = [];
            }
        }
    }

    $records = [];
    foreach ($seasonRoundIds as $seasonRoundId) {
        $records[$seasonRoundId] = $cache[$seasonRoundId] ?? [];
    }

    return $records;
}


function mlSaveRoundPlaylistRecord(PDO $pdo, int $seasonRoundId, string $spotifyPlaylistId, string $spotifyPlaylistUrl): void {
    if (!mlTableExists($pdo, 'ML_RoundPlaylists')) {
        throw new RuntimeException('The ML_RoundPlaylists table does not exist yet.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO ML_RoundPlaylists (SeasonRoundID, SpotifyPlaylistID, SpotifyPlaylistURL)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$seasonRoundId, $spotifyPlaylistId, $spotifyPlaylistUrl]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return;
        }
        throw $e;
    }
}


function mlGetAggregatePlaylistRecord(PDO $pdo, string $playlistType, ?int $userId = null, bool $refresh = false): array {
    static $cache = [];

    $playlistType = trim($playlistType);
    $cacheKey = $playlistType . ':' . ($userId === null ? 'null' : (string)(int)$userId);

    if (!$refresh && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!mlTableExists($pdo, 'ML_AggregatePlaylists')) {
        $cache[$cacheKey] = [];
        return [];
    }

    try {
        if ($userId === null) {
            $stmt = $pdo->prepare('SELECT AggregatePlaylistID, PlaylistType, UserID, PlaylistName, SpotifyPlaylistID, SpotifyPlaylistURL, LastSourceRoundPlaylistID, CreatedAt, UpdatedAt FROM ML_AggregatePlaylists WHERE PlaylistType = ? AND UserID IS NULL ORDER BY AggregatePlaylistID ASC LIMIT 1');
            $stmt->execute([$playlistType]);
        } else {
            $stmt = $pdo->prepare('SELECT AggregatePlaylistID, PlaylistType, UserID, PlaylistName, SpotifyPlaylistID, SpotifyPlaylistURL, LastSourceRoundPlaylistID, CreatedAt, UpdatedAt FROM ML_AggregatePlaylists WHERE PlaylistType = ? AND UserID = ? ORDER BY AggregatePlaylistID ASC LIMIT 1');
            $stmt->execute([$playlistType, (int)$userId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$cacheKey] = $row ?: [];
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = [];
        return [];
    }
}


function mlSaveAggregatePlaylistRecord(PDO $pdo, string $playlistType, ?int $userId, string $playlistName, string $spotifyPlaylistId, string $spotifyPlaylistUrl, ?int $lastSourceRoundPlaylistId = null): void {
    if (!mlTableExists($pdo, 'ML_AggregatePlaylists')) {
        throw new RuntimeException('The ML_AggregatePlaylists table does not exist yet.');
    }

    $playlistType = trim($playlistType);
    $playlistName = trim($playlistName);
    $spotifyPlaylistId = trim($spotifyPlaylistId);
    $spotifyPlaylistUrl = trim($spotifyPlaylistUrl);
    $lastSourceRoundPlaylistId = $lastSourceRoundPlaylistId !== null ? (int)$lastSourceRoundPlaylistId : null;

    $existing = mlGetAggregatePlaylistRecord($pdo, $playlistType, $userId, true);

    if (!empty($existing)) {
        $stmt = $pdo->prepare('UPDATE ML_AggregatePlaylists SET PlaylistName = ?, SpotifyPlaylistID = ?, SpotifyPlaylistURL = ?, LastSourceRoundPlaylistID = ?, UpdatedAt = UTC_TIMESTAMP() WHERE AggregatePlaylistID = ?');
        $stmt->execute([
            $playlistName,
            $spotifyPlaylistId,
            $spotifyPlaylistUrl,
            $lastSourceRoundPlaylistId,
            (int)$existing['AggregatePlaylistID'],
        ]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO ML_AggregatePlaylists (PlaylistType, UserID, PlaylistName, SpotifyPlaylistID, SpotifyPlaylistURL, LastSourceRoundPlaylistID, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([
            $playlistType,
            $userId,
            $playlistName,
            $spotifyPlaylistId,
            $spotifyPlaylistUrl,
            $lastSourceRoundPlaylistId,
        ]);
    }

    mlGetAggregatePlaylistRecord($pdo, $playlistType, $userId, true);
}


function mlAcquireAggregatePlaylistLock(PDO $pdo, string $lockKey, int $timeoutSeconds = 10): bool {
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?) AS lock_status');
        $stmt->execute(['musicball_aggregate_' . $lockKey, $timeoutSeconds]);
        return ((int)$stmt->fetchColumn() === 1);
    } catch (Throwable $e) {
        return false;
    }
}


function mlReleaseAggregatePlaylistLock(PDO $pdo, string $lockKey): void {
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['musicball_aggregate_' . $lockKey]);
    } catch (Throwable $e) {
        // Ignore lock release failures.
    }
}


function mlFetchSconeGhettoPlaylistSourceSongs(PDO $pdo, int $afterRoundPlaylistId = 0): array {
    if (!mlTableExists($pdo, 'ML_RoundPlaylists') || !mlTableExists($pdo, 'ML_RoundPlaylistItems') || !mlTableExists($pdo, 'ML_SeasonRounds')) {
        return [];
    }

    $sql = "
        SELECT rp.RoundPlaylistID,
               rp.SeasonRoundID,
               sr.SeasonID,
               sr.RoundNumber,
               rpi.PlaylistPosition,
               rpi.RoundSongID,
               rpi.UserID,
               rpi.SpotifyTrackID,
               rpi.SpotifyURI,
               rs.TrackName,
               rs.ArtistName
        FROM ML_RoundPlaylistItems rpi
        INNER JOIN ML_RoundPlaylists rp ON rp.RoundPlaylistID = rpi.RoundPlaylistID
        INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rp.SeasonRoundID
        LEFT JOIN ML_RoundSongs rs ON rs.RoundSongID = rpi.RoundSongID
    ";

    $params = [];
    if ($afterRoundPlaylistId > 0) {
        $sql .= ' WHERE rp.RoundPlaylistID > ?';
        $params[] = $afterRoundPlaylistId;
    }

    $sql .= ' ORDER BY sr.SeasonID ASC, sr.RoundNumber ASC, rpi.PlaylistPosition ASC, rpi.RoundSongID ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}


function mlGetSconeGhettoPendingSongCount(PDO $pdo, int $afterRoundPlaylistId = 0): int {
    $songs = mlFetchSconeGhettoPlaylistSourceSongs($pdo, $afterRoundPlaylistId);
    if (empty($songs)) {
        return 0;
    }

    $count = 0;
    foreach ($songs as $song) {
        if (trim((string)($song['SpotifyURI'] ?? '')) !== '') {
            $count++;
        }
    }

    return $count;
}


function mlCreateOrSyncSconeGhettoPlaylist(PDO $pdo): array {
    require_once __DIR__ . '/spotify_client.php';

    if (!mlTableExists($pdo, 'ML_AggregatePlaylists')) {
        throw new RuntimeException('The ML_AggregatePlaylists table does not exist yet.');
    }

    if (!mlSpotifyAppConfigured() || !mlSpotifyIsConnected($pdo)) {
        throw new RuntimeException('Spotify is not connected for playlist generation.');
    }

    $lockAcquired = mlAcquireAggregatePlaylistLock($pdo, 'all_time', 10);
    if (!$lockAcquired) {
        throw new RuntimeException('Scone Ghetto is already being built or synced.');
    }

    try {
        $existing = mlGetAggregatePlaylistRecord($pdo, 'all_time', null, true);
        $playlistId = trim((string)($existing['SpotifyPlaylistID'] ?? ''));
        $playlistUrl = trim((string)($existing['SpotifyPlaylistURL'] ?? ''));
        $lastSourceRoundPlaylistId = (int)($existing['LastSourceRoundPlaylistID'] ?? 0);
        $isNewPlaylist = ($playlistId === '');

        $sourceSongs = mlFetchSconeGhettoPlaylistSourceSongs($pdo, $isNewPlaylist ? 0 : $lastSourceRoundPlaylistId);

        $uris = [];
        $maxRoundPlaylistId = $lastSourceRoundPlaylistId;
        foreach ($sourceSongs as $song) {
            $uri = trim((string)($song['SpotifyURI'] ?? ''));
            if ($uri === '') {
                continue;
            }

            $uris[] = $uri;
            $roundPlaylistId = (int)($song['RoundPlaylistID'] ?? 0);
            if ($roundPlaylistId > $maxRoundPlaylistId) {
                $maxRoundPlaylistId = $roundPlaylistId;
            }
        }

        if ($isNewPlaylist && empty($uris)) {
            throw new RuntimeException('No generated round playlists are available yet for Scone Ghetto.');
        }

        $playlistName = 'Scone Ghetto';
        if ($playlistId === '') {
            $description = 'Every song from every generated ' . mlGetLeagueName($pdo) . ' round playlist, in league order.';
            $created = mlSpotifyCreatePlaylist($pdo, $playlistName, $description, false);
            $playlistId = trim((string)($created['playlist_id'] ?? ''));
            $playlistUrl = trim((string)($created['playlist_url'] ?? ''));

            if ($playlistId === '' || $playlistUrl === '') {
                throw new RuntimeException('Spotify did not return a valid playlist for Scone Ghetto.');
            }
        }

        if (!empty($uris)) {
            mlSpotifyAddItemsToPlaylist($pdo, $playlistId, $uris);
        }

        mlSaveAggregatePlaylistRecord(
            $pdo,
            'all_time',
            null,
            $playlistName,
            $playlistId,
            $playlistUrl,
            $maxRoundPlaylistId > 0 ? $maxRoundPlaylistId : null
        );

        $saved = mlGetAggregatePlaylistRecord($pdo, 'all_time', null, true);
        $saved['created_now'] = $isNewPlaylist;
        $saved['added_song_count'] = count($uris);
        return $saved;
    } finally {
        mlReleaseAggregatePlaylistLock($pdo, 'all_time');
    }
}




function mlFetchPlayerPlaylistSourceSongs(PDO $pdo, int $userId, int $afterRoundPlaylistId = 0): array {
    if ($userId <= 0) {
        return [];
    }

    if (!mlTableExists($pdo, 'ML_RoundPlaylists') || !mlTableExists($pdo, 'ML_RoundPlaylistItems') || !mlTableExists($pdo, 'ML_SeasonRounds')) {
        return [];
    }

    $sql = "
        SELECT rp.RoundPlaylistID,
               rp.SeasonRoundID,
               sr.SeasonID,
               sr.RoundNumber,
               rpi.PlaylistPosition,
               rpi.RoundSongID,
               rpi.UserID,
               rpi.SpotifyTrackID,
               rpi.SpotifyURI,
               rs.TrackName,
               rs.ArtistName
        FROM ML_RoundPlaylistItems rpi
        INNER JOIN ML_RoundPlaylists rp ON rp.RoundPlaylistID = rpi.RoundPlaylistID
        INNER JOIN ML_SeasonRounds sr ON sr.SeasonRoundID = rp.SeasonRoundID
        LEFT JOIN ML_RoundSongs rs ON rs.RoundSongID = rpi.RoundSongID
        WHERE rpi.UserID = ?
    ";

    $params = [$userId];
    if ($afterRoundPlaylistId > 0) {
        $sql .= ' AND rp.RoundPlaylistID > ?';
        $params[] = $afterRoundPlaylistId;
    }

    $sql .= ' ORDER BY sr.SeasonID ASC, sr.RoundNumber ASC, rpi.PlaylistPosition ASC, rpi.RoundSongID ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}


function mlCreateOrSyncPlayerSongsPlaylist(PDO $pdo, int $userId): array {
    require_once __DIR__ . '/spotify_client.php';

    if ($userId <= 0) {
        throw new RuntimeException('A valid player is required for this playlist.');
    }

    if (!mlTableExists($pdo, 'ML_AggregatePlaylists')) {
        throw new RuntimeException('The ML_AggregatePlaylists table does not exist yet.');
    }

    if (!mlSpotifyAppConfigured() || !mlSpotifyIsConnected($pdo)) {
        throw new RuntimeException('Spotify is not connected for playlist generation.');
    }

    $userStmt = $pdo->prepare('SELECT UserID, UserName FROM ML_Users WHERE UserID = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $userName = trim((string)($userRow['UserName'] ?? ''));
    if ($userName === '') {
        throw new RuntimeException('That player could not be found.');
    }

    $lockAcquired = mlAcquireAggregatePlaylistLock($pdo, 'player_' . $userId, 10);
    if (!$lockAcquired) {
        throw new RuntimeException($userName . "'s playlist is already being built or synced.");
    }

    try {
        $existing = mlGetAggregatePlaylistRecord($pdo, 'player', $userId, true);
        $playlistId = trim((string)($existing['SpotifyPlaylistID'] ?? ''));
        $playlistUrl = trim((string)($existing['SpotifyPlaylistURL'] ?? ''));
        $lastSourceRoundPlaylistId = (int)($existing['LastSourceRoundPlaylistID'] ?? 0);
        $isNewPlaylist = ($playlistId === '');

        $sourceSongs = mlFetchPlayerPlaylistSourceSongs($pdo, $userId, $isNewPlaylist ? 0 : $lastSourceRoundPlaylistId);

        $uris = [];
        $maxRoundPlaylistId = $lastSourceRoundPlaylistId;
        foreach ($sourceSongs as $song) {
            $uri = trim((string)($song['SpotifyURI'] ?? ''));
            if ($uri === '') {
                continue;
            }

            $uris[] = $uri;
            $roundPlaylistId = (int)($song['RoundPlaylistID'] ?? 0);
            if ($roundPlaylistId > $maxRoundPlaylistId) {
                $maxRoundPlaylistId = $roundPlaylistId;
            }
        }

        if ($isNewPlaylist && empty($uris)) {
            throw new RuntimeException('No generated round playlists are available yet for ' . $userName . ".");
        }

        $playlistName = $userName . "'s Songs";
        if ($playlistId === '') {
            $description = 'Every song from every generated ' . mlGetLeagueName($pdo) . ' round playlist submitted by ' . $userName . ', in league order.';
            $created = mlSpotifyCreatePlaylist($pdo, $playlistName, $description, false);
            $playlistId = trim((string)($created['playlist_id'] ?? ''));
            $playlistUrl = trim((string)($created['playlist_url'] ?? ''));

            if ($playlistId === '' || $playlistUrl === '') {
                throw new RuntimeException('Spotify did not return a valid playlist for ' . $userName . '.');
            }
        }

        if (!empty($uris)) {
            mlSpotifyAddItemsToPlaylist($pdo, $playlistId, $uris);
        }

        mlSaveAggregatePlaylistRecord(
            $pdo,
            'player',
            $userId,
            $playlistName,
            $playlistId,
            $playlistUrl,
            $maxRoundPlaylistId > 0 ? $maxRoundPlaylistId : null
        );

        $saved = mlGetAggregatePlaylistRecord($pdo, 'player', $userId, true);
        $saved['created_now'] = $isNewPlaylist;
        $saved['added_song_count'] = count($uris);
        return $saved;
    } finally {
        mlReleaseAggregatePlaylistLock($pdo, 'player_' . $userId);
    }
}


function mlGetPlaylistBuildMode(PDO $pdo): string {
    $mode = strtolower(trim((string)mlGetSettingValue($pdo, 'playlist_build_mode', 'due')));
    return in_array($mode, ['due', 'wait'], true) ? $mode : 'due';
}

function mlRoundReadyForPlaylistGeneration(array $round, array $playlistRecord, DateTimeImmutable $now, int $songSubmissionCount, int $expectedPlayers, string $playlistBuildMode): bool {
    if (!empty($playlistRecord)) {
        return false;
    }

    if ($songSubmissionCount <= 0) {
        return false;
    }

    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    if (!$songsDue instanceof DateTimeImmutable) {
        return false;
    }

    if ($now <= $songsDue) {
        return false;
    }

    if ($playlistBuildMode === 'wait') {
        return $expectedPlayers > 0 && $songSubmissionCount >= $expectedPlayers;
    }

    return true;
}

function mlCanChooseSongForRound(array $round, array $playlistRecord, DateTimeImmutable $now, string $playlistBuildMode, int $songSubmissionCount = 0, int $expectedPlayers = 0): bool {
    if (!empty($playlistRecord)) {
        return false;
    }

    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    if (!$songsDue instanceof DateTimeImmutable) {
        return true;
    }

    if ($now <= $songsDue) {
        return true;
    }

    if ($playlistBuildMode === 'wait') {
        return $expectedPlayers > 0 && $songSubmissionCount < $expectedPlayers;
    }

    return false;
}

function mlCanManuallyGeneratePlaylist(array $round, array $playlistRecord, DateTimeImmutable $now, int $songSubmissionCount = 0, int $expectedPlayers = 0, string $playlistBuildMode = 'due'): bool {
    if (!empty($playlistRecord)) {
        return false;
    }

    if ($songSubmissionCount <= 0) {
        return false;
    }

    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    $allSubmitted = ($expectedPlayers > 0 && $songSubmissionCount >= $expectedPlayers);

    if ($allSubmitted) {
        return true;
    }

    if (!$songsDue instanceof DateTimeImmutable) {
        return false;
    }

    return $now > $songsDue;
}


function mlRoundEligibleForAutomaticPlaylistBuild(array $round, array $playlistRecord, int $songSubmissionCount, int $expectedPlayers, DateTimeImmutable $now, string $playlistBuildMode): bool {
    return mlRoundReadyForPlaylistGeneration($round, $playlistRecord, $now, $songSubmissionCount, $expectedPlayers, $playlistBuildMode);
}

function mlAcquireRoundPlaylistLock(PDO $pdo, int $seasonRoundId, int $timeoutSeconds = 10): bool {
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?) AS lock_status');
        $stmt->execute(['musicball_playlist_round_' . $seasonRoundId, $timeoutSeconds]);
        return ((int)$stmt->fetchColumn() === 1);
    } catch (Throwable $e) {
        return false;
    }
}

function mlReleaseRoundPlaylistLock(PDO $pdo, int $seasonRoundId): void {
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['musicball_playlist_round_' . $seasonRoundId]);
    } catch (Throwable $e) {
        // Ignore lock release failures.
    }
}

function mlFetchRoundSongsForPlaylist(PDO $pdo, int $seasonRoundId): array {
    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        return [];
    }

    try {
        $profileSelect = mlUsersHasProfileImageColumn($pdo) ? ', u.ProfileImageFilename' : ', NULL AS ProfileImageFilename';
        $stmt = $pdo->prepare("
            SELECT rs.RoundSongID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI, rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL,
                   u.UserName{$profileSelect}
            FROM ML_RoundSongs rs
            LEFT JOIN ML_Users u ON rs.UserID = u.UserID
            WHERE rs.SeasonRoundID = ?
            ORDER BY rs.RoundSongID ASC
        ");
        $stmt->execute([$seasonRoundId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mlSaveRoundPlaylistItems(PDO $pdo, int $roundPlaylistId, int $seasonRoundId, array $songsInPlaylistOrder): void {
    if (!mlTableExists($pdo, 'ML_RoundPlaylistItems')) {
        return;
    }

    $deleteStmt = $pdo->prepare('DELETE FROM ML_RoundPlaylistItems WHERE SeasonRoundID = ?');
    $deleteStmt->execute([$seasonRoundId]);

    if (empty($songsInPlaylistOrder)) {
        return;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO ML_RoundPlaylistItems
            (RoundPlaylistID, SeasonRoundID, UserID, RoundSongID, SpotifyTrackID, SpotifyURI, PlaylistPosition)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $position = 1;
    foreach ($songsInPlaylistOrder as $song) {
        $insertStmt->execute([
            $roundPlaylistId,
            $seasonRoundId,
            (int)($song['UserID'] ?? 0),
            (int)($song['RoundSongID'] ?? 0),
            trim((string)($song['SpotifyTrackID'] ?? '')),
            trim((string)($song['SpotifyURI'] ?? '')),
            $position,
        ]);
        $position++;
    }
}

function mlGeneratePlaylistForRound(PDO $pdo, array $round, bool $force = false): array {
    require_once __DIR__ . '/spotify_client.php';
    require_once __DIR__ . '/ml_discord.php';

    $seasonRoundId = (int)$round['SeasonRoundID'];
    $playlistRecord = mlGetRoundPlaylistRecord($pdo, $seasonRoundId, true);
    if (!empty($playlistRecord)) {
        try {
            mlDiscordMaybeSendVotingOpenForRound($pdo, $round, $playlistRecord);
        } catch (Throwable $e) {
            // Never interrupt gameplay for Discord failures.
        }
        return $playlistRecord;
    }

    $lockAcquired = mlAcquireRoundPlaylistLock($pdo, $seasonRoundId, 10);
    if (!$lockAcquired) {
        $playlistRecord = mlGetRoundPlaylistRecord($pdo, $seasonRoundId, true);
        if (!empty($playlistRecord)) {
            return $playlistRecord;
        }

        throw new RuntimeException('Playlist generation is already in progress for this round.');
    }

    try {
        $playlistRecord = mlGetRoundPlaylistRecord($pdo, $seasonRoundId, true);
        if (!empty($playlistRecord)) {
            return $playlistRecord;
        }

        if (!mlSpotifyAppConfigured() || !mlSpotifyIsConnected($pdo)) {
            throw new RuntimeException('Spotify is not connected for playlist generation.');
        }

        $songs = mlFetchRoundSongsForPlaylist($pdo, $seasonRoundId);
        $playlistSongs = [];
        foreach ($songs as $song) {
            $uri = trim((string)($song['SpotifyURI'] ?? ''));
            if ($uri !== '') {
                $playlistSongs[] = $song;
            }
        }

        if (empty($playlistSongs)) {
            throw new RuntimeException('No submitted Spotify songs are available for this round yet.');
        }

        if (count($playlistSongs) > 1) {
            shuffle($playlistSongs);
        }

        $uris = [];
        foreach ($playlistSongs as $song) {
            $uris[] = trim((string)($song['SpotifyURI'] ?? ''));
        }

        $playlistName = trim((string)($round['Title'] ?? ''));
        if ($playlistName === '') {
            $playlistName = 'Round ' . (int)$round['RoundNumber'];
        }
        if (!empty($round['SeasonName'])) {
            $playlistName = trim((string)$round['SeasonName']) . ' - ' . $playlistName;
        }

        $playlistDescription = 'Generated by Musicball for Round ' . (int)$round['RoundNumber'] . '.';
        $created = mlSpotifyCreatePlaylist($pdo, $playlistName, $playlistDescription, false);
        mlSpotifyAddItemsToPlaylist($pdo, (string)$created['playlist_id'], $uris);
        mlSaveRoundPlaylistRecord($pdo, $seasonRoundId, (string)$created['playlist_id'], (string)$created['playlist_url']);

        $playlistRecord = mlGetRoundPlaylistRecord($pdo, $seasonRoundId, true);
        if (!empty($playlistRecord) && !empty($playlistSongs)) {
            mlSaveRoundPlaylistItems($pdo, (int)$playlistRecord['RoundPlaylistID'], $seasonRoundId, $playlistSongs);
        }
        try {
            mlDiscordMaybeSendVotingOpenForRound($pdo, $round, $playlistRecord);
        } catch (Throwable $e) {
            // Never interrupt gameplay for Discord failures.
        }

        return $playlistRecord;
    } finally {
        mlReleaseRoundPlaylistLock($pdo, $seasonRoundId);
    }
}

function mlResolveRoundState(array $round, DateTimeImmutable $now, ?DateTimeImmutable $previousVotesDue, int $expectedPlayers, int $songSubmissionCount, int $voteSubmissionCount, array $playlistRecord = [], string $playlistBuildMode = 'due'): array {
    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? $round['VotesDue'] : null);
    $hasPlaylist = !empty($playlistRecord) && trim((string)($playlistRecord['SpotifyPlaylistURL'] ?? $playlistRecord['SpotifyPlaylistID'] ?? '')) !== '';

    if ($previousVotesDue instanceof DateTimeImmutable && $now <= $previousVotesDue) {
        $roundState = 'upcoming';
    } elseif ($hasPlaylist) {
        if ($votesDue instanceof DateTimeImmutable && $now > $votesDue) {
            $roundState = 'closed';
        } else {
            $roundState = 'voting';
        }
    } else {
        $roundState = 'submission';
    }

    $statusMap = [
        'upcoming' => ['Upcoming', 'pill-neutral'],
        'submission' => ['Choose a Song Stage', 'pill-open'],
        'voting' => ['Voting Stage', 'pill-open'],
        'closed' => ['Round Closed', 'pill-complete'],
    ];

    $status = $statusMap[$roundState] ?? $statusMap['upcoming'];

    return [
        'round_state' => $roundState,
        'status_key' => $roundState,
        'status_label' => $status[0],
        'status_class' => $status[1],
        'can_choose_song' => $roundState === 'submission' && mlCanChooseSongForRound($round, $playlistRecord, $now, $playlistBuildMode, $songSubmissionCount, $expectedPlayers),
        'can_vote' => $roundState === 'voting',
        'can_view_playlist' => in_array($roundState, ['voting', 'closed'], true) && $hasPlaylist,
        'can_manual_generate_playlist' => false,
        'has_playlist' => $hasPlaylist,
        'songs_due_utc' => isset($round['SongsDue']) ? (string)$round['SongsDue'] : '',
        'votes_due_utc' => isset($round['VotesDue']) ? (string)$round['VotesDue'] : '',
        'songs_due_label' => mlFormatRoundDate(isset($round['SongsDue']) ? $round['SongsDue'] : null),
        'votes_due_label' => mlFormatRoundDate(isset($round['VotesDue']) ? $round['VotesDue'] : null),
        'submission_closed' => ($roundState === 'submission' && !mlCanChooseSongForRound($round, $playlistRecord, $now, $playlistBuildMode, $songSubmissionCount, $expectedPlayers)),
    ];
}


function mlComputeRoundPresentation(PDO $pdo, array $rounds, int $currentUserId): array {
    static $cache = [];

    $cacheRoundParts = [];
    foreach ($rounds as $round) {
        $cacheRoundParts[] = (int)($round['SeasonRoundID'] ?? 0)
            . ':'
            . (string)($round['SongsDue'] ?? '')
            . ':'
            . (string)($round['VotesDue'] ?? '');
    }

    $cacheKey = $currentUserId . '|' . implode('|', $cacheRoundParts);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $resolved = [];
    $previousVotesDue = null;
    $expectedPlayers = mlGetExpectedPlayerCount($pdo);
    $playlistBuildMode = mlGetPlaylistBuildMode($pdo);
    $allUsers = null;

    $roundIds = array_map(static function ($round) {
        return (int)$round['SeasonRoundID'];
    }, $rounds);
    $playlistRecords = mlFetchPlaylistRecordsForRounds($pdo, $roundIds);

    $currentRoundIndex = null;

    foreach ($rounds as $index => $round) {
        $seasonRoundId = (int)$round['SeasonRoundID'];
        $playlistRecord = $playlistRecords[$seasonRoundId] ?? [];
        $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? $round['VotesDue'] : null);
        $hasPlaylist = !empty($playlistRecord) && trim((string)($playlistRecord['SpotifyPlaylistURL'] ?? $playlistRecord['SpotifyPlaylistID'] ?? '')) !== '';

        if ($previousVotesDue instanceof DateTimeImmutable && $now <= $previousVotesDue) {
            $roundState = 'upcoming';
        } elseif ($votesDue instanceof DateTimeImmutable && $now > $votesDue) {
            $roundState = 'closed';
        } else {
            $roundState = $hasPlaylist ? 'voting' : 'submission';
            if ($currentRoundIndex === null) {
                $currentRoundIndex = $index;
            }
        }

        $statusMap = [
            'upcoming' => ['Upcoming', 'pill-neutral'],
            'submission' => ['Choose a Song Stage', 'pill-open'],
            'voting' => ['Voting Stage', 'pill-open'],
            'closed' => ['Round Closed', 'pill-complete'],
        ];
        $status = $statusMap[$roundState] ?? $statusMap['upcoming'];

        $round['expected_players'] = $expectedPlayers;
        $round['song_submission_count'] = 0;
        $round['vote_submission_count'] = 0;
        $round['playlist_record'] = $playlistRecord;
        $round['playlist_url'] = (string)($playlistRecord['SpotifyPlaylistURL'] ?? '');
        $round['has_playlist'] = $hasPlaylist;
        $round['song_draft'] = [];
        $round['vote_draft'] = [];
        $round['song_saved'] = false;
        $round['vote_saved'] = false;
        $round['vote_submitted'] = false;
        $round['progress_completed_users'] = [];
        $round['progress_pending_users'] = [];
        $round['progress_completed_names'] = 'None';
        $round['progress_pending_names'] = 'None';
        $round['round_state'] = $roundState;
        $round['status_key'] = $roundState;
        $round['status_label'] = $status[0];
        $round['status_class'] = $status[1];
        $round['can_choose_song'] = false;
        $round['can_vote'] = false;
        $round['can_view_playlist'] = in_array($roundState, ['voting', 'closed'], true) && $hasPlaylist;
        $round['can_manual_generate_playlist'] = false;
        $round['songs_due_utc'] = isset($round['SongsDue']) ? (string)$round['SongsDue'] : '';
        $round['votes_due_utc'] = isset($round['VotesDue']) ? (string)$round['VotesDue'] : '';
        $round['songs_due_label'] = mlFormatRoundDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
        $round['votes_due_label'] = mlFormatRoundDate(isset($round['VotesDue']) ? $round['VotesDue'] : null);
        $round['submission_closed'] = false;

        $resolved[] = $round;

        if ($votesDue instanceof DateTimeImmutable) {
            $previousVotesDue = $votesDue;
        }
    }

    foreach ($resolved as $index => $resolvedRound) {
        $seasonRoundId = (int)$resolvedRound['SeasonRoundID'];
        $seasonId = (int)$resolvedRound['SeasonID'];
        $playlistRecord = $resolvedRound['playlist_record'] ?? [];
        $roundState = (string)($resolvedRound['round_state'] ?? '');

        if ($roundState === 'submission') {
            $songSubmissionCount = mlFetchSongSubmissionCount($pdo, $seasonRoundId);
            $resolvedRound['song_submission_count'] = $songSubmissionCount;
            $resolvedRound['can_choose_song'] = mlCanChooseSongForRound($resolvedRound, $playlistRecord, $now, $playlistBuildMode, $songSubmissionCount, $expectedPlayers);
            $resolvedRound['submission_closed'] = !$resolvedRound['can_choose_song'];

            $songDraft = mlGetRoundSongDraft($pdo, $currentUserId, $seasonId, $seasonRoundId);
            $resolvedRound['song_draft'] = $songDraft;
            $resolvedRound['song_saved'] = !empty($songDraft);

            if ($index === $currentRoundIndex) {
                $resolvedRound['can_manual_generate_playlist'] = mlCanManuallyGeneratePlaylist($resolvedRound, $playlistRecord, $now, $songSubmissionCount, $expectedPlayers, $playlistBuildMode);

                $allUsers = $allUsers ?? mlLoadAllUsers($pdo);
                $progress = mlBuildRoundProgressUsers($pdo, $seasonRoundId, 'submission', $allUsers);
                $resolvedRound['progress_completed_users'] = $progress['completed'];
                $resolvedRound['progress_pending_users'] = $progress['pending'];
                $resolvedRound['progress_completed_names'] = $progress['completed_names'];
                $resolvedRound['progress_pending_names'] = $progress['pending_names'];
            }
        } elseif ($roundState === 'upcoming') {
            $resolvedRound['can_choose_song'] = true;
            $songDraft = mlGetRoundSongDraft($pdo, $currentUserId, $seasonId, $seasonRoundId);
            $resolvedRound['song_draft'] = $songDraft;
            $resolvedRound['song_saved'] = !empty($songDraft);
        } elseif ($index === $currentRoundIndex && $roundState === 'voting') {
            $voteSubmissionCount = mlFetchVoteSubmissionCount($pdo, $seasonRoundId);
            $resolvedRound['vote_submission_count'] = $voteSubmissionCount;
            $resolvedRound['can_vote'] = true;
            $voteDraft = mlGetRoundVoteDraft($currentUserId, $seasonId, $seasonRoundId);
            $resolvedRound['vote_draft'] = $voteDraft;
            $resolvedRound['vote_saved'] = !empty($voteDraft);
            $resolvedRound['vote_submitted'] = mlFetchCurrentUserVoteSubmission($pdo, $seasonRoundId, $currentUserId) || !empty($voteDraft['submitted_at']);

            $allUsers = $allUsers ?? mlLoadAllUsers($pdo);
            $progress = mlBuildRoundProgressUsers($pdo, $seasonRoundId, 'voting', $allUsers);
            $resolvedRound['progress_completed_users'] = $progress['completed'];
            $resolvedRound['progress_pending_users'] = $progress['pending'];
            $resolvedRound['progress_completed_names'] = $progress['completed_names'];
            $resolvedRound['progress_pending_names'] = $progress['pending_names'];
        }

        $resolved[$index] = $resolvedRound;
    }

    $cache[$cacheKey] = $resolved;
    return $resolved;
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

function mlFetchRoundVoteDraftFromDatabase(PDO $pdo, int $userId, int $seasonRoundId): array {
    if (!mlTableExists($pdo, 'ML_RoundVotes')) {
        return [];
    }

    try {
        $submittedAt = null;
        if (mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
            $submittedStmt = $pdo->prepare('SELECT SubmittedAt FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? AND UserID = ? LIMIT 1');
            $submittedStmt->execute([$seasonRoundId, $userId]);
            $submittedAt = $submittedStmt->fetchColumn();
        }

        $stmt = $pdo->prepare("\n            SELECT rv.RoundSongID, rv.Score, rv.Comment, rv.UpdatedAt\n            FROM ML_RoundVotes rv\n            WHERE rv.SeasonRoundID = ? AND rv.VoterUserID = ?\n            ORDER BY rv.RoundSongID ASC\n        ");
        $stmt->execute([$seasonRoundId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return $submittedAt ? ['entries' => [], 'submitted_at' => (string)$submittedAt] : [];
        }

        $entries = [];
        $savedAt = '';
        foreach ($rows as $row) {
            $entryId = 'entry_' . (int)$row['RoundSongID'];
            $entries[$entryId] = [
                'score' => (int)$row['Score'],
                'comment' => (string)($row['Comment'] ?? ''),
            ];
            if ($savedAt === '' && !empty($row['UpdatedAt'])) {
                $savedAt = (string)$row['UpdatedAt'];
            }
        }

        $result = [
            'entries' => $entries,
        ];
        if ($savedAt !== '') {
            $result['saved_at'] = $savedAt;
        }
        if ($submittedAt) {
            $result['submitted_at'] = (string)$submittedAt;
        }

        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

function mlGetRoundVoteDraft(int $userId, int $seasonId, int $seasonRoundId): array {
    $pdo = mlGameplayPdo();
    if ($pdo) {
        $dbDraft = mlFetchRoundVoteDraftFromDatabase($pdo, $userId, $seasonRoundId);
        if (!empty($dbDraft)) {
            return $dbDraft;
        }
    }

    if (!isset($_SESSION['ml_round_votes'][$userId][$seasonId][$seasonRoundId])) {
        return [];
    }

    $draft = $_SESSION['ml_round_votes'][$userId][$seasonId][$seasonRoundId];
    return is_array($draft) ? $draft : [];
}

function mlSaveRoundVoteDraft(int $userId, int $seasonId, int $seasonRoundId, array $votePayload, bool $markSubmitted): void {
    $pdo = mlGameplayPdo();

    if ($pdo && mlTableExists($pdo, 'ML_RoundVotes')) {
        require_once __DIR__ . '/ml_discord.php';
        try {
            $entries = isset($votePayload['entries']) && is_array($votePayload['entries']) ? $votePayload['entries'] : [];
            $pdo->beginTransaction();

            $deleteStmt = $pdo->prepare('DELETE FROM ML_RoundVotes WHERE SeasonRoundID = ? AND VoterUserID = ?');
            $deleteStmt->execute([$seasonRoundId, $userId]);

            $insertStmt = $pdo->prepare("\n                INSERT INTO ML_RoundVotes\n                    (SeasonRoundID, VoterUserID, RoundSongID, Score, Comment)\n                VALUES\n                    (?, ?, ?, ?, ?)\n            ");

            foreach ($entries as $entryId => $entry) {
                if (!preg_match('/^entry_(\d+)$/', (string)$entryId, $matches)) {
                    continue;
                }
                $roundSongId = (int)$matches[1];
                if ($roundSongId <= 0) {
                    continue;
                }

                $score = isset($entry['score']) ? max(0, min(10, (int)$entry['score'])) : 0;
                $comment = isset($entry['comment']) ? trim((string)$entry['comment']) : '';
                $insertStmt->execute([$seasonRoundId, $userId, $roundSongId, $score, $comment]);
            }

            if (mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
                if ($markSubmitted) {
                    $submitStmt = $pdo->prepare("\n                        INSERT INTO ML_RoundVoteSubmissions (SeasonRoundID, UserID)\n                        VALUES (?, ?)\n                        ON DUPLICATE KEY UPDATE SubmittedAt = CURRENT_TIMESTAMP\n                    ");
                    $submitStmt->execute([$seasonRoundId, $userId]);
                } else {
                    $clearStmt = $pdo->prepare('DELETE FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? AND UserID = ?');
                    $clearStmt->execute([$seasonRoundId, $userId]);
                }
            }

            $pdo->commit();

            if ($markSubmitted) {
                try {
                    mlDiscordMaybeSendVotesSubmittedForRound($pdo, $seasonRoundId, $userId);
                    mlDiscordMaybeSendAllVotesInForRound($pdo, $seasonRoundId);
                } catch (Throwable $e) {
                    // Never interrupt gameplay for Discord failures.
                }
            }

            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Fall back to session below.
        }
    }

    if (!isset($_SESSION['ml_round_votes'])) {
        $_SESSION['ml_round_votes'] = [];
    }
    if (!isset($_SESSION['ml_round_votes'][$userId])) {
        $_SESSION['ml_round_votes'][$userId] = [];
    }
    if (!isset($_SESSION['ml_round_votes'][$userId][$seasonId])) {
        $_SESSION['ml_round_votes'][$userId][$seasonId] = [];
    }

    $votePayload['saved_at'] = gmdate('Y-m-d H:i:s');
    if ($markSubmitted) {
        $votePayload['submitted_at'] = gmdate('Y-m-d H:i:s');
    } else {
        unset($votePayload['submitted_at']);
    }
    $_SESSION['ml_round_votes'][$userId][$seasonId][$seasonRoundId] = $votePayload;
}

function mlDemoTrackLibrary(): array {
    return [
        ['id' => 'track_001', 'title' => 'Dreams', 'artist' => 'Fleetwood Mac', 'album' => 'Rumours', 'artwork' => 'https://placehold.co/240x240/0f172a/e2e8f0?text=Dreams', 'uri' => 'spotify:track:demo001'],
        ['id' => 'track_002', 'title' => 'Electric Feel', 'artist' => 'MGMT', 'album' => 'Oracular Spectacular', 'artwork' => 'https://placehold.co/240x240/111827/e2e8f0?text=Electric+Feel', 'uri' => 'spotify:track:demo002'],
        ['id' => 'track_003', 'title' => 'Fast Car', 'artist' => 'Tracy Chapman', 'album' => 'Tracy Chapman', 'artwork' => 'https://placehold.co/240x240/1e293b/e2e8f0?text=Fast+Car', 'uri' => 'spotify:track:demo003'],
        ['id' => 'track_004', 'title' => 'Goodbye Yellow Brick Road', 'artist' => 'Elton John', 'album' => 'Goodbye Yellow Brick Road', 'artwork' => 'https://placehold.co/240x240/172554/e2e8f0?text=Goodbye+YBR', 'uri' => 'spotify:track:demo004'],
        ['id' => 'track_005', 'title' => 'Blue Monday', 'artist' => 'New Order', 'album' => 'Power, Corruption & Lies', 'artwork' => 'https://placehold.co/240x240/312e81/e2e8f0?text=Blue+Monday', 'uri' => 'spotify:track:demo005'],
        ['id' => 'track_006', 'title' => 'Midnight City', 'artist' => 'M83', 'album' => 'Hurry Up, We\'re Dreaming', 'artwork' => 'https://placehold.co/240x240/0f172a/e2e8f0?text=Midnight+City', 'uri' => 'spotify:track:demo006'],
        ['id' => 'track_007', 'title' => 'This Must Be the Place', 'artist' => 'Talking Heads', 'album' => 'Speaking in Tongues', 'artwork' => 'https://placehold.co/240x240/1f2937/e2e8f0?text=This+Must+Be+the+Place', 'uri' => 'spotify:track:demo007'],
        ['id' => 'track_008', 'title' => 'Ain\'t No Mountain High Enough', 'artist' => 'Marvin Gaye & Tammi Terrell', 'album' => 'United', 'artwork' => 'https://placehold.co/240x240/082f49/e2e8f0?text=Ain%27t+No+Mountain', 'uri' => 'spotify:track:demo008'],
        ['id' => 'track_009', 'title' => 'Dog Days Are Over', 'artist' => 'Florence + The Machine', 'album' => 'Lungs', 'artwork' => 'https://placehold.co/240x240/164e63/e2e8f0?text=Dog+Days', 'uri' => 'spotify:track:demo009'],
        ['id' => 'track_010', 'title' => 'Tennessee Whiskey', 'artist' => 'Chris Stapleton', 'album' => 'Traveller', 'artwork' => 'https://placehold.co/240x240/3f3f46/e2e8f0?text=Tennessee+Whiskey', 'uri' => 'spotify:track:demo010'],
        ['id' => 'track_011', 'title' => 'Sir Duke', 'artist' => 'Stevie Wonder', 'album' => 'Songs in the Key of Life', 'artwork' => 'https://placehold.co/240x240/27272a/e2e8f0?text=Sir+Duke', 'uri' => 'spotify:track:demo011'],
        ['id' => 'track_012', 'title' => 'Fade Into You', 'artist' => 'Mazzy Star', 'album' => 'So Tonight That I Might See', 'artwork' => 'https://placehold.co/240x240/1e1b4b/e2e8f0?text=Fade+Into+You', 'uri' => 'spotify:track:demo012'],
    ];
}

function mlSearchDemoTracks(string $query): array {
    $tracks = mlDemoTrackLibrary();
    $query = trim($query);

    if ($query === '') {
        return array_slice($tracks, 0, 8);
    }

    $queryLower = mb_strtolower($query);
    $results = [];
    foreach ($tracks as $track) {
        $haystack = mb_strtolower($track['title'] . ' ' . $track['artist'] . ' ' . $track['album']);
        if (strpos($haystack, $queryLower) !== false) {
            $results[] = $track;
        }
    }

    if (empty($results)) {
        return array_slice($tracks, 0, 5);
    }

    return $results;
}

function mlGetDemoTrackById(string $trackId): ?array {
    foreach (mlDemoTrackLibrary() as $track) {
        if ($track['id'] === $trackId) {
            return $track;
        }
    }

    return null;
}

function mlLoadAllUsers(PDO $pdo): array {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $select = 'SELECT UserID, UserName';
    if (mlUsersHasProfileImageColumn($pdo)) {
        $select .= ', ProfileImageFilename';
    }
    $select .= ' FROM ML_Users ORDER BY UserID ASC';

    $stmt = $pdo->query($select);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $user['profile_image_path'] = mlGetUserProfilePath((int)$user['UserID'], $user['ProfileImageFilename'] ?? null);
    }
    unset($user);

    $cache = $users;
    return $cache;
}


function mlFetchRoundCompletedUserIds(PDO $pdo, int $seasonRoundId, string $mode): array {
    static $cache = [];
    $cacheKey = $mode . ':' . $seasonRoundId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $ids = [];
    try {
        if ($mode === 'submission' && mlTableExists($pdo, 'ML_RoundSongs')) {
            $stmt = $pdo->prepare('SELECT DISTINCT UserID FROM ML_RoundSongs WHERE SeasonRoundID = ? ORDER BY UserID ASC');
            $stmt->execute([$seasonRoundId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($mode === 'voting' && mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
            $stmt = $pdo->prepare('SELECT DISTINCT UserID FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? ORDER BY UserID ASC');
            $stmt->execute([$seasonRoundId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Throwable $e) {
        $ids = [];
    }

    $cache[$cacheKey] = $ids;
    return $ids;
}

function mlBuildRoundProgressUsers(PDO $pdo, int $seasonRoundId, string $mode, array $allUsers): array {
    $completedIds = mlFetchRoundCompletedUserIds($pdo, $seasonRoundId, $mode);
    $completedLookup = array_fill_keys($completedIds, true);
    $completedUsers = [];
    $pendingUsers = [];

    foreach ($allUsers as $user) {
        $row = [
            'user_id' => (int)$user['UserID'],
            'user_name' => (string)$user['UserName'],
            'profile_image_path' => (string)($user['profile_image_path'] ?? mlGetUserProfilePath((int)$user['UserID'], $user['ProfileImageFilename'] ?? null)),
        ];

        if (isset($completedLookup[$row['user_id']])) {
            $completedUsers[] = $row;
        } else {
            $pendingUsers[] = $row;
        }
    }

    return [
        'completed' => $completedUsers,
        'pending' => $pendingUsers,
        'completed_names' => !empty($completedUsers) ? implode(', ', array_map(static fn($u) => $u['user_name'], $completedUsers)) : 'None',
        'pending_names' => !empty($pendingUsers) ? implode(', ', array_map(static fn($u) => $u['user_name'], $pendingUsers)) : 'None',
    ];
}


function mlMaybeAutoGeneratePlaylists(PDO $pdo, array $presentedRounds, int $currentUserId = 0): bool {
    if (!mlIsAdminUserId($pdo, $currentUserId)) {
        return false;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expectedPlayers = mlGetExpectedPlayerCount($pdo);
    $playlistBuildMode = mlGetPlaylistBuildMode($pdo);
    $generatedAny = false;

    foreach ($presentedRounds as $round) {
        if (($round['round_state'] ?? '') !== 'submission') {
            continue;
        }

        $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
        if ($seasonRoundId <= 0) {
            continue;
        }

        $playlistRecord = mlGetRoundPlaylistRecord($pdo, $seasonRoundId, true);
        $songSubmissionCount = mlFetchSongSubmissionCount($pdo, $seasonRoundId);

        if (!mlRoundEligibleForAutomaticPlaylistBuild($round, $playlistRecord, $songSubmissionCount, $expectedPlayers, $now, $playlistBuildMode)) {
            continue;
        }

        try {
            mlGeneratePlaylistForRound($pdo, $round, false);
            $generatedAny = true;
        } catch (Throwable $e) {
            $_SESSION['ml_playlist_auto_error'] = $e->getMessage();
        }
    }

    return $generatedAny;
}


function mlHandleManualPlaylistTrigger(PDO $pdo, array $presentedRounds): array {
    foreach ($presentedRounds as $round) {
        if (($round['round_state'] ?? '') !== 'submission') {
            continue;
        }

        $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
        if ($seasonRoundId <= 0) {
            continue;
        }

        $existingPlaylist = mlGetRoundPlaylistRecord($pdo, $seasonRoundId);
        $roundTitle = trim((string)($round['Title'] ?? 'Round ' . (int)$round['RoundNumber']));

        if (!empty($existingPlaylist)) {
            return [
                'title' => $roundTitle,
                'already_generated' => true,
            ];
        }

        if (empty($round['can_manual_generate_playlist'])) {
            continue;
        }

        mlGeneratePlaylistForRound($pdo, $round, true);

        return [
            'title' => $roundTitle,
            'already_generated' => false,
        ];
    }

    throw new RuntimeException('No round is ready for a manual playlist build yet.');
}

function mlBuildPlaylistPreview(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId): array {
    static $cache = [];

    $cacheKey = $seasonId . ':' . $seasonRoundId . ':' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $entries = [];

    if (mlTableExists($pdo, 'ML_RoundSongs')) {
        try {
            $profileSelect = mlUsersHasProfileImageColumn($pdo) ? ', u.ProfileImageFilename' : ', NULL AS ProfileImageFilename';
            $songCommentSelect = mlRoundSongsHasSongCommentColumn($pdo) ? ', rs.SongComment' : ', NULL AS SongComment';
            $playlistPositionSelect = ', NULL AS PlaylistPosition';
            $playlistJoin = '';
            $orderBy = 'rs.RoundSongID ASC';

            if (mlTableExists($pdo, 'ML_RoundPlaylistItems')) {
                $playlistPositionSelect = ', rpi.PlaylistPosition';
                $playlistJoin = 'LEFT JOIN ML_RoundPlaylistItems rpi ON rpi.SeasonRoundID = rs.SeasonRoundID AND rpi.RoundSongID = rs.RoundSongID';
                $orderBy = 'CASE WHEN rpi.PlaylistPosition IS NULL THEN 1 ELSE 0 END ASC, rpi.PlaylistPosition ASC, rs.RoundSongID ASC';
            }

            $stmt = $pdo->prepare("
                SELECT rs.RoundSongID, rs.UserID, rs.SpotifyTrackID, rs.SpotifyURI, rs.TrackName, rs.ArtistName, rs.AlbumName, rs.ArtworkURL{$songCommentSelect},
                       u.UserName{$profileSelect}{$playlistPositionSelect}
                FROM ML_RoundSongs rs
                LEFT JOIN ML_Users u ON rs.UserID = u.UserID
                {$playlistJoin}
                WHERE rs.SeasonRoundID = ?
                ORDER BY {$orderBy}
            ");
            $stmt->execute([$seasonRoundId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $entries[] = [
                    'entry_id' => 'entry_' . (int)$row['RoundSongID'],
                    'round_song_id' => (int)$row['RoundSongID'],
                    'user_id' => (int)$row['UserID'],
                    'user_name' => (string)($row['UserName'] ?? ('User ' . $row['UserID'])),
                    'track_id' => (string)($row['SpotifyTrackID'] ?? ''),
                    'title' => (string)$row['TrackName'],
                    'artist' => (string)$row['ArtistName'],
                    'album' => (string)($row['AlbumName'] ?? ''),
                    'artwork' => (string)($row['ArtworkURL'] ?? ''),
                    'uri' => (string)($row['SpotifyURI'] ?? ''),
                    'comment' => trim((string)($row['SongComment'] ?? '')),
                    'playlist_position' => isset($row['PlaylistPosition']) ? (int)$row['PlaylistPosition'] : 0,
                    'is_current_user_song' => ((int)$row['UserID'] === $currentUserId),
                    'profile_image_path' => mlGetUserProfilePath((int)$row['UserID'], $row['ProfileImageFilename'] ?? null),
                ];
            }
            if (!empty($entries)) {
                $cache[$cacheKey] = $entries;
                return $entries;
            }
        } catch (Throwable $e) {
            // Fall through to preview mode.
        }
    }

    $users = mlLoadAllUsers($pdo);
    $tracks = mlDemoTrackLibrary();
    $trackCount = count($tracks);
    $seed = max(1, $seasonRoundId);
    $index = 0;

    foreach ($users as $user) {
        $userId = (int)$user['UserID'];
        $savedSong = mlGetRoundSongDraft($pdo, $userId, $seasonId, $seasonRoundId);
        if (!empty($savedSong)) {
            $track = $savedSong;
        } else {
            $track = $tracks[($seed + $index) % $trackCount];
        }

        $entries[] = [
            'entry_id' => 'preview_' . $seasonRoundId . '_' . $userId,
            'round_song_id' => 0,
            'user_id' => $userId,
            'user_name' => (string)$user['UserName'],
            'profile_image_path' => (string)($user['profile_image_path'] ?? mlGetUserProfilePath($userId, $user['ProfileImageFilename'] ?? null)),
            'track_id' => (string)($track['id'] ?? ''),
            'title' => (string)($track['title'] ?? ''),
            'artist' => (string)($track['artist'] ?? ''),
            'album' => (string)($track['album'] ?? ''),
            'artwork' => (string)($track['artwork'] ?? ''),
            'uri' => (string)($track['uri'] ?? ''),
            'is_current_user_song' => ($userId === $currentUserId),
        ];
        $index++;
    }

    $cache[$cacheKey] = $entries;
    return $entries;
}

function mlBuildVotingBallot(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId): array {
    $playlistEntries = mlBuildPlaylistPreview($pdo, $seasonId, $seasonRoundId, $currentUserId);
    $ballot = [];

    foreach ($playlistEntries as $entry) {
        $entry['can_score'] = empty($entry['is_current_user_song']);
        $ballot[] = $entry;
    }

    return $ballot;
}

function mlCompareRoundStandingEntries(array $a, array $b): int {
    $pointsA = (int)($a['points'] ?? 0);
    $pointsB = (int)($b['points'] ?? 0);
    if ($pointsA !== $pointsB) {
        return ($pointsA > $pointsB) ? -1 : 1;
    }

    $votersA = (int)($a['voter_count'] ?? 0);
    $votersB = (int)($b['voter_count'] ?? 0);
    if ($votersA !== $votersB) {
        return ($votersA > $votersB) ? -1 : 1;
    }

    $userIdA = (int)($a['user_id'] ?? 0);
    $userIdB = (int)($b['user_id'] ?? 0);
    if ($userIdA !== $userIdB) {
        return ($userIdA > $userIdB) ? -1 : 1;
    }

    return 0;
}

function mlCompareOverallStandingsEntries(array $a, array $b): int {
    $pointsA = (int)($a['points'] ?? 0);
    $pointsB = (int)($b['points'] ?? 0);
    if ($pointsA !== $pointsB) {
        return ($pointsA > $pointsB) ? -1 : 1;
    }

    $votersA = (int)($a['positive_voter_total'] ?? 0);
    $votersB = (int)($b['positive_voter_total'] ?? 0);
    if ($votersA !== $votersB) {
        return ($votersA > $votersB) ? -1 : 1;
    }

    $userIdA = (int)($a['user_id'] ?? 0);
    $userIdB = (int)($b['user_id'] ?? 0);
    if ($userIdA !== $userIdB) {
        return ($userIdA > $userIdB) ? -1 : 1;
    }

    return 0;
}

function mlBuildStandingsDataFromClosedRounds(PDO $pdo, array $closedRounds, int $currentUserId, bool $includeRoundBreakdown = true): array {
    $users = mlLoadAllUsers($pdo);
    $playerStats = [];

    foreach ($users as $user) {
        $userId = (int)$user['UserID'];
        $playerStats[$userId] = [
            'user_id' => $userId,
            'user_name' => (string)$user['UserName'],
            'profile_image_path' => (string)($user['profile_image_path'] ?? mlGetUserProfilePath($userId, $user['ProfileImageFilename'] ?? null)),
            'points' => 0,
            'round_wins' => 0,
            'total_voters' => 0,
            'podiums' => 0,
            'best_round_score' => 0,
            'holdouts' => 0,
            'positive_voter_total' => 0,
            'is_current_user' => ($userId === $currentUserId),
        ];
    }

    $result = [
        'standings' => [],
        'round_breakdown' => [],
        'closed_round_count' => count($closedRounds),
    ];

    if (empty($closedRounds) || !mlTableExists($pdo, 'ML_RoundSongs') || !mlTableExists($pdo, 'ML_RoundVotes')) {
        foreach ($playerStats as $row) {
            $result['standings'][] = $row;
        }
        usort($result['standings'], 'mlCompareOverallStandingsEntries');
        $rank = 1;
        foreach ($result['standings'] as &$row) {
            $row['rank'] = $rank;
            $rank++;
        }
        unset($row);
        return $result;
    }

    $closedRoundIds = array_keys($closedRounds);
    $placeholders = implode(',', array_fill(0, count($closedRoundIds), '?'));

    $roundSongStats = [];
    try {
        $sql = "
            SELECT rs.SeasonRoundID,
                   rs.RoundSongID,
                   rs.UserID,
                   COALESCE(SUM(rv.Score), 0) AS TotalPoints,
                   COUNT(DISTINCT CASE WHEN rv.Score > 0 THEN rv.VoterUserID END) AS PositiveVoterCount
            FROM ML_RoundSongs rs
            LEFT JOIN ML_RoundVotes rv
              ON rv.RoundSongID = rs.RoundSongID
             AND rv.SeasonRoundID = rs.SeasonRoundID
            WHERE rs.SeasonRoundID IN ($placeholders)
            GROUP BY rs.SeasonRoundID, rs.RoundSongID, rs.UserID
            ORDER BY rs.SeasonRoundID ASC, rs.RoundSongID ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($closedRoundIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $seasonRoundId = (int)$row['SeasonRoundID'];
            $ownerUserId = (int)$row['UserID'];
            $entry = [
                'season_round_id' => $seasonRoundId,
                'round_song_id' => (int)$row['RoundSongID'],
                'user_id' => $ownerUserId,
                'points' => (int)$row['TotalPoints'],
                'voter_count' => (int)$row['PositiveVoterCount'],
                'voter_ids' => [],
            ];
            $roundSongStats[$seasonRoundId][$ownerUserId] = $entry;

            if (isset($playerStats[$ownerUserId])) {
                $playerStats[$ownerUserId]['points'] += $entry['points'];
                $playerStats[$ownerUserId]['positive_voter_total'] += $entry['voter_count'];
                $playerStats[$ownerUserId]['total_voters'] += $entry['voter_count'];
                if ($entry['points'] > $playerStats[$ownerUserId]['best_round_score']) {
                    $playerStats[$ownerUserId]['best_round_score'] = $entry['points'];
                }
            }
        }
    } catch (Throwable $e) {
        foreach ($playerStats as $row) {
            $result['standings'][] = $row;
        }
        usort($result['standings'], 'mlCompareOverallStandingsEntries');
        $rank = 1;
        foreach ($result['standings'] as &$row) {
            $row['rank'] = $rank;
            $rank++;
        }
        unset($row);
        return $result;
    }

    try {
        $voterSql = "
            SELECT rv.SeasonRoundID, rv.RoundSongID, rv.VoterUserID
            FROM ML_RoundVotes rv
            WHERE rv.SeasonRoundID IN ($placeholders)
              AND rv.Score > 0
            ORDER BY rv.SeasonRoundID ASC, rv.RoundSongID ASC, rv.VoterUserID ASC
        ";
        $stmt = $pdo->prepare($voterSql);
        $stmt->execute($closedRoundIds);
        $voterRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($voterRows as $row) {
            $seasonRoundId = (int)$row['SeasonRoundID'];
            $roundSongId = (int)$row['RoundSongID'];
            $voterUserId = (int)$row['VoterUserID'];

            if (empty($roundSongStats[$seasonRoundId])) {
                continue;
            }

            foreach ($roundSongStats[$seasonRoundId] as $ownerUserId => &$songEntry) {
                if ((int)$songEntry['round_song_id'] !== $roundSongId) {
                    continue;
                }
                $songEntry['voter_ids'][$voterUserId] = true;
                break;
            }
            unset($songEntry);
        }
    } catch (Throwable $e) {
        // Leave voter_ids empty if this query fails.
    }

    $roundBreakdown = [];
    foreach ($closedRounds as $seasonRoundId => $round) {
        $submittedEntries = [];
        $playerCells = [];

        foreach ($playerStats as $userId => $_unused) {
            $entry = $roundSongStats[$seasonRoundId][$userId] ?? null;
            $points = $entry ? (int)$entry['points'] : null;
            $voterCount = $entry ? (int)$entry['voter_count'] : 0;
            $playerCells[$userId] = [
                'user_id' => $userId,
                'points' => $points,
                'voter_count' => $voterCount,
                'is_winner' => false,
            ];

            if ($entry !== null) {
                $submittedEntries[] = [
                    'user_id' => $userId,
                    'points' => (int)$entry['points'],
                    'voter_count' => (int)$entry['voter_count'],
                ];
            }
        }

        if (!empty($submittedEntries)) {
            usort($submittedEntries, 'mlCompareRoundStandingEntries');

            $winnerUserId = (int)$submittedEntries[0]['user_id'];
            if (isset($playerStats[$winnerUserId])) {
                $playerStats[$winnerUserId]['round_wins'] += 1;
            }
            if (isset($playerCells[$winnerUserId])) {
                $playerCells[$winnerUserId]['is_winner'] = true;
            }

            $podiumCount = min(3, count($submittedEntries));
            for ($i = 0; $i < $podiumCount; $i++) {
                $podiumUserId = (int)$submittedEntries[$i]['user_id'];
                if (isset($playerStats[$podiumUserId])) {
                    $playerStats[$podiumUserId]['podiums'] += 1;
                }
            }

            foreach ($submittedEntries as $entry) {
                $ownerUserId = (int)$entry['user_id'];
                $songEntry = $roundSongStats[$seasonRoundId][$ownerUserId] ?? null;
                if ($songEntry === null) {
                    continue;
                }

                if ((int)$songEntry['voter_count'] < 9) {
                    continue;
                }

                $voterIds = $songEntry['voter_ids'] ?? [];
                foreach ($playerStats as $candidateUserId => &$candidateStats) {
                    if ((int)$candidateUserId === $ownerUserId) {
                        continue;
                    }
                    if (isset($voterIds[$candidateUserId])) {
                        continue;
                    }
                    $candidateStats['holdouts'] += 1;
                }
                unset($candidateStats);
            }
        }

        if ($includeRoundBreakdown) {
            $roundBreakdown[] = [
                'season_round_id' => $seasonRoundId,
                'round_number' => (int)($round['RoundNumber'] ?? 0),
                'title' => (string)($round['Title'] ?? ('Round ' . (int)($round['RoundNumber'] ?? 0))),
                'players' => $playerCells,
            ];
        }
    }

    $standings = array_values($playerStats);
    usort($standings, 'mlCompareOverallStandingsEntries');

    $rank = 1;
    foreach ($standings as &$row) {
        $row['rank'] = $rank;
        $rank++;
    }
    unset($row);

    return [
        'standings' => $standings,
        'round_breakdown' => $roundBreakdown,
        'closed_round_count' => count($closedRounds),
    ];
}

function mlBuildStandingsData(PDO $pdo, int $seasonId, int $currentUserId): array {
    static $cache = [];

    $cacheKey = 'season:' . $seasonId . ':' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $rounds = mlLoadSeasonRoundsForGameplay($pdo, $seasonId);
    $presentedRounds = mlComputeRoundPresentation($pdo, $rounds, $currentUserId);
    $closedRounds = [];
    foreach ($presentedRounds as $round) {
        if (($round['round_state'] ?? '') === 'closed') {
            $closedRounds[(int)$round['SeasonRoundID']] = $round;
        }
    }

    $cache[$cacheKey] = mlBuildStandingsDataFromClosedRounds($pdo, $closedRounds, $currentUserId, true);
    return $cache[$cacheKey];
}

function mlBuildAllTimeStandingsData(PDO $pdo, int $currentUserId): array {
    static $cache = [];

    $cacheKey = 'all:' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $seasonList = mlLoadSeasonSummaries($pdo);
    $closedRounds = [];

    foreach ($seasonList as $seasonRow) {
        $seasonId = (int)($seasonRow['SeasonID'] ?? 0);
        if ($seasonId <= 0 || (int)($seasonRow['RoundCount'] ?? 0) <= 0) {
            continue;
        }

        $rounds = mlLoadSeasonRoundsForGameplay($pdo, $seasonId);
        $presentedRounds = mlComputeRoundPresentation($pdo, $rounds, $currentUserId);
        foreach ($presentedRounds as $round) {
            if (($round['round_state'] ?? '') === 'closed') {
                $closedRounds[(int)$round['SeasonRoundID']] = $round;
            }
        }
    }

    if (!empty($closedRounds)) {
        uasort($closedRounds, static function (array $a, array $b): int {
            $seasonComparison = ((int)($a['SeasonID'] ?? 0)) <=> ((int)($b['SeasonID'] ?? 0));
            if ($seasonComparison !== 0) {
                return $seasonComparison;
            }

            $roundComparison = ((int)($a['RoundNumber'] ?? 0)) <=> ((int)($b['RoundNumber'] ?? 0));
            if ($roundComparison !== 0) {
                return $roundComparison;
            }

            return ((int)($a['SeasonRoundID'] ?? 0)) <=> ((int)($b['SeasonRoundID'] ?? 0));
        });
    }

    $cache[$cacheKey] = mlBuildStandingsDataFromClosedRounds($pdo, $closedRounds, $currentUserId, false);
    return $cache[$cacheKey];
}

function mlBuildStandingsPreview(PDO $pdo, int $seasonId, int $currentUserId): array {
    $data = mlBuildStandingsData($pdo, $seasonId, $currentUserId);
    return $data['standings'] ?? [];
}

function mlBuildStandingsBreakdown(PDO $pdo, int $seasonId, int $currentUserId): array {
    $data = mlBuildStandingsData($pdo, $seasonId, $currentUserId);
    return $data['round_breakdown'] ?? [];
}

function mlRoundIsFinishedForDisplay(array $round): bool {
    if (($round['status_key'] ?? '') === 'closed') {
        return true;
    }

    $expectedPlayers = (int)($round['expected_players'] ?? 0);
    $voteSubmissionCount = (int)($round['vote_submission_count'] ?? 0);

    return (($round['round_state'] ?? '') === 'voting')
        && $expectedPlayers > 0
        && $voteSubmissionCount >= $expectedPlayers;
}

function mlBuildRoundPodium(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId): array {
    static $cache = [];

    $cacheKey = $seasonId . ':' . $seasonRoundId . ':' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $results = mlBuildRoundResultsPreview($pdo, $seasonId, $seasonRoundId, $currentUserId);
    if (empty($results)) {
        $cache[$cacheKey] = [];
        return [];
    }

    $podium = [];
    $places = ['1st', '2nd', '3rd'];

    foreach ($results as $result) {
        $entry = $result['entry'] ?? [];
        $userId = (int)($entry['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (isset($podium[$userId])) {
            continue;
        }

        $podium[$userId] = [
            'user_id' => $userId,
            'user_name' => (string)($entry['user_name'] ?? ''),
            'profile_image_path' => (string)($entry['profile_image_path'] ?? mlGetUserProfilePath($userId)),
            'place_label' => $places[count($podium)] ?? '',
            'total_score' => (int)($result['total_score'] ?? 0),
        ];

        if (count($podium) >= 3) {
            break;
        }
    }

    $cache[$cacheKey] = array_values($podium);
    return $cache[$cacheKey];
}

function mlBuildRoundResultsPreview(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId): array {
    static $cache = [];

    $cacheKey = $seasonId . ':' . $seasonRoundId . ':' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $playlistEntries = mlBuildPlaylistPreview($pdo, $seasonId, $seasonRoundId, $currentUserId);
    if (empty($playlistEntries)) {
        $cache[$cacheKey] = [];
        return [];
    }

    $voteDraftMap = [];
    if (mlTableExists($pdo, 'ML_RoundVotes')) {
        try {
            $stmt = $pdo->prepare("\n                SELECT rv.RoundSongID, rv.VoterUserID, rv.Score, rv.Comment, u.UserName, u.ProfileImageFilename\n                FROM ML_RoundVotes rv\n                LEFT JOIN ML_Users u ON rv.VoterUserID = u.UserID\n                WHERE rv.SeasonRoundID = ?\n                ORDER BY rv.RoundSongID ASC, rv.VoterUserID ASC\n            ");
            $stmt->execute([$seasonRoundId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $entryId = 'entry_' . (int)$row['RoundSongID'];
                if (!isset($voteDraftMap[$entryId])) {
                    $voteDraftMap[$entryId] = [];
                }
                $voteDraftMap[$entryId][] = [
                    'voter_user_id' => (int)$row['VoterUserID'],
                    'voter_name' => (string)($row['UserName'] ?? ('User ' . $row['VoterUserID'])),
                    'score' => (int)$row['Score'],
                    'comment' => trim((string)($row['Comment'] ?? '')),
                    'profile_image_path' => mlGetUserProfilePath((int)$row['VoterUserID'], $row['ProfileImageFilename'] ?? null),
                ];
            }
        } catch (Throwable $e) {
            $voteDraftMap = [];
        }
    }

    $results = [];
    foreach ($playlistEntries as $entry) {
        $votes = $voteDraftMap[$entry['entry_id']] ?? [];
        usort($votes, function ($a, $b) {
            $scoreA = (int)($a['score'] ?? 0);
            $scoreB = (int)($b['score'] ?? 0);
            if ($scoreA !== $scoreB) {
                return ($scoreA > $scoreB) ? -1 : 1;
            }

            $hasCommentA = trim((string)($a['comment'] ?? '')) !== '';
            $hasCommentB = trim((string)($b['comment'] ?? '')) !== '';
            if ($hasCommentA !== $hasCommentB) {
                return $hasCommentA ? -1 : 1;
            }

            return strcasecmp((string)($a['voter_name'] ?? ''), (string)($b['voter_name'] ?? ''));
        });

        $totalScore = 0;
        $positiveVoterCount = 0;
        foreach ($votes as $vote) {
            $voteScore = (int)($vote['score'] ?? 0);
            $totalScore += $voteScore;
            if ($voteScore > 0) {
                $positiveVoterCount++;
            }
        }
        $voteCount = count($votes);

        $results[] = [
            'entry' => $entry,
            'total_score' => $totalScore,
            'positive_voter_count' => $positiveVoterCount,
            'average_score' => $voteCount > 0 ? ($totalScore / $voteCount) : 0,
            'vote_breakdown' => $votes,
        ];
    }

    usort($results, function ($a, $b) {
        $scoreA = (int)($a['total_score'] ?? 0);
        $scoreB = (int)($b['total_score'] ?? 0);
        if ($scoreA !== $scoreB) {
            return ($scoreA > $scoreB) ? -1 : 1;
        }

        $votersA = (int)($a['positive_voter_count'] ?? 0);
        $votersB = (int)($b['positive_voter_count'] ?? 0);
        if ($votersA !== $votersB) {
            return ($votersA > $votersB) ? -1 : 1;
        }

        $userIdA = (int)($a['entry']['user_id'] ?? 0);
        $userIdB = (int)($b['entry']['user_id'] ?? 0);
        if ($userIdA !== $userIdB) {
            return ($userIdA > $userIdB) ? -1 : 1;
        }

        return 0;
    });

    $cache[$cacheKey] = $results;
    return $results;
}
