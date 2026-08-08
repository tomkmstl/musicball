(function () {
    var root = document.querySelector('[data-push-admin-test]');
    var config = window.ML_PUSH_ADMIN_TEST || null;

    if (!root || !config) return;

    var statusNode = root.querySelector('[data-push-admin-status]');
    var typeSelect = root.querySelector('[data-push-admin-type]');
    var sendButton = root.querySelector('[data-push-admin-send]');
    var recipientCount = 0;
    var serverReady = false;
    var busy = false;

    if (typeSelect) typeSelect.disabled = false;
    if (sendButton) sendButton.disabled = true;

    function isReady() {
        return serverReady && recipientCount > 0;
    }

    function setStatus(message, state) {
        if (!statusNode) return;
        statusNode.textContent = message;
        statusNode.dataset.state = state || '';
    }

    function setBusy(nextBusy) {
        var ready = isReady();
        busy = nextBusy;
        if (typeSelect) typeSelect.disabled = busy;
        if (sendButton) sendButton.disabled = busy || !ready;
        root.classList.toggle('is-busy', busy);
    }

    function render() {
        var ready = isReady();

        setBusy(false);
        if (!serverReady) {
            setStatus('The push delivery service is not available yet.', 'unavailable');
        } else if (ready) {
            setStatus(
                'Ready to send to ' + recipientCount + ' enabled admin device' + (recipientCount === 1 ? '' : 's') + '.',
                'ready'
            );
        } else {
            setStatus('No admin devices currently have Push Notifications enabled.', 'unavailable');
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
        request('status', {
            scope: 'admin_test'
        }).then(function (body) {
            serverReady = !!body.ready;
            recipientCount = Math.max(0, Number(body.recipient_count) || 0);
            render();
        }).catch(function () {
            setBusy(false);
            setStatus('Enabled admin devices could not be checked.', 'error');
        });
    }

    if (sendButton) {
        sendButton.addEventListener('click', function () {
            if (busy || !isReady()) return;

            setBusy(true);
            request('test', {
                notification_type: typeSelect ? typeSelect.value : 'connection_test',
                scope: 'admin_test'
            }).then(function (body) {
                recipientCount = Math.max(0, Number(body.recipient_count) || recipientCount);
                setBusy(false);
                var sentCount = Math.max(0, Number(body.sent_count) || 0);
                var failedCount = Math.max(0, Number(body.failed_count) || 0);
                var message = 'Test sent to ' + sentCount + ' admin device' + (sentCount === 1 ? '' : 's') + '.';
                if (failedCount > 0) {
                    message += ' ' + failedCount + ' failed.';
                }
                setStatus(message, 'sent');
            }).catch(function (error) {
                setBusy(false);
                setStatus(error.message || 'The test could not be sent.', 'error');
            });
        });
    }

    initialize();
})();
