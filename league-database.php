<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

mlRequireAuthenticatedUser($pdo);
$currentPage = 'league-database';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Music Ball - League Database</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php include 'header.php'; ?>
<div class="wrapper">
    <div class="card game-card game-card-wide game-card-narrow">
        <div class="game-page-topline">
            <div class="game-page-intro">
                <div class="home-shell-kicker">League Archive</div>
                <h1 class="game-page-title">League Database</h1>
                <p>Search songs and artists that have already appeared in past Musicball rounds.</p>
            </div>
        </div>

        <section class="admin-panel admin-panel-full song-database-shell">
            <img src="/images/leagues/scone-ghetto.jpg" alt="" class="song-database-badge" aria-hidden="true">
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
<script src="<?= htmlspecialchars(mlAssetUrl('assets/js/song_database.js')) ?>"></script>
</body>
</html>
