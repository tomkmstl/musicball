<?php
require_once 'session_boot.php';
require_once 'config.php';

if (isset($_SESSION['UserID']) || isset($_SESSION['ml_user_id'])) {
    header('Location: season.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musicball | Build playlists with your friends</title>
    <meta name="description" content="Musicball is a weekly music competition with your friends. Submit songs, vote, track standings, and build shared playlists together.">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('assets/css/marketing.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?> marketing-page home-page">
<svg class="mb-symbols" style="display:none" aria-hidden="true" focusable="false">
    <symbol id="mb-icon-submit" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M14 3v4a1 1 0 0 0 1 1h4"/>
        <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v4"/>
        <path d="M16 19h6"/>
        <path d="M19 16v6"/>
    </symbol>

    <symbol id="mb-icon-vote" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M9 11l3 3l8 -8"/>
        <path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9"/>
    </symbol>

    <symbol id="mb-icon-build" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M9 6h11"/>
        <path d="M9 12h11"/>
        <path d="M9 18h11"/>
        <path d="M5 6v.01"/>
        <path d="M5 12v.01"/>
        <path d="M5 18v.01"/>
    </symbol>

    <symbol id="mb-icon-music" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M3 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/>
        <path d="M9 17v-13h10v9"/>
        <path d="M9 8h10"/>
        <path d="M17 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/>
    </symbol>

    <symbol id="mb-icon-results" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2"/>
        <path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z"/>
        <path d="M9 17v-5"/>
        <path d="M12 17v-1"/>
        <path d="M15 17v-3"/>
    </symbol>

    <symbol id="mb-icon-calendar" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z"/>
        <path d="M16 3l0 4"/>
        <path d="M8 3l0 4"/>
        <path d="M4 11l16 0"/>
        <path d="M8 15h2v2h-2z"/>
    </symbol>

    <symbol id="mb-icon-users" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M10 13a4 4 0 1 0 -8 0a4 4 0 0 0 8 0"/>
        <path d="M16 13a4 4 0 1 0 -8 0a4 4 0 0 0 8 0"/>
        <path d="M22 13a4 4 0 1 0 -8 0a4 4 0 0 0 8 0"/>
        <path d="M6 17v1a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2v-1"/>
    </symbol>

    <symbol id="mb-icon-person" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/>
        <path d="M16 19h6"/>
        <path d="M19 16v6"/>
        <path d="M6 21v-2a4 4 0 0 1 4 -4h4"/>
    </symbol>

    <symbol id="mb-icon-phone" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M6 4m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z"/>
        <path d="M11 17h2"/>
        <path d="M9 11l2 2l4 -4"/>
    </symbol>

    <symbol id="mb-icon-bars" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M3 12m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/>
        <path d="M9 8m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/>
        <path d="M15 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/>
        <path d="M4 20l14 0"/>
    </symbol>

    <symbol id="mb-icon-listen" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M4 13m0 2a2 2 0 0 1 2 -2h1v6h-1a2 2 0 0 1 -2 -2z"/>
        <path d="M17 13h1a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-1z"/>
        <path d="M4 15v-3a8 8 0 0 1 16 0v3"/>
    </symbol>

    <symbol id="mb-icon-shield" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M12 3l8 4v5c0 5 -3.5 9 -8 9s-8 -4 -8 -9v-5z"/>
        <path d="M9 12l2 2l4 -4"/>
    </symbol>

    <symbol id="mb-icon-star" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1.002l3.086 -6.253l3.086 6.253l6.9 1.002l-5 4.867l1.179 6.873z"/>
    </symbol>

    <symbol id="mb-icon-play" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M7 4v16l13 -8z"/>
    </symbol>
</svg>

<header class="marketing-nav">
    <div class="marketing-nav-inner">
        <a href="home.php" class="marketing-brand" aria-label="Musicball home">
            <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo_home.png')) ?>" alt="Musicball">
        </a>
        <nav class="marketing-links" aria-label="Marketing navigation">
            <a href="#how-it-works">How It Works</a>
            <a href="#features">Features</a>
            <a href="#start">Start a League</a>
            <a href="index.php" class="marketing-login-link">Log In</a>
        </nav>
    </div>
</header>

<main>
    <section class="hero-section section-dark" id="home-hero">
        <div class="hero-copy">
            <div class="hero-brand-mark" aria-label="Musicball">
                <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo_home.png')) ?>" alt="Musicball">
            </div>
            <h1>Built for music fans.<br>Designed for friends.</h1>
            <p>Serious competition. Personalized leagues. Songs you'll love.</p>
            <div class="hero-actions">
                <a href="#start" class="btn btn-primary">Start a league</a>
                <a href="#how-it-works" class="btn btn-secondary">See how it works</a>
            </div>
            <div class="hero-notes">
                <span>Works with Spotify</span>
                <span>Takes under a minute to start</span>
            </div>
        </div>
    </section>

    <section class="marketing-section section-dark">
        <div class="marketing-container">
            <div class="hero-preview" aria-label="Musicball product preview">
                <article class="round-preview card-glass">
                    <div class="eyebrow">ROUND 1</div>
                    <h2>My Current Jam <span>s5</span></h2>
                    <p>submit by 5/8/26, 12:00 PM · vote by 5/13/26, 11:00 PM</p>
                    <div class="avatar-row-wrap"><span>submitted:</span><div class="avatar-row"><?php for ($i = 1; $i <= 6; $i++): ?><i class="avatar avatar-<?= $i ?>"></i><?php endfor; ?></div></div>
                    <div class="avatar-row-wrap"><span>still<br>researching:</span><div class="avatar-row"><?php for ($i = 7; $i <= 12; $i++): ?><i class="avatar avatar-<?= $i ?>"></i><?php endfor; ?></div></div>
                    <div class="round-actions-mini">
                        <div><svg><use href="#mb-icon-submit"></use></svg><span>Choose Song</span></div>
                        <div class="muted"><svg><use href="#mb-icon-vote"></use></svg><span>Vote</span></div>
                    </div>
                </article>
                <article class="standings-preview card-glass">
                    <h3>Season Standings</h3>
                    <table>
                        <thead><tr><th></th><th>Player</th><th>Total<br>Points</th><th>Best<br>Song</th></tr></thead>
                        <tbody>
                            <tr><td>1</td><td><i></i> Manic Arch Tour</td><td>144</td><td>36</td></tr>
                            <tr><td>2</td><td><i></i> Fashion Forward Fuckboi</td><td>143</td><td>34</td></tr>
                        </tbody>
                    </table>
                </article>
            </div>
        </div>
    </section>

    <section class="build-section marketing-section">
        <h2>A game that builds something real</h2>
        <div class="three-step-strip">
            <div><svg><use href="#mb-icon-submit"></use></svg><strong>Submit</strong><p>Everyone brings a song<br>to the round.</p></div>
            <div><svg><use href="#mb-icon-vote"></use></svg><strong>Vote</strong><p>Rank your favorites<br>each week.</p></div>
            <div><svg><use href="#mb-icon-build"></use></svg><strong>Build</strong><p>Every round adds to<br>your shared playlist.</p></div>
        </div>
        <p class="section-tagline">At the end, you don't just have winners — you have a playlist that's yours.</p>
    </section>

    <section class="feel-section marketing-section section-soft">
        <div class="feel-copy">
            <h2>This is what<br>Musicball feels like</h2>
            <p>It starts as a game.<br>It turns into something more.</p>
            <a href="#" class="btn btn-primary btn-small">Watch video</a>
        </div>
        <div class="video-card" role="img" aria-label="Friends gathered at a table talking about music">
            <div class="play-button"><svg><use href="#mb-icon-play"></use></svg></div>
        </div>
    </section>

    <section class="action-section marketing-section">
        <div class="marketing-container">
            <div class="section-heading centered">
                <h2>See Musicball in action</h2>
                <p>Everything you need for the ultimate music competition.</p>
            </div>
            <div class="action-grid">
                <article><div class="screen-card screen-round"><span>ROUND 1</span><strong>My Current Jam s5</strong><div class="mini-avatars"></div></div><h3>Active Rounds</h3><p>See who's in, who's working on it, and what's next.</p></article>
                <article><div class="screen-card screen-standings"><strong>Standings</strong><ol><li>Manic Arch Tour</li><li>Fashion Forward Fuckboi</li><li>Echo Loops</li></ol></div><h3>Standings</h3><p>Track points, wins, and bragging rights.</p></article>
                <article><div class="screen-card screen-playlists"><strong>Playlists</strong><p>Hank's Songs</p><p>Fashion Forward Fuckboi's Songs</p><p>The Curator Jester's Songs</p></div><h3>Playlists</h3><p>Every round becomes part of something you keep.</p></article>
                <article><div class="screen-card screen-builder"><strong>Musicball.</strong><p>Era rounds</p><p>Madlibs</p><p>Custom prompts</p></div><h3>Season Builder</h3><p>Customize rounds, themes, eras, and formats.</p></article>
            </div>
        </div>
    </section>

    <section id="how-it-works" class="how-section marketing-section">
        <div class="marketing-container">
            <div class="section-heading centered">
                <h2>How Musicball works</h2>
                <p>Musicball is a weekly music competition with your friends.<br>Everyone submits songs, everyone votes, and every round<br>builds a shared playlist.</p>
            </div>
            <div class="how-steps">
                <article><svg><use href="#mb-icon-submit"></use></svg><h3>1. Submit</h3><p>Pick a song that fits the round theme and submit before the deadline.</p></article>
                <article><svg><use href="#mb-icon-vote"></use></svg><h3>2. Vote</h3><p>Rank your favorite songs. Points are awarded automatically.</p></article>
                <article><svg><use href="#mb-icon-build"></use></svg><h3>3. Build</h3><p>Standings update and the playlist grows. On to the next round!</p></article>
            </div>
            <div class="info-card split-card">
                <div class="info-title"><svg><use href="#mb-icon-calendar"></use></svg><div><h3>Seasons</h3><p>A season is a series of rounds with different themes — eras, madlibs, custom prompts, and more. You decide.</p></div></div>
                <ul><li>Weekly rounds keep it fresh</li><li>Compete across the whole season</li><li>History and stats for every league</li></ul>
            </div>
            <div class="info-card what-card">
                <h3>What you get</h3>
                <div class="what-grid">
                    <div><svg><use href="#mb-icon-listen"></use></svg><strong>Shared playlist</strong><p>Every round, in order.</p></div>
                    <div><svg><use href="#mb-icon-music"></use></svg><strong>Personal playlists</strong><p>Your picks, all season.</p></div>
                    <div><svg><use href="#mb-icon-bars"></use></svg><strong>Season standings</strong><p>Track your progress.</p></div>
                    <div><svg><use href="#mb-icon-star"></use></svg><strong>Lasting memories</strong><p>The songs stick around.</p></div>
                </div>
            </div>
        </div>
    </section>

    <section id="start" class="start-section marketing-section">
        <div class="start-layout">
            <div>
                <h2>Start a league in<br>under a minute</h2>
                <p>Invite your friends, pick your first theme,<br>and let the games begin.</p>
                <div class="start-card steps-card">
                    <article><svg><use href="#mb-icon-person"></use></svg><div><h3>1. Create your league</h3><p>Name your league and set the basics.</p></div></article>
                    <article><svg><use href="#mb-icon-users"></use></svg><div><h3>2. Invite your friends</h3><p>Send invites and build your roster.</p></div></article>
                    <article><svg><use href="#mb-icon-phone"></use></svg><div><h3>3. Pick your first round</h3><p>Choose from ready-made themes or create your own.</p></div></article>
                </div>
                <div class="start-card commissioner-card">
                    <h3><span>♛</span> Commissioner control</h3>
                    <ul><li>Customize rounds and themes</li><li>Set deadlines and voting windows</li><li>Choose formats and scoring</li><li>Many options. Total flexibility.</li></ul>
                </div>
                <a href="index.php" class="btn btn-primary btn-wide">Start your league</a>
            </div>
        </div>
    </section>

    <section id="features" class="features-section marketing-section">
        <div class="marketing-container">
            <div class="section-heading centered">
                <h2>Everything you need for<br>the ultimate music league</h2>
            </div>
            <div class="feature-grid">
                <article><span class="spotify-dot">●</span><div><h3>Spotify Integration</h3><p>Add songs, listen, and build playlists with one click.</p></div></article>
                <article><svg><use href="#mb-icon-bars"></use></svg><div><h3>League Standings</h3><p>Track wins, podiums, points, and season history.</p></div></article>
                <article><svg><use href="#mb-icon-vote"></use></svg><div><h3>Voting & Scoring</h3><p>Rank songs, earn points, climb the standings.</p></div></article>
                <article><svg><use href="#mb-icon-listen"></use></svg><div><h3>Playlist History</h3><p>Every round lives on in your league playlist.</p></div></article>
                <article><svg><use href="#mb-icon-build"></use></svg><div><h3>Round Themes</h3><p>Eras, madlibs, custom prompts, and more.</p></div></article>
                <article><svg><use href="#mb-icon-shield"></use></svg><div><h3>Commissioner Tools</h3><p>Powerful tools to run your league your way.</p></div></article>
            </div>
            <div class="built-banner">
                <div><h2>Build playlists with your friends.</h2><p>Compete, vote, and discover music together — then keep what you create.</p></div>
                <svg><use href="#mb-icon-music"></use></svg>
            </div>
        </div>
    </section>
</main>
<script>
(function () {
    var hero = document.getElementById('home-hero');
    if (!hero) {
        return;
    }

    function updateHeaderLogo() {
        var heroBottom = hero.getBoundingClientRect().bottom;
        document.body.classList.toggle('hero-passed', heroBottom <= 24);
    }

    updateHeaderLogo();
    window.addEventListener('scroll', updateHeaderLogo, { passive: true });
    window.addEventListener('resize', updateHeaderLogo);
})();
</script>
</body>
</html>
