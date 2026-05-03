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
        </div>

        <div class="marketing-container">
            <div class="marketing-process-grid">
                <article>
                    <div class="marketing-process-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/submit-song.svg')) ?>" alt="Submit">
                    </div>
                    <h3>1. Submit</h3>
                </article>
                <article>
                    <div class="marketing-process-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/vote.svg')) ?>" alt="Vote">
                    </div>
                    <h3>2. Vote</h3>
                </article>
                <article>
                    <div class="marketing-process-icon">
                        <img src="<?= htmlspecialchars(mlAssetUrl('assets/icons/marketing/results.svg')) ?>" alt="Results">
                    </div>
                    <h3>3. Results</h3>
                </article>
            </div>
        </div>
    </section>

</main>

</body>
</html>
