(function () {
    var themeMeta = document.querySelector('meta[name="theme-color"]');
    var body = document.body;
    var swUrl = window.ML_SW_URL || 'service-worker.js';

    var pullThreshold = 110;
    var maxPullDistance = 120;

    var startY = 0;
    var currentPull = 0;
    var trackingPull = false;
    var pullTriggered = false;
    var refreshIndicator = null;

    function updateThemeColor() {
        if (!themeMeta || !body) return;

        if (body.classList.contains('theme-light')) {
            themeMeta.setAttribute('content', '#f4f7fb');
        } else {
            themeMeta.setAttribute('content', '#000614');
        }
    }

    function ensureRefreshIndicator() {
        if (refreshIndicator || !document.body) return;

        refreshIndicator = document.createElement('div');
        refreshIndicator.className = 'ml-pull-refresh';
        refreshIndicator.setAttribute('aria-hidden', 'true');
        refreshIndicator.innerHTML =
            '<div class="ml-pull-refresh-pill">' +
            '<span class="ml-pull-refresh-text">Pull to refresh</span>' +
            '</div>';

        document.body.appendChild(refreshIndicator);
    }

    function getScrollTop() {
        return window.pageYOffset ||
            document.documentElement.scrollTop ||
            document.body.scrollTop || 0;
    }

    function setPullDistance(distance) {
        ensureRefreshIndicator();

        if (!document.body || !refreshIndicator) return;

        var safeDistance = Math.max(0, Math.min(maxPullDistance, distance));
        currentPull = safeDistance;

        document.body.style.setProperty('--ml-pull-distance', safeDistance + 'px');
        document.body.classList.toggle('ml-pull-active', safeDistance > 0);
        document.body.classList.toggle('ml-pull-ready', safeDistance >= pullThreshold);

        var textNode = refreshIndicator.querySelector('.ml-pull-refresh-text');
        if (textNode) {
            textNode.textContent =
                safeDistance >= pullThreshold
                    ? 'Release to refresh'
                    : 'Pull to refresh';
        }
    }

    function resetPullState() {
        trackingPull = false;
        pullTriggered = false;
        startY = 0;
        setPullDistance(0);

        if (document.body) {
            document.body.classList.remove('ml-pull-refreshing');
        }
    }

    function triggerRefresh() {
        if (pullTriggered) return;

        pullTriggered = true;

        if (document.body) {
            document.body.classList.add('ml-pull-refreshing');
        }

        if (refreshIndicator) {
            var textNode = refreshIndicator.querySelector('.ml-pull-refresh-text');
            if (textNode) {
                textNode.textContent = 'Refreshing...';
            }
        }

        setTimeout(function () {
            window.location.reload();
        }, 120);
    }

    function handleTouchStart(event) {
        if (!event.touches || event.touches.length !== 1) return;

        if (getScrollTop() > 0 || pullTriggered) return;

        startY = event.touches[0].clientY;
        trackingPull = true;
    }

    function handleTouchMove(event) {
        if (!trackingPull || !event.touches || event.touches.length !== 1) return;

        var currentY = event.touches[0].clientY;
        var delta = currentY - startY;

        if (delta <= 0) {
            setPullDistance(0);
            return;
        }

        if (getScrollTop() > 0) {
            resetPullState();
            return;
        }

        var easedDistance = Math.min(maxPullDistance, delta * 0.55);
        setPullDistance(easedDistance);

        if (easedDistance > 6) {
            event.preventDefault();
        }
    }

    function handleTouchEnd() {
        if (!trackingPull) return;

        if (currentPull >= pullThreshold) {
            triggerRefresh();
            return;
        }

        resetPullState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            updateThemeColor();
            ensureRefreshIndicator();
        });
    } else {
        updateThemeColor();
        ensureRefreshIndicator();
    }

    document.addEventListener('touchstart', handleTouchStart, { passive: true });
    document.addEventListener('touchmove', handleTouchMove, { passive: false });
    document.addEventListener('touchend', handleTouchEnd, { passive: true });
    document.addEventListener('touchcancel', resetPullState, { passive: true });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(swUrl).catch(function (error) {
                console.error('Service worker registration failed:', error);
            });
        });
    }
})();
