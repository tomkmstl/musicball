<?php
require_once __DIR__ . '/ml_gameplay.php';
require_once __DIR__ . '/spotify_client.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentUserId = (int)$currentUser['UserID'];

$seasonStmt = $pdo->query("SELECT SeasonID, SeasonName, IsActive FROM ML_Seasons ORDER BY IsActive DESC, SeasonID DESC");
$seasonRows = $seasonStmt ? $seasonStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$roundStmt = $pdo->query("SELECT SeasonRoundID, SeasonID, RoundNumber, Title FROM ML_SeasonRounds ORDER BY SeasonID DESC, RoundNumber ASC");
$allRounds = $roundStmt ? $roundStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$selectedScope = isset($_POST['test_scope']) ? trim((string)$_POST['test_scope']) : 'round';
$selectedSeasonId = isset($_POST['season_id']) ? (int)$_POST['season_id'] : (!empty($seasonRows) ? (int)$seasonRows[0]['SeasonID'] : 0);
$seasonRounds = [];
foreach ($allRounds as $roundRow) {
    if ((int)$roundRow['SeasonID'] === $selectedSeasonId) {
        $seasonRounds[] = $roundRow;
    }
}
$selectedSeasonRoundId = isset($_POST['season_round_id']) ? (int)$_POST['season_round_id'] : (!empty($seasonRounds) ? (int)$seasonRounds[0]['SeasonRoundID'] : 0);
$selectedRound = null;
foreach ($seasonRounds as $roundRow) {
    if ((int)$roundRow['SeasonRoundID'] === $selectedSeasonRoundId) {
        $selectedRound = $roundRow;
        break;
    }
}
if ($selectedRound === null && !empty($seasonRounds)) {
    $selectedRound = $seasonRounds[0];
    $selectedSeasonRoundId = (int)$selectedRound['SeasonRoundID'];
}
$selectedSeasonName = '';
foreach ($seasonRows as $seasonRow) {
    if ((int)$seasonRow['SeasonID'] === $selectedSeasonId) {
        $selectedSeasonName = trim((string)$seasonRow['SeasonName']);
        break;
    }
}

$spotifyConfigured = mlSpotifyAppConfigured();
$spotifyConnected = $spotifyConfigured && mlSpotifyIsConnected($pdo);
$message = '';
$error = '';
$checkResult = null;
$selectedTrack = [
    'id' => trim((string)($_POST['track_id'] ?? '')),
    'uri' => trim((string)($_POST['track_uri'] ?? '')),
    'title' => trim((string)($_POST['track_title'] ?? '')),
    'artist' => trim((string)($_POST['track_artist'] ?? '')),
    'album' => trim((string)($_POST['track_album'] ?? '')),
    'artwork' => trim((string)($_POST['track_artwork'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['song_test_action']) && (string)$_POST['song_test_action'] === 'run_duplicate_check') {
    if ($selectedTrack['id'] === '' || $selectedTrack['uri'] === '' || $selectedTrack['title'] === '' || $selectedTrack['artist'] === '') {
        $error = 'Pick a Spotify song from the search results before running the test.';
    } elseif ($selectedScope === 'round' && $selectedSeasonRoundId <= 0) {
        $error = 'Choose a valid round before testing.';
    } else {
        $roundDuplicate = null;
        if ($selectedScope === 'round') {
            $roundDuplicate = mlFindCurrentRoundSongDuplicate($pdo, $selectedSeasonRoundId, $currentUserId, $selectedTrack['id'], $selectedTrack['title'], $selectedTrack['artist']);
        }

        if (is_array($roundDuplicate) && !empty($roundDuplicate)) {
            $checkResult = ['outcome' => 'blocked_round_duplicate', 'round_duplicate' => $roundDuplicate, 'history_duplicate' => null];
            $error = 'That song has already been chosen for this round. Pick a different song.';
        } else {
            $historyDuplicate = null;
            if ($selectedScope === 'all') {
                $historyDuplicate = mlFindHistoricalSongDuplicate($pdo, -999999, -999999, $selectedTrack['id'], $selectedTrack['title'], $selectedTrack['artist']);
            } elseif ($selectedScope === 'season') {
                foreach ($seasonRounds as $roundRow) {
                    $historyDuplicate = mlFindHistoricalSongDuplicate($pdo, (int)$roundRow['SeasonRoundID'], -999999, $selectedTrack['id'], $selectedTrack['title'], $selectedTrack['artist']);
                    if (is_array($historyDuplicate) && !empty($historyDuplicate)) {
                        break;
                    }
                }
            } else {
                $historyDuplicate = mlFindHistoricalSongDuplicate($pdo, $selectedSeasonRoundId, $currentUserId, $selectedTrack['id'], $selectedTrack['title'], $selectedTrack['artist']);
            }

            if (is_array($historyDuplicate) && !empty($historyDuplicate)) {
                $checkResult = ['outcome' => 'warn_league_duplicate', 'round_duplicate' => null, 'history_duplicate' => $historyDuplicate];
                $message = 'The song you are selecting matches something that has already been submitted! In the live app, the user would be warned and allowed to proceed or cancel.';
            } else {
                $checkResult = ['outcome' => 'clean', 'round_duplicate' => null, 'history_duplicate' => null];
                $message = 'No duplicate was found. In the live app, this song would be allowed to save.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Song Test</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide game-card-narrow">
        <div class="game-page-topline">
            <div class="song-page-intro">
                <div class="home-shell-kicker">Duplicate checker</div>
                <h1>song_test.php</h1>
                <h3>Use this page to test duplicate logic without saving anything.</h3>
            </div>
        </div>
        <?php if ($message !== ''): ?><div class="status-banner success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="status-banner error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <section class="admin-panel admin-panel-full">
            <div class="home-shell-kicker">What to test against</div>
            <form method="post" action="song_test.php" id="song_test_scope_form" class="song-comment-form">
                <input type="hidden" name="track_id" id="scope_track_id" value="<?= htmlspecialchars($selectedTrack['id']) ?>">
                <input type="hidden" name="track_uri" id="scope_track_uri" value="<?= htmlspecialchars($selectedTrack['uri']) ?>">
                <input type="hidden" name="track_title" id="scope_track_title" value="<?= htmlspecialchars($selectedTrack['title']) ?>">
                <input type="hidden" name="track_artist" id="scope_track_artist" value="<?= htmlspecialchars($selectedTrack['artist']) ?>">
                <input type="hidden" name="track_album" id="scope_track_album" value="<?= htmlspecialchars($selectedTrack['album']) ?>">
                <input type="hidden" name="track_artwork" id="scope_track_artwork" value="<?= htmlspecialchars($selectedTrack['artwork']) ?>">

                <label class="admin-label" for="test_scope">Check Mode</label>
                <select name="test_scope" id="test_scope" class="admin-select" onchange="this.form.submit()">
                    <option value="round" <?= $selectedScope === 'round' ? 'selected' : '' ?>>Specific round</option>
                    <option value="season" <?= $selectedScope === 'season' ? 'selected' : '' ?>>Whole season</option>
                    <option value="all" <?= $selectedScope === 'all' ? 'selected' : '' ?>>All songs</option>
                </select>

                <label class="admin-label" for="season_id">Season</label>
                <select name="season_id" id="season_id" class="admin-select" onchange="this.form.submit()" <?= $selectedScope === 'all' ? 'disabled' : '' ?>>
                    <?php foreach ($seasonRows as $seasonRow): ?>
                        <option value="<?= (int)$seasonRow['SeasonID'] ?>" <?= (int)$seasonRow['SeasonID'] === $selectedSeasonId ? 'selected' : '' ?>><?= htmlspecialchars($seasonRow['SeasonName']) ?><?= (int)$seasonRow['IsActive'] === 1 ? ' (active)' : '' ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="admin-label" for="season_round_id">Round</label>
                <select name="season_round_id" id="season_round_id" class="admin-select" onchange="this.form.submit()" <?= $selectedScope !== 'round' ? 'disabled' : '' ?>>
                    <?php foreach ($seasonRounds as $roundRow): ?>
                        <option value="<?= (int)$roundRow['SeasonRoundID'] ?>" <?= (int)$roundRow['SeasonRoundID'] === $selectedSeasonRoundId ? 'selected' : '' ?>>Round <?= (int)$roundRow['RoundNumber'] ?> - <?= htmlspecialchars($roundRow['Title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if ($selectedScope === 'all'): ?>
                <div class="note">Testing against all songs in the database.</div>
            <?php elseif ($selectedScope === 'season'): ?>
                <div class="note">Testing against all songs in <?= htmlspecialchars($selectedSeasonName !== '' ? $selectedSeasonName : ('Season ' . $selectedSeasonId)) ?>.</div>
            <?php elseif ($selectedRound): ?>
                <div class="note">Testing against Round <?= (int)$selectedRound['RoundNumber'] ?> - <?= htmlspecialchars($selectedRound['Title']) ?>.</div>
            <?php endif; ?>
        </section>

        <section class="admin-panel admin-panel-full song-search-shell">
            <div class="home-shell-kicker">Spotify search</div>
            <h2>Find a song</h2>
            <?php if (!$spotifyConfigured): ?>
                <p>Spotify is not configured in the app yet. Add your Spotify client ID and secret to <code>config/spotify_config.php</code>.</p>
            <?php elseif (!$spotifyConnected): ?>
                <p>Spotify is not connected yet. Ask the admin to connect the playlist account in Settings before searching.</p>
            <?php else: ?>
                <p>Search Spotify and click a result to run the duplicate test. This page does not save anything.</p>
                <div class="song-search-form-live">
                    <div>
                        <label for="song_query" class="game-visually-hidden">Search for a song or paste a Spotify URL</label>
                        <input type="text" id="song_query" class="admin-input song-search-input" placeholder="Search Spotify or paste a Spotify track link" autocomplete="off">
                    </div>
                    <button type="button" class="button-primary song-search-submit" onclick="document.getElementById('song_query').focus();">Search</button>
                </div>
                <div id="spotify_search_status" class="spotify-search-status muted"></div>
                <div id="spotify_search_results" class="spotify-search-results"></div>
                <form method="post" action="song_test.php" id="spotify_track_save_form" class="spotify-track-save-form">
                    <input type="hidden" name="song_test_action" value="run_duplicate_check">
                    <input type="hidden" name="test_scope" value="<?= htmlspecialchars($selectedScope) ?>">
                    <input type="hidden" name="season_id" value="<?= (int)$selectedSeasonId ?>">
                    <input type="hidden" name="season_round_id" value="<?= (int)$selectedSeasonRoundId ?>">
                    <input type="hidden" name="track_id" id="selected_track_id" value="<?= htmlspecialchars($selectedTrack['id']) ?>">
                    <input type="hidden" name="track_uri" id="selected_track_uri" value="<?= htmlspecialchars($selectedTrack['uri']) ?>">
                    <input type="hidden" name="track_title" id="selected_track_title" value="<?= htmlspecialchars($selectedTrack['title']) ?>">
                    <input type="hidden" name="track_artist" id="selected_track_artist" value="<?= htmlspecialchars($selectedTrack['artist']) ?>">
                    <input type="hidden" name="track_album" id="selected_track_album" value="<?= htmlspecialchars($selectedTrack['album']) ?>">
                    <input type="hidden" name="track_artwork" id="selected_track_artwork" value="<?= htmlspecialchars($selectedTrack['artwork']) ?>">
                </form>
            <?php endif; ?>
        </section>

        <?php if (!empty($selectedTrack['id'])): ?>
            <section class="admin-panel admin-panel-full song-current-pick-panel">
                <div class="home-shell-kicker">Selected track</div>
                <div class="song-selected-card">
                    <?php if ($selectedTrack['artwork'] !== ''): ?><img src="<?= htmlspecialchars($selectedTrack['artwork']) ?>" alt="Album art" class="song-artwork-large"><?php else: ?><div class="song-artwork-large song-artwork-fallback" aria-hidden="true"></div><?php endif; ?>
                    <div>
                        <div class="song-card-title"><?= htmlspecialchars($selectedTrack['title']) ?></div>
                        <div class="song-card-meta"><?= htmlspecialchars($selectedTrack['artist']) ?><?php if ($selectedTrack['album'] !== ''): ?> &middot; <?= htmlspecialchars($selectedTrack['album']) ?><?php endif; ?></div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if (is_array($checkResult)): ?>
            <section class="admin-panel admin-panel-full">
                <div class="home-shell-kicker">What the app would do</div>
                <div class="song-comment-form">
                    <?php if ($checkResult['outcome'] === 'blocked_round_duplicate'): ?>
                        <div class="song-card-title">Result: blocked</div>
                        <div class="note">Same-round duplicate found. Existing user: <?= htmlspecialchars((string)$checkResult['round_duplicate']['UserName']) ?>. Match method: <?= htmlspecialchars((string)$checkResult['round_duplicate']['MatchType']) ?>.</div>
                    <?php elseif ($checkResult['outcome'] === 'warn_league_duplicate'): ?>
                        <div class="song-card-title">Result: warning only</div>
                        <div class="note">Existing user: <?= htmlspecialchars((string)$checkResult['history_duplicate']['UserName']) ?>. Found in <?= htmlspecialchars((string)$checkResult['history_duplicate']['SeasonName']) ?> / Round <?= htmlspecialchars((string)$checkResult['history_duplicate']['RoundNumber']) ?>. Match method: <?= htmlspecialchars((string)$checkResult['history_duplicate']['MatchType']) ?>.</div>
                    <?php else: ?>
                        <div class="song-card-title">Result: clean</div>
                        <div class="note">No duplicate was found for the selected scope.</div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php if ($spotifyConfigured && $spotifyConnected): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('song_query');
    var resultsWrap = document.getElementById('spotify_search_results');
    var resultsStatus = document.getElementById('spotify_search_status');
    var saveForm = document.getElementById('spotify_track_save_form');
    if (!searchInput || !resultsWrap || !resultsStatus || !saveForm) {
        return;
    }
    var trackIdField = document.getElementById('selected_track_id');
    var trackUriField = document.getElementById('selected_track_uri');
    var trackTitleField = document.getElementById('selected_track_title');
    var trackArtistField = document.getElementById('selected_track_artist');
    var trackAlbumField = document.getElementById('selected_track_album');
    var trackArtworkField = document.getElementById('selected_track_artwork');
    var scopeTrackIdField = document.getElementById('scope_track_id');
    var scopeTrackUriField = document.getElementById('scope_track_uri');
    var scopeTrackTitleField = document.getElementById('scope_track_title');
    var scopeTrackArtistField = document.getElementById('scope_track_artist');
    var scopeTrackAlbumField = document.getElementById('scope_track_album');
    var scopeTrackArtworkField = document.getElementById('scope_track_artwork');
    var activeRequest = 0;
    var debounceTimer = null;
    function setStatus(message, type) {
        resultsStatus.textContent = message || '';
        resultsStatus.className = 'spotify-search-status' + (type ? ' ' + type : '');
    }
    function clearResults() { resultsWrap.innerHTML = ''; }
    function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function renderTracks(tracks) {
        clearResults();
        if (!tracks || !tracks.length) {
            setStatus('No Spotify results matched that search.', 'muted');
            return;
        }
        setStatus('Click a result to run the duplicate test.', 'muted');
        tracks.forEach(function (track) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'spotify-search-result';
            button.innerHTML = '<span class="spotify-search-result-art-wrap">' + (track.artwork ? '<img src="' + escapeHtml(track.artwork) + '" alt="Album art" class="spotify-search-result-art">' : '<span class="spotify-search-result-art spotify-search-result-art-fallback"></span>') + '</span><span class="spotify-search-result-copy"><span class="spotify-search-result-title">' + escapeHtml(track.title) + '</span><span class="spotify-search-result-meta">' + escapeHtml(track.artist) + ' · ' + escapeHtml(track.album) + '</span></span><span class="spotify-search-result-action">Test</span>';
            button.addEventListener('click', function () {
                trackIdField.value = track.id || '';
                trackUriField.value = track.uri || '';
                trackTitleField.value = track.title || '';
                trackArtistField.value = track.artist || '';
                trackAlbumField.value = track.album || '';
                trackArtworkField.value = track.artwork || '';
                if (scopeTrackIdField) scopeTrackIdField.value = track.id || '';
                if (scopeTrackUriField) scopeTrackUriField.value = track.uri || '';
                if (scopeTrackTitleField) scopeTrackTitleField.value = track.title || '';
                if (scopeTrackArtistField) scopeTrackArtistField.value = track.artist || '';
                if (scopeTrackAlbumField) scopeTrackAlbumField.value = track.album || '';
                if (scopeTrackArtworkField) scopeTrackArtworkField.value = track.artwork || '';
                setStatus('Track selected. Running duplicate check...', 'muted');
                saveForm.submit();
            });
            resultsWrap.appendChild(button);
        });
    }
    function runSearch() {
        var query = searchInput.value.trim();
        if (query.length < 2) {
            clearResults();
            setStatus(query.length === 0 ? 'Start typing to search Spotify.' : 'Keep typing to narrow the results.', 'muted');
            return;
        }
        activeRequest += 1;
        var requestId = activeRequest;
        setStatus('Searching Spotify...', 'muted');
        fetch('spotify_search.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function (response) { return response.json().then(function (data) { return { status: response.status, data: data }; }); })
            .then(function (payload) {
                if (requestId !== activeRequest) { return; }
                if (!payload.data || !payload.data.ok) {
                    clearResults();
                    setStatus((payload.data && payload.data.error) ? payload.data.error : 'Spotify search could not be completed.', 'error');
                    return;
                }
                renderTracks(payload.data.tracks || []);
            })
            .catch(function () {
                if (requestId !== activeRequest) { return; }
                clearResults();
                setStatus('Spotify search could not be completed right now.', 'error');
            });
    }
    searchInput.addEventListener('input', function () { window.clearTimeout(debounceTimer); debounceTimer = window.setTimeout(runSearch, 260); });
    searchInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); window.clearTimeout(debounceTimer); runSearch(); } });
    setStatus('Start typing to search Spotify.', 'muted');
});
</script>
<?php endif; ?>
</body>
</html>
