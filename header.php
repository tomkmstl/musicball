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
$headerUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
$isAdminUser = mlIsAdminUserId($pdo, $headerUserId);
$mlIsQaMode = function_exists('mlIsQaMode') && mlIsQaMode();
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
<header class="mb-header">
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

            <?php $settingsHref = ($currentPage === 'settings') ? mlUrl('season.php') : mlUrl('settings.php'); ?>
            <a href="<?= htmlspecialchars($settingsHref) ?>" class="mb-settings-link<?= $currentPage === 'settings' ? ' is-active' : '' ?>" aria-label="<?= $currentPage === 'settings' ? 'Close settings' : 'Open settings' ?>">
                <span class="mb-settings-gear" aria-hidden="true">⚙</span>
            </a>
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
    var pageTransitionLinks = Array.prototype.slice.call(document.querySelectorAll('.game-round-action-link[href*="song.php"], .game-round-action-link[href*="vote.php"], .game-round-action-link[href*="results.php"], .mb-settings-link[href], .mb-next-season-link[href]'));
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
            closeLightbox();
        }
    });
});
</script>
