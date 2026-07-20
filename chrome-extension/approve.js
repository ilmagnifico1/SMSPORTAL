(function () {
  'use strict';
  var extensionApi = typeof browser !== 'undefined' ? browser : chrome;
  var params = new URLSearchParams(window.location.search);
  var authorizationId = params.get('authorization_id') || '';
  var challenge = params.get('challenge') || '';
  var baseUrl = params.get('base_url') || '';
  var locale = params.get('locale') === 'en' ? 'en' : 'it';
  var summary = document.getElementById('summary');
  var status = document.getElementById('status');
  var approveButton = document.getElementById('approve');
  var denyButton = document.getElementById('deny');
  var countdownTimer = null;
  var translations = {
    en: {
      title: 'Authorize SMS sending', heading: 'Confirm sending', warning: 'Check carefully: the signature authorizes only the operation shown below.',
      loading: 'Loading request…', cancel: 'Cancel', approve: 'Authorize sending', expired: 'The request has expired. Close the window and try again.',
      expires_one: 'The request expires in {seconds} second.', expires_many: 'The request expires in {seconds} seconds.', unavailable: 'Request unavailable.',
      signing: 'Cryptographic signature in progress…', failed: 'Authorization failed.', authorized: 'Sending authorized. You can close this window.',
      tipo: 'Type', provider: 'Provider', destinatario: 'Recipient', destinatari: 'Recipients', mittente: 'Sender', messaggio: 'Message', campagna: 'Campaign',
      'SMS singolo': 'Single SMS', 'Campagna SMS': 'SMS campaign'
    }
  };
  function tr(key) { return locale === 'en' && translations.en[key] ? translations.en[key] : key; }
  if (locale === 'en') {
    document.documentElement.lang = 'en';
    document.title = tr('title');
    document.querySelector('h1').textContent = tr('heading');
    document.querySelector('.warning').textContent = tr('warning');
    status.textContent = tr('loading');
    denyButton.textContent = tr('cancel');
    approveButton.textContent = tr('approve');
  }

  function stopCountdown() {
    if (countdownTimer !== null) {
      window.clearInterval(countdownTimer);
      countdownTimer = null;
    }
  }

  function startCountdown(expiresAt) {
    var deadline = Number(expiresAt) * 1000;

    function updateCountdown() {
      var remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      if (remaining <= 0) {
        stopCountdown();
        approveButton.disabled = true;
        status.textContent = locale === 'en' ? tr('expired') : 'La richiesta è scaduta. Chiudi la finestra e riprova.';
        status.classList.add('error');
        return;
      }
      status.textContent = locale === 'en'
        ? tr(remaining === 1 ? 'expires_one' : 'expires_many').replace('{seconds}', remaining)
        : 'La richiesta scade tra ' + remaining + (remaining === 1 ? ' secondo.' : ' secondi.');
    }

    stopCountdown();
    updateCountdown();
    countdownTimer = window.setInterval(updateCountdown, 250);
  }

  function send(action) {
    return extensionApi.runtime.sendMessage({ action: action, authorization_id: authorizationId, challenge: challenge, base_url: baseUrl });
  }

  function showDetails(details) {
    Object.entries(details.summary || {}).forEach(function (entry) {
      var term = document.createElement('dt');
      var value = document.createElement('dd');
      term.textContent = tr(entry[0]).replace(/_/g, ' ');
      value.textContent = tr(String(entry[1]));
      summary.append(term, value);
    });
    approveButton.disabled = false;
    startCountdown(details.expires_at);
  }

  send('get_authorization_details').then(function (response) {
    if (!response || !response.success) throw new Error(response && response.error || (locale === 'en' ? tr('unavailable') : 'Richiesta non disponibile.'));
    showDetails(response.result);
  }).catch(function (error) {
    status.textContent = error.message;
    status.classList.add('error');
  });

  approveButton.addEventListener('click', function () {
    stopCountdown();
    approveButton.disabled = true;
    denyButton.disabled = true;
    status.textContent = locale === 'en' ? tr('signing') : 'Firma crittografica in corso…';
    send('approve_authorization').then(function (response) {
      if (!response || !response.success) throw new Error(response && response.error || (locale === 'en' ? tr('failed') : 'Autorizzazione fallita.'));
      status.textContent = locale === 'en' ? tr('authorized') : 'Invio autorizzato. Puoi chiudere questa finestra.';
      status.classList.add('success');
      window.setTimeout(function () { window.close(); }, 900);
    }).catch(function (error) {
      status.textContent = error.message;
      status.classList.add('error');
      denyButton.disabled = false;
    });
  });

  denyButton.addEventListener('click', function () { window.close(); });
  window.addEventListener('unload', stopCountdown);
}());
