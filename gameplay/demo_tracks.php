<?php
// gameplay/demo_tracks.php
// Fallback demo track library helpers.

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
