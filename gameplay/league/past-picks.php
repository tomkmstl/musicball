<section class="admin-panel admin-panel-full song-database-shell" data-song-database-mode="replace-results" aria-labelledby="league-library-search-title">
    <div class="song-database-heading<?= !empty($showLeaguePastPicksIcon) ? ' song-database-heading-with-icon' : '' ?>">
        <div>
            <div class="home-shell-kicker">Past Picks</div>
            <h2 id="league-library-search-title">Search the Archive</h2>
        </div>
        <?php if (!empty($showLeaguePastPicksIcon)): ?>
            <span class="song-database-library-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-books" focusable="false">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M5 5a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1l0 -14" />
                    <path d="M9 5a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1l0 -14" />
                    <path d="M5 8h4" />
                    <path d="M9 16h4" />
                    <path d="M13.803 4.56l2.184 -.53c.562 -.135 1.133 .19 1.282 .732l3.695 13.418a1.02 1.02 0 0 1 -.634 1.219l-.133 .041l-2.184 .53c-.562 .135 -1.133 -.19 -1.282 -.732l-3.695 -13.418a1.02 1.02 0 0 1 .634 -1.219l.133 -.041" />
                    <path d="M14 9l4 -1" />
                    <path d="M16 16l3.923 -.98" />
                </svg>
            </span>
        <?php endif; ?>
    </div>
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
        <button type="button" class="button-primary song-database-submit" aria-label="Search past league picks" title="Search">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="song-database-search-icon" aria-hidden="true" focusable="false">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" />
                <path d="M21 21l-6 -6" />
            </svg>
        </button>
    </div>

    <div id="league_song_database_status" class="spotify-search-status muted" role="status" aria-live="polite"></div>
    <div class="song-database-content">
        <div id="league_song_database_results" class="spotify-search-results song-database-results"></div>
        <div id="league_song_database_details" class="song-database-details"></div>
    </div>
</section>
