(function () {
    'use strict';
    var root = document.querySelector('[data-extension-manager]');
    if (!root) return;
    var CHANNEL = 'sms-device-auth-v1';
    var status = root.querySelector('[data-extension-status]');
    var updateButton = root.querySelector('[data-extension-update]');
    var expectedVersion = root.dataset.expectedVersion || '';
    var installUrl = root.dataset.installUrl || '';
    var installedBrowser = '';
    var pending = new Map();
    var tr = function (key) { return typeof window.smsTranslate === 'function' ? window.smsTranslate(key) : key; };

    function extensionRequest(action, timeout) {
        return new Promise(function (resolve, reject) {
            var requestId = crypto.randomUUID();
            var timer = window.setTimeout(function () {
                pending.delete(requestId);
                reject(new Error(tr('extension_management_timeout')));
            }, timeout || 5000);
            pending.set(requestId, { resolve: resolve, reject: reject, timer: timer });
            window.postMessage({ channel: CHANNEL, direction: 'page-to-extension', requestId: requestId, action: action, payload: {} }, window.location.origin);
        });
    }

    window.addEventListener('message', function (event) {
        var data = event.data;
        if (event.source !== window || event.origin !== window.location.origin || !data || data.channel !== CHANNEL || data.direction !== 'extension-to-page') return;
        var request = pending.get(data.requestId);
        if (!request) return;
        pending.delete(data.requestId);
        window.clearTimeout(request.timer);
        if (data.success) request.resolve(data.result || {});
        else request.reject(new Error(data.error || tr('extension_management_unavailable')));
    });

    function showInfo(info) {
        var browserName = info.browser || 'Browser';
        installedBrowser = browserName.toLowerCase();
        var version = info.version || '?';
        status.textContent = tr('extension_version_installed').replace('{browser}', browserName).replace('{version}', version).replace('{latest}', expectedVersion);
        status.classList.remove('error', 'success');
        status.classList.add(version === expectedVersion ? 'success' : 'error');
    }

    extensionRequest('get_extension_info').then(showInfo).catch(function () {
        status.textContent = tr('extension_manual_reload_required');
        status.classList.add('error');
    });

    updateButton.addEventListener('click', function () {
        var separator = installUrl.indexOf('?') === -1 ? '?' : '&';
        var browserParameter = installedBrowser === 'firefox' ? 'firefox' : (installedBrowser === 'chrome' ? 'chrome' : 'auto');
        window.open(installUrl + separator + 'browser=' + encodeURIComponent(browserParameter), '_blank', 'noopener');
        updateButton.disabled = true;
        status.textContent = tr('extension_reloading');
        status.classList.remove('error', 'success');
        extensionRequest('reload_extension', 8000).then(function () {
            status.textContent = tr('extension_reload_started');
            status.classList.add('success');
            window.setTimeout(function () { window.location.reload(); }, 1800);
        }).catch(function () {
            status.textContent = tr('extension_manual_reload_required');
            status.classList.add('error');
            updateButton.disabled = false;
        });
    });
}());
