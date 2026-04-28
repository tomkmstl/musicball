<?php
require_once __DIR__ . '/spotify_client.php';
require_once __DIR__ . '/ml_gameplay.php';

header('Content-Type: text/plain; charset=UTF-8');

$spotifyValue = $_GET['track'] ?? '';

$trackId = mlSpotifyExtractTrackId($spotifyValue);
if ($trackId === '') {
    http_response_code(400);
    exit('Invalid Spotify track value');
}

$track = mlSpotifyGetTrackById($pdo, $trackId);
if (!$track || empty($track['artwork'])) {
    http_response_code(404);
    exit('Artwork not found');
}

echo $track['artwork'];