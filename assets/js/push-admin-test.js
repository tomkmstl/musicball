(function () {
    var root = document.querySelector('[data-push-admin-test]');
    var config = window.ML_PUSH_ADMIN_TEST || null;

    if (!root || !config) return;

    var statusNode = root.querySelector('[data-push-admin-status]');
    var typeSelect = root.querySelector('[data-push-admin-type]');
    var sendButton = root.querySelector('[data-push-admin-send]');
    var browserSubscription = null;
    var serverSubscribed = false;
    var busy = false;

    if (typeSelect) typeSelect.disabled = true;
    if (sendButton) sendButton.disabled = true;

    function isReady() {
        return !!browserSubscription
            && serverSubscribed
            && 'Notification' in window
            && Notification.permission === 'granted';
    }

    function setStatus(message, state) {
        if (!statusNode) return;
        statusNode.textContent = message;
        statusNode.dataset.state = state || '';
    }

    function setBusy(nextBusy) {
        var ready = isReady();
        busy = nextBusy;
        if (typeSelect) typeSelect.disabled = busy || !ready;
        if (sendButton) sendButton.disabled = busy || !ready;
        root.classList.toggle('is-busy', busy);
    }

    function render() {
        var ready = isReady();

        setBusy(false);
        if (ready) {
            setStatus('Ready on this device', 'ready');
        } else if ('Notification' in window && Notification.permission === 'denied') {
            setStatus('Blocked in this device\'s notification settings', 'error');
        } else {
            setStatus('Turn on Push Notifications for this device in Settings first.', 'unavailable');
        }
    }

    function request(action, payload) {
        return fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-ML-Push-CSRF': config.csrfToken
            },
            body: JSON.stringify(Object.assign({ action: action }, payload || {}))
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'The server returned an invalid response.' };
            }).then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body.error || 'The request could not be completed.');
                }
                return body;
            });
        });
    }

    function initialize() {
        if (!config.ready) {
            setBusy(false);
            setStatus('Push notifications are not available yet.', 'unavailable');
            return;
        }

        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            setBusy(false);
            setStatus('Push notifications are not supported on this device.', 'unavailable');
            return;
        }

        navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.getSubscription();
        }).then(function (subscription) {
            browserSubscription = subscription;

            if (!browserSubscription) {
                render();
                return null;
            }

            return request('status', { endpoint: browserSubscription.endpoint }).then(function (body) {
                serverSubscribed = !!body.subscribed;
                render();
            });
        }).catch(function () {
            setBusy(false);
            setStatus('Push notification status could not be checked.', 'error');
        });
    }

    if (sendButton) {
        sendButton.addEventListener('click', function () {
            if (busy || !browserSubscription || !serverSubscribed) return;

            setBusy(true);
            request('test', {
                endpoint: browserSubscription.endpoint,
                notification_type: typeSelect ? typeSelect.value : 'connection_test'
            }).then(function () {
                setBusy(false);
                setStatus('Test sent', 'sent');
            }).catch(function (error) {
                setBusy(false);
                setStatus(error.message || 'The test could not be sent.', 'error');
            });
        });
    }

    initialize();
})();
