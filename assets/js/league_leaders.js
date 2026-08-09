document.addEventListener('DOMContentLoaded', function () {
    var section = document.querySelector('[data-leaders-section]');
    var stage = section ? section.querySelector('[data-leaders-stage]') : null;
    var board = stage ? stage.querySelector('[data-leaders-board]') : null;
    var detail = stage ? stage.querySelector('[data-leaders-detail]') : null;
    var detailContent = detail ? detail.querySelector('[data-leaders-detail-content]') : null;
    var backLink = detail ? detail.querySelector('[data-leaders-back]') : null;

    if (!section || !stage || !board || !detail || !detailContent || !backLink) {
        return;
    }

    var templates = {};
    var metricLinks = Array.prototype.slice.call(section.querySelectorAll('[data-leader-metric-link]'));
    var activeMetric = '';
    var detailCleanupTimer = null;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var boardUrl = stage.dataset.leadersBoardUrl || backLink.href;

    section.querySelectorAll('[data-leader-detail-template]').forEach(function (template) {
        templates[template.dataset.leaderDetailTemplate] = template;
    });

    if ('scrollRestoration' in window.history) {
        window.history.scrollRestoration = 'manual';
    }

    function plainPrimaryClick(event) {
        return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    function historyState(values) {
        var state = window.history.state && typeof window.history.state === 'object'
            ? Object.assign({}, window.history.state)
            : {};

        Object.keys(values).forEach(function (key) {
            state[key] = values[key];
        });

        return state;
    }

    function locationMetric() {
        var metric = new URL(window.location.href).searchParams.get('metric') || '';
        return templates[metric] ? metric : '';
    }

    function metricLink(metric) {
        return metricLinks.find(function (link) {
            return link.dataset.leaderMetricLink === metric;
        }) || null;
    }

    function setViewAccessibility(detailOpen) {
        if (detailOpen) {
            board.setAttribute('aria-hidden', 'true');
            board.setAttribute('inert', '');
            detail.removeAttribute('aria-hidden');
            detail.removeAttribute('inert');
        } else {
            detail.setAttribute('aria-hidden', 'true');
            detail.setAttribute('inert', '');
            board.removeAttribute('aria-hidden');
            board.removeAttribute('inert');
        }
    }

    function stageTop() {
        var stickyHeader = document.querySelector('.mb-header');
        var headerOffset = stickyHeader ? stickyHeader.getBoundingClientRect().height : 0;
        return Math.max(0, window.scrollY + section.getBoundingClientRect().top - headerOffset - 12);
    }

    function scrollToStage() {
        window.scrollTo({
            top: stageTop(),
            behavior: reduceMotion ? 'auto' : 'smooth'
        });
    }

    function replaceDetail(metric) {
        var template = templates[metric];
        if (!template) {
            return false;
        }

        while (detailContent.firstChild) {
            detailContent.removeChild(detailContent.firstChild);
        }
        detailContent.appendChild(template.content.cloneNode(true));
        return true;
    }

    function showDetail(metric, options) {
        options = options || {};
        if (!replaceDetail(metric)) {
            return;
        }

        window.clearTimeout(detailCleanupTimer);
        activeMetric = metric;
        stage.dataset.leadersActiveMetric = metric;
        setViewAccessibility(true);
        stage.classList.add('is-detail-open');

        if (options.scroll !== false) {
            scrollToStage();
        }

        if (options.focus !== false) {
            backLink.focus({ preventScroll: true });
        }
    }

    function showBoard(options) {
        options = options || {};
        var metricToRestore = options.metric || activeMetric;

        stage.classList.remove('is-detail-open');
        setViewAccessibility(false);
        stage.dataset.leadersActiveMetric = '';
        activeMetric = '';

        if (typeof options.scrollY === 'number' && Number.isFinite(options.scrollY)) {
            window.requestAnimationFrame(function () {
                window.scrollTo({
                    top: Math.max(0, options.scrollY),
                    behavior: reduceMotion ? 'auto' : 'smooth'
                });
            });
        }

        if (options.focus !== false) {
            var returnLink = metricLink(metricToRestore);
            if (returnLink) {
                returnLink.focus({ preventScroll: true });
            }
        }

        detailCleanupTimer = window.setTimeout(function () {
            if (!stage.classList.contains('is-detail-open')) {
                while (detailContent.firstChild) {
                    detailContent.removeChild(detailContent.firstChild);
                }
            }
        }, reduceMotion ? 0 : 280);
    }

    metricLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            var metric = link.dataset.leaderMetricLink || '';
            if (!plainPrimaryClick(event) || !templates[metric]) {
                return;
            }

            event.preventDefault();
            var savedScrollY = window.scrollY;
            window.history.replaceState(historyState({
                leadersView: 'board',
                leadersScrollY: savedScrollY,
                leaderMetric: metric
            }), '', window.location.href);
            window.history.pushState(historyState({
                leadersView: 'detail',
                leadersScrollY: savedScrollY,
                leaderMetric: metric
            }), '', link.href);
            showDetail(metric);
        });
    });

    backLink.addEventListener('click', function (event) {
        if (!plainPrimaryClick(event)) {
            return;
        }

        event.preventDefault();
        if (locationMetric() && window.history.state && window.history.state.leadersView === 'detail') {
            window.history.back();
            return;
        }

        var scrollY = window.history.state && Number(window.history.state.leadersScrollY);
        window.history.replaceState(historyState({
            leadersView: 'board',
            leadersScrollY: Number.isFinite(scrollY) ? scrollY : stageTop(),
            leaderMetric: activeMetric
        }), '', boardUrl);
        showBoard({
            metric: activeMetric,
            scrollY: Number.isFinite(scrollY) ? scrollY : stageTop()
        });
    });

    window.addEventListener('popstate', function (event) {
        var metric = locationMetric();
        var state = event.state && typeof event.state === 'object' ? event.state : {};
        var savedScrollY = Number(state.leadersScrollY);

        if (metric) {
            showDetail(metric, { focus: false });
            return;
        }

        showBoard({
            metric: state.leaderMetric || activeMetric,
            scrollY: Number.isFinite(savedScrollY) ? savedScrollY : stageTop()
        });
    });

    var initialMetric = stage.dataset.leadersInitialMetric || locationMetric();
    var initialScrollY = window.scrollY;
    if (initialMetric && templates[initialMetric]) {
        var directDetailUrl = window.location.href;
        window.history.replaceState(historyState({
            leadersView: 'board',
            leadersScrollY: initialScrollY,
            leaderMetric: initialMetric
        }), '', boardUrl);
        window.history.pushState(historyState({
            leadersView: 'detail',
            leadersScrollY: initialScrollY,
            leaderMetric: initialMetric
        }), '', directDetailUrl);
        showDetail(initialMetric, { scroll: false, focus: false });
    } else {
        window.history.replaceState(historyState({
            leadersView: 'board',
            leadersScrollY: initialScrollY,
            leaderMetric: ''
        }), '', window.location.href);
        showBoard({ focus: false });
    }
});
