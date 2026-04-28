<?php
require_once __DIR__ . '/ml_gameplay.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'playlists';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $playlistAction = trim((string)($_POST['playlist_action'] ?? ''));

    try {
        $playlistUrl = '';

        if ($playlistAction === 'go_to_scone_ghetto') {
            $syncResult = mlCreateOrSyncSconeGhettoPlaylist($pdo);
            $playlistUrl = trim((string)($syncResult['SpotifyPlaylistURL'] ?? ''));

            if ($playlistUrl === '') {
                throw new RuntimeException('Spotify did not return a playlist URL for Scone Ghetto.');
            }
        } elseif ($playlistAction === 'go_to_player_playlist') {
            $playerUserId = (int)($_POST['playlist_user_id'] ?? 0);
            $syncResult = mlCreateOrSyncPlayerSongsPlaylist($pdo, $playerUserId);
            $playlistUrl = trim((string)($syncResult['SpotifyPlaylistURL'] ?? ''));
            $playerName = trim((string)($syncResult['PlaylistName'] ?? 'that playlist'));

            if ($playlistUrl === '') {
                throw new RuntimeException('Spotify did not return a playlist URL for ' . $playerName . '.');
            }
        }

        if ($playlistUrl !== '') {
            header('Location: ' . $playlistUrl);
            exit;
        }
    } catch (Throwable $e) {
        header('Location: playlists.php?status=error&message=' . rawurlencode(trim((string)$e->getMessage())));
        exit;
    }
}

$statusType = trim((string)($_GET['status'] ?? ''));
$statusMessage = trim((string)($_GET['message'] ?? ''));
if (!in_array($statusType, ['success', 'error'], true)) {
    $statusType = '';
}
if ($statusMessage === '') {
    $statusType = '';
}

$hasRequiredTables = (
    mlTableExists($pdo, 'ML_RoundPlaylists') &&
    mlTableExists($pdo, 'ML_RoundPlaylistItems') &&
    mlTableExists($pdo, 'ML_SeasonRounds') &&
    mlTableExists($pdo, 'ML_Users')
);

$sconeSongCount = 0;
$sconePlaylistRecord = [];
$sconePlaylistUrl = '';
$players = [];

if ($hasRequiredTables) {
    $sconePlaylistRecord = mlGetAggregatePlaylistRecord($pdo, 'all_time', null, true);
    $sconePlaylistUrl = trim((string)($sconePlaylistRecord['SpotifyPlaylistURL'] ?? ''));
    $lastSourceRoundPlaylistId = (int)($sconePlaylistRecord['LastSourceRoundPlaylistID'] ?? 0);

    $sconeStmt = $pdo->query(
        "SELECT COUNT(*)
        FROM ML_RoundPlaylistItems rpi
        INNER JOIN ML_RoundPlaylists rp ON rp.RoundPlaylistID = rpi.RoundPlaylistID"
    );
    $sconeSongCount = $sconeStmt ? (int)$sconeStmt->fetchColumn() : 0;

    $usersStmt = $pdo->query(
        "SELECT UserID, UserName, ProfileImageFilename
        FROM ML_Users
        ORDER BY UserID ASC"
    );
    $allUsers = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($allUsers as $userRow) {
        $userId = (int)$userRow['UserID'];
        $playerPlaylistRecord = mlGetAggregatePlaylistRecord($pdo, 'player', $userId, true);
        $players[$userId] = [
            'user_id' => $userId,
            'user_name' => (string)$userRow['UserName'],
            'profile_image_path' => mlGetUserProfilePath($userId, $userRow['ProfileImageFilename'] ?? null),
            'song_count' => 0,
            'playlist_url' => trim((string)($playerPlaylistRecord['SpotifyPlaylistURL'] ?? ''))
        ];
    }

    $playerCountsStmt = $pdo->query(
        "SELECT rpi.UserID, COUNT(*) AS SongCount
        FROM ML_RoundPlaylistItems rpi
        INNER JOIN ML_RoundPlaylists rp ON rp.RoundPlaylistID = rpi.RoundPlaylistID
        GROUP BY rpi.UserID
        ORDER BY rpi.UserID ASC"
    );
    $playerCounts = $playerCountsStmt ? $playerCountsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($playerCounts as $countRow) {
        $userId = (int)$countRow['UserID'];
        if (!isset($players[$userId])) {
            continue;
        }

        $players[$userId]['song_count'] = (int)$countRow['SongCount'];
    }
}

$playlistImageSrc = 'images/playlist.png';
$playlistImagePath = __DIR__ . '/images/playlist.png';
$hasPlaylistImage = is_file($playlistImagePath);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Playlists</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper playlists-page">
    <div class="card game-card game-card-wide">
        <div class="game-page-topline">
            <div class="game-page-intro">
                <div class="home-shell-kicker">Playlists</div>
                <h1 class="game-page-title"><?= htmlspecialchars(mlGetLeagueName($pdo)) ?></h1>
            </div>
        </div>

        <?php if ($statusType !== '' && $statusMessage !== ''): ?>
            <div class="status-banner <?= $statusType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($statusMessage) ?></div>
        <?php endif; ?>

        <?php if (!$hasRequiredTables): ?>
            <div class="status-banner error">The playlists page could not load because one or more required Musicball tables are missing.</div>
        <?php elseif ($sconeSongCount === 0): ?>
            <div class="status-banner">No generated round playlists exist yet, so there is nothing to preview here yet.</div>
        <?php else: ?>
            <div class="playlist-section">
                <article class="playlist-overview-card">
                    <div class="playlist-card-main">
                        <div class="playlist-card-copy">
                            <div class="home-shell-kicker">All-time league playlist</div>
                            <h2 class="playlist-card-title">Scone Ghetto</h2>
                            <div class="playlist-card-subtitle"><?= (int)$sconeSongCount ?> song<?= $sconeSongCount === 1 ? '' : 's' ?></div>
                            <p>Every song from every generated round playlist, in league order from the first eligible round to the latest eligible round.</p>
                        </div>

                        <div class="playlist-card-cta-wrap">
                            <form method="post" target="_blank" class="playlist-cta-form">
                                <input type="hidden" name="playlist_action" value="go_to_scone_ghetto">
                                <button type="submit" class="mb-next-season-link playlist-cta-link playlist-cta-button" aria-label="Go to Scone Ghetto on Spotify">
                                    <?php if ($hasPlaylistImage): ?>
                                        <img src="<?= htmlspecialchars(mlAssetUrl($playlistImageSrc)) ?>" alt="Go to playlist" class="playlist-image">
                                    <?php else: ?>
                                        <span class="mb-next-season-text">Playlist</span>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </article>
            </div>

            <div class="playlist-section">
                <div class="playlist-section-heading-wrap">
                    <div>
                        <h2>Player Playlists</h2>
                    </div>
                </div>

                <div class="player-playlist-grid">
                    <?php foreach ($players as $player): ?>
                        <article class="player-playlist-card">
                            <div class="playlist-card-main playlist-card-main-player">
                                <div class="player-playlist-user">
                                    <img src="<?= htmlspecialchars($player['profile_image_path']) ?>" alt="<?= htmlspecialchars($player['user_name']) ?>" class="profile-avatar profile-avatar-result-submitter">
                                    <div class="playlist-card-copy">
                                        <h3 class="player-playlist-title"><?= htmlspecialchars($player['user_name']) ?>&#039;s Songs</h3>
                                        <div class="playlist-card-subtitle"><?= (int)$player['song_count'] ?> song<?= (int)$player['song_count'] === 1 ? '' : 's' ?></div>
                                    </div>
                                </div>

                                <div class="playlist-card-cta-wrap">
                                    <form method="post" target="_blank" class="playlist-cta-form">
                                        <input type="hidden" name="playlist_action" value="go_to_player_playlist">
                                        <input type="hidden" name="playlist_user_id" value="<?= (int)$player['user_id'] ?>">
                                        <button type="submit" class="mb-next-season-link playlist-cta-link playlist-cta-button" aria-label="Go to <?= htmlspecialchars($player['user_name']) ?>'s playlist on Spotify">
                                            <?php if ($hasPlaylistImage): ?>
                                                <img src="<?= htmlspecialchars(mlAssetUrl($playlistImageSrc)) ?>" alt="Open playlist" class="playlist-image">
                                            <?php else: ?>
                                                <span class="mb-next-season-text">Playlist</span>
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var playlistForms = Array.prototype.slice.call(document.querySelectorAll('.playlist-cta-form'));
    if (!playlistForms.length) {
        return;
    }

    function clearPlaylistLoadingState() {
        document.body.classList.remove('playlist-page-loading');
        playlistForms.forEach(function (form) {
            var button = form.querySelector('.playlist-cta-button');
            if (button) {
                button.classList.remove('is-pressed', 'is-loading');
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        });
    }

    playlistForms.forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('.playlist-cta-button');
            document.body.classList.add('playlist-page-loading');

            playlistForms.forEach(function (otherForm) {
                var otherButton = otherForm.querySelector('.playlist-cta-button');
                if (!otherButton) {
                    return;
                }

                if (otherButton === button) {
                    otherButton.classList.add('is-pressed', 'is-loading');
                    otherButton.setAttribute('aria-busy', 'true');
                } else {
                    otherButton.classList.remove('is-pressed');
                    otherButton.classList.add('is-loading');
                }

                otherButton.disabled = true;
            });

            window.setTimeout(clearPlaylistLoadingState, 1800);
        });
    });
});
</script>
</body>
</html>
