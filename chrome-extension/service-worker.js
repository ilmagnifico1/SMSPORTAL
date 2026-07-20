'use strict';

var extensionApi = typeof browser !== 'undefined' ? browser : chrome;
var DB_NAME = 'sms-device-identity-v1';
var STORE_NAME = 'identity';

function openDatabase() {
  return new Promise(function (resolve, reject) {
    var request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = function () {
      request.result.createObjectStore(STORE_NAME);
    };
    request.onsuccess = function () { resolve(request.result); };
    request.onerror = function () { reject(request.error); };
  });
}

async function databaseGet(key) {
  var db = await openDatabase();
  return new Promise(function (resolve, reject) {
    var transaction = db.transaction(STORE_NAME, 'readonly');
    var request = transaction.objectStore(STORE_NAME).get(key);
    request.onsuccess = function () { resolve(request.result); };
    request.onerror = function () { reject(request.error); };
    transaction.oncomplete = function () { db.close(); };
  });
}

async function databasePut(key, value) {
  var db = await openDatabase();
  return new Promise(function (resolve, reject) {
    var transaction = db.transaction(STORE_NAME, 'readwrite');
    transaction.objectStore(STORE_NAME).put(value, key);
    transaction.oncomplete = function () { db.close(); resolve(); };
    transaction.onerror = function () { db.close(); reject(transaction.error); };
  });
}

async function identity() {
  var stored = await databaseGet('device');
  if (stored && stored.device_uuid && stored.private_key && stored.public_jwk) return stored;
  var pair = await crypto.subtle.generateKey(
    { name: 'ECDSA', namedCurve: 'P-256' },
    false,
    ['sign', 'verify']
  );
  var publicJwk = await crypto.subtle.exportKey('jwk', pair.publicKey);
  stored = {
    device_uuid: crypto.randomUUID(),
    device_name: (typeof browser !== 'undefined' ? 'Firefox' : 'Chrome') + ' · ' + navigator.platform,
    private_key: pair.privateKey,
    public_jwk: publicJwk
  };
  await databasePut('device', stored);
  return stored;
}

function validatedBaseUrl(value) {
  try {
    var url = new URL(value);
    if (url.protocol !== 'https:' || url.hostname !== 'smsportal.book-my.eu') return null;
    if (url.pathname !== '/' || url.search || url.hash || url.username || url.password) return null;
    return url.href;
  } catch (error) {
    return null;
  }
}

function pageSenderIsValid(sender, baseUrl) {
  if (!sender.tab || !sender.tab.url) return false;
  try {
    var page = new URL(sender.tab.url);
    var base = new URL(baseUrl);
    return page.origin === base.origin && page.pathname.startsWith('/');
  } catch (error) {
    return false;
  }
}

function internalSenderIsValid(sender) {
  return typeof sender.url === 'string' && sender.url.startsWith(extensionApi.runtime.getURL('approve.html'));
}

async function parseJsonResponse(response) {
  var text = await response.text();
  try {
    return JSON.parse(text);
  } catch (error) {
    throw new Error('Il server ha restituito una risposta non valida (HTTP ' + response.status + ').');
  }
}

function base64Url(buffer) {
  var bytes = new Uint8Array(buffer);
  var binary = '';
  for (var i = 0; i < bytes.length; i += 1) binary += String.fromCharCode(bytes[i]);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function fetchAuthorization(baseUrl, authorizationId, challenge) {
  var url = new URL('index.php?route=device-api', baseUrl);
  url.searchParams.set('api_action', 'details');
  url.searchParams.set('authorization_id', authorizationId);
  url.searchParams.set('challenge', challenge);
  var response = await fetch(url.href, { cache: 'no-store' });
  var body = await parseJsonResponse(response);
  if (!response.ok || !body.success) throw new Error(body.message || 'Autorizzazione non disponibile.');
  return body.authorization;
}

async function approve(baseUrl, authorizationId, challenge) {
  var auth = await fetchAuthorization(baseUrl, authorizationId, challenge);
  var device = await identity();
  if (auth.device_uuid !== device.device_uuid || auth.challenge !== challenge) throw new Error('Richiesta destinata a un altro dispositivo.');
  var text = ['SMS-AUTH-V1', auth.authorization_id, auth.challenge, auth.payload_hash,
    String(auth.expires_at), auth.device_uuid.toLowerCase()].join('\n');
  var signature = await crypto.subtle.sign(
    { name: 'ECDSA', hash: 'SHA-256' },
    device.private_key,
    new TextEncoder().encode(text)
  );
  var response = await fetch(new URL('index.php?route=device-api', baseUrl).href, {
    method: 'POST',
    cache: 'no-store',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      api_action: 'approve',
      authorization_id: authorizationId,
      device_uuid: device.device_uuid,
      signature: base64Url(signature)
    })
  });
  var body = await parseJsonResponse(response);
  if (!response.ok || !body.success) throw new Error(body.message || 'Firma rifiutata dal server.');
  return body;
}

extensionApi.runtime.onMessage.addListener(function (message, sender, sendResponse) {
  (async function () {
    if (message && message.source === 'sms-portal-page') {
      var baseUrl = validatedBaseUrl(message.base_url);
      if (!baseUrl || !pageSenderIsValid(sender, baseUrl)) throw new Error('Pagina SMS non autorizzata.');
      if (message.action === 'get_extension_info') {
        return { success: true, result: {
          version: extensionApi.runtime.getManifest().version,
          browser: typeof browser !== 'undefined' ? 'Firefox' : 'Chrome'
        } };
      }
      if (message.action === 'reload_extension') {
        var version = extensionApi.runtime.getManifest().version;
        var updateStatus = 'reload_only';
        if (typeof extensionApi.runtime.requestUpdateCheck === 'function') {
          try {
            var updateResult = await extensionApi.runtime.requestUpdateCheck();
            updateStatus = updateResult && updateResult.status ? updateResult.status : updateStatus;
          } catch (updateError) {
            updateStatus = 'reload_only';
          }
        }
        setTimeout(function () { extensionApi.runtime.reload(); }, 600);
        return { success: true, result: { version: version, reloading: true, update_status: updateStatus } };
      }
      if (message.action === 'get_identity') {
        var current = await identity();
        return { success: true, result: {
          device_uuid: current.device_uuid,
          device_name: current.device_name,
          public_jwk: current.public_jwk
        } };
      }
      if (message.action === 'request_authorization') {
        var id = String(message.payload && message.payload.authorization_id || '');
        var challenge = String(message.payload && message.payload.challenge || '');
        var locale = String(message.payload && message.payload.locale || '') === 'en' ? 'en' : 'it';
        if (!/^[a-f0-9]{48}$/.test(id) || !/^[A-Za-z0-9_-]{40,100}$/.test(challenge)) throw new Error('Richiesta non valida.');
        var query = new URLSearchParams({ authorization_id: id, challenge: challenge, base_url: baseUrl, locale: locale });
        await extensionApi.windows.create({ url: extensionApi.runtime.getURL('approve.html?' + query.toString()), type: 'popup', width: 520, height: 680, focused: true });
        return { success: true, result: { opened: true } };
      }
      throw new Error('Operazione non consentita.');
    }

    if (!internalSenderIsValid(sender)) throw new Error('Mittente non autorizzato.');
    var internalBase = validatedBaseUrl(message && message.base_url);
    if (!internalBase) throw new Error('Server non autorizzato.');
    if (message.action === 'get_authorization_details') {
      return { success: true, result: await fetchAuthorization(internalBase, String(message.authorization_id || ''), String(message.challenge || '')) };
    }
    if (message.action === 'approve_authorization') {
      return { success: true, result: await approve(internalBase, String(message.authorization_id || ''), String(message.challenge || '')) };
    }
    throw new Error('Operazione interna non consentita.');
  }()).then(sendResponse).catch(function (error) {
    sendResponse({ success: false, error: error instanceof Error ? error.message : 'Errore dell’estensione.' });
  });
  return true;
});
