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
        <a href="home.php" class="marketing-brand" aria-label="Musicball home">
            <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo.png')) ?>" alt="Musicball">
        </a>

        <nav class="marketing-links" aria-label="Marketing navigation">
            <a href="#how-it-works">How It Works</a>
            <a href="#features">Features</a>
            <a href="#start-a-league">Start a League</a>
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
                    <a href="#start-a-league" class="marketing-btn marketing-btn-primary">Start a league</a>
                    <a href="#how-it-works" class="marketing-btn marketing-btn-secondary">See how it works</a>
                </div>

                <div class="marketing-proof-row">
                    <span>Works with Spotify</span>
                    <span>Built for Discord groups</span>
                    <span>Start in 5 minutes</span>
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

    <section class="marketing-section marketing-section-light marketing-build-section">
        <div class="marketing-container marketing-centered">
            <h2>A game that builds something real</h2>

            <div class="marketing-three-up">
                <article>
                    <div class="marketing-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/submit-song.svg')) ?>" alt="Submit">
                    </div>
                    <h3>Submit</h3>
                    <p>Everyone brings a song to the round.</p>
                </article>

                <article>
                    <div class="marketing-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/vote.svg')) ?>" alt="Vote">
                    </div>
                    <h3>Vote</h3>
                    <p>Rank your favorites each week.</p>
                </article>

                <article>
                    <div class="marketing-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/build-playlist.svg')) ?>" alt="Build">
                    </div>
                    <h3>Build</h3>
                    <p>Every round adds to your shared playlist.</p>
                </article>
            </div>

            <p class="marketing-big-note">At the end, you don’t just have winners—you have a playlist that’s yours.</p>
        </div>
    </section>

    <section id="how-it-works" class="marketing-section marketing-section-light marketing-how-section">
        <div class="marketing-container marketing-centered marketing-section-heading">
            <h2>How Musicball works</h2>
            <p>Musicball is a weekly music competition with your friends. Everyone submits songs, everyone votes, and every round builds a shared playlist.</p>
        </div>

        <div class="marketing-container">
            <div class="marketing-process-grid">
                <article>
                    <div class="marketing-process-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/submit-song.svg')) ?>" alt="Submit">
                    </div>
                    <h3>1. Submit</h3>
                    <p>Pick a song that fits the round theme and submit before the deadline.</p>
                </article>
                <article>
                    <div class="marketing-process-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/vote.svg')) ?>" alt="Vote">
                    </div>
                    <h3>2. Vote</h3>
                    <p>Rank your favorite songs. Points are awarded automatically.</p>
                </article>
                <article>
                    <div class="marketing-process-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/results.svg')) ?>" alt="Results">
                    </div>
                    <h3>3. Results</h3>
                    <p>Standings update and the playlist grows. On to the next round.</p>
                </article>
            </div>

            <div class="marketing-info-card marketing-season-card">
                <div>
                    <div class="marketing-info-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/seasons.svg')) ?>" alt="Seasons">
                    </div>
                    <h3>Seasons</h3>
                    <p>A season is a series of rounds with different themes—eras, madlibs, custom prompts, and more. You decide.</p>
                </div>
                <ul>
                    <li>Weekly rounds keep it fresh</li>
                    <li>Compete across the whole season</li>
                    <li>History and stats for every league</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="features" class="marketing-section marketing-discord-section">
        <div class="marketing-container marketing-discord-grid">
            <div>
                <div class="marketing-kicker">Discord integration</div>
                <h2>One login. Total integration.</h2>
                <p>Musicball is built for friend groups, and Discord is where your league already talks. Keep your league connected without adding more friction.</p>

                <div class="marketing-discord-list">
                    <div><strong>One sign-on</strong><span>Connect with Discord. No extra Musicball password to remember.</span></div>
                    <div><strong>Built for groups</strong><span>Connect league activity to the Discord server your friends already use.</span></div>
                    <div><strong>Real-time updates</strong><span>Send round reminders, voting windows, results, and announcements.</span></div>
                    <div><strong>Stay in the loop</strong><span>No one misses a deadline or result because the game meets them where they already are.</span></div>
                </div>
            </div>

            <div class="marketing-discord-card marketing-discord-visual" aria-label="Discord notification preview">
                <div class="marketing-discord-topbar">
                    <span class="marketing-discord-hash">#</span>
                    <span>musicball</span>
                    <span class="marketing-discord-dots">•••</span>
                </div>

                <div class="marketing-discord-message marketing-discord-message-bot">
                    <div class="marketing-discord-bot-avatar">MB</div>
                    <div>
                        <div class="marketing-discord-message-head"><strong>Musicball</strong><span>APP</span></div>
                        <p>Round 1: My Current Jam s5 is live!</p>
                        <small>Submit by 5/8 at 12:00 PM<br>Vote by 5/13 at 11:00 PM</small>
                        <button type="button">View Round</button>
                    </div>
                </div>

                <div class="marketing-discord-message marketing-discord-message-bot">
                    <div class="marketing-discord-bot-avatar">MB</div>
                    <div>
                        <div class="marketing-discord-message-head"><strong>Musicball</strong><span>APP</span></div>
                        <p>Results are in 🏆</p>
                        <small>Check out the new standings.</small>
                        <button type="button">View Standings</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="marketing-container">
            <div class="marketing-info-card marketing-discord-note">
                <div class="marketing-info-icon marketing-info-icon-discord">☁</div>
                <div>
                    <h3>Less friction. More music.</h3>
                    <p>Discord keeps your league connected—so you can focus on discovering new music together.</p>
                </div>
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

    <section id="start-a-league" class="marketing-section marketing-start-section">
        <div class="marketing-container marketing-start-grid">
            <div class="marketing-start-copy">
                <h2>Start a league in 5 minutes.</h2>
                <p>Invite your friends, pick your first theme, and let the games begin.</p>

                <div class="marketing-start-steps">
                    <article>
                        <span>1</span>
                        <div>
                            <h3>Create your league</h3>
                            <p>Name your league and set the basics.</p>
                        </div>
                    </article>
                    <article>
                        <span>2</span>
                        <div>
                            <h3>Invite your friends</h3>
                            <p>Send invites and build your roster.</p>
                        </div>
                    </article>
                    <article>
                        <span>3</span>
                        <div>
                            <h3>Pick your first round</h3>
                            <p>Choose from ready-made themes or create your own.</p>
                        </div>
                    </article>
                </div>
            </div>

            <aside class="marketing-commissioner-card">
                <div class="marketing-crown">♛</div>
                <h3>Commissioner control</h3>
                <ul>
                    <li>Customize rounds and themes</li>
                    <li>Set deadlines and voting windows</li>
                    <li>Choose formats and scoring</li>
                    <li>Manage seasons without chaos</li>
                </ul>
                <a href="index.php" class="marketing-btn marketing-btn-primary">Start your league</a>
            </aside>
        </div>
    </section>
</main>

</body>
</html>
