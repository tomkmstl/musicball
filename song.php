<?php
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/spotify/client.php';
require_once __DIR__ . '/integrations/discord/discord.php';

$currentUser = mlRequireAuthenticatedUser($pdo);
$currentPage = 'season';
$currentUserId = (int)$currentUser['UserID'];
$currentUserLabel = trim((string)($currentUser['DisplayName'] ?? $currentUser['UserName'] ?? $currentUser['Name'] ?? 'musicballer'));
$seasonRoundId = isset($_GET['season_round_id']) ? (int)$_GET['season_round_id'] : (isset($_POST['season_round_id']) ? (int)$_POST['season_round_id'] : 0);
$round = $seasonRoundId > 0 ? mlFindRoundById($pdo, $seasonRoundId) : null;

if (!$round) {
    header('Location: ' . mlUrl('season.php'));
    exit;
}

$presentation = mlComputeRoundPresentation($pdo, [$round], $currentUserId);
$roundView = $presentation[0];

if (mlMaybeAutoGeneratePlaylists($pdo, [$roundView], $currentUserId)) {
    header('Location: ' . mlUrl('season.php?season_id=' . (int)$round['SeasonID']));
    exit;
}

$message = '';
$error = '';
if (isset($_SESSION['ml_playlist_auto_error']) && trim((string)$_SESSION['ml_playlist_auto_error']) !== '') {
    $error = trim((string)$_SESSION['ml_playlist_auto_error']);
}
unset($_SESSION['ml_playlist_auto_error']);

$pendingDuplicateTrack = [];
$pendingDuplicateMatch = null;
$pendingArtistSeasonMatch = null;
$pendingSelectedTrack = [];
$pendingArtistUsageCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['song_action']) ? (string)$_POST['song_action'] : '';

    if ($action === 'remove_track') {
        if (!$roundView['can_choose_song']) {
            $error = 'Song selection for this round is not open in the current round stage.';
        } else {
            mlDeleteRoundSongDraft($currentUserId, (int)$round['SeasonID'], $seasonRoundId);
            $message = 'Saved song removed.';
        }
    } elseif ($action === 'save_track') {
        if (!$roundView['can_choose_song']) {
            $error = 'Song selection for this round is not open in the current round stage.';
        } else {
            $track = [
                'id' => trim((string)($_POST['track_id'] ?? '')),
                'uri' => trim((string)($_POST['track_uri'] ?? '')),
                'title' => trim((string)($_POST['track_title'] ?? '')),
                'artist' => trim((string)($_POST['track_artist'] ?? '')),
                'album' => trim((string)($_POST['track_album'] ?? '')),
                'artwork' => trim((string)($_POST['track_artwork'] ?? '')),
                'comment' => trim((string)($_POST['song_comment'] ?? '')),
            ];

            if ($track['id'] === '' || $track['uri'] === '' || $track['title'] === '' || $track['artist'] === '') {
                $error = 'Pick a Spotify song from the search results before saving.';
            } else {
                $existingSong = mlGetRoundSongDraft($pdo, $currentUserId, (int)$round['SeasonID'], $seasonRoundId);
                $hadExistingSong = !empty($existingSong);
                $existingTrackId = trim((string)($existingSong['id'] ?? $existingSong['SpotifyTrackID'] ?? ''));
                $existingTrackUri = trim((string)($existingSong['uri'] ?? $existingSong['SpotifyURI'] ?? ''));
                $existingTrackTitle = trim((string)($existingSong['title'] ?? $existingSong['TrackName'] ?? ''));
                $existingTrackArtist = trim((string)($existingSong['artist'] ?? $existingSong['ArtistName'] ?? ''));
                $trackChanged = $hadExistingSong && (
                    $existingTrackId !== $track['id'] ||
                    $existingTrackUri !== $track['uri'] ||
                    $existingTrackTitle !== $track['title'] ||
                    $existingTrackArtist !== $track['artist']
                );
                $confirmSelection = isset($_POST['confirm_selection']) && (string)$_POST['confirm_selection'] === '1';
                $confirmDuplicate = isset($_POST['confirm_duplicate']) && (string)$_POST['confirm_duplicate'] === '1';
                $confirmArtistSeasonDuplicate = isset($_POST['confirm_artist_season_duplicate']) && (string)$_POST['confirm_artist_season_duplicate'] === '1';

                $roundDuplicate = mlFindCurrentRoundSongDuplicate($pdo, $seasonRoundId, $currentUserId, $track['id'], $track['title'], $track['artist']);
                if (is_array($roundDuplicate) && !empty($roundDuplicate)) {
                    $error = 'That song has already been chosen for this round. Pick a different song.';
                } else {
                    $historicalDuplicate = mlFindHistoricalSongDuplicate($pdo, $seasonRoundId, $currentUserId, $track['id'], $track['title'], $track['artist']);
                    $artistSeasonDuplicate = mlFindCurrentSeasonArtistDuplicate($pdo, (int)$round['SeasonID'], $seasonRoundId, $currentUserId, $track['artist']);
                    $artistUsageCount = mlCountArtistSelectionsInPastRounds($pdo, $seasonRoundId, $track['artist']);
                    $hasHistoricalDuplicate = is_array($historicalDuplicate) && !empty($historicalDuplicate);
                    $hasArtistSeasonDuplicate = is_array($artistSeasonDuplicate) && !empty($artistSeasonDuplicate);

                    if (!$confirmSelection) {
                        $message = 'Confirm this song before saving it.';
                        $pendingSelectedTrack = $track;
                        $pendingArtistUsageCount = $artistUsageCount;

                        if ($hasHistoricalDuplicate) {
                            $pendingDuplicateTrack = $track;
                            $pendingDuplicateMatch = $historicalDuplicate;
                        }

                        if ($hasArtistSeasonDuplicate) {
                            $pendingArtistSeasonMatch = $artistSeasonDuplicate;
                        }
                    } elseif ((!$confirmDuplicate && $hasHistoricalDuplicate) || (!$confirmArtistSeasonDuplicate && $hasArtistSeasonDuplicate)) {
                        if ($hasHistoricalDuplicate && $hasArtistSeasonDuplicate) {
                            $message = 'This song has warnings you should review before saving.';
                        } elseif ($hasHistoricalDuplicate) {
                            $message = 'The song you are selecting matches something that has already been submitted!';
                        } else {
                            $message = 'This artist has already been submitted in this season.';
                        }

                        $pendingSelectedTrack = $track;
                        $pendingArtistUsageCount = $artistUsageCount;

                        if ($hasHistoricalDuplicate) {
                            $pendingDuplicateTrack = $track;
                            $pendingDuplicateMatch = $historicalDuplicate;
                        }

                        if ($hasArtistSeasonDuplicate) {
                            $pendingArtistSeasonMatch = $artistSeasonDuplicate;
                        }
                    } else {
                        mlSaveRoundSongDraft($currentUserId, (int)$round['SeasonID'], $seasonRoundId, $track);
                        $message = 'Song saved.';

                        try {
                            if (!$hadExistingSong) {
                                mlDiscordMaybeSendSongSubmittedForRound($pdo, $round, $currentUserLabel, $currentUserId);
                            } elseif ($trackChanged && (($roundView['status_key'] ?? '') === 'submission')) {
                                $trackScope = trim((string)($track['id'] !== '' ? $track['id'] : $track['uri']));
                                if ($trackScope === '') {
                                    $trackScope = substr(sha1($track['title'] . '|' . $track['artist']), 0, 12);
                                }
                                mlDiscordMaybeSendSongChangedForRound($pdo, $round, $currentUserLabel, $currentUserId, $trackScope);
                            }
                        } catch (Throwable $e) {
                            // Never interrupt gameplay for Discord failures.
                        }
                    }
                }
            }
        }
    } elseif ($action === 'save_comment') {
        if (!$roundView['can_choose_song']) {
            $error = 'Song comments can no longer be edited for this round.';
        } else {
            $existingSong = mlGetRoundSongDraft($pdo, $currentUserId, (int)$round['SeasonID'], $seasonRoundId);
            if (empty($existingSong)) {
                $error = 'Choose a song before saving a comment.';
            } else {
                mlSaveRoundSongComment($currentUserId, (int)$round['SeasonID'], $seasonRoundId, (string)($_POST['song_comment'] ?? ''));
                $message = 'Comment saved.';
            }
        }
    }

    $presentation = mlComputeRoundPresentation($pdo, [$round], $currentUserId);
    $roundView = $presentation[0];

    if (mlMaybeAutoGeneratePlaylists($pdo, [$roundView], $currentUserId)) {
        header('Location: ' . mlUrl('season.php?season_id=' . (int)$round['SeasonID']));
        exit;
    }
}

$savedSong = mlGetRoundSongDraft($pdo, $currentUserId, (int)$round['SeasonID'], $seasonRoundId);
$roundView['song_draft'] = $savedSong;
$roundView['song_saved'] = !empty($savedSong);
$spotifyConfigured = mlSpotifyAppConfigured();
$spotifyConnected = $spotifyConfigured && mlSpotifyIsConnected($pdo);
$savedSongComment = trim((string)($savedSong['comment'] ?? ''));
$hasPendingHistoricalDuplicate = !empty($pendingDuplicateTrack) && is_array($pendingDuplicateMatch);
$hasPendingArtistSeasonDuplicate = is_array($pendingArtistSeasonMatch) && !empty($pendingArtistSeasonMatch);
$hasPendingWarnings = $hasPendingHistoricalDuplicate || $hasPendingArtistSeasonDuplicate;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - Choose Song</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide game-card-narrow">
        <div class="game-page-topline">
            <div class="song-page-intro">
                <div class="home-shell-kicker">Choose your song</div>
                <h1><?= htmlspecialchars($round['Title']) ?></h1>
                <?php if (trim((string)$round['Tagline']) !== ''): ?>
                    <h3><?= htmlspecialchars($round['Tagline']) ?></h3>
                <?php endif; ?>
                <div class="round-schedule-inline">
                    <span data-utc-schedule-value="<?= htmlspecialchars($roundView['songs_due_utc'] ?? '') ?>" data-schedule-kind="submit">submit <?= htmlspecialchars($roundView['songs_due_label'] ?? '') ?></span>
                    <span class="round-schedule-separator"> · </span>
                    <span data-utc-schedule-value="<?= htmlspecialchars($roundView['votes_due_utc'] ?? '') ?>" data-schedule-kind="vote">vote by <?= htmlspecialchars($roundView['votes_due_label'] ?? '') ?></span>
                </div>
            </div>
        </div>

        <?php if ($message !== '' && !$hasPendingWarnings): ?>
            <div class="status-banner success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($hasPendingWarnings): ?>
            <div class="status-banner error">
                <?php if ($hasPendingHistoricalDuplicate && $hasPendingArtistSeasonDuplicate): ?>
                    The song you selected has already been submitted before, and this artist has already been selected this season.
                <?php elseif ($hasPendingHistoricalDuplicate): ?>
                    The song you are selecting matches something that has already been submitted!
                <?php else: ?>
                    Warning: This artist has already been selected this season.
                <?php endif; ?>
            </div>

            <section class="admin-panel admin-panel-full">
                <div class="home-shell-kicker">Review your song</div>
                <div class="song-selected-card">
                    <?php if (trim((string)($pendingSelectedTrack['artwork'] ?? '')) !== ''): ?>
                        <img src="<?= htmlspecialchars((string)$pendingSelectedTrack['artwork']) ?>" alt="Album art" class="song-artwork-large">
                    <?php else: ?>
                        <div class="song-artwork-large song-artwork-fallback" aria-hidden="true"></div>
                    <?php endif; ?>
                    <div>
                        <div class="song-card-title"><?= htmlspecialchars((string)($pendingSelectedTrack['title'] ?? '')) ?></div>
                        <div class="song-card-meta"><?= htmlspecialchars((string)($pendingSelectedTrack['artist'] ?? '')) ?><?php if (trim((string)($pendingSelectedTrack['album'] ?? '')) !== ''): ?> &middot; <?= htmlspecialchars((string)$pendingSelectedTrack['album']) ?><?php endif; ?></div>
                        <div class="song-card-meta">
                            <?= htmlspecialchars((string)($pendingSelectedTrack['artist'] ?? '')) ?> has been chosen <?= (int)$pendingArtistUsageCount ?> time<?= (int)$pendingArtistUsageCount === 1 ? '' : 's' ?> in past rounds.
                        </div>
                    </div>
                </div>
                <div class="song-duplicate-actions">
                    <form method="post" action="<?= htmlspecialchars(mlUrl('song.php?season_round_id=' . (int)$seasonRoundId)) ?>">
                        <input type="hidden" name="season_round_id" value="<?= (int)$seasonRoundId ?>">
                        <input type="hidden" name="song_action" value="save_track">
                        <input type="hidden" name="confirm_selection" value="1">
                        <?php if ($hasPendingHistoricalDuplicate): ?>
                            <input type="hidden" name="confirm_duplicate" value="1">
                        <?php endif; ?>
                        <?php if ($hasPendingArtistSeasonDuplicate): ?>
                            <input type="hidden" name="confirm_artist_season_duplicate" value="1">
                        <?php endif; ?>
                        <input type="hidden" name="track_id" value="<?= htmlspecialchars((string)($pendingSelectedTrack['id'] ?? '')) ?>">
                        <input type="hidden" name="track_uri" value="<?= htmlspecialchars((string)($pendingSelectedTrack['uri'] ?? '')) ?>">
                        <input type="hidden" name="track_title" value="<?= htmlspecialchars((string)($pendingSelectedTrack['title'] ?? '')) ?>">
                        <input type="hidden" name="track_artist" value="<?= htmlspecialchars((string)($pendingSelectedTrack['artist'] ?? '')) ?>">
                        <input type="hidden" name="track_album" value="<?= htmlspecialchars((string)($pendingSelectedTrack['album'] ?? '')) ?>">
                        <input type="hidden" name="track_artwork" value="<?= htmlspecialchars((string)($pendingSelectedTrack['artwork'] ?? '')) ?>">
                        <input type="hidden" name="song_comment" value="<?= htmlspecialchars((string)($pendingSelectedTrack['comment'] ?? '')) ?>">
                        <button class="button-primary button-danger">Proceed Anyway</button>
                    </form>
                    <a href="<?= htmlspecialchars(mlUrl('song.php?season_round_id=' . (int)$seasonRoundId)) ?>" class="button-secondary">Cancel</a>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($pendingSelectedTrack) && !$hasPendingWarnings): ?>
            <section class="admin-panel admin-panel-full">
                <div class="home-shell-kicker">Confirm your song</div>
                <div class="song-selected-card">
                    <?php if (trim((string)($pendingSelectedTrack['artwork'] ?? '')) !== ''): ?>
                        <img src="<?= htmlspecialchars((string)$pendingSelectedTrack['artwork']) ?>" alt="Album art" class="song-artwork-large">
                    <?php else: ?>
                        <div class="song-artwork-large song-artwork-fallback" aria-hidden="true"></div>
                    <?php endif; ?>
                    <div>
                        <div class="song-card-title"><?= htmlspecialchars((string)($pendingSelectedTrack['title'] ?? '')) ?></div>
                        <div class="song-card-meta"><?= htmlspecialchars((string)($pendingSelectedTrack['artist'] ?? '')) ?><?php if (trim((string)($pendingSelectedTrack['album'] ?? '')) !== ''): ?> &middot; <?= htmlspecialchars((string)$pendingSelectedTrack['album']) ?><?php endif; ?></div>
                        <div class="song-card-meta">
                            <?= htmlspecialchars((string)($pendingSelectedTrack['artist'] ?? '')) ?> has been chosen <?= (int)$pendingArtistUsageCount ?> time<?= (int)$pendingArtistUsageCount === 1 ? '' : 's' ?> in past rounds.
                        </div>
                        <div class="note">Your song is not saved yet. Confirm below to lock in this pick.</div>
                    </div>
                </div>
                <div class="song-duplicate-actions">
                    <form method="post" action="<?= htmlspecialchars(mlUrl('song.php?season_round_id=' . (int)$seasonRoundId)) ?>">
                        <input type="hidden" name="season_round_id" value="<?= (int)$seasonRoundId ?>">
                        <input type="hidden" name="song_action" value="save_track">
                        <input type="hidden" name="confirm_selection" value="1">
                        <input type="hidden" name="track_id" value="<?= htmlspecialchars((string)($pendingSelectedTrack['id'] ?? '')) ?>">
                        <input type="hidden" name="track_uri" value="<?= htmlspecialchars((string)($pendingSelectedTrack['uri'] ?? '')) ?>">
                        <input type="hidden" name="track_title" value="<?= htmlspecialchars((string)($pendingSelectedTrack['title'] ?? '')) ?>">
                        <input type="hidden" name="track_artist" value="<?= htmlspecialchars((string)($pendingSelectedTrack['artist'] ?? '')) ?>">
                        <input type="hidden" name="track_album" value="<?= htmlspecialchars((string)($pendingSelectedTrack['album'] ?? '')) ?>">
                        <input type="hidden" name="track_artwork" value="<?= htmlspecialchars((string)($pendingSelectedTrack['artwork'] ?? '')) ?>">
                        <input type="hidden" name="song_comment" value="<?= htmlspecialchars((string)($pendingSelectedTrack['comment'] ?? '')) ?>">
                        <button type="submit" class="button-primary">Confirm Song</button>
                    </form>
                    <a href="<?= htmlspecialchars(mlUrl('song.php?season_round_id=' . (int)$seasonRoundId)) ?>" class="button-secondary">Cancel</a>
                </div>
            </section>
        <?php endif; ?>


        <section class="admin-panel admin-panel-full song-current-pick-panel">
            <div class="home-shell-kicker">Your current pick</div>
            <?php if (!empty($savedSong)): ?>
                <div class="song-selected-card">
                    <?php if (trim((string)$savedSong['artwork']) !== ''): ?>
                        <img src="<?= htmlspecialchars($savedSong['artwork']) ?>" alt="Album art" class="song-artwork-large">
                    <?php else: ?>
                        <div class="song-artwork-large song-artwork-fallback" aria-hidden="true"></div>
                    <?php endif; ?>
                    <div>
                        <div class="song-card-title"><?= htmlspecialchars($savedSong['title']) ?></div>
                        <div class="song-card-meta"><?= htmlspecialchars($savedSong['artist']) ?> &middot; <?= htmlspecialchars($savedSong['album']) ?></div>
                        <div class="song-card-meta">Saved <?= htmlspecialchars($savedSong['saved_at']) ?> UTC</div>
                    </div>
                </div>

                <form method="post" action="<?= htmlspecialchars(mlUrl('song.php?season_round_id=' . (int)$seasonRoundId)) ?>" class="song-comment-form">
                    <input type="hidden" name="season_round_id" value="<?= (int)$seasonRoundId ?>">
                    <input type="hidden" name="song_action" value="save_comment">
                    <label class="admin-label" for="saved_song_comment">Optional comment</label>
                    <textarea name="song_comment" id="saved_song_comment" class="vote-comment-input song-comment-input" rows="4" maxlength="1000" <?= !$roundView['can_choose_song'] ? 'disabled' : '' ?>><?= htmlspecialchars($savedSongComment) ?></textarea>
                    <div class="song-comment-actions">
                        <button type="submit" class="button-secondary" <?= !$roundView['can_choose_song'] ? 'disabled' : '' ?>>Save Comment</button>
                    </div>
                </form>

                <form method="post" action="<?= htmlspecialchars(mlUrl('song.php?season_round_id=' . (int)$seasonRoundId)) ?>" class="song-current-pick-actions" id="remove_song_form">
                    <input type="hidden" name="season_round_id" value="<?= (int)$seasonRoundId ?>">
                    <input type="hidden" name="song_action" value="remove_track">
                    <button type="button" id="show_remove_song_confirm_button" class="button-secondary" aria-controls="remove_song_confirm_panel" aria-expanded="false" <?= !$roundView['can_choose_song'] ? 'disabled' : '' ?>>Remove Song</button>

                    <div id="remove_song_confirm_panel" class="song-remove-confirm" hidden>
                        <div class="song-remove-confirm-copy">
                            <strong>Remove this song?</strong>
                            <span class="note">You'll need to choose another song before the deadline.</span>
                        </div>
                        <div class="song-remove-confirm-actions">
                            <button type="button" id="cancel_remove_song_button" class="button-secondary">Cancel</button>
                            <button type="submit" id="confirm_remove_song_button" class="button-primary button-danger">Remove Song</button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <p>No song chosen yet.</p>
                <div class="song-comment-form">
                    <label class="admin-label" for="saved_song_comment">Optional comment</label>
                    <textarea id="saved_song_comment" class="vote-comment-input song-comment-input" rows="4" maxlength="1000" <?= !$roundView['can_choose_song'] ? 'disabled' : '' ?>><?= htmlspecialchars((string)($pendingSelectedTrack['comment'] ?? '')) ?></textarea>
                    <div class="note">This comment will save with your song when you pick one.</div>
                </div>
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
                <?php if (!empty($roundView['submission_closed'])): ?>
                    <p>Songs Due has passed. Song changes are closed while Musicball waits for the playlist to be generated.</p>
                <?php else: ?>
                    <p>Start typing a title, artist, album, Spotify track URL, or Spotify track URI. Results narrow dynamically as you type.</p>
                <?php endif; ?>

                <div class="song-search-form-live">
                    <div>
                        <label for="song_query" class="game-visually-hidden">Search for a song or paste a Spotify URL</label>
                        <input
                            type="text"
                            id="song_query"
                            name="q"
                            class="admin-input song-search-input"
                            placeholder="Search Spotify or paste a Spotify track link"
                            autocomplete="off"
                            <?= !$roundView['can_choose_song'] ? 'disabled' : '' ?>
                        >
                    </div>
                    <button type="button" class="button-primary song-search-submit" <?= !$roundView['can_choose_song'] ? 'disabled' : '' ?> onclick="document.getElementById('song_query').focus();">Search</button>
                </div>

                <div id="spotify_search_status" class="spotify-search-status muted"></div>
                <div id="spotify_search_results" class="spotify-search-results"></div>

                <div id="spotify_selection_confirm_panel" class="spotify-selection-confirm-panel" hidden>
                    <div class="home-shell-kicker">Confirm this choice</div>
                    <div class="spotify-selection-confirm-card">
                        <div id="spotify_selection_confirm_art" class="spotify-selection-confirm-art song-artwork-fallback" aria-hidden="true"></div>
                        <div class="spotify-selection-confirm-copy">
                            <div id="spotify_selection_confirm_title" class="song-card-title"></div>
                            <div id="spotify_selection_confirm_meta" class="song-card-meta"></div>
                            <div class="note">This song is not saved yet. Confirm below to lock in this pick.</div>
                        </div>
                    </div>
                    <div class="spotify-selection-confirm-actions">
                        <button type="button" id="spotify_selection_confirm_button" class="button-primary">Confirm Song</button>
                        <button type="button" id="spotify_selection_cancel_button" class="button-secondary">Go Back</button>
                    </div>
                </div>

                <form method="post" action="<?= htmlspecialchars(mlUrl('song.php?season_round_id=' . (int)$seasonRoundId)) ?>" id="spotify_track_save_form" class="spotify-track-save-form">
                    <input type="hidden" name="season_round_id" value="<?= (int)$seasonRoundId ?>">
                    <input type="hidden" name="song_action" value="save_track">
                    <input type="hidden" name="confirm_selection" value="1">
                    <input type="hidden" name="track_id" id="selected_track_id" value="">
                    <input type="hidden" name="track_uri" id="selected_track_uri" value="">
                    <input type="hidden" name="track_title" id="selected_track_title" value="">
                    <input type="hidden" name="track_artist" id="selected_track_artist" value="">
                    <input type="hidden" name="track_album" id="selected_track_album" value="">
                    <input type="hidden" name="track_artwork" id="selected_track_artwork" value="">
                    <input type="hidden" name="song_comment" id="selected_song_comment" value="<?= htmlspecialchars(!empty($pendingSelectedTrack) ? (string)($pendingSelectedTrack['comment'] ?? '') : $savedSongComment) ?>">
                </form>
            <?php endif; ?>
        </section>

		<section class="admin-panel admin-panel-full song-database-shell song-database-shell-with-badge">
			<img src="<?= htmlspecialchars(mlAssetUrl('assets/images/leagues/scone-ghetto.jpg')) ?>" alt="" class="song-database-badge" aria-hidden="true">
			<div class="song-database-label">League Archive</div>
			<h2><?= htmlspecialchars(mlGetLeagueName($pdo), ENT_QUOTES, 'UTF-8') ?> Song Database</h2>
            <p class="song-database-intro">Search past rounds before picking your song. Results only appear for songs or artists that have already been used in completed rounds.</p>

            <div class="song-database-form-live">
                <div>
                    <label for="league_song_database_query" class="game-visually-hidden">Search league song database</label>
                    <input
                        type="text"
                        id="league_song_database_query"
                        class="admin-input song-database-input"
                        placeholder="Look up a used song or artist"
                        autocomplete="off"
                    >
                </div>
                <button type="button" class="button-secondary song-database-submit" onclick="document.getElementById('league_song_database_query').focus();">Look Up</button>
            </div>

            <div id="league_song_database_status" class="spotify-search-status muted"></div>
            <div id="league_song_database_results" class="spotify-search-results song-database-results"></div>
            <div id="league_song_database_details" class="song-database-details"></div>
        </section>
    </div>
</div>
<?php if ($spotifyConfigured && $spotifyConnected && $roundView['can_choose_song']): ?>
    <script>
    (function () {
        var visibleComment = document.getElementById('saved_song_comment');
        var hiddenComment = document.getElementById('selected_song_comment');
        if (!visibleComment || !hiddenComment) {
            return;
        }

        function syncSongComment() {
            hiddenComment.value = visibleComment.value;
        }

        visibleComment.addEventListener('input', syncSongComment);
        syncSongComment();
    })();
    </script>
    <script src="<?= htmlspecialchars(mlAssetUrl('assets/js/song_spotify.js')) ?>"></script>
<?php endif; ?>
<script src="<?= htmlspecialchars(mlAssetUrl('assets/js/song_database.js')) ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var showRemoveButton = document.getElementById('show_remove_song_confirm_button');
    var removeConfirmPanel = document.getElementById('remove_song_confirm_panel');
    var confirmRemoveButton = document.getElementById('confirm_remove_song_button');
    var cancelRemoveButton = document.getElementById('cancel_remove_song_button');

    if (!showRemoveButton || !removeConfirmPanel || !confirmRemoveButton || !cancelRemoveButton) {
        return;
    }

    showRemoveButton.addEventListener('click', function () {
        showRemoveButton.hidden = true;
        showRemoveButton.setAttribute('aria-expanded', 'true');
        removeConfirmPanel.hidden = false;
        cancelRemoveButton.focus();
    });

    cancelRemoveButton.addEventListener('click', function () {
        removeConfirmPanel.hidden = true;
        showRemoveButton.hidden = false;
        showRemoveButton.setAttribute('aria-expanded', 'false');
        showRemoveButton.focus();
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    if (!body) {
        return;
    }

    function applySongLeavingState() {
        body.classList.add('mb-page-leaving');
    }

    document.querySelectorAll('a.button-secondary[href], .song-duplicate-actions a[href]').forEach(function (link) {
        link.addEventListener('pointerdown', applySongLeavingState, { passive: true });
        link.addEventListener('click', applySongLeavingState);
    });

    document.querySelectorAll('.song-duplicate-actions form, .song-comment-form, .song-current-pick-actions, #spotify_track_save_form').forEach(function (form) {
        form.addEventListener('submit', function () {
            applySongLeavingState();
        });
    });

    document.querySelectorAll('.song-duplicate-actions button[type="submit"], .song-comment-form button[type="submit"], .song-current-pick-actions button[type="submit"]').forEach(function (button) {
        button.addEventListener('pointerdown', applySongLeavingState, { passive: true });
        button.addEventListener('click', applySongLeavingState);
    });


});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function detectBrowserTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (error) {
            return 'UTC';
        }
    }

    function formatUtcSchedule(utcValue, timezone) {
        if (!utcValue) {
            return 'TBD';
        }

        const isoLike = utcValue.replace(' ', 'T') + 'Z';
        const date = new Date(isoLike);
        if (Number.isNaN(date.getTime())) {
            return 'TBD';
        }

        return new Intl.DateTimeFormat(undefined, {
            month: 'numeric',
            day: 'numeric',
            year: '2-digit',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZone: timezone
        }).format(date);
    }

    const timezone = detectBrowserTimezone();

    document.querySelectorAll('[data-utc-schedule-value]').forEach(function (node) {
        const value = node.getAttribute('data-utc-schedule-value') || '';
        const formatted = formatUtcSchedule(value, timezone);

        if (node.getAttribute('data-schedule-kind') === 'submit') {
            node.textContent = 'submit ' + formatted;
        } else if (node.getAttribute('data-schedule-kind') === 'vote') {
            node.textContent = 'vote by ' + formatted;
        } else {
            node.textContent = formatted;
        }
    });
});
</script>
</body>
</html>
