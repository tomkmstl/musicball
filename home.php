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
    <link rel="stylesheet" href="
						<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <link rel="stylesheet" href="
							<?= htmlspecialchars(mlAssetUrl('assets/css/marketing.css')) ?>"> <?php require_once 'pwa_head.php'; ?>
  </head>
  <body class="
							<?= htmlspecialchars(mlGetThemeBodyClass()) ?> marketing-page home-page">
    <svg class="mb-symbols" style="display:none" aria-hidden="true" focusable="false">
      <symbol id="mb-icon-submit" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M3 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
        <path d="M9 17v-13h10v8" />
        <path d="M9 8h10" />
        <path d="M16 19h6" />
        <path d="M19 16v6" />
      </symbol>
      <symbol id="mb-icon-vote" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M9 11l3 3l8 -8" />
        <path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" />
      </symbol>
      <symbol id="mb-icon-build" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M14 4a1 1 0 0 1 1 -1h5a1 1 0 0 1 1 1v5a1 1 0 0 1 -1 1h-5a1 1 0 0 1 -1 -1l0 -5" />
        <path d="M3 14h12a2 2 0 0 1 2 2v3a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h3a2 2 0 0 1 2 2v12" />
      </symbol>
      <symbol id="mb-icon-music" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M11 17a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
        <path d="M17 17v-13h4" />
        <path d="M13 5h-10" />
        <path d="M3 9l10 0" />
        <path d="M9 13h-6" />
      </symbol>
      <symbol id="mb-icon-results" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" />
        <path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" />
        <path d="M9 17v-5" />
        <path d="M12 17v-1" />
        <path d="M15 17v-3" />
      </symbol>
      <symbol id="mb-icon-calendar" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" />
        <path d="M16 3l0 4" />
        <path d="M8 3l0 4" />
        <path d="M4 11l16 0" />
        <path d="M8 15h2v2h-2z" />
      </symbol>
      <symbol id="mb-icon-users" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M5 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
        <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
      </symbol>
      <symbol id="mb-icon-create" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M4 10a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
        <path d="M6 4v4" />
        <path d="M6 12v8" />
        <path d="M13.927 15.462a2 2 0 1 0 -1.927 2.538" />
        <path d="M12 4v10" />
        <path d="M12 18v2" />
        <path d="M16 7a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
        <path d="M18 4v1" />
        <path d="M18 9v3" />
        <path d="M19 22v-6" />
        <path d="M22 19l-3 -3l-3 3" />
      </symbol>
      <symbol id="mb-icon-phone" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M6 4m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" />
        <path d="M11 17h2" />
        <path d="M9 11l2 2l4 -4" />
      </symbol>
      <symbol id="mb-icon-bars" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M4 20h3" />
        <path d="M10.5 20h3" />
        <path d="M17 20h3" />
        <path d="M4 16h3" />
        <path d="M10.5 16h3" />
        <path d="M17 16h3" />
        <path d="M4 12h3" />
        <path d="M10.5 12h3" />
        <path d="M17 12h3" />
        <path d="M4 8h3" />
        <path d="M17 8h3" />
        <path d="M4 4h3" />
      </symbol>
      <symbol id="mb-icon-listen" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M4 13m0 2a2 2 0 0 1 2 -2h1v6h-1a2 2 0 0 1 -2 -2z" />
        <path d="M17 13h1a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-1z" />
        <path d="M4 15v-3a8 8 0 0 1 16 0v3" />
      </symbol>
      <symbol id="mb-icon-shield" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M12 3l8 4v5c0 5 -3.5 9 -8 9s-8 -4 -8 -9v-5z" />
        <path d="M9 12l2 2l4 -4" />
      </symbol>
      <symbol id="mb-icon-star" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M5 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
        <path d="M5 22v-5l-1 -1v-4a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4l-1 1v5" />
        <path d="M15 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
        <path d="M15 22v-4h-2l2 -6a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1l2 6h-2v4" />
      </symbol>
      <symbol id="mb-icon-play" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M7 4v16l13 -8z" />
      </symbol>
    </svg>
    <header class="marketing-nav">
      <div class="marketing-nav-inner">
        <a href="home.php" class="marketing-brand" aria-label="Musicball home">
          <img src="
											<?= htmlspecialchars(mlAssetUrl('images/musicball_logo_home.png')) ?>" alt="Musicball">
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
      <section class="hero-section section-dark hero-section-product" id="home-hero">
        <div class="hero-copy">
          <div class="hero-brand-mark" aria-label="Musicball">
            <img src="
													<?= htmlspecialchars(mlAssetUrl('images/musicball_logo_home.png')) ?>" alt="Musicball">
          </div>
          <h1>Built for music fans. <br>Designed for friends. </h1>
          <p>Submit songs, vote with friends, and turn every round into a playlist your league built together.</p>
          <div class="hero-actions">
            <a href="#start" class="btn btn-primary">Start a league</a>
            <a href="#how-it-works" class="btn btn-secondary">See how it works</a>
          </div>
          <div class="hero-notes">
            <span>Works with Spotify</span>
            <span>Takes under a minute to start</span>
          </div>
        </div>
        <div class="hero-gameplay-showcase" aria-label="Musicball gameplay preview">
          <!-- BACK CARD: STANDINGS -->
          <div class="hero-snapshot hero-snapshot-round">
            <article class="standings-preview card-glass">
              <h3>Season Standings</h3>
              <table>
                <thead>
                  <tr>
                    <th></th>
                    <th>Player</th>
                    <th>Total <br>Votes </th>
                    <th>Total <br>Voters </th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>1</td>
                    <td>
                      <i></i> Manic Arch Tour
                    </td>
                    <td>144</td>
                    <td>99</td>
                  </tr>
                  <tr>
                    <td>2</td>
                    <td>
                      <i></i> Fashion Forward
                    </td>
                    <td>143</td>
                    <td>97</td>
                  </tr>
                  <tr>
                    <td>3</td>
                    <td>
                      <i></i> Ham
                    </td>
                    <td>130</td>
                    <td>95</td>
                  </tr>
                  <tr>
                    <td>4</td>
                    <td>
                      <i></i> Lizewanana
                    </td>
                    <td>130</td>
                    <td>94</td>
                  </tr>
                </tbody>
              </table>
            </article>
          </div>
          <!-- FRONT CARD: ROUND -->
          <div class="hero-snapshot hero-snapshot-results">
            <article class="round-preview card-glass">
              <div class="eyebrow">ROUND 1</div>
              <h2>My Current Jam <span>s5</span>
              </h2>
              <p>submit by 5/8/26, 12:00 PM · vote by 5/13/26, 11:00 PM</p>
              <div class="avatar-row-wrap">
                <span>submitted:</span>
                <div class="avatar-row"> <?php for ($i = 1; $i <= 6; $i++): ?> <i class="avatar avatar-
																				<?= $i ?>">
                  </i> <?php endfor; ?> </div>
              </div>
              <div class="avatar-row-wrap">
                <span>still <br>researching: </span>
                <div class="avatar-row"> <?php for ($i = 7; $i <= 12; $i++): ?> <i class="avatar avatar-
																					<?= $i ?>">
                  </i> <?php endfor; ?> </div>
              </div>
              <div class="round-actions-mini">
                <div>
                  <svg>
                    <use href="#mb-icon-submit"></use>
                  </svg>
                  <span>Choose Song</span>
                </div>
                <div class="muted">
                  <svg>
                    <use href="#mb-icon-vote"></use>
                  </svg>
                  <span>Vote</span>
                </div>
              </div>
            </article>
          </div>
        </div>
        </div>
      </section>
      <section class="marketing-section product-story-section section-light">
        <div class="marketing-container">
          <div class="product-story-intro">
            <h2>A weekly music competition that turns into a shared playlist.</h2>
            <p>Musicball gives your friend group a reason to keep sharing songs, arguing over favorites, and building something together over time.</p>
          </div>
          <div class="product-card-grid">
            <article class="product-feature-card">
              <div class="product-feature-copy">
                <h3>Play The Round</h3>
              </div>
              <div class="product-feature-preview preview-round">
                <article class="round-preview card-glass">
                  <div class="eyebrow">ROUND 1</div>
                  <h2>My Current Jam <span>s5</span>
                  </h2>
                  <p>submit by 5/8/26, 12:00 PM · vote by 5/13/26, 11:00 PM</p>
                  <div class="avatar-row-wrap">
                    <span>submitted:</span>
                    <div class="avatar-row"> <?php for ($i = 1; $i <= 6; $i++): ?> <i class="avatar avatar-
																						<?= $i ?>">
                      </i> <?php endfor; ?> </div>
                  </div>
                  <div class="avatar-row-wrap">
                    <span>still <br>researching: </span>
                    <div class="avatar-row"> <?php for ($i = 7; $i <= 12; $i++): ?> <i class="avatar avatar-
																							<?= $i ?>">
                      </i> <?php endfor; ?> </div>
                  </div>
                  <div class="round-actions-mini">
                    <div>
                      <svg>
                        <use href="#mb-icon-submit"></use>
                      </svg>
                      <span>Choose Song</span>
                    </div>
                    <div class="muted">
                      <svg>
                        <use href="#mb-icon-vote"></use>
                      </svg>
                      <span>Vote</span>
                    </div>
                  </div>
                </article>
              </div>
              <p>Every round gives your league a prompt, a deadline, and a reason to find the perfect song.</p>
            </article>
            <article class="product-feature-card">
              <div class="product-feature-copy">
                <h3>Submit your pick</h3>
              </div>
              <div class="product-feature-preview preview-song">
                <article class="song-confirm-preview card-glass">
                  <div class="eyebrow">CONFIRM YOUR SONG</div>
                  <div class="song-confirm-panel">
                    <div class="song-confirm-card-inner">
                      <img class="song-confirm-artwork" src="https://i.scdn.co/image/ab67616d0000b273dc52a67943ab8838fc661a94" alt="LWA IN THE TRAILER PARK album art">
                      <div>
                        <div class="song-confirm-title">LWA IN THE TRAILER PARK</div>
                        <div class="song-confirm-meta">Benjamin Booker · LOWER</div>
                        <p class="song-confirm-note">Benjamin Booker has been chosen 0 times in past rounds.</p>
                        <p class="song-confirm-note">Your song is not saved yet. Confirm below to lock in this pick.</p>
                      </div>
                    </div>
                    <div class="song-confirm-actions">
                      <div class="song-confirm-primary">Confirm Song</div>
                      <div class="song-confirm-secondary">Cancel</div>
                    </div>
                  </div>
                  <div class="song-preview-stack">
                    <div class="song-current-panel">
                      <div class="eyebrow">YOUR CURRENT PICK</div>
                      <p>No song chosen yet.</p>
                      <div class="song-preview-comment">Wow, this hit me like a ton of bricks.</div>
                      <p>This comment will save with your song when you pick one.</p>
                    </div>
                    <div class="song-search-panel">
                      <div class="eyebrow">SPOTIFY SEARCH</div>
                      <div class="song-search-title">Find a song</div>
                      <p>Start typing a title, artist, album, Spotify track URL, or Spotify track URI.</p>
                      <div class="song-search-row-preview">
                        <div class="song-search-input-preview">Search Spotify or paste a Spotify track link</div>
                        <div class="song-search-button-preview">Search</div>
                      </div>
                      <p>Start typing to search Spotify.</p>
                    </div>
                  </div>
                </article>
              </div>
              <p>Players search Spotify, lock in a song, and add the context that makes their choice personal.</p>
            </article>
            <article class="product-feature-card product-feature-card-highlight">
              <div class="product-feature-copy">
                <h3>Cast Your Votes</h3>
              </div>
              <div class="product-feature-preview preview-vote">
                <article class="vote-preview card-glass">
                  <div class="eyebrow">ROUND VOTING</div>
                  <h2>Songs to Hear while Leaving this World</h2>
                  <div class="vote-preview-header">
                    <strong>total votes given</strong>
                    <span>5 / 10</span>
                  </div>
                  <p class="vote-preview-max">Max per song: 4</p>
                  <div class="vote-preview-list">
                    <section class="vote-preview-item">
                      <div class="vote-preview-main">
                        <img src="https://i.scdn.co/image/ab67616d0000b273201a9af6d3296d20a205adb5" alt="">
                        <div>
                          <strong>Song To The Siren - Remastered</strong>
                          <span>This Mortal Coil · It'll End In Tears (Remastered)</span>
                        </div>
                        <div class="vote-preview-controls">
                          <button type="button">−</button>
                          <b>3</b>
                          <button type="button">+</button>
                        </div>
                      </div>
                      <label>Comment</label>
                      <div class="vote-preview-comment">Beautiful.</div>
                    </section>
                    <section class="vote-preview-item">
                      <div class="vote-preview-main">
                        <img src="https://i.scdn.co/image/ab67616d0000b273ca69f52416a728ebd0b9103c" alt="">
                        <div>
                          <strong>Do You Realize??</strong>
                          <span>The Flaming Lips · Yoshimi Battles the Pink Robots</span>
                        </div>
                        <div class="vote-preview-controls">
                          <button type="button">−</button>
                          <b>1</b>
                          <button type="button">+</button>
                        </div>
                      </div>
                      <label>Comment</label>
                      <div class="vote-preview-comment">Hasn't gotten old this is a good one.</div>
                    </section>
                    <section class="vote-preview-item">
                      <div class="vote-preview-main">
                        <img src="https://i.scdn.co/image/ab67616d0000b27319575a7b324f9c7e3a1d1139" alt="">
                        <div>
                          <strong>Loose Ends</strong>
                          <span>Great Northern · Sleepy Eepee</span>
                        </div>
                        <div class="vote-preview-controls">
                          <button type="button">−</button>
                          <b>0</b>
                          <button type="button">+</button>
                        </div>
                      </div>
                      <label>Comment</label>
                      <div class="vote-preview-comment">I get it, it belongs here. Just missed on votes.</div>
                    </section>
                  </div>
                </article>
              </div>
              <p>Distribute your points across the ballot. Every vote changes the round and the standings.</p>
            </article>
            <article class="product-feature-card">
              <div class="product-feature-copy">
                <h3>Reveal Round Winners</h3>
              </div>
              <div class="product-feature-preview preview-results">
                <article class="results-preview card-glass">
                  <div class="eyebrow">ROUND RESULTS</div>
                  <h2>100M+ Listens</h2>
                  <p>A song with more than 100 million listens</p>
                  <div class="results-podium">
                    <div>
                      <span>1ST</span>
                      <div class="avatar avatar-1"></div>
                      <strong>Lake</strong>
                      <p>22 pts</p>
                    </div>
                    <div>
                      <span>2ND</span>
                      <div class="avatar avatar-2"></div>
                      <strong>Das Bot</strong>
                      <p>15 pts</p>
                    </div>
                    <div>
                      <span>3RD</span>
                      <div class="avatar avatar-3"></div>
                      <strong>Marty McFly</strong>
                      <p>12 pts</p>
                    </div>
                  </div>
                  <div class="results-song">
                    <img src="https://i.scdn.co/image/ab67616d0000b273dc52a67943ab8838fc661a94" alt="">
                    <div>
                      <div class="results-song-title">#1 · Chum</div>
                      <div class="results-song-meta">Earl Sweatshirt · Doris</div>
                    </div>
                    <div class="results-score">
                      <strong>22</strong>
                      <span>10 voters</span>
                    </div>
                  </div>
                  <div class="results-submitted">
                    <div class="avatar avatar-1"></div>
                    <span>Submitted by Lake</span>
                  </div>
                  <div class="results-comments">
                    <div class="results-comment">
                      <div class="avatar avatar-4"></div>
                      <div>
                        <strong>Fashion Forward</strong>
                        <p>I’ve probably heard this song before, but I obviously never really listened to it. I love everything about this song...</p>
                      </div>
                      <span class="results-points">3</span>
                    </div>
                    <div class="results-comment">
                      <div class="avatar avatar-5"></div>
                      <div>
                        <strong>Ham</strong>
                        <p>Incredible.</p>
                      </div>
                      <span class="results-points">3</span>
                    </div>
                    <div class="results-comment">
                      <div class="avatar avatar-6"></div>
                      <div>
                        <strong>Manic Arch Tour</strong>
                        <p>I listened to this so many times, really liked the throw back feel...</p>
                      </div>
                      <span class="results-points">3</span>
                    </div>
                  </div>
                </article>
              </div>
              <p>See what won, who submitted it, how people voted, and what the league had to say.</p>
            </article>
            <article class="product-feature-card">
              <div class="product-feature-copy">
                <h3>Track The Results</h3>
              </div>
              <div class="product-feature-preview preview-standings">
                <article class="standings-preview card-glass">
                  <h3>Season Standings</h3>
                  <table>
                    <thead>
                      <tr>
                        <th></th>
                        <th>Player</th>
                        <th>Total <br>Votes </th>
                        <th>Total <br>Voters </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>1</td>
                        <td>
                          <i></i> Manic Arch Tour
                        </td>
                        <td>144</td>
                        <td>99</td>
                      </tr>
                      <tr>
                        <td>2</td>
                        <td>
                          <i></i> Fashion Forward
                        </td>
                        <td>143</td>
                        <td>97</td>
                      </tr>
                      <tr>
                        <td>3</td>
                        <td>
                          <i></i> Ham
                        </td>
                        <td>130</td>
                        <td>94</td>
                      </tr>
                      <tr>
                        <td>4</td>
                        <td>
                          <i></i> Lizewanana
                        </td>
                        <td>130</td>
                        <td>90</td>
                      </tr>
                      <tr>
                        <td>5</td>
                        <td>
                          <i></i> Lake
                        </td>
                        <td>130</td>
                        <td>85</td>
                      </tr>
                      <tr>
                        <td>6</td>
                        <td>
                          <i></i> Brett
                        </td>
                        <td>124</td>
                        <td>90</td>
                      </tr>
                    </tbody>
                  </table>
                </article>
              </div>
              <p>Points add up. See where everyone stands.</p>
            </article>
            <article class="product-feature-card">
              <div class="product-feature-copy">
                <h3>Listen Back Anytime</h3>
              </div>
              <div class="product-feature-preview preview-playlist">
                <article class="playlist-preview card-glass">
                  <div class="eyebrow">PLAYLISTS</div>
                  <h2>Scone Ghetto</h2>
                  <section class="playlist-overview-preview">
                    <div>
                      <div class="eyebrow">ALL-TIME LEAGUE PLAYLIST</div>
                      <h3>Scone Ghetto</h3>
                      <strong>719 songs</strong>
                      <p>Every song from every generated round playlist, in league order from the first eligible round to the latest eligible round.</p>
                    </div>
                    <svg>
                      <use href="#mb-icon-music"></use>
                    </svg>
                  </section>
                  <h3 class="playlist-player-heading">Player Playlists</h3>
                  <div class="playlist-player-grid-preview">
                    <div class="playlist-player-card-preview">
                      <span class="playlist-avatar-preview"></span>
                      <div>
                        <strong>Das Bot's Songs</strong>
                        <p>60 songs</p>
                      </div>
                      <svg>
                        <use href="#mb-icon-music"></use>
                      </svg>
                    </div>
                    <div class="playlist-player-card-preview">
                      <span class="playlist-avatar-preview avatar-two"></span>
                      <div>
                        <strong>Ham's Songs</strong>
                        <p>60 songs</p>
                      </div>
                      <svg>
                        <use href="#mb-icon-music"></use>
                      </svg>
                    </div>
                    <div class="playlist-player-card-preview">
                      <span class="playlist-avatar-preview avatar-three"></span>
                      <div>
                        <strong>Fashion Forward's Songs</strong>
                        <p>60 songs</p>
                      </div>
                      <svg>
                        <use href="#mb-icon-music"></use>
                      </svg>
                    </div>
                    <div class="playlist-player-card-preview">
                      <span class="playlist-avatar-preview avatar-four"></span>
                      <div>
                        <strong>Manic Arch Tour's Songs</strong>
                        <p>60 songs</p>
                      </div>
                      <svg>
                        <use href="#mb-icon-music"></use>
                      </svg>
                    </div>
                  </div>
                </article>
              </div>
              <p>Every round builds a playlist you can keep.</p>
            </article>
          </div>
        </div>
      </section>
      <section id="how-it-works" class="how-section marketing-section">
        <div class="marketing-container">
          <div class="section-heading centered">
            <h2>How Musicball works</h2>
          </div>
          <div class="how-steps">
            <article>
              <svg>
                <use href="#mb-icon-submit"></use>
              </svg>
              <h3>1. Submit</h3>
              <p>Pick a song that fits the round theme and submit before the deadline.</p>
            </article>
            <article>
              <svg>
                <use href="#mb-icon-vote"></use>
              </svg>
              <h3>2. Vote</h3>
              <p>Rank your favorite songs. Points are awarded automatically.</p>
            </article>
            <article>
              <svg>
                <use href="#mb-icon-build"></use>
              </svg>
              <h3>3. Build</h3>
              <p>Standings update and the playlist grows. On to the next round!</p>
            </article>
          </div>
          <div class="info-card what-card">
            <div class="section-heading centered">
              <h2 style="font-size:40px;">What you get</h2>
            </div>
            <div class="what-grid">
              <div>
                <svg>
                  <use href="#mb-icon-listen"></use>
                </svg>
                <strong>Shared playlist</strong>
                <p>Every round, in order.</p>
              </div>
              <div>
                <svg>
                  <use href="#mb-icon-music"></use>
                </svg>
                <strong>Personal playlists</strong>
                <p>Your picks, all season.</p>
              </div>
              <div>
                <svg>
                  <use href="#mb-icon-bars"></use>
                </svg>
                <strong>Season standings</strong>
                <p>Track your progress.</p>
              </div>
              <div>
                <svg>
                  <use href="#mb-icon-star"></use>
                </svg>
                <strong>Lasting memories</strong>
                <p>The songs stick around.</p>
              </div>
            </div>
          </div>
        </div>
      </section>
      <section id="start" class="start-section marketing-section">
        <div class="start-layout">
          <div>
            <h2>Start a league in <br>under a minute </h2>
            <p>Invite your friends, pick your first theme, <br>and let the games begin. </p>
            <div class="start-card steps-card">
              <article>
                <svg>
                  <use href="#mb-icon-person"></use>
                </svg>
                <div>
                  <h3>1. Create your league</h3>
                  <p>Name your league and set the basics.</p>
                </div>
              </article>
              <article>
                <svg>
                  <use href="#mb-icon-users"></use>
                </svg>
                <div>
                  <h3>2. Invite your friends</h3>
                  <p>Send invites and build your roster.</p>
                </div>
              </article>
              <article>
                <svg>
                  <use href="#mb-icon-phone"></use>
                </svg>
                <div>
                  <h3>3. Pick your first round</h3>
                  <p>Choose from ready-made themes or create your own.</p>
                </div>
              </article>
            </div>
            <div class="start-card commissioner-card">
              <h3>
                <span>♛</span> Commissioner control
              </h3>
              <ul>
                <li>Customize rounds and themes</li>
                <li>Set deadlines and voting windows</li>
                <li>Choose formats and scoring</li>
                <li>Many options. Total flexibility.</li>
              </ul>
            </div>
            <a href="index.php" class="btn btn-primary btn-wide">Start your league</a>
          </div>
        </div>
      </section>
      <section id="features" class="features-section marketing-section">
        <div class="marketing-container">
          <div class="section-heading centered">
            <h2>Everything you need for <br>the ultimate music league </h2>
          </div>
          <div class="feature-grid">
            <article>
              <span class="spotify-dot">●</span>
              <div>
                <h3>Spotify Integration</h3>
                <p>Add songs, listen, and build playlists with one click.</p>
              </div>
            </article>
            <article>
              <svg>
                <use href="#mb-icon-bars"></use>
              </svg>
              <div>
                <h3>League Standings</h3>
                <p>Track wins, podiums, points, and season history.</p>
              </div>
            </article>
            <article>
              <svg>
                <use href="#mb-icon-vote"></use>
              </svg>
              <div>
                <h3>Voting & Scoring</h3>
                <p>Rank songs, earn points, climb the standings.</p>
              </div>
            </article>
            <article>
              <svg>
                <use href="#mb-icon-listen"></use>
              </svg>
              <div>
                <h3>Playlist History</h3>
                <p>Every round lives on in your league playlist.</p>
              </div>
            </article>
            <article>
              <svg>
                <use href="#mb-icon-build"></use>
              </svg>
              <div>
                <h3>Round Themes</h3>
                <p>Eras, madlibs, custom prompts, and more.</p>
              </div>
            </article>
            <article>
              <svg>
                <use href="#mb-icon-shield"></use>
              </svg>
              <div>
                <h3>Commissioner Tools</h3>
                <p>Powerful tools to run your league your way.</p>
              </div>
            </article>
          </div>
          <div class="built-banner">
            <div>
              <h2>Build playlists with your friends.</h2>
              <p>Compete, vote, and discover music together — then keep what you create.</p>
            </div>
            <svg>
              <use href="#mb-icon-music"></use>
            </svg>
          </div>
        </div>
      </section>
    </main>
    <script>
      (function() {
        var hero = document.getElementById('home-hero');
        if (!hero) {
          return;
        }

        function updateHeaderLogo() {
          var heroBottom = hero.getBoundingClientRect().bottom;
          document.body.classList.toggle('hero-passed', heroBottom <= 24);
        }
        updateHeaderLogo();
        window.addEventListener('scroll', updateHeaderLogo, {
          passive: true
        });
        window.addEventListener('resize', updateHeaderLogo);
      })();
      (function() {
        var hero = document.getElementById('home-hero');
        if (!hero) {
          return;
        }

        function updateMobileNav() {
          var heroBottom = hero.getBoundingClientRect().bottom;
          document.body.classList.toggle('hero-passed', heroBottom <= 20);
        }
        updateMobileNav();
        window.addEventListener('scroll', updateMobileNav, {
          passive: true
        });
        window.addEventListener('resize', updateMobileNav);
      })();
    </script>
  </body>
</html>