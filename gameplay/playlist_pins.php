<?php
// gameplay/playlist_pins.php
// Per-user playlist shortcuts shown on a season page.

function mlPlaylistPinsTableAvailable(PDO $pdo): bool
{
    static $availability = [];

    $isQaMode = function_exists('mlIsQaMode') && mlIsQaMode();
    $physicalTableName = $isQaMode
        ? 'QA_ML_UserSeasonPlaylistPins'
        : 'ML_UserSeasonPlaylistPins';
    $cacheKey = ($isQaMode ? 'qa:' : 'live:') . $physicalTableName;

    if (array_key_exists($cacheKey, $availability)) {
        return $availability[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$physicalTableName]);
        $availability[$cacheKey] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $availability[$cacheKey] = false;
    }

    return $availability[$cacheKey];
}

function mlPlaylistPinServiceLabel(string $serviceKey): string
{
    $labels = [
        'spotify' => 'Spotify',
        'apple_music' => 'Apple Music',
        'youtube_music' => 'YouTube Music',
        'tidal' => 'TIDAL',
        'soundcloud' => 'SoundCloud',
        'deezer' => 'Deezer',
    ];

    return $labels[$serviceKey] ?? 'playlist';
}

function mlInvalidPlaylistPin(string $message): array
{
    return [
        'valid' => false,
        'url' => '',
        'service_key' => '',
        'service_label' => '',
        'error' => $message,
    ];
}

function mlValidPlaylistPin(string $url, string $serviceKey): array
{
    return [
        'valid' => true,
        'url' => $url,
        'service_key' => $serviceKey,
        'service_label' => mlPlaylistPinServiceLabel($serviceKey),
        'error' => '',
    ];
}

function mlValidatePlaylistPinUrl(string $input): array
{
    $input = trim($input);
    $genericError = 'Enter a secure, direct playlist link beginning with https://.';

    if (
        $input === ''
        || strlen($input) > 2048
        || preg_match('/[\x00-\x1F\x7F]/', $input)
        || filter_var($input, FILTER_VALIDATE_URL) === false
    ) {
        return mlInvalidPlaylistPin($genericError);
    }

    $parts = parse_url($input);
    if (!is_array($parts)) {
        return mlInvalidPlaylistPin($genericError);
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');

    if (
        $scheme !== 'https'
        || $host === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)
        || strpos($path, '\\') !== false
        || preg_match('/%(?:2f|5c)/i', $path)
        || preg_match('/%(?![A-Fa-f0-9]{2})/', $path)
    ) {
        return mlInvalidPlaylistPin($genericError);
    }

    if ($host === 'open.spotify.com') {
        if (!preg_match('~^/(?:intl-[a-z]{2,3}(?:-[a-z]{2})?/)?playlist/([A-Za-z0-9]{10,64})/?$~i', $path, $matches)) {
            return mlInvalidPlaylistPin('This looks like a Spotify link, but it is not a direct playlist link.');
        }

        return mlValidPlaylistPin('https://open.spotify.com/playlist/' . $matches[1], 'spotify');
    }

    if ($host === 'music.apple.com') {
        if (!preg_match('~^/(?:[a-z]{2}(?:-[a-z]{2})?/)?playlist/[A-Za-z0-9._\~%+\-]+/(pl\.[A-Za-z0-9._\-]+|p\.[A-Za-z0-9._\-]+)/?$~i', $path)) {
            return mlInvalidPlaylistPin('This looks like an Apple Music link, but it is not a direct playlist link.');
        }

        return mlValidPlaylistPin('https://music.apple.com/' . ltrim($path, '/'), 'apple_music');
    }

    if (in_array($host, ['youtube.com', 'www.youtube.com', 'music.youtube.com'], true)) {
        if ($path !== '/playlist') {
            return mlInvalidPlaylistPin('This looks like a YouTube link, but it is not a direct playlist link.');
        }

        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        $playlistId = isset($query['list']) && is_string($query['list']) ? $query['list'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{10,128}$/', $playlistId)) {
            return mlInvalidPlaylistPin('This looks like a YouTube link, but it is not a direct playlist link.');
        }

        $canonicalHost = $host === 'music.youtube.com' ? 'music.youtube.com' : 'www.youtube.com';
        return mlValidPlaylistPin('https://' . $canonicalHost . '/playlist?list=' . $playlistId, 'youtube_music');
    }

    if (in_array($host, ['tidal.com', 'listen.tidal.com'], true)) {
        if (!preg_match('~^/(?:browse/)?playlist/([A-Za-z0-9-]{10,128})/?$~i', $path, $matches)) {
            return mlInvalidPlaylistPin('This looks like a TIDAL link, but it is not a direct playlist link.');
        }

        return mlValidPlaylistPin('https://tidal.com/browse/playlist/' . $matches[1], 'tidal');
    }

    if (in_array($host, ['soundcloud.com', 'www.soundcloud.com'], true)) {
        if (!preg_match('~^/([A-Za-z0-9_-]+)/sets/([A-Za-z0-9_-]+)(?:/(s-[A-Za-z0-9_-]+))?/?$~i', $path, $matches)) {
            return mlInvalidPlaylistPin('This looks like a SoundCloud link, but it is not a direct playlist link.');
        }

        $canonicalUrl = 'https://soundcloud.com/' . $matches[1] . '/sets/' . $matches[2];
        if (!empty($matches[3])) {
            $canonicalUrl .= '/' . $matches[3];
        }

        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        $secretToken = isset($query['secret_token']) && is_string($query['secret_token'])
            ? $query['secret_token']
            : '';
        if (array_key_exists('secret_token', $query)) {
            if ($secretToken === '' || !preg_match('/^s-[A-Za-z0-9_-]{6,128}$/', $secretToken)) {
                return mlInvalidPlaylistPin('This SoundCloud playlist has an invalid private-share token.');
            }

            $canonicalUrl .= '?secret_token=' . rawurlencode($secretToken);
        }

        return mlValidPlaylistPin($canonicalUrl, 'soundcloud');
    }

    if (in_array($host, ['deezer.com', 'www.deezer.com'], true)) {
        if (!preg_match('~^/(?:[a-z]{2}/)?playlist/([0-9]{3,24})/?$~i', $path, $matches)) {
            return mlInvalidPlaylistPin('This looks like a Deezer link, but it is not a direct playlist link.');
        }

        return mlValidPlaylistPin('https://www.deezer.com/playlist/' . $matches[1], 'deezer');
    }

    return mlInvalidPlaylistPin(
        'That playlist service is not supported. Try Spotify, Apple Music, YouTube Music, TIDAL, SoundCloud, or Deezer.'
    );
}

function mlLoadUserPrivatePlaylist(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !mlPlaylistPinsTableAvailable($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT ServiceKey, PlaylistURL, CreatedAt, UpdatedAt
        FROM ML_UserSeasonPlaylistPins
        WHERE UserID = ?
          AND SeasonID = 0
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['ServiceLabel'] = mlPlaylistPinServiceLabel((string)($row['ServiceKey'] ?? ''));
    return $row;
}

function mlSaveUserPrivatePlaylist(PDO $pdo, int $userId, array $playlist): void
{
    $stmt = $pdo->prepare("
        INSERT INTO ML_UserSeasonPlaylistPins (UserID, SeasonID, ServiceKey, PlaylistURL)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ServiceKey = VALUES(ServiceKey),
            PlaylistURL = VALUES(PlaylistURL),
            UpdatedAt = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $userId,
        0,
        (string)$playlist['service_key'],
        (string)$playlist['url'],
    ]);
}

function mlDeleteUserPrivatePlaylist(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('DELETE FROM ML_UserSeasonPlaylistPins WHERE UserID = ? AND SeasonID = 0');
    $stmt->execute([$userId]);
}
