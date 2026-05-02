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
    <meta name="description" content="Musicball is a weekly music competition where friends submit songs, vote, compete, and build shared playlists together.">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('assets/css/marketing.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>

<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?> marketing-page">

<header class="marketing-nav">
    <div class="marketing-container marketing-nav-inner">
        <a href="home.php" class="marketing-brand" aria-label="Musicball home">
            <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo.png')) ?>" alt="Musicball">
        </a>

        <nav class="marketing-links" aria-label="Marketing navigation">
            <a href="how-it-works.php">How It Works</a>
            <a href="features.php">Features</a>
            <a href="start-a-league.php">Start a League</a>
            <a href="index.php" class="marketing-login-link">Log In</a>
        </nav>
    </div>
</header>

<main>
    <section class="marketing-hero">
        <div class="marketing-container marketing-hero-grid">
            <div class="marketing-hero-copy">
                <h1>Build playlists with your friends.</h1>
                <p class="marketing-subhead">Compete, vote, and discover music together—then keep what you create.</p>

                <div class="marketing-cta-row">
                    <a href="start-a-league.php" class="marketing-btn marketing-btn-primary">Start a league</a>
                    <a href="how-it-works.php" class="marketing-btn marketing-btn-secondary">See how it works</a>
                </div>

                <div class="marketing-proof-row">
                    <span>Works with Spotify</span>
                    <span>Built for Discord groups</span>
                    <span>Start in under a minute</span>
                </div>
            </div>

            <div class="marketing-product-preview" aria-label="Musicball product preview">
                <article class="marketing-app-card marketing-round-preview">
                    <div class="marketing-kicker">Round 1</div>
                    <h2>My Current Jam s5</h2>
                    <p>submit by 5/8/26, 12:00 PM · vote by 5/13/26, 11:00 PM</p>

                    <div class="marketing-player-line">
                        <strong>submitted:</strong>
                        <div class="marketing-avatar-row" aria-hidden="true">
                            <span></span><span></span><span></span><span></span><span></span><span></span>
                        </div>
                    </div>

                    <div class="marketing-player-line">
                        <strong>still researching:</strong>
                        <div class="marketing-avatar-row marketing-avatar-row-alt" aria-hidden="true">
                            <span></span><span></span><span></span><span></span><span></span>
                        </div>
                    </div>

                    <div class="marketing-action-icons">
                        <img src="<?= htmlspecialchars(mlAssetUrl('images/choose_song.png')) ?>" alt="Choose song">
                        <img src="<?= htmlspecialchars(mlAssetUrl('images/vote.png')) ?>" alt="Vote">
                    </div>
                </article>

                <article class="marketing-app-card marketing-standings-preview">
                    <div class="marketing-kicker">Season Standings</div>
                    <div class="marketing-standing-row"><b>1</b><span>Manic Arch Tour</span><strong>144</strong></div>
                    <div class="marketing-standing-row"><b>2</b><span>Fashion Forward</span><strong>143</strong></div>
                    <div class="marketing-standing-row"><b>3</b><span>Ham</span><strong>130</strong></div>
                </article>
            </div>
        </div>
    </section>

    <section class="marketing-section marketing-section-light">
        <div class="marketing-container marketing-centered">
            <h2>A game that builds something real</h2>

            <div class="marketing-three-up">
                <article>
                    <div class="marketing-icon">♪</div>
                    <h3>Submit</h3>
                    <p>Everyone brings a song to the round.</p>
                </article>

                <article>
                    <div class="marketing-icon">▣</div>
                    <h3>Vote</h3>
                    <p>Rank your favorites each week.</p>
                </article>

                <article>
                    <div class="marketing-icon">▤</div>
                    <h3>Build</h3>
                    <p>Every round adds to your shared playlist.</p>
                </article>
            </div>

            <p class="marketing-big-note">At the end, you don’t just have winners—you have a playlist that’s yours.</p>
        </div>
    </section>

    <section class="marketing-section marketing-video-section">
        <div class="marketing-container marketing-video-grid">
            <div>
                <h2>This is what Musicball feels like</h2>
                <p>It starts as a game. It turns into something more.</p>
                <a href="how-it-works.php" class="marketing-btn marketing-btn-primary">Watch video</a>
            </div>

            <div class="marketing-video-placeholder">
                <div class="marketing-play-button">▶</div>
            </div>
        </div>
    </section>

    <section class="marketing-section marketing-section-light">
        <div class="marketing-container">
            <div class="marketing-centered marketing-section-heading">
                <h2>See Musicball in action</h2>
                <p>Everything you need for the ultimate music competition.</p>
            </div>

            <div class="marketing-feature-grid">
                <article>
                    <div class="marketing-mini-shot marketing-mini-shot-dark">Active Round</div>
                    <h3>Active Rounds</h3>
                    <p>See who’s in, who’s working on it, and what’s next.</p>
                </article>

                <article>
                    <div class="marketing-mini-shot marketing-mini-shot-dark">Standings</div>
                    <h3>Standings</h3>
                    <p>Track points, wins, and bragging rights.</p>
                </article>

                <article>
                    <div class="marketing-mini-shot marketing-mini-shot-light">Playlists</div>
                    <h3>Playlists</h3>
                    <p>Every round becomes part of something you keep.</p>
                </article>

                <article>
                    <div class="marketing-mini-shot marketing-mini-shot-dark">Season Builder</div>
                    <h3>Season Builder</h3>
                    <p>Customize rounds, themes, eras, and formats.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="marketing-section marketing-discord-section">
        <div class="marketing-container marketing-discord-grid">
            <div>
                <div class="marketing-kicker">Discord integration</div>
                <h2>One login. Total league connection.</h2>
                <p>Musicball only requires Discord for login because Musicball is built for friend groups, and Discord is where many leagues already live.</p>

                <div class="marketing-discord-list">
                    <div><strong>One sign-on</strong><span>Log in securely with Discord. No extra Musicball password to remember.</span></div>
                    <div><strong>Built for groups</strong><span>Connect your league to the Discord server your friends already use.</span></div>
                    <div><strong>Round updates</strong><span>Submit reminders, voting windows, results, and announcements can flow into your server.</span></div>
                    <div><strong>Less friction</strong><span>Your league stays connected, and players spend more time discovering music.</span></div>
                </div>
            </div>

            <div class="marketing-discord-card">
                <div class="marketing-discord-channel"># musicball</div>
                <div class="marketing-discord-message">
                    <strong>Musicball</strong>
                    <span>Round 1: My Current Jam s5 is live!</span>
                    <button type="button">View Round</button>
                </div>
                <div class="marketing-discord-message">
                    <strong>Musicball</strong>
                    <span>Results are in. Check out the new standings.</span>
                    <button type="button">View Standings</button>
                </div>
            </div>
        </div>
    </section>

    <section class="marketing-section marketing-final-cta">
        <div class="marketing-container marketing-centered">
            <h2>Start your first league</h2>
            <p>Invite your friends. Pick your first theme. See what happens.</p>
            <a href="start-a-league.php" class="marketing-btn marketing-btn-primary">Start a league</a>
        </div>
    </section>
</main>

</body>
</html>
