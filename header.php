<?php
// header.php

$showHeader = isset($_SESSION['UserID']) || isset($_SESSION['ml_user_id']);

if (!$showHeader) {
    return;
}

$currentPage = isset($currentPage) ? (string)$currentPage : '';
$headerScriptName = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$headerActivePrimaryPage = '';
if ($headerScriptName === 'season.php') {
    $headerActivePrimaryPage = 'season';
} elseif ($headerScriptName === 'standings.php') {
    $headerActivePrimaryPage = 'standings';
}
$headerUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : (isset($_SESSION['ml_user_id']) ? (int)$_SESSION['ml_user_id'] : 0);
$isAdminUser = mlIsAdminUserId($pdo, $headerUserId);
$mlIsQaMode = function_exists('mlIsQaMode') && mlIsQaMode();
$showAdminToolsBanner = !$mlIsQaMode && $headerScriptName === 'admin.php';
$nextSeasonImageSrc = 'images/next_season.png';
$nextSeasonImagePath = __DIR__ . '/images/next_season.png';
$hasNextSeasonImage = is_file($nextSeasonImagePath);
$isPrimaryNavPage = ($headerActivePrimaryPage !== '');
$headerNextSeason = isset($nextSeason) && is_array($nextSeason) ? $nextSeason : mlGetNextSeason($pdo);
$headerShowNextSeasonButton = false;
$headerNextSeasonHref = mlUrl('season-builder/sb_vote.php');
$headerNextSeasonLabel = 'Vote For Next Season';
$headerNextSeasonAria = 'Vote For Next Season';
$headerNextSeasonSubmittedCount = 0;
$headerNextSeasonTotalUsers = 0;
$headerNextSeasonProgressPercent = 0;

if ($headerNextSeason) {
    $headerNextSeasonId = (int)$headerNextSeason['SeasonID'];
    $headerNextVotingOpen = mlIsSeasonVotingOpen($pdo, $headerNextSeasonId);
    if ($headerNextVotingOpen) {
        $headerShowNextSeasonButton = true;
        $headerNextSeasonSubmittedCount = mlGetSeasonSubmissionCount($pdo, $headerNextSeasonId);
        $headerNextSeasonTotalUsers = mlGetTotalUserCount($pdo);

        if ($headerNextSeasonTotalUsers > 0) {
            $headerNextSeasonProgressPercent = (int)round(($headerNextSeasonSubmittedCount / $headerNextSeasonTotalUsers) * 100);
            $headerNextSeasonProgressPercent = max(0, min(100, $headerNextSeasonProgressPercent));
        }

        if (mlIsSeasonVotingComplete($pdo, $headerNextSeasonId)) {
            $headerNextSeasonHref = mlUrl('final.php');
            $headerNextSeasonLabel = 'View Next Season';
            $headerNextSeasonAria = 'View Next Season';
            $nextSeasonImageSrc = 'images/view_next_season.png';
            $nextSeasonImagePath = __DIR__ . '/images/view_next_season.png';
            $hasNextSeasonImage = is_file($nextSeasonImagePath);
        }
    }
}
?>
<?php if ($mlIsQaMode): ?>
    <div class="ml-qa-banner" style="background:#7a0019;color:#fff;padding:10px 14px;text-align:center;font-weight:700;letter-spacing:.02em;">
        QA MODE ACTIVE &nbsp;|&nbsp; <a href="<?= htmlspecialchars(mlUrl('admin.php?testing=live')) ?>" style="color:#fff;text-decoration:underline;">Return to live</a>
    </div>
<?php endif; ?>
<?php if ($showAdminToolsBanner): ?>
    <div class="ml-admin-tools-banner" style="background:#12324f;color:#fff;padding:10px 14px;text-align:center;font-weight:700;letter-spacing:.02em;">
        ADMIN TOOLS &nbsp;|&nbsp; <a href="<?= htmlspecialchars(mlUrl('season.php?testing=live')) ?>" style="color:#fff;text-decoration:underline;">Return Home</a>
    </div>
<?php endif; ?>
<header class="mb-header">
    <style>
        .mb-account-menu {
            position: relative;
        }

        .mb-account-menu summary {
            list-style: none;
        }

        .mb-account-menu summary::-webkit-details-marker {
            display: none;
        }

        .mb-menu-toggle {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line-strong);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text);
            cursor: pointer;
            transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .mb-menu-toggle:hover,
        .mb-account-menu[open] .mb-menu-toggle {
            background: var(--surface-3);
            border-color: var(--brand);
        }

        .mb-menu-icon {
            width: 18px;
            display: grid;
            gap: 4px;
        }

        .mb-menu-icon span {
            display: block;
            height: 2px;
            border-radius: 999px;
            background: currentColor;
        }

        .mb-account-menu-panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(240px, calc(100vw - 32px));
            padding: 8px;
            border: 1px solid var(--line-strong);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.28);
            z-index: 1200;
        }

        .mb-account-menu-link {
            display: flex;
            align-items: center;
            min-height: 42px;
            padding: 10px 12px;
            border-radius: 9px;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        .mb-account-menu-link:hover,
        .mb-account-menu-link.is-active {
            background: var(--surface-3);
            color: var(--text);
        }

        .mb-account-menu-divider {
            height: 1px;
            margin: 8px 4px;
            background: var(--line);
        }
    </style>
    <div class="mb-header-inner">
        <img src="<?= htmlspecialchars(mlAssetUrl('images/musicball_logo.png')) ?>" alt="<?= htmlspecialchars(mlGetLeagueName($pdo)) ?>" class="mb-brand-logo">

        <div class="mb-header-actions" aria-label="Utility navigation">
            <?php if ($headerShowNextSeasonButton): ?>
                <?php if ($headerNextSeasonTotalUsers > 0): ?>
                    <div class="mb-next-season-progress" aria-label="<?= htmlspecialchars($headerNextSeasonSubmittedCount . ' of ' . $headerNextSeasonTotalUsers . ' players have submitted') ?>">
                        <span class="mb-next-season-progress-chart" style="--mb-progress: <?= (int)$headerNextSeasonProgressPercent ?>%;"></span>
                        <span class="mb-next-season-progress-text">
                            <?= (int)$headerNextSeasonSubmittedCount ?>/<?= (int)$headerNextSeasonTotalUsers ?>
                        </span>
                    </div>
                <?php endif; ?>

                <a href="<?= htmlspecialchars($headerNextSeasonHref) ?>" class="mb-next-season-link<?= $currentPage === 'vote' ? ' is-active' : '' ?>" aria-label="<?= htmlspecialchars($headerNextSeasonAria) ?>">
                    <?php if ($hasNextSeasonImage): ?>
                        <img src="<?= htmlspecialchars(mlAssetUrl($nextSeasonImageSrc)) ?>" alt="<?= htmlspecialchars($headerNextSeasonLabel) ?>" class="mb-next-season-image">
                    <?php else: ?>
                        <span class="mb-next-season-text"><?= htmlspecialchars($headerNextSeasonLabel) ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <details class="mb-account-menu">
                <summary class="mb-menu-toggle" aria-label="Open menu">
                    <span class="mb-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
                </summary>
                <nav class="mb-account-menu-panel" aria-label="Account menu">
                    <a href="<?= htmlspecialchars(mlUrl('playlists.php')) ?>" class="mb-account-menu-link<?= $currentPage === 'playlists' ? ' is-active' : '' ?>">Playlists</a>
                    <a href="<?= htmlspecialchars(mlUrl('league-database.php')) ?>" class="mb-account-menu-link<?= $currentPage === 'league-database' ? ' is-active' : '' ?>">League Database</a>
                    <?php if ($isAdminUser): ?>
                        <a href="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="mb-account-menu-link<?= $currentPage === 'admin' ? ' is-active' : '' ?>">Admin Tools</a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars(mlUrl('settings.php')) ?>" class="mb-account-menu-link<?= $currentPage === 'settings' ? ' is-active' : '' ?>">Settings</a>
                    <div class="mb-account-menu-divider" aria-hidden="true"></div>
                    <a href="<?= htmlspecialchars(mlUrl('logout.php')) ?>" class="mb-account-menu-link">Logout</a>
                </nav>
            </details>
        </div>
    </div>

    <div class="mb-header-subnav-row<?= $currentPage === 'admin' ? ' mb-header-subnav-row-admin-mobile-hidden' : '' ?>">
        <div class="mb-subnav<?= $isPrimaryNavPage ? ' is-contextual' : '' ?>">
            <a href="<?= htmlspecialchars(mlUrl('season.php')) ?>" class="mb-subnav-card<?= $headerActivePrimaryPage === 'season' ? ' is-active' : '' ?>">
                Season
            </a>
            <a href="<?= htmlspecialchars(mlUrl('standings.php')) ?>" class="mb-subnav-card<?= $headerActivePrimaryPage === 'standings' ? ' is-active' : '' ?>">
                Standings
            </a>
        </div>
    </div>
</header>
<div class="mb-image-lightbox" id="mb-image-lightbox" hidden aria-hidden="true">
    <button type="button" class="mb-image-lightbox-close" id="mb-image-lightbox-close" aria-label="Close photo viewer">&times;</button>
    <img src="" alt="" class="mb-image-lightbox-image" id="mb-image-lightbox-image">
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var subnav = document.querySelector('.mb-subnav');
    var subnavLinks = Array.prototype.slice.call(document.querySelectorAll('.mb-subnav-card'));
    var pageTransitionLinks = Array.prototype.slice.call(document.querySelectorAll('.game-round-action-link[href*="song.php"], .game-round-action-link[href*="vote.php"], .game-round-action-link[href*="results.php"], .mb-next-season-link[href], .mb-account-menu-link[href]'));
    var accountMenu = document.querySelector('.mb-account-menu');
    var lightbox = document.getElementById('mb-image-lightbox');
    var lightboxImage = document.getElementById('mb-image-lightbox-image');
    var lightboxClose = document.getElementById('mb-image-lightbox-close');

    function isModifiedClick(event, link) {
        return !!(
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            (link && link.target === '_blank')
        );
    }

    function beginPageTransition(clickedLink, options) {
        options = options || {};

        if (!clickedLink) {
            return;
        }

        var href = clickedLink.getAttribute('href');
        if (!href) {
            return;
        }

        document.body.classList.add('mb-page-leaving');

        if (options.clearSubnavState) {
            subnavLinks.forEach(function (otherLink) {
                otherLink.classList.remove('is-pressed', 'is-leaving', 'is-active');
            });
        }

        clickedLink.classList.add('is-pressed', 'is-leaving');

        if (options.activateSubnavLink) {
            clickedLink.classList.add('is-active');
        }

        if (subnav && options.markSubnavLoading) {
            subnav.classList.add('is-loading');
        }

        window.setTimeout(function () {
            window.location.href = href;
        }, 140);
    }

    function openLightbox(image) {
        if (!lightbox || !lightboxImage || !image) {
            return;
        }

        lightboxImage.src = image.getAttribute('src') || '';
        lightboxImage.alt = image.getAttribute('alt') || 'Profile photo';
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.classList.add('mb-lightbox-open');
    }

    function closeLightbox() {
        if (!lightbox || !lightboxImage) {
            return;
        }

        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        lightboxImage.src = '';
        lightboxImage.alt = '';
        document.body.classList.remove('mb-lightbox-open');
    }

    subnavLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (isModifiedClick(e, this)) {
                return;
            }

            e.preventDefault();
            beginPageTransition(this, {
                clearSubnavState: true,
                activateSubnavLink: true,
                markSubnavLoading: true
            });
        });
    });

    pageTransitionLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (isModifiedClick(e, this)) {
                return;
            }

            e.preventDefault();
            beginPageTransition(this, {
                clearSubnavState: false,
                activateSubnavLink: false,
                markSubnavLoading: false
            });
        });
    });

    document.addEventListener('click', function (e) {
        if (accountMenu && accountMenu.open && !accountMenu.contains(e.target)) {
            accountMenu.open = false;
        }

        var profileImage = e.target.closest('img.profile-avatar');
        if (!profileImage) {
            return;
        }

        if (profileImage.closest('.mb-image-lightbox')) {
            return;
        }

        e.preventDefault();
        openLightbox(profileImage);
    });

    if (lightboxClose) {
        lightboxClose.addEventListener('click', function () {
            closeLightbox();
        });
    }

    if (lightbox) {
        lightbox.addEventListener('click', function (e) {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (accountMenu && accountMenu.open) {
                accountMenu.open = false;
            }
            closeLightbox();
        }
    });
});
</script>
