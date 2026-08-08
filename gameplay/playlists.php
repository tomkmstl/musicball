<?php
// gameplay/playlists.php
// Spotify playlist, aggregate playlist, and playlist-generation helpers.

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
    require_once __DIR__ . '/../integrations/spotify/client.php';

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
          AND (
              sr.RoundState = 'closed'
              OR (sr.VotesDue IS NOT NULL AND sr.VotesDue < UTC_TIMESTAMP())
          )
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
    require_once __DIR__ . '/../integrations/spotify/client.php';

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
function mlGetPlaylistWaitFallbackAt(array $round): ?DateTimeImmutable {
    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? (string)$round['SongsDue'] : null);
    $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? (string)$round['VotesDue'] : null);
    if (!$songsDue instanceof DateTimeImmutable || !$votesDue instanceof DateTimeImmutable) {
        return null;
    }

    $fallbackAt = $votesDue->modify('-12 hours');
    return $fallbackAt < $songsDue ? $songsDue : $fallbackAt;
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
    require_once __DIR__ . '/../integrations/spotify/client.php';
    require_once __DIR__ . '/../integrations/discord/discord.php';

    $seasonRoundId = (int)$round['SeasonRoundID'];
    $roundSeasonId = (int)($round['SeasonID'] ?? 0);
    if (!mlSeasonIsActiveForGameplay($pdo, $roundSeasonId)) {
        throw new RuntimeException('Playlists can only be generated for the active season.');
    }
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
function mlNotifySongPhaseClosedBestEffort(PDO $pdo, array $round): void {
    try {
        require_once __DIR__ . '/../integrations/push/push.php';
        mlPushSendIncompletePhaseClosed($pdo, $round, 'song');
    } catch (Throwable $e) {
        // Never interrupt playlist generation or the admin response for push failures.
    }
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
        if (empty($round['season_is_active'])) {
            continue;
        }
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
            mlNotifySongPhaseClosedBestEffort($pdo, $round);
            $generatedAny = true;
        } catch (Throwable $e) {
            $_SESSION['ml_playlist_auto_error'] = $e->getMessage();
        }
    }

    return $generatedAny;
}
function mlHandleManualPlaylistTrigger(PDO $pdo, array $presentedRounds): array {
    foreach ($presentedRounds as $round) {
        if (empty($round['season_is_active'])) {
            continue;
        }
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
        mlNotifySongPhaseClosedBestEffort($pdo, $round);

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
