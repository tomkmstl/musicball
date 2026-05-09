<?php
require_once dirname(__DIR__, 2) . '/config.php';

function mlSpotifyConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
        'account_key' => 'playlist_owner',
        'default_market' => 'US',
        'search_limit' => 8,
        'scopes' => [
            'playlist-modify-private',
            'playlist-modify-public',
            'user-read-private',
        ],
    ];

    $configFile = dirname(__DIR__, 2) . '/config/spotify_config.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $config = array_merge($defaults, $loaded);
        } else {
            $config = $defaults;
        }
    } else {
        $config = $defaults;
    }

    $config['client_id'] = trim((string)$config['client_id']);
    $config['client_secret'] = trim((string)$config['client_secret']);
    $config['redirect_uri'] = trim((string)$config['redirect_uri']);
    $config['account_key'] = trim((string)$config['account_key']) !== '' ? trim((string)$config['account_key']) : 'playlist_owner';
    $config['default_market'] = trim((string)$config['default_market']) !== '' ? strtoupper(trim((string)$config['default_market'])) : 'US';
    $config['search_limit'] = max(1, min(10, (int)$config['search_limit']));

    if (!isset($config['scopes']) || !is_array($config['scopes'])) {
        $config['scopes'] = $defaults['scopes'];
    }

    $cleanScopes = [];
    foreach ($config['scopes'] as $scope) {
        $scope = trim((string)$scope);
        if ($scope !== '') {
            $cleanScopes[] = $scope;
        }
    }
    $config['scopes'] = array_values(array_unique($cleanScopes));

    return $config;
}

function mlSpotifyAppConfigured(): bool
{
    $config = mlSpotifyConfig();

    return $config['client_id'] !== ''
        && $config['client_secret'] !== ''
        && $config['redirect_uri'] !== ''
        && strpos($config['client_id'], 'PUT_YOUR_') !== 0
        && strpos($config['client_secret'], 'PUT_YOUR_') !== 0;
}

function mlSpotifyAccountKey(): string
{
    $config = mlSpotifyConfig();
    return $config['account_key'];
}

function mlSpotifyTokenTableExists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query('SELECT DATABASE()');
        $databaseName = (string)$stmt->fetchColumn();

        if ($databaseName === '') {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = ?
              AND table_name = ?
        ");
        $stmt->execute([$databaseName, 'ML_SpotifyTokens']);

        return ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

function mlSpotifyConnectionRow(PDO $pdo): ?array
{
    if (!mlSpotifyTokenTableExists($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM ML_SpotifyTokens WHERE AccountKey = ? LIMIT 1');
    $stmt->execute([mlSpotifyAccountKey()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function mlSpotifyIsConnected(PDO $pdo): bool
{
    $row = mlSpotifyConnectionRow($pdo);
    return is_array($row) && trim((string)($row['RefreshToken'] ?? '')) !== '';
}

function mlSpotifyBuildAuthorizeUrl(bool $showDialog = true): string
{
    if (!mlSpotifyAppConfigured()) {
        throw new RuntimeException('Spotify is not configured yet. Add your client ID and client secret first.');
    }

    $config = mlSpotifyConfig();
    $state = bin2hex(random_bytes(16));
    $_SESSION['ml_spotify_oauth_state'] = $state;

    $query = http_build_query([
        'client_id' => $config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $config['redirect_uri'],
        'state' => $state,
        'scope' => implode(' ', $config['scopes']),
        'show_dialog' => $showDialog ? 'true' : 'false',
    ]);

    return 'https://accounts.spotify.com/authorize?' . $query;
}

function mlSpotifyHttpRequest(string $method, string $url, array $headers = [], array $body = [], bool $formEncoded = false): array
{
    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Could not initialize the Spotify request.');
    }

    $method = strtoupper($method);
    $curlHeaders = [];
    foreach ($headers as $headerName => $headerValue) {
        $curlHeaders[] = $headerName . ': ' . $headerValue;
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_TIMEOUT => 20,
    ];

    if (!empty($body)) {
        if ($formEncoded) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($body);
        } else {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    }

    curl_setopt_array($ch, $options);
    $rawBody = curl_exec($ch);

    if ($rawBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Spotify request failed: ' . $error);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'status_code' => $statusCode,
        'body' => $decoded,
        'raw_body' => $rawBody,
    ];
}

function mlSpotifyStoreTokenRow(PDO $pdo, array $payload, array $profile = []): array
{
	if (!mlSpotifyTokenTableExists($pdo)) {
		$databaseName = '';
		try {
			$stmt = $pdo->query('SELECT DATABASE()');
			$databaseName = (string)$stmt->fetchColumn();
		} catch (Throwable $inner) {
			$databaseName = '';
		}

		$message = 'The ML_SpotifyTokens table was not found in the database the app is currently using';
		if ($databaseName !== '') {
			$message .= ': ' . $databaseName;
		}
		$message .= '.';

		throw new RuntimeException($message);
	}

    $accountKey = mlSpotifyAccountKey();
    $accessToken = trim((string)($payload['access_token'] ?? ''));
    $refreshToken = trim((string)($payload['refresh_token'] ?? ''));
    $scope = trim((string)($payload['scope'] ?? ''));
    $expiresIn = max(1, (int)($payload['expires_in'] ?? 3600));
    $tokenExpiresAt = gmdate('Y-m-d H:i:s', time() + $expiresIn - 60);

    if ($accessToken === '') {
        throw new RuntimeException('Spotify did not return an access token.');
    }

    $existing = mlSpotifyConnectionRow($pdo);
    if ($refreshToken === '' && is_array($existing)) {
        $refreshToken = trim((string)($existing['RefreshToken'] ?? ''));
    }

    $spotifyUserId = trim((string)($profile['id'] ?? ($existing['SpotifyUserID'] ?? '')));
    $spotifyDisplayName = trim((string)($profile['display_name'] ?? ($existing['SpotifyDisplayName'] ?? '')));

    $stmt = $pdo->prepare(
        'INSERT INTO ML_SpotifyTokens (AccountKey, SpotifyUserID, SpotifyDisplayName, AccessToken, RefreshToken, Scope, TokenExpiresAt)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            SpotifyUserID = VALUES(SpotifyUserID),
            SpotifyDisplayName = VALUES(SpotifyDisplayName),
            AccessToken = VALUES(AccessToken),
            RefreshToken = VALUES(RefreshToken),
            Scope = VALUES(Scope),
            TokenExpiresAt = VALUES(TokenExpiresAt),
            UpdatedAt = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        $accountKey,
        $spotifyUserId,
        $spotifyDisplayName,
        $accessToken,
        $refreshToken,
        $scope,
        $tokenExpiresAt,
    ]);

    return mlSpotifyConnectionRow($pdo) ?? [];
}

function mlSpotifyGetCurrentProfileFromAccessToken(string $accessToken): array
{
    $response = mlSpotifyHttpRequest('GET', 'https://api.spotify.com/v1/me', [
        'Authorization' => 'Bearer ' . $accessToken,
    ]);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        return [];
    }

    return is_array($response['body']) ? $response['body'] : [];
}

function mlSpotifyExchangeCodeForToken(PDO $pdo, string $code): array
{
    if (!mlSpotifyAppConfigured()) {
        throw new RuntimeException('Spotify is not configured yet. Add your client ID and client secret first.');
    }

    $config = mlSpotifyConfig();
    $authHeader = base64_encode($config['client_id'] . ':' . $config['client_secret']);
    $response = mlSpotifyHttpRequest('POST', 'https://accounts.spotify.com/api/token', [
        'Authorization' => 'Basic ' . $authHeader,
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
    ], true);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        $message = trim((string)($response['body']['error_description'] ?? $response['body']['error'] ?? 'Spotify token exchange failed.'));
        throw new RuntimeException($message);
    }

    $payload = is_array($response['body']) ? $response['body'] : [];
    $profile = mlSpotifyGetCurrentProfileFromAccessToken((string)($payload['access_token'] ?? ''));

    return mlSpotifyStoreTokenRow($pdo, $payload, $profile);
}

function mlSpotifyRefreshAccessToken(PDO $pdo): array
{
    $existing = mlSpotifyConnectionRow($pdo);
    if (!is_array($existing)) {
        throw new RuntimeException('Spotify is not connected yet.');
    }

    $refreshToken = trim((string)($existing['RefreshToken'] ?? ''));
    if ($refreshToken === '') {
        throw new RuntimeException('The saved Spotify connection does not have a refresh token. Reconnect the account.');
    }

    $config = mlSpotifyConfig();
    $authHeader = base64_encode($config['client_id'] . ':' . $config['client_secret']);
    $response = mlSpotifyHttpRequest('POST', 'https://accounts.spotify.com/api/token', [
        'Authorization' => 'Basic ' . $authHeader,
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
    ], true);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        $message = trim((string)($response['body']['error_description'] ?? $response['body']['error'] ?? 'Spotify token refresh failed.'));
        throw new RuntimeException($message);
    }

    $payload = is_array($response['body']) ? $response['body'] : [];
    if (!isset($payload['refresh_token']) || trim((string)$payload['refresh_token']) === '') {
        $payload['refresh_token'] = $refreshToken;
    }
    if (!isset($payload['scope']) || trim((string)$payload['scope']) === '') {
        $payload['scope'] = (string)($existing['Scope'] ?? '');
    }

    $profile = mlSpotifyGetCurrentProfileFromAccessToken((string)($payload['access_token'] ?? ''));

    return mlSpotifyStoreTokenRow($pdo, $payload, $profile);
}

function mlSpotifyGetValidAccessToken(PDO $pdo): string
{
    $existing = mlSpotifyConnectionRow($pdo);
    if (!is_array($existing)) {
        throw new RuntimeException('Spotify is not connected yet.');
    }

    $expiresAt = trim((string)($existing['TokenExpiresAt'] ?? ''));
    $needsRefresh = true;
    if ($expiresAt !== '') {
        $expiryTimestamp = strtotime($expiresAt . ' UTC');
        if ($expiryTimestamp !== false && $expiryTimestamp > time() + 30) {
            $needsRefresh = false;
        }
    }

    if ($needsRefresh) {
        $existing = mlSpotifyRefreshAccessToken($pdo);
    }

    $accessToken = trim((string)($existing['AccessToken'] ?? ''));
    if ($accessToken === '') {
        throw new RuntimeException('No Spotify access token is stored.');
    }

    return $accessToken;
}

function mlSpotifyDisconnect(PDO $pdo): void
{
    if (!mlSpotifyTokenTableExists($pdo)) {
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM ML_SpotifyTokens WHERE AccountKey = ?');
    $stmt->execute([mlSpotifyAccountKey()]);
}

function mlSpotifyApiRequest(PDO $pdo, string $method, string $endpoint, array $query = [], array $body = [], bool $retryOnUnauthorized = true): array
{
    $accessToken = mlSpotifyGetValidAccessToken($pdo);
    $url = 'https://api.spotify.com/v1' . $endpoint;
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $headers = [
        'Authorization' => 'Bearer ' . $accessToken,
    ];
    if (!empty($body)) {
        $headers['Content-Type'] = 'application/json';
    }

    $response = mlSpotifyHttpRequest($method, $url, $headers, $body, false);

    if ($response['status_code'] === 401 && $retryOnUnauthorized) {
        mlSpotifyRefreshAccessToken($pdo);
        return mlSpotifyApiRequest($pdo, $method, $endpoint, $query, $body, false);
    }

    return $response;
}

function mlSpotifyNormalizeTrackItem(array $track): array
{
    $artistNames = [];
    if (isset($track['artists']) && is_array($track['artists'])) {
        foreach ($track['artists'] as $artist) {
            $name = trim((string)($artist['name'] ?? ''));
            if ($name !== '') {
                $artistNames[] = $name;
            }
        }
    }

    $artwork = '';
    if (isset($track['album']['images']) && is_array($track['album']['images'])) {
        foreach ($track['album']['images'] as $image) {
            if (!empty($image['url'])) {
                $artwork = (string)$image['url'];
                break;
            }
        }
    }

    return [
        'id' => (string)($track['id'] ?? ''),
        'uri' => (string)($track['uri'] ?? ''),
        'title' => (string)($track['name'] ?? ''),
        'artist' => !empty($artistNames) ? implode(', ', $artistNames) : '',
        'album' => (string)($track['album']['name'] ?? ''),
        'artwork' => $artwork,
        'external_url' => (string)($track['external_urls']['spotify'] ?? ''),
    ];
}

function mlSpotifyExtractTrackId(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('~spotify:track:([A-Za-z0-9]+)~', $value, $matches)) {
        return (string)$matches[1];
    }

    if (preg_match('~open\.spotify\.com/track/([A-Za-z0-9]+)~', $value, $matches)) {
        return (string)$matches[1];
    }

    if (preg_match('~^[A-Za-z0-9]{10,}$~', $value)) {
        return $value;
    }

    return '';
}

function mlSpotifyGetTrackById(PDO $pdo, string $trackId): ?array
{
    $trackId = trim($trackId);
    if ($trackId === '') {
        return null;
    }

    $response = mlSpotifyApiRequest($pdo, 'GET', '/tracks/' . rawurlencode($trackId), [], []);
    if ($response['status_code'] < 200 || $response['status_code'] >= 300 || !is_array($response['body'])) {
        return null;
    }

    return mlSpotifyNormalizeTrackItem($response['body']);
}

function mlSpotifySearchTracks(PDO $pdo, string $query, int $limit = 8): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $directTrackId = mlSpotifyExtractTrackId($query);
    if ($directTrackId !== '') {
        $track = mlSpotifyGetTrackById($pdo, $directTrackId);
        return $track ? [$track] : [];
    }

    $config = mlSpotifyConfig();
    $limit = max(1, min(10, $limit > 0 ? $limit : (int)$config['search_limit']));

    $response = mlSpotifyApiRequest($pdo, 'GET', '/search', [
        'q' => $query,
        'type' => 'track',
        'limit' => $limit,
        'market' => $config['default_market'],
    ], []);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        $message = trim((string)($response['body']['error']['message'] ?? 'Spotify search failed.'));
        throw new RuntimeException($message);
    }

    $items = $response['body']['tracks']['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $results = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $results[] = mlSpotifyNormalizeTrackItem($item);
        }
    }

    return $results;
}

function mlSpotifyConnectionSummary(PDO $pdo): array
{
    $row = mlSpotifyConnectionRow($pdo);
    if (!is_array($row)) {
        return [
            'is_connected' => false,
            'display_name' => '',
            'spotify_user_id' => '',
            'updated_at' => '',
        ];
    }

    return [
        'is_connected' => true,
        'display_name' => (string)($row['SpotifyDisplayName'] ?? ''),
        'spotify_user_id' => (string)($row['SpotifyUserID'] ?? ''),
        'updated_at' => (string)($row['UpdatedAt'] ?? ''),
    ];
}

function mlSpotifyCreatePlaylist(PDO $pdo, string $name, string $description, bool $isPublic = false): array
{
    $response = mlSpotifyApiRequest($pdo, 'POST', '/me/playlists', [], [
        'name' => $name,
        'description' => $description,
        'public' => $isPublic,
    ]);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        $message = trim((string)($response['body']['error']['message'] ?? 'Spotify playlist creation failed.'));

        if ((int)$response['status_code'] === 403 && $message === 'Forbidden') {
            $message = 'Spotify refused playlist creation. Reconnect Spotify from Admin and try again.';
        }

        throw new RuntimeException($message);
    }

    return [
        'playlist_id' => (string)($response['body']['id'] ?? ''),
        'playlist_url' => (string)($response['body']['external_urls']['spotify'] ?? ''),
        'name' => (string)($response['body']['name'] ?? $name),
    ];
}

function mlSpotifyAddItemsToPlaylist(PDO $pdo, string $playlistId, array $uris): void
{
    $uris = array_values(array_filter(array_map('strval', $uris), static function ($value) {
        return trim($value) !== '';
    }));

    if ($playlistId === '' || empty($uris)) {
        return;
    }

    $uriChunks = array_chunk($uris, 100);
    foreach ($uriChunks as $uriChunk) {
        $response = mlSpotifyApiRequest($pdo, 'POST', '/playlists/' . rawurlencode($playlistId) . '/items', [], [
            'uris' => array_values($uriChunk),
        ]);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            $message = trim((string)($response['body']['error']['message'] ?? 'Spotify playlist update failed.'));
            throw new RuntimeException($message);
        }
    }
}

function mlSpotifyGetPlaylistItems(PDO $pdo, string $playlistId): array
{
    $playlistId = trim($playlistId);
    if ($playlistId === '') {
        return [];
    }

    $items = [];
    $offset = 0;
    $limit = 100;

    do {
        $response = mlSpotifyApiRequest($pdo, 'GET', '/playlists/' . rawurlencode($playlistId) . '/items', [
            'limit' => $limit,
            'offset' => $offset,
            'fields' => 'snapshot_id,total,items(track(uri,name,artists(name)))',
        ], []);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            $message = trim((string)($response['body']['error']['message'] ?? 'Spotify playlist item lookup failed.'));
            throw new RuntimeException($message);
        }

        $bodyItems = $response['body']['items'] ?? [];
        if (!is_array($bodyItems)) {
            break;
        }

        foreach ($bodyItems as $item) {
            $items[] = $item;
        }

        $total = (int)($response['body']['total'] ?? count($items));
        $offset += $limit;
    } while ($offset < $total);

    return $items;
}

function mlSpotifyRemovePlaylistItemAtPosition(PDO $pdo, string $playlistId, string $spotifyUri, int $position): void
{
    $playlistId = trim($playlistId);
    $spotifyUri = trim($spotifyUri);

    if ($playlistId === '' || $spotifyUri === '' || $position < 0) {
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
        $message = trim((string)($response['body']['error']['message'] ?? 'Spotify playlist item removal failed.'));
        throw new RuntimeException($message);
    }
}
