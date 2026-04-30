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
    var activeRequest = 0;
    var debounceTimer = null;

    function setStatus(message, type) {
        resultsStatus.textContent = message || '';
        resultsStatus.className = 'spotify-search-status' + (type ? ' ' + type : '');
    }

    function clearResults() {
        resultsWrap.innerHTML = '';
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderTracks(tracks) {
        clearResults();

        if (!tracks || !tracks.length) {
            setStatus('No Spotify results matched that search.', 'muted');
            return;
        }

        setStatus('Select the correct song below.', 'muted');

        tracks.forEach(function (track) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'spotify-search-result';
            button.innerHTML = '' +
                '<span class="spotify-search-result-art-wrap">' +
                    (track.artwork ? '<img src="' + escapeHtml(track.artwork) + '" alt="Album art" class="spotify-search-result-art">' : '<span class="spotify-search-result-art spotify-search-result-art-fallback"></span>') +
                '</span>' +
                '<span class="spotify-search-result-copy">' +
                    '<span class="spotify-search-result-title">' + escapeHtml(track.title) + '</span>' +
                    '<span class="spotify-search-result-meta">' + escapeHtml(track.artist) + ' · ' + escapeHtml(track.album) + '</span>' +
                '</span>' +
                '<span class="spotify-search-result-action">Choose</span>';

            button.addEventListener('click', function () {
                trackIdField.value = track.id || '';
                trackUriField.value = track.uri || '';
                trackTitleField.value = track.title || '';
                trackArtistField.value = track.artist || '';
                trackAlbumField.value = track.album || '';
                trackArtworkField.value = track.artwork || '';
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

        fetch('integrations/spotify/search.php?q=' + encodeURIComponent(query), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return {
                        status: response.status,
                        data: data
                    };
                });
            })
            .then(function (payload) {
                if (requestId !== activeRequest) {
                    return;
                }

                if (!payload.data || !payload.data.ok) {
                    clearResults();
                    setStatus((payload.data && payload.data.error) ? payload.data.error : 'Spotify search could not be completed.', 'error');
                    return;
                }

                renderTracks(payload.data.tracks || []);
            })
            .catch(function () {
                if (requestId !== activeRequest) {
                    return;
                }
                clearResults();
                setStatus('Spotify search could not be completed right now.', 'error');
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

    setStatus('Start typing to search Spotify.', 'muted');
});
