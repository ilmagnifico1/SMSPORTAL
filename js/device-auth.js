(function () {
    'use strict';

    var CHANNEL = 'sms-device-auth-v1';
    var pending = new Map();
    var tr = function (key) { return typeof window.smsTranslate === 'function' ? window.smsTranslate(key) : key; };

    window.addEventListener('message', function (event) {
        var data = event.data;
        if (event.source !== window || !data || data.channel !== CHANNEL || data.direction !== 'extension-to-page') return;
        var request = pending.get(data.requestId);
        if (!request) return;
        pending.delete(data.requestId);
        window.clearTimeout(request.timer);
        data.success ? request.resolve(data.result || {}) : request.reject(new Error(data.error || tr('extension_unavailable')));
    });

    function extensionRequest(action, payload, timeout) {
        return new Promise(function (resolve, reject) {
            var requestId = crypto.randomUUID();
            var timer = window.setTimeout(function () {
                pending.delete(requestId);
                reject(new Error(tr('extension_missing')));
            }, timeout || 4000);
            pending.set(requestId, { resolve: resolve, reject: reject, timer: timer });
            window.postMessage({
                channel: CHANNEL,
                direction: 'page-to-extension',
                requestId: requestId,
                action: action,
                payload: payload || {}
            }, window.location.origin);
        });
    }

    async function apiJson(url, options) {
        var response = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {}));
        var body;
        try { body = await response.json(); } catch (error) { body = { success: false, message: tr('invalid_server_response') }; }
        if (!response.ok || !body.success) throw new Error(body.message || tr('operation_failed'));
        return body;
    }

    function statusElement(form) {
        var current = form.querySelector('.device-auth-status');
        if (current) return current;
        var node = document.createElement('div');
        node.className = 'device-auth-status alert';
        node.hidden = true;
        node.setAttribute('role', 'status');
        form.prepend(node);
        return node;
    }

    function showStatus(form, message, error) {
        var node = statusElement(form);
        node.hidden = false;
        node.textContent = message;
        node.classList.toggle('alert-danger', Boolean(error));
    }

    function setBusy(form, busy) {
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.disabled = busy;
        });
    }

    async function registerIdentity(form, identity) {
        var csrf = form.querySelector('input[name="csrf_token"]');
        if (!csrf) throw new Error(tr('session_token_missing'));
        var body = {
            api_action: 'register',
            csrf_token: csrf.value,
            device_uuid: identity.device_uuid,
            device_name: identity.device_name || navigator.userAgent.slice(0, 150),
            public_jwk: identity.public_jwk
        };
        var result = await apiJson('index.php?route=device-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        if (result.status !== 'approved') throw new Error(result.message || tr('device_not_approved'));
    }

    async function waitForApproval(authorizationId) {
        var deadline = Date.now() + 95000;
        while (Date.now() < deadline) {
            await new Promise(function (resolve) { window.setTimeout(resolve, 1000); });
            var result = await apiJson('index.php?route=device-api&api_action=status&authorization_id=' + encodeURIComponent(authorizationId));
            if (result.status === 'approved') return;
            if (result.status === 'expired' || result.status === 'missing' || result.status === 'used') {
                throw new Error(tr('authorization_expired'));
            }
        }
        throw new Error(tr('authorization_timeout'));
    }

    async function authorizeAndSubmit(form) {
        setBusy(form, true);
        showStatus(form, tr('checking_extension'), false);
        try {
            var identity = await extensionRequest('get_identity');
            if (!identity.device_uuid || !identity.public_jwk) throw new Error(tr('invalid_extension_identity'));
            await registerIdentity(form, identity);

            var requestBody = new FormData(form);
            requestBody.set('api_action', 'prepare');
            requestBody.set('action_type', form.dataset.deviceAuthorized);
            requestBody.set('device_uuid', identity.device_uuid);
            requestBody.delete('authorization_id');
            var prepared = await apiJson('index.php?route=device-api', { method: 'POST', body: requestBody });

            showStatus(form, tr('confirm_extension'), false);
            await extensionRequest('request_authorization', {
                authorization_id: prepared.authorization_id,
                challenge: prepared.challenge,
                locale: document.documentElement.lang === 'en' ? 'en' : 'it'
            });
            await waitForApproval(prepared.authorization_id);

            var field = form.querySelector('input[name="authorization_id"]');
            if (!field) throw new Error(tr('authorization_field_missing'));
            field.value = prepared.authorization_id;
            showStatus(form, tr('authorization_valid_sending'), false);
            if (form.dataset.asyncSubmit === 'true') {
                form.requestSubmit();
            } else {
                HTMLFormElement.prototype.submit.call(form);
            }
        } catch (error) {
            showStatus(form, error instanceof Error ? error.message : tr('sending_unauthorized'), true);
            setBusy(form, false);
        }
    }

    document.querySelectorAll('form[data-device-authorized]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var authorization = form.querySelector('input[name="authorization_id"]');
            if (authorization && authorization.value) return;
            event.preventDefault();
            authorizeAndSubmit(form);
        });
    });
}());
