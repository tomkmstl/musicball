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
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?> marketing-page">
<svg class="mb-symbols" aria-hidden="true" focusable="false">
    <symbol id="mb-icon-submit" viewBox="0 0 48 48"><path stroke-width="2.5" d="M24 30V10"/><path stroke-width="2.5" d="M16 18l8-8 8 8"/><rect stroke-width="2.5" x="10" y="30" width="28" height="10" rx="4"/></symbol>
    <symbol id="mb-icon-vote" viewBox="0 0 48 48"><path stroke-width="2.5" d="M10 30h6"/><path stroke-width="2.5" d="M10 24h10"/><path stroke-width="2.5" d="M10 18h14"/><path stroke-width="2.5" d="M30 14l2.5 5 5.5.8-4 3.9.9 5.5-4.9-2.6-4.9 2.6.9-5.5-4-3.9 5.5-.8z"/></symbol>
    <symbol id="mb-icon-build" viewBox="0 0 48 48"><rect stroke-width="2.5" x="10" y="12" width="28" height="24" rx="4"/><path stroke-width="2.5" d="M16 20h16"/><path stroke-width="2.5" d="M16 26h10"/><circle stroke-width="2.5" cx="32" cy="26" r="2"/></symbol>
    <symbol id="mb-icon-music" viewBox="0 0 48 48"><path d="M30 8v24.4a7.2 7.2 0 1 1-4-6.4V13.7l16-3.2v17.9a7.2 7.2 0 1 1-4-6.4V6.5L30 8Z"/></symbol>
    <symbol id="mb-icon-results" viewBox="0 0 48 48"><path d="M10 7h22l6 6v27H10V7Zm19 3v7h7M16 18h13M16 25h18M16 32h10"/><path d="m31 30 4 4 7-8"/></symbol>
    <symbol id="mb-icon-calendar" viewBox="0 0 48 48"><path d="M10 11h28v29H10V11Zm0 10h28M17 7v8M31 7v8M17 27h5M26 27h5M17 34h5M26 34h5"/></symbol>
    <symbol id="mb-icon-users" viewBox="0 0 48 48"><path d="M18 23a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm-12 17c1.5-8 5.5-12 12-12s10.5 4 12 12H6Zm27-16a6 6 0 1 0 0-12m-1 16c5.4.5 8.8 4.5 10 12h-8"/></symbol>
    <symbol id="mb-icon-person" viewBox="0 0 48 48"><path d="M24 24a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-15 17c1.8-8.7 6.8-13 15-13s13.2 4.3 15 13H9Z"/></symbol>
    <symbol id="mb-icon-phone" viewBox="0 0 48 48"><path d="M17 5h14a4 4 0 0 1 4 4v30a4 4 0 0 1-4 4H17a4 4 0 0 1-4-4V9a4 4 0 0 1 4-4Zm3 6h8M21 36h6"/><path d="m24 22 5 5 9-11"/></symbol>
    <symbol id="mb-icon-bars" viewBox="0 0 48 48"><path d="M10 38h6V22h-6v16Zm11 0h6V10h-6v28Zm11 0h6V17h-6v21Z"/></symbol>
    <symbol id="mb-icon-listen" viewBox="0 0 48 48"><path d="M16 34a6 6 0 1 1-4-5.7V10l22-4v24a6 6 0 1 1-4-5.7V13.5l-14 2.6V34Z"/></symbol>
    <symbol id="mb-icon-shield" viewBox="0 0 48 48"><path d="M24 5 39 11v10c0 10-5.3 17.2-15 22C14.3 38.2 9 31 9 21V11l15-6Zm-6 19 4 4 9-10"/></symbol>
    <symbol id="mb-icon-star" viewBox="0 0 48 48"><path d="m24 5 5.6 11.3L42 18.1l-9 8.7 2.1 12.3L24 33.3l-11.1 5.8L15 26.8 6 18.1l12.4-1.8L24 5Z"/></symbol>
    <symbol id="mb-icon-play" viewBox="0 0 64 64"><path d="M26 20v24l20-12-20-12Z"/></symbol>
</svg>

<header class="marketing-nav">
    <div class="marketing-nav-inner">
        <a href="home.php" class="marketing-brand" aria-label="Musicball home">
            <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo.png')) ?>" alt="Musicball">
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
    <section class="hero-section section-dark">
        <div class="hero-copy">
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
</body>
</html>
