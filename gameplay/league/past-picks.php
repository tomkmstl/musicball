<section class="admin-panel admin-panel-full song-database-shell" data-song-database-mode="replace-results" aria-labelledby="league-library-search-title">
    <div class="home-shell-kicker">Past Picks</div>
    <h2 id="league-library-search-title">Search the Archive</h2>
    <p class="song-database-intro">Check whether a song or artist has already appeared in a completed Musicball round.</p>

    <div class="song-database-form-live">
        <div>
            <label for="league_song_database_query" class="game-visually-hidden">Search past league picks</label>
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

    <div id="league_song_database_status" class="spotify-search-status muted" role="status" aria-live="polite"></div>
    <div class="song-database-content">
        <div id="league_song_database_results" class="spotify-search-results song-database-results"></div>
        <div id="league_song_database_details" class="song-database-details"></div>
    </div>
</section>
