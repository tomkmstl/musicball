<?php
require_once __DIR__ . '/client.php';
require_once dirname(__DIR__, 2) . '/gameplay/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $currentUser = mlRequireAuthenticatedUser($pdo);
    $query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

    if ($query === '') {
        echo json_encode([
            'ok' => true,
            'tracks' => [],
        ]);
        exit;
    }

    if (!mlSpotifyAppConfigured()) {
        throw new RuntimeException('Spotify has not been configured in the app yet.');
    }

    if (!mlSpotifyIsConnected($pdo)) {
        throw new RuntimeException('Spotify is not connected yet. Ask the admin to connect the playlist account in Settings.');
    }

    $tracks = mlSpotifySearchTracks($pdo, $query, 8);

    echo json_encode([
        'ok' => true,
        'tracks' => $tracks,
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
