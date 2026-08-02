(function () {
    var root = document.querySelector('[data-push-settings]');
    var config = window.ML_PUSH_SETTINGS || null;

    if (!root || !config) return;

    var statusNode = root.querySelector('[data-push-status]');
    var toggleButton = root.querySelector('[data-push-toggle]');
    var testPanel = root.querySelector('[data-push-test-panel]');
    var testTypeSelect = root.querySelector('[data-push-test-type]');
    var testButton = root.querySelector('[data-push-test]');
    var registration = null;
    var browserSubscription = null;
    var serverSubscribed = false;
    var busy = false;

    if (toggleButton) toggleButton.disabled = true;
    if (testButton) testButton.disabled = true;

    function setStatus(message, state) {
        if (!statusNode) return;
        statusNode.textContent = message;
        statusNode.dataset.state = state || '';
    }

    function setBusy(nextBusy) {
        busy = nextBusy;
        if (toggleButton) toggleButton.disabled = busy || !config.ready;
        if (testTypeSelect) testTypeSelect.disabled = busy || !serverSubscribed;
        if (testButton) testButton.disabled = busy || !serverSubscribed;
        root.classList.toggle('is-busy', busy);
    }

    function render() {
        var enabled = !!browserSubscription && serverSubscribed && Notification.permission === 'granted';

        root.classList.toggle('is-enabled', enabled);
        if (toggleButton) {
            toggleButton.textContent = enabled ? 'Turn off reminders' : 'Turn on reminders';
        }
        if (testPanel) {
            testPanel.hidden = !enabled;
        }

        if (!busy) {
            setBusy(false);
        }

        if (enabled) {
            setStatus('On for this device', 'on');
        } else if (Notification.permission === 'denied') {
            setStatus('Blocked in this device\'s notification settings', 'blocked');
            if (toggleButton) toggleButton.disabled = true;
        } else {
            setStatus('Off for this device', 'off');
        }
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        return Uint8Array.from(rawData, function (character) {
            return character.charCodeAt(0);
        });
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

    function getContentEncoding() {
        if (window.PushManager && Array.isArray(PushManager.supportedContentEncodings)) {
            if (PushManager.supportedContentEncodings.indexOf('aes128gcm') !== -1) {
                return 'aes128gcm';
            }
            if (PushManager.supportedContentEncodings.length) {
                return PushManager.supportedContentEncodings[0];
            }
        }
        return 'aes128gcm';
    }

    function serializeSubscription(subscription) {
        var value = subscription.toJSON();
        value.contentEncoding = getContentEncoding();
        return value;
    }

    function isIosBrowserTab() {
        var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent || '');
        var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        return isIos && !isStandalone;
    }

    function initialize() {
        if (!config.ready) {
            setStatus('Not available yet', 'unavailable');
            if (toggleButton) toggleButton.disabled = true;
            if (testPanel) testPanel.hidden = true;
            return;
        }

        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            setStatus(
                isIosBrowserTab()
                    ? 'Add Musicball to your Home Screen to use reminders'
                    : 'Push notifications are not supported on this device',
                'unsupported'
            );
            if (toggleButton) toggleButton.disabled = true;
            if (testPanel) testPanel.hidden = true;
            return;
        }

        navigator.serviceWorker.ready.then(function (readyRegistration) {
            registration = readyRegistration;
            return registration.pushManager.getSubscription();
        }).then(function (subscription) {
            browserSubscription = subscription;

            if (!browserSubscription) {
                serverSubscribed = false;
                render();
                return null;
            }

            return request('status', { endpoint: browserSubscription.endpoint }).then(function (body) {
                serverSubscribed = !!body.subscribed;
                render();
            });
        }).catch(function () {
            setBusy(false);
            setStatus('Reminder status could not be checked', 'error');
        });
    }

    function enableReminders() {
        var createdSubscription = false;

        setBusy(true);
        Promise.resolve().then(function () {
            if (Notification.permission === 'denied') {
                throw new Error('Notifications are blocked in this device\'s settings.');
            }
            if (Notification.permission === 'default') {
                return Notification.requestPermission().then(function (permission) {
                    if (permission !== 'granted') {
                        throw new Error('Notification permission was not granted.');
                    }
                });
            }
            return null;
        }).then(function () {
            if (browserSubscription) return browserSubscription;

            return registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(config.publicKey)
            }).then(function (subscription) {
                createdSubscription = true;
                browserSubscription = subscription;
                return subscription;
            });
        }).then(function (subscription) {
            return request('subscribe', { subscription: serializeSubscription(subscription) });
        }).then(function () {
            serverSubscribed = true;
            setBusy(false);
            render();
        }).catch(function (error) {
            if (createdSubscription && browserSubscription) {
                browserSubscription.unsubscribe().catch(function () { return null; });
                browserSubscription = null;
            }
            serverSubscribed = false;
            setBusy(false);
            setStatus(error.message || 'Reminders could not be turned on', 'error');
        });
    }

    function disableReminders() {
        if (!browserSubscription) return;

        setBusy(true);
        request('unsubscribe', { endpoint: browserSubscription.endpoint }).then(function () {
            serverSubscribed = false;
            return browserSubscription.unsubscribe().catch(function () { return false; });
        }).then(function () {
            browserSubscription = null;
            setBusy(false);
            render();
        }).catch(function (error) {
            setBusy(false);
            setStatus(error.message || 'Reminders could not be turned off', 'error');
        });
    }

    if (toggleButton) {
        toggleButton.addEventListener('click', function () {
            if (busy || !registration) return;

            if (browserSubscription && serverSubscribed) {
                disableReminders();
            } else {
                enableReminders();
            }
        });
    }

    if (testButton) {
        testButton.addEventListener('click', function () {
            if (busy || !browserSubscription || !serverSubscribed) return;

            setBusy(true);
            request('test', {
                endpoint: browserSubscription.endpoint,
                notification_type: testTypeSelect ? testTypeSelect.value : 'connection_test'
            }).then(function () {
                setBusy(false);
                setStatus('Test sent', 'on');
                window.setTimeout(render, 2500);
            }).catch(function (error) {
                setBusy(false);
                setStatus(error.message || 'The test could not be sent', 'error');
            });
        });
    }

    initialize();
})();
