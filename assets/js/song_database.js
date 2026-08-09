document.addEventListener('DOMContentLoaded', function () {
    function attachSearchClearButton(input, options) {
        options = options || {};

        if (!input || input.dataset.searchClearAttached === '1') {
            return null;
        }

        input.dataset.searchClearAttached = '1';

        if (!document.getElementById('musicball-search-clear-styles')) {
            var style = document.createElement('style');
            style.id = 'musicball-search-clear-styles';
            style.textContent = '' +
                '.musicball-clearable-input-wrap{' +
                    'position:relative;' +
                    'width:100%;' +
                '}' +
                '.musicball-clearable-input-wrap>.admin-input{' +
                    'padding-right:44px;' +
                '}' +
                '.musicball-search-clear{' +
                    'position:absolute;' +
                    'right:8px;' +
                    'top:50%;' +
                    'width:30px;' +
                    'height:30px;' +
                    'display:none;' +
                    'align-items:center;' +
                    'justify-content:center;' +
                    'padding:0;' +
                    'border:1px solid transparent;' +
                    'border-radius:999px;' +
                    'background:transparent;' +
                    'color:var(--muted);' +
                    'font-size:1.35rem;' +
                    'font-weight:700;' +
                    'line-height:1;' +
                    'cursor:pointer;' +
                    'transform:translateY(-50%);' +
                    'transition:background-color .15s ease,border-color .15s ease,color .15s ease;' +
                    '-webkit-tap-highlight-color:transparent;' +
                '}' +
                '.musicball-search-clear:hover,' +
                '.musicball-search-clear:focus-visible{' +
                    'background:var(--surface-3);' +
                    'border-color:var(--line-strong);' +
                    'color:var(--text);' +
                    'outline:none;' +
                '}' +
                '.musicball-search-clear.is-visible{' +
                    'display:inline-flex;' +
                '}';
            document.head.appendChild(style);
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'musicball-clearable-input-wrap';

        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'musicball-search-clear';
        button.setAttribute('aria-label', options.label || 'Clear search');
        button.textContent = '×';
        wrapper.appendChild(button);

        function updateVisibility() {
            button.classList.toggle('is-visible', input.value.length > 0 && !input.disabled);
        }

        button.addEventListener('click', function () {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
            updateVisibility();
        });

        input.addEventListener('input', updateVisibility);
        input.addEventListener('change', updateVisibility);

        updateVisibility();

        return {
            wrapper: wrapper,
            button: button,
            updateVisibility: updateVisibility
        };
    }

    var searchInput = document.getElementById('league_song_database_query');
    var resultsWrap = document.getElementById('league_song_database_results');
    var detailsWrap = document.getElementById('league_song_database_details');
    var statusWrap = document.getElementById('league_song_database_status');
    var databaseShell = searchInput ? searchInput.closest('.song-database-shell') : null;
    var replaceResultsMode = databaseShell && databaseShell.dataset.songDatabaseMode === 'replace-results';

    attachSearchClearButton(document.getElementById('song_query'), {
        label: 'Clear Spotify search'
    });

    attachSearchClearButton(searchInput, {
        label: 'Clear league song database search'
    });

    if (!searchInput || !resultsWrap || !detailsWrap || !statusWrap) {
        return;
    }

    var activeRequest = 0;
    var debounceTimer = null;
    var activeItems = [];

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setStatus(message, type) {
        statusWrap.textContent = message || '';
        statusWrap.className = 'spotify-search-status' + (type ? ' ' + type : '');
    }

    function clearResults() {
        resultsWrap.innerHTML = '';
        detailsWrap.innerHTML = '';
    }

    function usageLine(usage) {
        var seasonLabel = usage.season || ('Season ' + usage.season_id);
        var playerLabel = usage.player || 'Unknown player';
        var roundNumber = usage.round_number ? 'r' + usage.round_number : 'round';
        var roundTitle = usage.round || 'Unknown round';
        var playlistOrder = usage.playlist_order ? '#' + usage.playlist_order : 'Not playlisted';
        var hasFinish = usage.finish_label && usage.total_votes !== null && typeof usage.total_votes !== 'undefined';
        var voteLabel = Number(usage.total_votes) === 1 ? 'vote' : 'votes';

        return '' +
            '<div class="song-database-usage-row">' +
                '<div>' +
                    '<div class="song-database-usage-title">' + escapeHtml(usage.title) + '</div>' +
                    '<div class="song-database-usage-meta">' + escapeHtml(usage.artist) + '</div>' +
                '</div>' +
                '<div class="song-database-usage-context">' +
                    '<span>' + escapeHtml(seasonLabel) + ' by ' + escapeHtml(playerLabel) + '</span>' +
                    '<span>' + escapeHtml(playlistOrder) + ' on ' + escapeHtml(roundTitle) + ' (' + escapeHtml(roundNumber) + ')</span>' +
                    (hasFinish ? '<span>finished ' + escapeHtml(usage.finish_label) + ' with ' + escapeHtml(usage.total_votes) + ' ' + escapeHtml(voteLabel) + '</span>' : '') +
                '</div>' +
            '</div>';
    }

    function renderDetails(item, selectedIndex) {
        var noun = item.type === 'artist' ? 'artist' : 'song';
        var usageIntro = item.usage_count === 1 ? '1 league use' : item.usage_count + ' league uses';
        var resultNoun = activeItems.length === 1 ? 'result' : 'results';

        if (replaceResultsMode) {
            resultsWrap.innerHTML = '';
            setStatus('', '');
        }

        detailsWrap.innerHTML = '' +
            '<section class="song-database-detail-card">' +
                '<div class="song-selected-card">' +
                    (item.artwork ? '<img src="' + escapeHtml(item.artwork) + '" alt="Album art" class="song-artwork-large">' : '<div class="song-artwork-large song-artwork-fallback" aria-hidden="true"></div>') +
                    '<div>' +
                        '<div class="home-shell-kicker">Selected ' + escapeHtml(noun) + '</div>' +
                        '<div class="song-card-title">' + escapeHtml(item.title) + '</div>' +
                        (item.type === 'song' ? '<div class="song-card-meta">' + escapeHtml(item.artist) + (item.album ? ' · ' + escapeHtml(item.album) : '') + '</div>' : '<div class="song-card-meta">Songs used by this artist</div>') +
                        '<div class="song-database-count-pill">' + escapeHtml(usageIntro) + '</div>' +
                    '</div>' +
                '</div>' +
                (replaceResultsMode ? '<button type="button" class="song-database-back"><span aria-hidden="true">&larr;</span> Back to ' + escapeHtml(activeItems.length) + ' ' + escapeHtml(resultNoun) + '</button>' : '') +
                '<div class="song-database-usage-list">' + (item.usages || []).map(usageLine).join('') + '</div>' +
            '</section>';

        if (replaceResultsMode) {
            var backButton = detailsWrap.querySelector('.song-database-back');
            backButton.addEventListener('click', function () {
                renderResults(activeItems, selectedIndex);
            });
            backButton.focus({ preventScroll: true });
        }

        detailsWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function renderResults(items, focusIndex) {
        clearResults();
        activeItems = (items || []).slice();

        if (!items || !items.length) {
            setStatus('No past-round songs or artists matched that search.', 'muted');
            return;
        }

        setStatus('Select a song or artist from past rounds.', 'muted');

        items.forEach(function (item, itemIndex) {
            var button = document.createElement('button');
            var typeLabel = item.type === 'artist' ? 'Artist' : 'Song';
            var countLabel = item.usage_count === 1 ? '1 use' : item.usage_count + ' uses';
            button.type = 'button';
            button.className = 'spotify-search-result song-database-result';
            button.innerHTML = '' +
                '<span class="spotify-search-result-art-wrap">' +
                    (item.artwork ? '<img src="' + escapeHtml(item.artwork) + '" alt="Album art" class="spotify-search-result-art">' : '<span class="spotify-search-result-art spotify-search-result-art-fallback"></span>') +
                '</span>' +
                '<span class="spotify-search-result-copy">' +
                    '<span class="spotify-search-result-title">' + escapeHtml(item.title) + '</span>' +
                    '<span class="spotify-search-result-meta">' + escapeHtml(typeLabel) + (item.type === 'song' ? ' · ' + escapeHtml(item.artist) : '') + ' · ' + escapeHtml(countLabel) + '</span>' +
                '</span>' +
                '<span class="spotify-search-result-action">View</span>';

            button.addEventListener('click', function () {
                renderDetails(item, itemIndex);
            });

            resultsWrap.appendChild(button);
        });

        if (typeof focusIndex === 'number' && resultsWrap.children[focusIndex]) {
            resultsWrap.children[focusIndex].focus({ preventScroll: true });
            resultsWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function runSearch() {
        var query = searchInput.value.trim();

        if (query.length < 2) {
            clearResults();
            activeItems = [];
            setStatus(query.length === 0 ? 'Start typing to search past rounds in the league song database.' : 'Keep typing to narrow the database search.', 'muted');
            return;
        }

        activeRequest += 1;
        var requestId = activeRequest;
        setStatus('Searching league song database...', 'muted');

        fetch('league_song_database_search.php?q=' + encodeURIComponent(query), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (payload) {
                if (requestId !== activeRequest) {
                    return;
                }

                if (!payload.data || !payload.data.ok) {
                    clearResults();
                    activeItems = [];
                    setStatus((payload.data && payload.data.error) ? payload.data.error : 'League song database search could not be completed.', 'error');
                    return;
                }

                renderResults(payload.data.results || []);
            })
            .catch(function () {
                if (requestId !== activeRequest) {
                    return;
                }
                clearResults();
                activeItems = [];
                setStatus('League song database search could not be completed right now.', 'error');
            });
    }

    searchInput.addEventListener('input', function () {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(runSearch, 260);
    });

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            window.clearTimeout(debounceTimer);
            runSearch();
        }
    });

    setStatus('Start typing to search past rounds in the league song database.', 'muted');
});
