<?php
// gameplay/bootstrap.php
// Gameplay compatibility bootstrap. Pages should include this file instead of individual gameplay modules.

require_once __DIR__ . '/../session_boot.php';
require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/seasons.php';
require_once __DIR__ . '/songs.php';
require_once __DIR__ . '/votes.php';
require_once __DIR__ . '/playlists.php';
require_once __DIR__ . '/playlist_pins.php';
require_once __DIR__ . '/rounds.php';
require_once __DIR__ . '/demo_tracks.php';
require_once __DIR__ . '/standings.php';
