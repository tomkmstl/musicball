<div class="game-page-topline">
    <div class="game-page-intro">
        <div class="home-shell-kicker"><?= htmlspecialchars($leagueName, ENT_QUOTES, 'UTF-8') ?></div>
        <h1 class="game-page-title">League Library</h1>
        <p>Search past picks or listen back to the music your league has collected.</p>
    </div>
</div>

<?php if ($statusType !== '' && $statusMessage !== ''): ?>
    <div class="status-banner <?= $statusType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($statusMessage) ?></div>
<?php endif; ?>

<?php $showLeaguePastPicksIcon = true; ?>
<?php require __DIR__ . '/past-picks.php'; ?>
<?php unset($showLeaguePastPicksIcon); ?>

<section class="library-playlists" aria-labelledby="league-library-playlists-title">
    <div class="playlist-section-heading-wrap library-section-heading">
        <div>
            <div class="home-shell-kicker">Listen Back</div>
            <h2 id="league-library-playlists-title">Playlists</h2>
            <p>Open the complete league playlist or revisit the songs picked by each player.</p>
        </div>
    </div>

    <?php if (!$hasRequiredTables): ?>
        <div class="status-banner error">The playlists could not load because one or more required Musicball tables are missing.</div>
    <?php elseif ($sconeSongCount === 0): ?>
        <div class="status-banner">No generated round playlists exist yet, so there is nothing to preview here yet.</div>
    <?php else: ?>
        <div class="playlist-section">
            <article class="playlist-overview-card">
                <div class="playlist-card-main">
                    <div class="playlist-card-copy">
                        <div class="home-shell-kicker">All-Time League Playlist</div>
                        <h3 class="playlist-card-title">Scone Ghetto</h3>
                        <div class="playlist-card-subtitle"><?= (int)$sconeSongCount ?> song<?= $sconeSongCount === 1 ? '' : 's' ?></div>
                        <p>Every song from every generated round playlist, in league order from the first eligible round to the latest eligible round.</p>
                    </div>

                    <div class="playlist-card-cta-wrap">
                        <form method="post" action="<?= htmlspecialchars(mlUrl('league.php?view=songs')) ?>" target="_blank" class="playlist-cta-form">
                            <input type="hidden" name="playlist_action" value="go_to_scone_ghetto">
                            <button type="submit" class="playlist-cta-button game-round-action-link" aria-label="Go to Scone Ghetto on Spotify">
                                <span class="game-round-action-icon playlist-interface-icon" aria-hidden="true"></span>
                                <span class="game-round-action-label">Playlist</span>
                            </button>
                        </form>
                    </div>
                </div>
            </article>
        </div>

        <div class="playlist-section">
            <div class="playlist-section-heading-wrap">
                <h3>Player Playlists</h3>
            </div>

            <div class="player-playlist-grid">
                <?php foreach ($players as $player): ?>
                    <article class="player-playlist-card">
                        <div class="playlist-card-main playlist-card-main-player">
                            <div class="player-playlist-user">
                                <img src="<?= htmlspecialchars($player['profile_image_path']) ?>" alt="<?= htmlspecialchars($player['user_name']) ?>" class="profile-avatar profile-avatar-result-submitter">
                                <div class="playlist-card-copy">
                                    <h4 class="player-playlist-title"><?= htmlspecialchars($player['user_name']) ?>&#039;s Songs</h4>
                                    <div class="playlist-card-subtitle"><?= (int)$player['song_count'] ?> song<?= (int)$player['song_count'] === 1 ? '' : 's' ?></div>
                                </div>
                            </div>

                            <div class="playlist-card-cta-wrap">
                                <form method="post" action="<?= htmlspecialchars(mlUrl('league.php?view=songs')) ?>" target="_blank" class="playlist-cta-form">
                                    <input type="hidden" name="playlist_action" value="go_to_player_playlist">
                                    <input type="hidden" name="playlist_user_id" value="<?= (int)$player['user_id'] ?>">
                                    <button type="submit" class="playlist-cta-button game-round-action-link" aria-label="Go to <?= htmlspecialchars($player['user_name']) ?>'s playlist on Spotify">
                                        <span class="game-round-action-icon playlist-interface-icon" aria-hidden="true"></span>
                                        <span class="game-round-action-label">Playlist</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
