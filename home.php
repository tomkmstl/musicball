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
    <meta name="description" content="Musicball is a social music competition where friends submit songs, vote, compete, and build shared playlists together over time.">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('assets/css/marketing.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>

<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?> marketing-page">

<header class="marketing-nav">
    <div class="marketing-container marketing-nav-inner">
        <a href="home.php" class="marketing-brand">
            <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo.png')) ?>" alt="Musicball">
        </a>

        <nav class="marketing-links">
            <a href="#how-it-works">How It Works</a>
            <a href="#features">Features</a>
            <a href="#start-a-league">Start a League</a>
            <a href="index.php" class="marketing-login-link">Log In</a>
        </nav>
    </div>
</header>

<main>

<!-- HERO -->
<section class="marketing-hero">
    <div class="marketing-container marketing-hero-grid">
        <div class="marketing-hero-copy">
            <h1>Build playlists with your friends.</h1>
            <p class="marketing-subhead">Compete, vote, and discover music together—then keep what you create.</p>

            <div class="marketing-cta-row">
                <a href="#start-a-league" class="marketing-btn marketing-btn-primary">Start a league</a>
                <a href="#how-it-works" class="marketing-btn marketing-btn-secondary">See how it works</a>
            </div>

            <div class="marketing-proof-row">
                <span>Works with Spotify</span>
                <span>Built for Discord groups</span>
                <span>Start in 5 minutes</span>
            </div>
        </div>

        <div class="marketing-product-preview">
            <article class="marketing-app-card">
                <div class="marketing-kicker">Round 1</div>
                <h2>My Current Jam s5</h2>
                <p>submit by 5/8 · vote by 5/13</p>
            </article>
        </div>
    </div>
</section>

<!-- HOW IT WORKS (PRIMARY EXPLANATION) -->
<section id="how-it-works" class="marketing-section marketing-section-light">
    <div class="marketing-container marketing-centered">
        <h2>How Musicball works</h2>
        <p>Everyone submits songs. Everyone votes. Every round builds a shared playlist.</p>
    </div>

    <div class="marketing-container">
        <div class="marketing-process-grid">
            <article>
                <div class="marketing-process-icon">
                    <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/submit-song.svg')) ?>">
                </div>
                <h3>Submit</h3>
            </article>

            <article>
                <div class="marketing-process-icon">
                    <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/vote.svg')) ?>">
                </div>
                <h3>Vote</h3>
            </article>

            <article>
                <div class="marketing-process-icon">
                    <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/results.svg')) ?>">
                </div>
                <h3>Results</h3>
            </article>
        </div>

        <!-- Seasons cleaned up -->
        <div class="marketing-info-card">
            <div>
                <h3>Seasons</h3>
                <p>A season is a series of themed rounds that build one shared playlist.</p>
            </div>
            <ul>
                <li>Weekly rounds</li>
                <li>Season standings</li>
                <li>Full history</li>
            </ul>
        </div>
    </div>
</section>

<!-- DISCORD (VISUAL HEAVY) -->
<section id="features" class="marketing-section marketing-discord-section">
    <div class="marketing-container marketing-discord-grid">

        <div>
            <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/discord.svg')) ?>" style="width:48px;margin-bottom:16px;">
            <h2>Built for Discord</h2>
            <p>Your league lives where your friends already are.</p>

            <div class="marketing-discord-list">
                <div><strong>One login</strong><span>No new accounts needed</span></div>
                <div><strong>Real-time updates</strong><span>Rounds, votes, and results</span></div>
                <div><strong>Stay engaged</strong><span>Never miss a moment</span></div>
            </div>
        </div>

        <div class="marketing-discord-card">
            <div class="marketing-discord-message">
                <div class="marketing-discord-bot-avatar">MB</div>
                <div>
                    <strong>Musicball</strong>
                    <p>Round is live</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- START CTA CLEANED -->
<section id="start-a-league" class="marketing-section marketing-section-light">
    <div class="marketing-container marketing-centered">
        <h2>Start a league in 5 minutes</h2>
        <p>Invite friends and start playing instantly.</p>

        <div style="max-width:700px;margin:40px auto;">
            <div class="marketing-info-card">
                <ul>
                    <li>Create your league</li>
                    <li>Invite your friends</li>
                    <li>Start your first round</li>
                </ul>
                <div style="display:flex;align-items:center;justify-content:center;">
                    <a href="index.php" class="marketing-btn marketing-btn-primary">Start your league</a>
                </div>
            </div>
        </div>
    </div>
</section>

</main>
</body>
</html>
