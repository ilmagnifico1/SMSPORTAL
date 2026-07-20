(function () {
  'use strict';
  var CHANNEL = 'sms-device-auth-v1';
  var extensionApi = typeof browser !== 'undefined' ? browser : chrome;
  var allowedActions = ['get_identity', 'request_authorization', 'get_extension_info', 'reload_extension'];

  window.addEventListener('message', function (event) {
    var data = event.data;
    if (event.source !== window || event.origin !== window.location.origin || !data ||
        data.channel !== CHANNEL || data.direction !== 'page-to-extension') return;
    if (allowedActions.indexOf(data.action) === -1) return;

    extensionApi.runtime.sendMessage({
      source: 'sms-portal-page',
      action: data.action,
      payload: data.payload || {},
      base_url: new URL('.', window.location.href).href
    }).then(function (result) {
      window.postMessage({
        channel: CHANNEL,
        direction: 'extension-to-page',
        requestId: data.requestId,
        success: Boolean(result && result.success),
        result: result && result.result,
        error: result && result.error
      }, window.location.origin);
    }).catch(function () {
      window.postMessage({
        channel: CHANNEL,
        direction: 'extension-to-page',
        requestId: data.requestId,
        success: false,
        error: 'Estensione non disponibile.'
      }, window.location.origin);
    });
  });
}());
